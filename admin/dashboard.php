<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login(); require_company();

$cid = current_company_id();
$pageTitle = 'Dashboard'; $activePage = 'dashboard';

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = db_row("
    SELECT
        COUNT(*)                              AS total,
        SUM(status='active')                  AS active,
        SUM(session_type='retrospective')     AS retros,
        SUM(session_type='daily')             AS dailies,
        SUM(session_type='poker')             AS pokers
    FROM retro_rooms WHERE company_id=?", [$cid]) ?? [];

// ── Poker summary (last 5 poker sessions) ─────────────────────────────────────
$pokerSessions = [];
try {
    $ppExists = db_row("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_sessions'")['n'] ?? 0;
    if ($ppExists) {
        $pokerSessions = db_query("
            SELECT s.code, s.sprint, s.phase, s.created_at,
                   r.name AS room_name, r.status AS room_status, r.session_date,
                   (SELECT COUNT(DISTINCT p.token) FROM pp_players p WHERE p.session_code=s.code AND p.last_seen > NOW() - INTERVAL 1 HOUR) AS active_players,
                   (SELECT COUNT(*) FROM pp_players p2 WHERE p2.session_code=s.code) AS total_players,
                   (SELECT COUNT(*) FROM pp_history h WHERE h.session_code=s.code) AS stories_done,
                   (SELECT COALESCE(SUM(h2.final_sp),0) FROM pp_history h2 WHERE h2.session_code=s.code) AS total_sp
            FROM pp_sessions s
            LEFT JOIN retro_rooms r ON r.id=s.retro_room_id AND r.company_id=s.company_id
            WHERE s.company_id=?
            ORDER BY s.created_at DESC LIMIT 5",
            [$cid]
        );
    }
} catch (Throwable $e) {}
// Also count poker-type retro rooms
$pokerRooms = db_query(
    "SELECT id, name, status, created_at, session_date FROM retro_rooms
     WHERE company_id=? AND session_type='poker' ORDER BY created_at DESC LIMIT 5",
    [$cid]
);

$participants = (int)(db_row(
    "SELECT COUNT(DISTINCT session_token) AS n FROM participants WHERE company_id=?", [$cid])['n'] ?? 0);
$actionCounts = db_query(
    "SELECT status, COUNT(*) AS n FROM action_items WHERE company_id=? GROUP BY status", [$cid]);
$acMap = array_column($actionCounts, 'n', 'status');

// ── Participants per session (last 8) ─────────────────────────────────────────
$sessionChart = db_query("
    SELECT r.name, r.session_type, COUNT(DISTINCT p.session_token) AS cnt
    FROM retro_rooms r
    LEFT JOIN participants p ON p.room_id = r.id AND p.company_id = r.company_id
    WHERE r.company_id = ?
    GROUP BY r.id
    ORDER BY r.created_at DESC LIMIT 8", [$cid]);
$sessionChart = array_reverse($sessionChart);

// ── Recent sessions ───────────────────────────────────────────────────────────
$recent = db_query("
    SELECT r.id, r.room_uuid, r.name, r.status, r.session_type, r.session_date, r.created_at,
           COUNT(DISTINCT n.id) AS notes, COUNT(DISTINCT a.id) AS actions
    FROM retro_rooms r
    LEFT JOIN retro_notes  n ON n.room_id=r.id
    LEFT JOIN action_items a ON a.room_id=r.id
    WHERE r.company_id=?
    GROUP BY r.id ORDER BY r.created_at DESC LIMIT 6", [$cid]);

require_once ROOT_PATH . '/templates/admin-header.php';
?>

<!-- Stat cards -->
<div class="stats-row">
  <div class="stat-card">
    <div class="stat-card__value"><?= (int)($stats['total']??0) ?></div>
    <div class="stat-card__label">Total Sessions</div>
  </div>
  <div class="stat-card stat-card--green">
    <div class="stat-card__value"><?= (int)($stats['active']??0) ?></div>
    <div class="stat-card__label">Active Now</div>
  </div>
  <div class="stat-card stat-card--purple">
    <div class="stat-card__value"><?= $participants ?></div>
    <div class="stat-card__label">Total Participants</div>
  </div>
  <a href="<?= e(BASE_URL) ?>/admin/actions.php" class="stat-card stat-card--orange" style="text-decoration:none;display:block;" title="View all action items">
    <div class="stat-card__value"><?= (int)($acMap['open']??0) ?></div>
    <div class="stat-card__label">Open Action Items <span style="font-size:.65rem;opacity:.7;">→</span></div>
  </a>
</div>

<!-- Charts row -->
<div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-bottom:1.5rem;">

  <!-- Action items by status -->
  <div class="card" style="flex:1;min-width:250px;">
    <div class="card__header"><span class="card__title">Action Items by Status</span></div>
    <div class="card__body">
      <?php
      $statuses = ['open'=>['Open','#3b82f6'],'in_progress'=>['In Progress','#f59e0b'],'done'=>['Done','#22c55e'],'cancelled'=>['Cancelled','#94a3b8']];
      $total_ac = array_sum($acMap) ?: 1;
      foreach ($statuses as $key => [$label, $color]):
        $n   = (int)($acMap[$key] ?? 0);
        $pct = round($n / $total_ac * 100);
      ?>
      <div style="margin-bottom:.75rem;">
        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.25rem;">
          <span style="color:var(--text-2);font-weight:500;"><?= $label ?></span>
          <span style="font-weight:700;"><?= $n ?></span>
        </div>
        <div style="height:8px;background:var(--surface-2);border-radius:4px;overflow:hidden;">
          <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:4px;transition:width .4s;"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Session type breakdown -->
  <div class="card" style="flex:1;min-width:200px;">
    <div class="card__header"><span class="card__title">Session Distribution</span></div>
    <div class="card__body" style="display:flex;flex-direction:column;gap:.75rem;">
      <?php
      $typeTotal = ((int)($stats['total']??0)) ?: 1;
      $types = [
        ['🔄 Retrospectives', (int)($stats['retros']??0),  '#3E6DBA'],
        ['☀ Daily Standups',  (int)($stats['dailies']??0), '#16A34A'],
        ['🃏 Poker Sessions',  (int)($stats['pokers']??0),  '#7C3AED'],
      ];
      foreach ($types as [$lbl, $n, $col]):
        $pct = round($n / $typeTotal * 100);
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.25rem;">
          <span style="color:var(--text-2);font-weight:500;"><?= $lbl ?></span>
          <span style="font-weight:700;"><?= $n ?></span>
        </div>
        <div style="height:8px;background:var(--surface-2);border-radius:4px;overflow:hidden;">
          <div style="width:<?= $pct ?>%;height:100%;background:<?= $col ?>;border-radius:4px;"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Participants per session chart -->
  <?php if (!empty($sessionChart)): ?>
  <div class="card" style="flex:2;min-width:300px;">
    <div class="card__header"><span class="card__title">Participants per Session (last <?= count($sessionChart) ?>)</span></div>
    <div class="card__body">
      <?php $maxP = max(array_column($sessionChart,'cnt') ?: [1]); ?>
      <?php foreach ($sessionChart as $s): ?>
      <?php $pct = $maxP > 0 ? round((int)$s['cnt'] / $maxP * 100) : 0; ?>
      <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.55rem;font-size:.78rem;">
        <span style="width:120px;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex-shrink:0;" title="<?= e($s['name']) ?>"><?= e(mb_substr($s['name'],0,18)) ?></span>
        <div style="flex:1;height:14px;background:var(--surface-2);border-radius:3px;overflow:hidden;">
          <div style="width:<?= $pct ?>%;height:100%;background:<?= $s['session_type']==='daily' ? '#16A34A' : ($s['session_type']==='poker' ? '#7C3AED' : 'var(--brand-mid)') ?>;border-radius:3px;"></div>
        </div>
        <span style="width:22px;text-align:right;font-weight:700;color:var(--text-2);"><?= (int)$s['cnt'] ?></span>
      </div>
      <?php endforeach; ?>
      <div style="display:flex;gap:1rem;margin-top:.5rem;font-size:.72rem;color:var(--text-secondary);">
        <span><span style="display:inline-block;width:10px;height:10px;background:var(--brand-mid);border-radius:2px;margin-right:.25rem;"></span>Retro</span>
        <span><span style="display:inline-block;width:10px;height:10px;background:#16A34A;border-radius:2px;margin-right:.25rem;"></span>Daily</span>
        <span><span style="display:inline-block;width:10px;height:10px;background:#7C3AED;border-radius:2px;margin-right:.25rem;"></span>Poker</span>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- Poker Summary card -->
<?php if (!empty($pokerSessions) || !empty($pokerRooms)): ?>
<div class="card" style="margin-bottom:1.25rem;">
  <div class="card__header" style="background:var(--brand);border-radius:var(--r-lg) var(--r-lg) 0 0;">
    <span class="card__title" style="color:#fff;">🃏 Poker Summary</span>
    <a href="<?= e(BASE_URL) ?>/admin/poker.php" class="btn btn--sm btn--outline" style="color:#fff;border-color:rgba(255,255,255,.3);">View all</a>
  </div>
  <?php if (!empty($pokerSessions)): ?>
  <table class="data-table">
    <thead>
      <tr>
        <th scope="col">Session</th>
        <th scope="col">Date</th>
        <th scope="col">Stories</th>
        <th scope="col">Total SP</th>
        <th scope="col">Participants</th>
        <th scope="col">Status</th>
        <th scope="col"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pokerSessions as $ps):
        $phaseColor = $ps['phase']==='voting' ? 'badge--active' : ($ps['phase']==='revealed' ? 'badge--revealed' : ($ps['phase']==='closed' ? 'badge--closed' : 'badge--draft'));
        $phaseLabel = ['waiting'=>'⏳ Waiting','voting'=>'🗳 Voting','revealed'=>'👁 Revealed','closed'=>'🔒 Closed'][$ps['phase']] ?? ucfirst($ps['phase']);
      ?>
      <tr>
        <td>
          <strong><?= e($ps['room_name'] ?? $ps['sprint']) ?></strong>
          <div style="font-size:.75rem;color:var(--muted);font-family:monospace;"><?= e($ps['code']) ?></div>
        </td>
        <td class="text-sm" style="color:var(--muted);"><?= $ps['session_date'] ? e(date('M j',strtotime($ps['session_date']))) : e(date('M j',strtotime($ps['created_at']))) ?></td>
        <td style="font-weight:700;"><?= (int)$ps['stories_done'] ?></td>
        <td>
          <?php if ($ps['total_sp'] > 0): ?>
          <span style="font-weight:800;color:var(--brand-mid);"><?= (int)$ps['total_sp'] ?></span>
          <span style="font-size:.75rem;color:var(--muted);"> SP</span>
          <?php else: ?><span style="color:var(--muted);">—</span><?php endif; ?>
        </td>
        <td style="font-size:.875rem;"><?= (int)$ps['total_players'] ?></td>
        <td><span class="badge <?= $phaseColor ?>"><?= $phaseLabel ?></span></td>
        <td>
          <a href="<?= e(BASE_URL) ?>/poker-room.php?code=<?= e($ps['code']) ?>&mod=1&name=<?= urlencode($_SESSION['admin_username']??'Moderator') ?>"
             class="btn btn--sm btn--outline">Open →</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php elseif (!empty($pokerRooms)): ?>
  <div class="card__body">
    <p style="font-size:.875rem;color:var(--muted);">No poker sessions started yet. Open a poker session to begin estimating.</p>
    <?php foreach ($pokerRooms as $pr): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.55rem 0;border-bottom:1px solid var(--divider);">
      <span style="flex:1;font-weight:600;font-size:.875rem;"><?= e($pr['name']) ?></span>
      <span class="badge badge--<?= e($pr['status']) ?>"><?= ucfirst(e($pr['status'])) ?></span>
      <a href="<?= e(BASE_URL) ?>/admin/room-manage.php?id=<?= (int)$pr['id'] ?>" class="btn btn--sm btn--outline">Manage</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Recent sessions -->
<div class="card">
  <div class="card__header">
    <span class="card__title">Recent Sessions</span>
    <a href="<?= e(BASE_URL) ?>/admin/rooms.php" class="btn btn--outline btn--sm">View all</a>
  </div>
  <?php if (empty($recent)): ?>
  <div class="empty-state"><p>No sessions yet.</p>
    <a href="<?= e(BASE_URL) ?>/admin/room-create.php" class="btn btn--primary">Create your first session →</a>
  </div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Session</th><th>Type</th><th>Status</th><th>Date</th><th>Notes</th><th>Actions</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong></td>
      <td>
        <?php if ($r['session_type'] === 'poker'): ?>
        <span class="badge badge--poker" style="font-size:.7rem;">🃏 Poker</span>
        <?php elseif ($r['session_type'] === 'daily'): ?>
        <span class="badge badge--active" style="font-size:.7rem;">☀ Daily</span>
        <?php else: ?>
        <span class="badge" style="font-size:.7rem;background:var(--brand-lt);color:var(--brand-mid);border:1px solid var(--color-info-border);">🔄 Retro</span>
        <?php endif; ?>
      </td>
      <td><span class="badge badge--<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
      <td class="text-sm" style="color:var(--text-secondary);"><?= $r['session_date'] ? e(date('M j, Y',strtotime($r['session_date']))) : '—' ?></td>
      <td><?= (int)$r['notes'] ?></td>
      <td><?= (int)$r['actions'] ?></td>
      <td><a href="<?= e(BASE_URL) ?>/admin/room-manage.php?id=<?= (int)$r['id'] ?>" class="btn btn--outline btn--sm">Manage</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
