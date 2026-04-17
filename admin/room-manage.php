<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login(); require_company();
$cid = current_company_id();

$roomId = input_int('id', 'GET');
$room   = db_row('SELECT * FROM retro_rooms WHERE id=? AND company_id=?', [$roomId, $cid]);
if (!$room) { flash_set('error', 'Session not found.'); redirect(BASE_URL . '/admin/rooms.php'); }

// ── POST: status changes + participant management ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Participant permission toggle
    if (isset($_POST['participant_id'])) {
        csrf_verify();
        $pid    = input_int('participant_id');
        $pfield = input_str('pfield'); // can_vote | can_add_notes | is_guest
        $pval   = (int)trim($_POST['pval'] ?? '0'); // explicit cast — avoids filter_var(0) edge case
        $allowed = ['can_vote','can_add_notes','is_guest'];

        header('Content-Type: application/json');

        if (!in_array($pfield, $allowed, true) || !in_array($pval, [0,1], true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid field or value.']);
            exit;
        }

        // Auto-add permission columns if the upgrade hasn't been run yet
        $pdo = db();
        foreach (['is_guest'=>0, 'can_vote'=>1, 'can_add_notes'=>1] as $col => $default) {
            $exists = db_row(
                "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='participants' AND COLUMN_NAME=?",
                [$col]
            );
            if ((int)($exists['n'] ?? 0) === 0) {
                $stmt = $pdo->query("ALTER TABLE `participants` ADD COLUMN `$col` TINYINT(1) NOT NULL DEFAULT $default");
                $stmt->closeCursor();
            }
        }

        try {
            if ($pfield === 'is_guest' && $pval === 1) {
                // Guest: read-only observer — revoke both write permissions
                db_exec('UPDATE participants SET is_guest=1, can_vote=0, can_add_notes=0 WHERE id=? AND room_id=?', [$pid, $roomId]);
            } elseif ($pfield === 'is_guest' && $pval === 0) {
                // Remove guest: restore full permissions
                db_exec('UPDATE participants SET is_guest=0, can_vote=1, can_add_notes=1 WHERE id=? AND room_id=?', [$pid, $roomId]);
            } else {
                $colMap = ['can_vote'=>'can_vote','can_add_notes'=>'can_add_notes','is_guest'=>'is_guest'];
                $safeCol = $colMap[$pfield] ?? null;
                if (!$safeCol) { echo json_encode(['ok'=>false,'error'=>'Invalid field']); exit; }
                db_exec("UPDATE participants SET `{$safeCol}`=? WHERE id=? AND room_id=?", [$pval, $pid, $roomId]);
            }
            echo json_encode(['ok' => true, 'field' => $pfield, 'val' => $pval]);
        } catch (Throwable $ex) {
            echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        }
        exit;
    }

// ── POST: status changes ───────────────────────────────────────────────────────
    csrf_verify();
    $action = input_str('action');

    $transitions = [
        'activate' => ['from' => ['draft'],           'to' => 'active'],
        'reveal'   => ['from' => ['active'],           'to' => 'revealed'],
        'close'    => ['from' => ['active','revealed'],'to' => 'closed'],
        'archive'  => ['from' => ['closed'],           'to' => 'archived'],
        'reopen'   => ['from' => ['closed','archived'],'to' => 'active'],
    ];

    if (isset($transitions[$action]) && in_array($room['status'], $transitions[$action]['from'], true)) {
        $newStatus = $transitions[$action]['to'];
        $revealAt  = ($action === 'reveal') ? date('Y-m-d H:i:s') : $room['reveal_at'];
        db_exec('UPDATE retro_rooms SET status=?, reveal_at=? WHERE id=?', [$newStatus, $revealAt, $roomId]);
        if ($action === 'reveal') {
            db_exec('UPDATE retro_notes SET is_revealed=1 WHERE room_id=?', [$roomId]);
        }
        flash_set('success', 'Status changed to ' . $newStatus . '.');
        redirect(BASE_URL . '/admin/room-manage.php?id=' . $roomId);
    }

    if ($action === 'delete') {
        db_exec('DELETE FROM retro_rooms WHERE id=?', [$roomId]);
        flash_set('success', 'Session deleted.');
        redirect(BASE_URL . '/admin/rooms.php');
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$room    = db_row('SELECT * FROM retro_rooms WHERE id = ?', [$roomId]); // refresh
$columns = db_query('SELECT * FROM retro_columns WHERE room_id=? ORDER BY display_order', [$roomId]);

$notes = db_query("
    SELECT n.*, c.title AS col_title, c.color AS col_color,
           COUNT(DISTINCT v.id) AS votes,
           p.nickname AS participant_nickname
    FROM retro_notes n
    JOIN retro_columns c ON c.id = n.column_id
    LEFT JOIN note_votes v ON v.note_id = n.id
    LEFT JOIN participants p ON p.id = n.participant_id
    WHERE n.room_id = ?
    GROUP BY n.id
    ORDER BY c.display_order, votes DESC, n.created_at
", [$roomId]);

$notesByCol = [];
foreach ($columns as $col) $notesByCol[$col['id']] = [];
foreach ($notes as $note) $notesByCol[$note['column_id']][] = $note;

$participants = db_query("
    SELECT p.id, p.nickname, p.created_at,
           COUNT(DISTINCT n.id) AS note_count
    FROM participants p
    LEFT JOIN retro_notes n ON n.participant_id = p.id
    WHERE p.room_id = ?
    GROUP BY p.id
    ORDER BY p.created_at
", [$roomId]);
$participantCount = count($participants);

$actionItems = db_query('SELECT * FROM action_items WHERE room_id=? ORDER BY created_at DESC', [$roomId]);
$isRevealed  = in_array($room['status'], ['revealed','closed','archived']);
// Fetch live template guidance
$tplRow   = db_row('SELECT * FROM board_templates WHERE name=? LIMIT 1', [$room['template_name']]);
$guidance = trim($tplRow['guidance'] ?? '');
$boardUrl    = BASE_URL . '/room.php?id=' . urlencode($room['room_uuid']);

// Linked poker sessions
$linkedPoker = [];
try {
    $_ppColExists = db_row(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_sessions' AND COLUMN_NAME='retro_room_id'"
    )['n'] ?? 0;
    if ($_ppColExists) {
        $linkedPoker = db_query(
            "SELECT s.code, s.sprint, s.phase, s.created_at,
                    (SELECT COALESCE(SUM(h.final_sp),0) FROM pp_history h WHERE h.session_code=s.code) AS total_sp,
                    (SELECT COUNT(*) FROM pp_history h WHERE h.session_code=s.code) AS story_count
             FROM pp_sessions s
             WHERE s.retro_room_id=? AND s.company_id=?
             ORDER BY s.created_at DESC",
            [$roomId, $cid]
        );
    }
} catch (Throwable $e) {}

$pageTitle  = $room['name'];
$activePage = 'rooms';
require_once ROOT_PATH . '/templates/admin-header.php';
?>

<!-- Room header bar -->
<div class="room-header">
  <div class="room-header__meta">
    <span class="badge badge--<?= e($room['status']) ?> badge--lg"><?= e(ucfirst($room['status'])) ?></span>
    <?php if ($room['session_date']): ?>
      <span class="text-sm" style="color:var(--text-secondary);">📅 <?= e(date('M j, Y', strtotime($room['session_date']))) ?></span>
    <?php endif; ?>
    <span class="text-sm" style="color:var(--text-secondary);cursor:pointer;" onclick="switchTab('participants')">👥 <?= $participantCount ?> participant<?= $participantCount !== 1 ? 's' : '' ?></span>
    <span class="text-sm" style="color:var(--text-secondary);">📝 <?= count($notes) ?> note<?= count($notes) !== 1 ? 's' : '' ?></span>
  </div>
  <div class="room-header__actions">
    <a href="<?= e($boardUrl) ?>" target="_blank" class="btn btn--outline btn--sm">Open Board ↗</a>
    <a href="<?= e(BASE_URL) ?>/admin/room-create.php?id=<?= $roomId ?>" class="btn btn--outline btn--sm">✏️ Edit</a>

    <?php if ($room['status'] === 'draft'): ?>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="activate">
        <button type="button" class="btn btn--success btn--sm" data-confirm="Activate this session? Participants will be able to join." data-confirm-title="Activate Session" data-confirm-btn="▶ Activate" data-confirm-danger="false">▶ Activate</button>
      </form>
    <?php elseif ($room['status'] === 'active'): ?>
      <?php if (!in_array($room['session_type'], ['daily', 'poker'], true)): ?>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="reveal">
        <button type="button" class="btn btn--primary btn--sm" data-confirm="Reveal all notes? All submitted notes will become visible to participants." data-confirm-title="Reveal Notes" data-confirm-btn="👁 Reveal">👁 Reveal Notes</button>
      </form>
      <?php endif; ?>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="close">
        <button type="button" class="btn btn--warning btn--sm" data-confirm="Close this session? No more notes or votes will be accepted." data-confirm-title="Close Session" data-confirm-btn="Close">⏹ Close</button>
      </form>
    <?php elseif ($room['status'] === 'revealed'): ?>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="close">
        <button type="button" class="btn btn--warning btn--sm" data-confirm="Close this session? No more notes or votes will be accepted." data-confirm-title="Close Session" data-confirm-btn="Close">⏹ Close</button>
      </form>
    <?php elseif ($room['status'] === 'closed'): ?>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="archive">
        <button class="btn btn--outline btn--sm">📦 Archive</button>
      </form>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="reopen">
        <button class="btn btn--outline btn--sm">↩ Reopen</button>
      </form>
    <?php endif; ?>

    <?php if ($isRevealed): ?>
      <a href="<?= e(BASE_URL) ?>/admin/export.php?id=<?= $roomId ?>" class="btn btn--outline btn--sm">📄 Export Report</a>
    <?php endif; ?>
    <a href="<?= e(BASE_URL) ?>/admin/poker.php?room_id=<?= $roomId ?>" class="btn btn--outline btn--sm" title="Start a Planning Poker session linked to this retro">🃏 Poker</a>
  </div>
</div>

<!-- Unified Share Panel -->
<?php if (in_array($room['status'], ['draft','active','revealed'])): ?>
<div class="card" style="margin-bottom:1rem;">
  <div class="card__header" style="background:var(--brand);border-radius:var(--r-lg) var(--r-lg) 0 0;">
    <span class="card__title" style="color:#fff;">🔗 Share Session</span>
    <span style="font-size:.75rem;color:rgba(255,255,255,.5);">Give participants everything they need to join</span>
  </div>
  <div class="card__body" style="display:flex;flex-direction:column;gap:.65rem;padding:1rem 1.25rem;">

    <!-- Room link -->
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
      <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);width:80px;flex-shrink:0;">Board Link</span>
      <code style="flex:1;font-size:.825rem;color:var(--ink);word-break:break-all;background:var(--surface);padding:.35rem .65rem;border-radius:var(--r-xs);border:1px solid var(--border);"><?= e($boardUrl) ?></code>
      <button type="button" class="btn btn--sm btn--outline" onclick="
        var url = '<?= e(addslashes($boardUrl)) ?>';
        navigator.clipboard && navigator.clipboard.writeText(url)
          .then(function(){ showToast('Board link copied!','success',2000); })
          .catch(function(){ showToast('Link: '+url,'info',6000); });
      ">Copy</button>
    </div>

    <?php if (!empty($room['join_password'])): ?>
    <!-- Password -->
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;background:var(--color-warning-bg);border:1px solid var(--color-warning-border);border-radius:var(--r-sm);padding:.5rem .75rem;">
      <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--color-warning);width:80px;flex-shrink:0;">🔒 Password</span>
      <code style="flex:1;font-size:1rem;font-weight:800;color:var(--color-warning);letter-spacing:.12em;"><?= e($room['join_password']) ?></code>
      <button type="button" class="btn btn--sm btn--outline" onclick="
        navigator.clipboard && navigator.clipboard.writeText('<?= e(addslashes($room['join_password'])) ?>')
          .then(function(){ showToast('Password copied!','success',2000); });
      ">Copy</button>
    </div>
    <?php endif; ?>

    <?php if (!empty($linkedPoker)): ?>
    <!-- Poker code -->
    <?php foreach ($linkedPoker as $ps): ?>
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;background:var(--color-info-bg);border:1px solid var(--color-info-border);border-radius:var(--r-sm);padding:.5rem .75rem;">
      <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--color-info);width:80px;flex-shrink:0;">🃏 Poker</span>
      <code style="font-size:1rem;font-weight:800;color:var(--color-info);letter-spacing:.2em;"><?= e($ps['code']) ?></code>
      <span style="font-size:.75rem;color:var(--muted);"><?= e($ps['sprint']) ?></span>
      <span style="font-size:.7rem;font-weight:600;padding:.1rem .45rem;border-radius:999px;
        background:<?= $ps['phase']==='voting' ? 'var(--color-success-bg)' : ($ps['phase']==='revealed' ? 'var(--color-info-bg)' : 'var(--surface-2)') ?>;
        color:<?= $ps['phase']==='voting' ? 'var(--color-success)' : ($ps['phase']==='revealed' ? 'var(--color-info)' : 'var(--muted)') ?>;">
        <?= ucfirst(e($ps['phase'])) ?>
      </span>
      <div style="margin-left:auto;display:flex;gap:.4rem;">
        <button type="button" class="btn btn--sm btn--outline" onclick="
          navigator.clipboard && navigator.clipboard.writeText('<?= e(addslashes($ps['code'])) ?>')
            .then(function(){ showToast('Poker code copied!','success',2000); });
        ">Copy code</button>
        <a href="<?= e(BASE_URL) ?>/poker-room.php?code=<?= e($ps['code']) ?>&mod=1&name=<?= urlencode($_SESSION['admin_username'] ?? 'Moderator') ?>"
           class="btn btn--sm btn--primary">Open →</a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<!-- Template Guidance (admin view — collapsible, default collapsed) -->
<?php if ($guidance !== ''): ?>
<div class="admin-guidance-panel" id="admin-guidance-panel" style="margin-bottom:1rem;">
  <button type="button" class="admin-guidance-toggle" id="admin-guidance-toggle"
    onclick="
      var body = document.getElementById('admin-guidance-body');
      var icon = document.getElementById('admin-guidance-icon');
      var open = body.style.display !== 'none';
      body.style.display = open ? 'none' : 'block';
      icon.textContent   = open ? '▸' : '▾';
      try { localStorage.setItem('ra_admin_guidance_open_<?= $roomId ?>', open ? '0' : '1'); } catch(e){}
    ">
    <span id="admin-guidance-icon">▸</span>
    <span>📋 Session Guidance</span>
    <span style="font-size:.75rem;color:var(--text-secondary);font-weight:400;margin-left:.4rem;">— <?= e($room['template_name']) ?></span>
    <span style="margin-left:auto;font-size:.72rem;color:var(--text-secondary);" id="admin-guidance-hint">Click to expand</span>
  </button>
  <div id="admin-guidance-body" class="admin-guidance-body" style="display:none;">
    <?= nl2br(e($guidance)) ?>
  </div>
</div>
<script>
(function() {
  try {
    var stored = localStorage.getItem('ra_admin_guidance_open_<?= $roomId ?>');
    if (stored === '1') {
      document.getElementById('admin-guidance-body').style.display = 'block';
      document.getElementById('admin-guidance-icon').textContent = '▾';
      document.getElementById('admin-guidance-hint').textContent = 'Click to collapse';
    }
  } catch(e) {}
})();
</script>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs" role="tablist" aria-label="Session management">
  <?php if ($room['session_type'] !== 'poker'): ?>
  <button class="tab active" data-tab="board" role="tab" aria-selected="true" aria-controls="tab-board" id="tbtn-board">Board Notes (<?= count($notes) ?>)</button>
  <?php else: ?>
  <button class="tab active" data-tab="poker-board" role="tab" aria-selected="true" aria-controls="tab-poker-board" id="tbtn-poker-board">🃏 Poker Session</button>
  <?php endif; ?>
  <button class="tab <?= $room['session_type']==='poker' ? '' : '' ?>" data-tab="participants" role="tab" aria-selected="false" aria-controls="tab-participants" id="tbtn-participants">Participants (<?= $participantCount ?>)</button>
  <button class="tab" data-tab="actions" role="tab" aria-selected="false" aria-controls="tab-actions" id="tbtn-actions">Action Items (<?= count($actionItems) ?>)</button>
</div>

<!-- POKER BOARD TAB (for poker session type) -->
<?php if ($room['session_type'] === 'poker'): ?>
<?php
$pokerSession = null;
if (!empty($linkedPoker)) $pokerSession = $linkedPoker[0];
?>
<div id="tab-poker-board" role="tabpanel" aria-labelledby="tbtn-poker-board" tabindex="0">
  <?php if ($pokerSession): ?>
  <div class="card" style="margin-bottom:1rem;">
    <div class="card__header" style="background:var(--brand);border-radius:var(--r-lg) var(--r-lg) 0 0;">
      <div style="display:flex;align-items:center;gap:.75rem;">
        <span class="card__title" style="color:#fff;">🃏 <?= e($pokerSession['sprint']) ?></span>
        <?php
      $ppPhaseLabel = ['waiting'=>'⏳ Waiting','voting'=>'🗳 Voting','revealed'=>'👁 Revealed','closed'=>'🔒 Closed'][$pokerSession['phase']] ?? ucfirst($pokerSession['phase']);
      $ppPhaseCls   = $pokerSession['phase']==='closed' ? 'badge--closed' : ($pokerSession['phase']==='voting' ? 'badge--active' : ($pokerSession['phase']==='revealed' ? 'badge--revealed' : 'badge--draft'));
      ?>
      <span class="badge <?= $ppPhaseCls ?>"><?= $ppPhaseLabel ?></span>
        <code style="font-size:.8rem;color:rgba(255,255,255,.6);letter-spacing:.15em;"><?= e($pokerSession['code']) ?></code>
      </div>
      <a href="<?= e(BASE_URL) ?>/poker-room.php?code=<?= e($pokerSession['code']) ?>&mod=1&name=<?= urlencode($_SESSION['admin_username']??'Moderator') ?>"
         class="btn btn--sm btn--outline" style="color:#fff;border-color:rgba(255,255,255,.3);">Open Session →</a>
    </div>
    <div class="card__body">
      <?php
      $histItems = db_query(
          'SELECT * FROM pp_history WHERE session_code=? ORDER BY id DESC',
          [$pokerSession['code']]
      );
      $totalSP  = array_sum(array_column($histItems, 'final_sp'));
      $oversized = array_filter($histItems, function($h){ return (int)$h['final_sp'] >= 8; });
      ?>
      <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
        <div class="stat-card" style="flex:0 0 auto;min-width:0;padding:.75rem 1.25rem;">
          <div class="stat-card__value"><?= count($histItems) ?></div>
          <div class="stat-card__label">Stories Estimated</div>
        </div>
        <div class="stat-card stat-card--purple" style="flex:0 0 auto;min-width:0;padding:.75rem 1.25rem;">
          <div class="stat-card__value"><?= $totalSP ?></div>
          <div class="stat-card__label">Total SP</div>
        </div>
        <?php if (count($oversized)): ?>
        <div class="stat-card" style="flex:0 0 auto;min-width:0;padding:.75rem 1.25rem;background:var(--color-warning-bg);border-color:var(--color-warning-border);">
          <div class="stat-card__value" style="color:var(--color-warning);"><?= count($oversized) ?></div>
          <div class="stat-card__label">Oversized (≥8 SP)</div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($histItems)): ?>
      <table class="data-table">
        <thead>
          <tr>
            <th scope="col">Story</th>
            <th scope="col">Final SP</th>
            <th scope="col">Avg Vote</th>
            <th scope="col">Consensus</th>
            <th scope="col">Flag</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($histItems as $hi): ?>
          <tr <?= (int)$hi['final_sp'] >= 13 ? 'style="background:var(--color-danger-bg);"' : ((int)$hi['final_sp'] >= 8 ? 'style="background:var(--color-warning-bg);"' : '') ?>>
            <td style="font-weight:500;">
              <?= e($hi['story']) ?>
              <?php
              if (!empty($hi['votes_json'])) {
                  $votes = json_decode($hi['votes_json'], true) ?? [];
                  $hasComments = false;
                  foreach ($votes as $v) { if (is_array($v) && !empty($v['comment'])) { $hasComments = true; break; } }
                  if ($hasComments): ?>
                  <div style="margin-top:.4rem;display:flex;flex-direction:column;gap:.2rem;">
                  <?php foreach ($votes as $pName => $pData):
                      $comment = is_array($pData) ? ($pData['comment'] ?? null) : null;
                      if (!$comment) continue;
                      $sp = is_array($pData) ? ($pData['sp'] ?? '?') : $pData;
                  ?>
                  <div style="font-size:.72rem;display:flex;gap:.4rem;align-items:baseline;">
                    <span style="font-weight:700;color:var(--brand-mid);white-space:nowrap;"><?= e($pName) ?> (<?= (int)$sp ?> SP):</span>
                    <span style="color:var(--muted);font-style:italic;"><?= e(mb_substr($comment, 0, 120)) ?></span>
                  </div>
                  <?php endforeach; ?>
                  </div>
                  <?php endif;
              }
              ?>
            </td>
            <td><strong style="font-size:1.1rem;color:var(--brand-mid);"><?= (int)$hi['final_sp'] ?></strong> SP</td>
            <td style="color:var(--muted);"><?= $hi['avg_vote'] !== null && $hi['avg_vote'] !== '' ? e(number_format((float)$hi['avg_vote'],1)) : '—' ?></td>
            <td><?= $hi['consensus'] ? '<span style="color:var(--color-success);font-weight:700;">✓ Yes</span>' : '<span style="color:var(--muted);">✗ No</span>' ?></td>
            <td>
              <?php if ((int)$hi['final_sp'] >= 13): ?>
              <span class="badge" style="background:var(--color-danger-bg);color:var(--color-danger);border:1px solid var(--color-danger-border);">🔴 Must Split</span>
              <?php elseif ((int)$hi['final_sp'] >= 8): ?>
              <span class="badge" style="background:var(--color-warning-bg);color:var(--color-warning);border:1px solid var(--color-warning-border);">🟡 Consider Split</span>
              <?php else: ?>
              <span style="color:var(--muted);font-size:.8rem;">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state"><p>No stories estimated yet. Open the poker session to begin.</p></div>
      <?php endif; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="card"><div class="card__body">
    <p style="color:var(--muted);font-size:.875rem;">No poker session linked yet.</p>
    <a href="<?= e(BASE_URL) ?>/admin/poker.php?room_id=<?= $roomId ?>" class="btn btn--primary" style="margin-top:.75rem;">Create Poker Session →</a>
  </div></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- BOARD TAB -->
<div id="tab-board" role="tabpanel" aria-labelledby="tbtn-board" tabindex="0" <?= $room['session_type']==='poker' ? 'style="display:none;"' : '' ?>>
  <?php if (empty($columns)): ?>
    <div class="card"><div class="empty-state"><p>No columns configured.</p>
      <a href="<?= e(BASE_URL) ?>/admin/room-create.php?id=<?= $roomId ?>" class="btn btn--outline">Edit session to add columns</a>
    </div></div>
  <?php else: ?>
  <div class="board-preview">
    <?php foreach ($columns as $col): ?>
    <div class="preview-col" data-col-id="<?= (int)$col['id'] ?>">
      <div class="preview-col__head" style="border-top-color:<?= e($col['color']) ?>">
        <span class="preview-col__title"><?= e($col['title']) ?></span>
        <span class="preview-col__count"><?= count($notesByCol[$col['id']] ?? []) ?></span>
      </div>
      <!-- Typing indicator for this column (admin view) -->
      <div class="admin-typing-bar" id="admin-typing-<?= (int)$col['id'] ?>">
        <span class="admin-typing-dots">
          <span></span><span></span><span></span>
        </span>
        <span class="admin-typing-name" id="admin-typing-text-<?= (int)$col['id'] ?>"></span>
      </div>
      <div class="preview-col__notes" id="admin-col-notes-<?= (int)$col['id'] ?>">
        <?php if (empty($notesByCol[$col['id']])): ?>
          <span class="text-xs" style="color:var(--text-tertiary);font-style:italic;">No notes yet</span>
        <?php else: ?>
          <?php foreach ($notesByCol[$col['id']] as $note): ?>
          <div class="note-sm <?= $isRevealed ? 'note-sm--admin' : 'note-sm--hidden-admin' ?>"
               data-note-id="<?= (int)$note['id'] ?>">

            <?php if ($isRevealed): ?>
              <!-- Revealed: show full content -->
              <div style="font-size:.8rem;word-break:break-word;line-height:1.45;">
                <?= e($note['content']) ?>
              </div>
            <?php else: ?>
              <!-- Pre-reveal: content hidden, show blurred placeholder -->
              <div style="font-size:.75rem;color:var(--text-tertiary);font-style:italic;
                          filter:blur(3px);user-select:none;pointer-events:none;line-height:1.4;">
                ████ ██████ ███ ████
              </div>
            <?php endif; ?>

            <!-- Participant name always visible to admin regardless of reveal -->
            <div style="display:flex;justify-content:space-between;align-items:center;
                        margin-top:.35rem;flex-wrap:wrap;gap:.25rem;">
              <span style="font-size:.7rem;font-weight:600;
                            color:var(--accent);
                            background:var(--brand-lt);
                            padding:.1rem .45rem;
                            border-radius:var(--r-full);">
                👤 <?= $note['participant_nickname'] ? e($note['participant_nickname']) : 'Anonymous' ?>
              </span>
              <?php if ($isRevealed && $note['votes'] > 0): ?>
                <span style="font-size:.7rem;color:var(--text-secondary);">👍 <?= (int)$note['votes'] ?></span>
              <?php endif; ?>
            </div>

          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- PARTICIPANTS TAB -->
<div id="tab-participants" role="tabpanel" aria-labelledby="tbtn-participants" tabindex="0" style="display:none;">
  <?php if (empty($participants)): ?>
  <div class="card"><div class="empty-state"><p>No participants have joined yet.</p></div></div>
  <?php else: ?>
  <div class="card">
    <div class="card__header">
      <span class="card__title">Joined Participants</span>
      <span class="text-sm" style="color:var(--text-secondary);"><?= $participantCount ?> joined</span>
    </div>
    <table class="data-table">
      <thead><tr>
        <th>#</th><th>Nickname</th><th>Notes</th><th>Joined</th>
        <th title="Can add notes">📝 Notes</th>
        <th title="Can vote">👍 Votes</th>
        <th title="Guest: read-only observer">👁 Guest</th>
      </tr></thead>
      <tbody>
      <?php foreach ($participants as $i => $p): ?>
      <tr id="prow-<?= (int)$p['id'] ?>">
        <td class="text-sm" style="color:var(--text-secondary);"><?= $i + 1 ?></td>
        <td>
          <strong><?= $p['nickname'] ? e($p['nickname']) : '<em style="color:var(--text-secondary)">Anonymous</em>' ?></strong>
          <?php if (($p['is_guest'] ?? 0)): ?>
            <span style="font-size:.65rem;background:rgba(255,149,0,.12);color:var(--accent-orange);border-radius:999px;padding:.05rem .4rem;margin-left:.25rem;">guest</span>
          <?php endif; ?>
        </td>
        <td><?= (int)$p['note_count'] ?></td>
        <td class="text-sm" style="color:var(--text-secondary);"><?= e(date('M j, g:i a', strtotime($p['created_at']))) ?></td>
        <!-- Permission toggles -->
        <td style="text-align:center;">
          <button type="button" class="perm-toggle <?= ($p['can_add_notes'] ?? 1) ? 'perm-on' : 'perm-off' ?>"
            data-pid="<?= (int)$p['id'] ?>" data-field="can_add_notes" data-val="<?= (int)($p['can_add_notes'] ?? 1) ?>"
            title="<?= ($p['can_add_notes'] ?? 1) ? 'Click to restrict' : 'Click to allow' ?>">
            <?= ($p['can_add_notes'] ?? 1) ? '✓' : '✕' ?>
          </button>
        </td>
        <td style="text-align:center;">
          <button type="button" class="perm-toggle <?= ($p['can_vote'] ?? 1) ? 'perm-on' : 'perm-off' ?>"
            data-pid="<?= (int)$p['id'] ?>" data-field="can_vote" data-val="<?= (int)($p['can_vote'] ?? 1) ?>"
            title="<?= ($p['can_vote'] ?? 1) ? 'Click to restrict voting' : 'Click to allow voting' ?>">
            <?= ($p['can_vote'] ?? 1) ? '✓' : '✕' ?>
          </button>
        </td>
        <td style="text-align:center;">
          <button type="button" class="perm-toggle <?= ($p['is_guest'] ?? 0) ? 'perm-on perm-guest' : 'perm-off' ?>"
            data-pid="<?= (int)$p['id'] ?>" data-field="is_guest" data-val="<?= (int)($p['is_guest'] ?? 0) ?>"
            title="<?= ($p['is_guest'] ?? 0) ? 'Guest (read-only) — click to restore' : 'Click to make guest (read-only)' ?>">
            <?= ($p['is_guest'] ?? 0) ? '👁' : '—' ?>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div style="padding:.6rem 1rem;font-size:.75rem;color:var(--text-secondary);border-top:1px solid var(--divider);">
      Toggle permissions live — changes take effect immediately for the participant.
    </div>
  </div>

<style>
.perm-toggle {
  width:30px;height:30px;border-radius:50%;border:1.5px solid;
  font-size:.8rem;font-weight:700;cursor:pointer;
  transition:all .15s;display:inline-flex;align-items:center;justify-content:center;
}
.perm-on  { background:var(--color-success-bg); border-color:var(--color-success-border); color:var(--color-success); }
.perm-off { background:rgba(255,59,48,.08);  border-color:rgba(255,59,48,.25); color:#991b1b; }
.perm-guest { background:rgba(255,149,0,.1); border-color:rgba(255,149,0,.35); color:var(--accent-orange); }
</style>

<script>
function applyPermButton(btn, val) {
  // val must be a number 0 or 1
  var v   = parseInt(val);
  var fld = btn.dataset.field;
  btn.dataset.val = String(v);   // always store as string in dataset

  if (fld === 'is_guest') {
    if (v === 1) {
      btn.className = 'perm-toggle perm-on perm-guest';
      btn.textContent = '👁';
      btn.title = 'Guest (read-only) — click to restore';
    } else {
      btn.className = 'perm-toggle perm-off';
      btn.textContent = '—';
      btn.title = 'Click to make read-only guest';
    }
  } else if (fld === 'can_add_notes') {
    if (v === 1) {
      btn.className = 'perm-toggle perm-on';
      btn.textContent = '✓';
      btn.title = 'Can add notes — click to restrict';
    } else {
      btn.className = 'perm-toggle perm-off';
      btn.textContent = '✕';
      btn.title = 'Restricted — click to allow';
    }
  } else if (fld === 'can_vote') {
    if (v === 1) {
      btn.className = 'perm-toggle perm-on';
      btn.textContent = '✓';
      btn.title = 'Can vote — click to restrict';
    } else {
      btn.className = 'perm-toggle perm-off';
      btn.textContent = '✕';
      btn.title = 'Restricted — click to allow';
    }
  }
}

document.querySelectorAll('.perm-toggle').forEach(function(btn) {
  btn.addEventListener('click', async function() {
    var pid    = btn.dataset.pid;
    var field  = btn.dataset.field;
    var cur    = parseInt(btn.dataset.val, 10);  // parse as base-10 int
    var newVal = (cur === 1) ? 0 : 1;            // toggle

    // Optimistic visual update immediately
    applyPermButton(btn, newVal);
    btn.disabled = true;
    btn.style.opacity = '.7';

    var form = new FormData();
    form.append('participant_id', pid);
    form.append('pfield', field);
    form.append('pval', String(newVal));  // send as string
    form.append('<?= CSRF_TOKEN_NAME ?>', '<?= csrf_token() ?>');

    var res = null;
    try {
      var response = await fetch(
        '<?= e(BASE_URL) ?>/admin/room-manage.php?id=<?= $roomId ?>',
        { method: 'POST', body: form }
      );
      var text = await response.text();
      try {
        res = JSON.parse(text);
      } catch (jsonErr) {
        console.error('Perm toggle — non-JSON from server:', text.substring(0, 500));
      }
    } catch (fetchErr) {
      console.error('Perm toggle — fetch error:', fetchErr);
    }

    btn.disabled = false;
    btn.style.opacity = '1';

    if (!res || !res.ok) {
      // Roll back optimistic update
      applyPermButton(btn, cur);
      var msg = (res && res.error) ? res.error : 'Update failed — see browser console.';
      showToast(msg, 'error', 4000);
      return;
    }

    showToast('Permission updated.', 'success', 1800);

    // For is_guest: also update the can_vote and can_add_notes buttons in the same row
    if (field === 'is_guest') {
      var row = btn.closest('tr');
      if (row) {
        var cascade = newVal === 1 ? 0 : 1;  // guest=1 revokes both; guest=0 restores both
        row.querySelectorAll('.perm-toggle').forEach(function(b) {
          if (b.dataset.field === 'can_add_notes' || b.dataset.field === 'can_vote') {
            applyPermButton(b, cascade);
          }
        });
        // Update nickname cell guest badge
        var nickCell = row.querySelector('td:nth-child(2) span.guest-badge');
        if (newVal === 1 && !nickCell) {
          var strong = row.querySelector('td:nth-child(2) strong');
          if (strong) {
            var badge = document.createElement('span');
            badge.className = 'guest-badge';
            badge.style.cssText = 'font-size:.65rem;background:rgba(255,149,0,.12);color:var(--accent-orange);border-radius:999px;padding:.05rem .4rem;margin-left:.25rem;';
            badge.textContent = 'guest';
            strong.parentNode.insertBefore(badge, strong.nextSibling);
          }
        } else if (newVal === 0 && nickCell) {
          nickCell.remove();
        }
      }
    }
  });
});
</script>
  <?php endif; ?>
</div>

<!-- ACTIONS TAB -->
<div id="tab-actions" role="tabpanel" aria-labelledby="tbtn-actions" tabindex="0" style="display:none;">

  <!-- Add action item -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="card__header"><span class="card__title">Add Action Item</span></div>
    <div class="card__body">
      <form method="POST" action="<?= e(BASE_URL) ?>/admin/action-save.php">
        <?= csrf_field() ?>
        <input type="hidden" name="room_id" value="<?= $roomId ?>">
        <input type="hidden" name="redirect_to" value="<?= e(BASE_URL . '/admin/room-manage.php?id=' . $roomId) ?>">

        <div class="form-row">
          <div class="form-group" style="flex:2;min-width:200px;">
            <label class="form-label">Title *</label>
            <input type="text" name="title" class="form-input" required maxlength="300" placeholder="What needs to be done?">
          </div>
          <div class="form-group" style="min-width:140px;">
            <label class="form-label">Owner</label>
            <input type="text" name="owner_name" class="form-input" maxlength="100" placeholder="Who owns it?">
          </div>
          <div class="form-group" style="min-width:140px;">
            <label class="form-label">Due Date</label>
            <input type="date" name="due_date" class="form-input">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:2;min-width:200px;">
            <label class="form-label">Notes <span style="font-weight:400;color:var(--text-secondary)">(optional)</span></label>
            <input type="text" name="description" class="form-input" maxlength="500" placeholder="Additional context…">
          </div>
          <?php if (!empty($notes)): ?>
          <div class="form-group" style="min-width:180px;">
            <label class="form-label">Link to Note <span style="font-weight:400;color:var(--text-secondary)">(optional)</span></label>
            <select name="note_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($notes as $n): ?>
              <option value="<?= (int)$n['id'] ?>"><?= e(mb_substr($n['content'], 0, 45)) ?>… (<?= e($n['col_title']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary btn--sm">Add Action Item</button>
      </form>
    </div>
  </div>

  <!-- Action items list -->
  <?php if (empty($actionItems)): ?>
    <div class="card"><div class="empty-state"><p>No action items yet.</p></div></div>
  <?php else: ?>
  <div class="action-list">
    <?php foreach ($actionItems as $ai): ?>
    <div class="action-row">
      <div class="action-row__main">
        <div class="action-row__title"><?= e($ai['title']) ?></div>
        <?php if ($ai['description']): ?><div class="action-row__desc"><?= e($ai['description']) ?></div><?php endif; ?>
      </div>
      <div class="action-row__meta">
        <?php if ($ai['owner_name']): ?><span>👤 <?= e($ai['owner_name']) ?></span><?php endif; ?>
        <?php if ($ai['due_date']): ?><span>📅 <?= e(date('M j, Y', strtotime($ai['due_date']))) ?></span><?php endif; ?>
      </div>
      <div>
        <form method="POST" action="<?= e(BASE_URL) ?>/admin/action-save.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action_id" value="<?= (int)$ai['id'] ?>">
          <input type="hidden" name="room_id" value="<?= $roomId ?>">
          <input type="hidden" name="redirect_to" value="<?= e(BASE_URL . '/admin/room-manage.php?id=' . $roomId) ?>">
          <select name="status" class="form-select form-select--xs" onchange="this.form.submit()">
            <?php foreach (['open','in_progress','done','cancelled'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $ai['status'] === $s ? 'selected' : '' ?>><?= e(str_replace('_',' ',ucfirst($s))) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<script>
// ── Tab switching ──────────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.tab').forEach(function(t) {
    var isActive = t.dataset.tab === name;
    t.classList.toggle('active', isActive);
    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
  });
  ['board','poker-board','participants','actions'].forEach(function(id) {
    var el = document.getElementById('tab-' + id);
    if (el) el.style.display = id === name ? '' : 'none';
  });
  try { history.replaceState(null, '', location.pathname + location.search + '#tab=' + name); } catch(e){}
}
document.querySelectorAll('.tab').forEach(function(tab) {
  tab.addEventListener('click', function() { switchTab(tab.dataset.tab); });
});
// On load: restore tab from URL hash
(function() {
  var hash = location.hash;
  var match = hash.match(/tab=([a-z]+)/);
  if (match) { switchTab(match[1]); }
})();

// ── Admin live board refresh ───────────────────────────────────────────────────
// Polls get_updates every 3s to show new notes and participant names.
// Polls get_typing every 2.5s to show who is currently typing.
var ADMIN_ROOM_ID = <?= $roomId ?>;
var ADMIN_API     = '<?= e(BASE_URL) ?>/api.php';
var adminMaxNoteId = <?= (int)(empty($notes) ? 0 : max(array_column($notes, 'id'))) ?>;
var knownAdminNoteIds = new Set([<?= implode(',', array_map('intval', array_column($notes, 'id'))) ?>]);

// Typing poll
async function adminPollTyping() {
  try {
    var res  = await fetch(ADMIN_API + '?action=get_typing&room_id=' + ADMIN_ROOM_ID, { cache: 'no-store' });
    var data = await res.json();
    if (!data.ok) return;

    // Update each column's typing indicator
    document.querySelectorAll('.preview-col').forEach(function(col) {
      var colId = col.dataset.colId;
      if (!colId) return;
      var ind  = document.getElementById('admin-typing-' + colId);
      var txt  = document.getElementById('admin-typing-text-' + colId);
      if (!ind || !txt) return;
      var names = (data.typing && (data.typing[colId] || data.typing[String(colId)])) 
                  ? (data.typing[colId] || data.typing[String(colId)]) : [];
      if (names.length > 0) {
        txt.textContent = names.join(', ') + (names.length === 1 ? ' is typing…' : ' are typing…');
        ind.classList.add('typing-active');
      } else {
        txt.textContent = '';
        ind.classList.remove('typing-active');
      }
    });
  } catch(e) {}
}

// Notes + vote sync poll
async function adminPollUpdates() {
  try {
    var url  = ADMIN_API + '?action=get_updates&room_id=' + ADMIN_ROOM_ID + '&since_id=' + adminMaxNoteId;
    var res  = await fetch(url, { cache: 'no-store' });
    var data = await res.json();
    if (!data.ok) return;

    // Inject new notes into admin board columns
    if (data.new_notes && data.new_notes.length) {
      data.new_notes.forEach(function(note) {
        if (knownAdminNoteIds.has(note.id)) return;
        var container = document.getElementById('admin-col-notes-' + note.column_id);
        if (!container) return;

        // Remove "no notes yet" placeholder if present
        var placeholder = container.querySelector('.text-xs');
        if (placeholder) placeholder.remove();

        var isRevealed = '<?= $isRevealed ? 'true' : 'false' ?>' === 'true';
        var card = document.createElement('div');
        card.className = isRevealed ? 'note-sm note-sm--admin' : 'note-sm note-sm--hidden-admin';
        card.dataset.noteId = note.id;
        // Content hidden pre-reveal, always show participant name
        var contentHtml = isRevealed
          ? '<div style="font-size:.8rem;word-break:break-word;line-height:1.45;">' + escHtml(note.content) + '</div>'
          : '<div style="font-size:.75rem;color:var(--text-tertiary);font-style:italic;filter:blur(3px);user-select:none;">████ ██████ ███</div>';
        card.innerHTML = contentHtml +
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:.35rem;">' +
            '<span class="note__author">👤 ' + escHtml(note.nickname || 'Anonymous') + '</span>' +
          '</div>';
        container.appendChild(card);
        knownAdminNoteIds.add(note.id);
        if (note.id > adminMaxNoteId) adminMaxNoteId = note.id;

        // Flash card to show it's new
        card.style.animation = 'noteIn .3s ease';

        // Update column count badge
        var col = container.closest('.preview-col');
        if (col) {
          var badge = col.querySelector('.preview-col__count');
          if (badge) badge.textContent = parseInt(badge.textContent || '0') + 1;
        }
      });
    }

    // Sync vote counts
    if (data.vote_counts) {
      Object.entries(data.vote_counts).forEach(function([noteId, count]) {
        var card = document.querySelector('.note-sm[data-note-id="' + noteId + '"]');
        if (!card) return;
        var voteEl = card.querySelector('.admin-votes');
        if (voteEl) {
          voteEl.textContent = '👍 ' + count;
        }
      });
    }

    // Status change → reload
    if (data.status && data.status !== '<?= e($room['status']) ?>') {
      window.location.reload();
    }
  } catch(e) {}
}

function escHtml(str) {
  var d = document.createElement('div');
  d.textContent = String(str || '');
  return d.innerHTML;
}

// Start polling — 1s notes, 500ms typing, pauses when tab is hidden
var adminUpdatesTimer = null;
var adminTypingTimer2 = null;

function adminStartPolling() {
  if (adminUpdatesTimer) return;
  adminUpdatesTimer = setInterval(adminPollUpdates, 1000);
  adminTypingTimer2 = setInterval(adminPollTyping,  500);
}
function adminStopPolling() {
  clearInterval(adminUpdatesTimer); adminUpdatesTimer = null;
  clearInterval(adminTypingTimer2); adminTypingTimer2 = null;
}

document.addEventListener('visibilitychange', function() {
  if (document.hidden) {
    adminStopPolling();
  } else {
    adminStartPolling();
    adminPollUpdates();
    adminPollTyping();
  }
});

adminStartPolling();
setTimeout(adminPollUpdates, 100);
setTimeout(adminPollTyping,  200);
</script>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
