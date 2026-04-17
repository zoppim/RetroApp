<?php
/**
 * Planning Poker — Lobby (Admin)
 * Create or manage poker sessions. Each session is scoped to this company.
 * Version: 1.9.9
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login();
require_company();

$cid         = current_company_id();
$preRoomId   = input_int('room_id', 'GET');  // pre-select from room-manage "Poker" button

// ── Auto-create tables if missing ─────────────────────────────────────────────
try {
    $pdo = db();
    if (!db_row("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_sessions'")['n']) {
        $s = $pdo->query("CREATE TABLE IF NOT EXISTS `pp_sessions` (
            `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `code`      CHAR(8)  NOT NULL,
            `retro_room_id` INT UNSIGNED DEFAULT NULL,
            `mod_token` VARCHAR(64) NOT NULL,
            `sprint`    VARCHAR(255) NOT NULL DEFAULT 'Sprint 1',
            `phase`     ENUM('waiting','voting','revealed') NOT NULL DEFAULT 'waiting',
            `story`     TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ux_code` (`code`),
            INDEX `ix_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $s->closeCursor();
    }
    if (!db_row("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_players'")['n']) {
        $s = $pdo->query("CREATE TABLE IF NOT EXISTS `pp_players` (
            `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `session_code` CHAR(8) NOT NULL,
            `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `token`     VARCHAR(64) NOT NULL,
            `name`      VARCHAR(100) NOT NULL,
            `vote`      TINYINT UNSIGNED NULL,
            `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_session_token` (`session_code`, `token`),
            INDEX `ix_session` (`session_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $s->closeCursor();
    }
    if (!db_row("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_history'")['n']) {
        $s = $pdo->query("CREATE TABLE IF NOT EXISTS `pp_history` (
            `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `session_code` CHAR(8) NOT NULL,
            `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `sprint`    VARCHAR(255) NOT NULL,
            `story`     TEXT NOT NULL,
            `final_sp`  TINYINT UNSIGNED NOT NULL,
            `avg_vote`  DECIMAL(5,2) NULL,
            `consensus` TINYINT(1) NOT NULL DEFAULT 0,
            `votes_json` TEXT NULL,
            `saved_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `ix_session` (`session_code`),
            INDEX `ix_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $s->closeCursor();
    }
} catch (Throwable $e) {
    error_log('Poker table creation: ' . $e->getMessage());
}

// ── POST: create session ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input_str('action');

    if ($action === 'create') {
        $sprint      = input_str('sprint', 'POST', 100) ?: 'Sprint 1';
        $retroRoomId = input_int('retro_room_id') ?: null;
        // If linked to a retro room, use its name as the sprint name
        if ($retroRoomId) {
            $rr = db_row('SELECT name, session_date FROM retro_rooms WHERE id=? AND company_id=?', [$retroRoomId, $cid]);
            if ($rr) {
                $sprint = $rr['name'];
            } else {
                $retroRoomId = null; // invalid room, ignore link
            }
        }
        // generate unique 8-char code
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (db_row('SELECT id FROM pp_sessions WHERE code=?', [$code]));

        $modToken = bin2hex(random_bytes(16));
        db_exec(
            'INSERT INTO pp_sessions (company_id, code, retro_room_id, mod_token, sprint) VALUES (?,?,?,?,?)',
            [$cid, $code, $retroRoomId, $modToken, $sprint]
        );
        // Store mod token in session so this admin is the moderator
        $_SESSION['pp_mod_' . $code] = $modToken;

        flash_set('success', 'Poker session created! Share the code with your team.');
        $adminName = $_SESSION['admin_username'] ?? 'Moderator';
        redirect(BASE_URL . '/poker-room.php?code=' . $code . '&mod=1&name=' . urlencode($adminName));
    }

    if ($action === 'delete') {
        $code = strtoupper(input_str('code', 'POST', 8));
        db_exec('DELETE FROM pp_history WHERE session_code=? AND company_id=?', [$code, $cid]);
        db_exec('DELETE FROM pp_players WHERE session_code=? AND company_id=?', [$code, $cid]);
        db_exec('DELETE FROM pp_sessions WHERE code=? AND company_id=?', [$code, $cid]);
        flash_set('success', 'Session deleted.');
        redirect(BASE_URL . '/admin/poker.php');
    }
}

// ── Load retro rooms for linking ──────────────────────────────────────────────
$retroRooms = db_query(
    "SELECT id, name, status, session_date FROM retro_rooms
     WHERE company_id=? AND status NOT IN ('archived')
     ORDER BY created_at DESC LIMIT 50",
    [$cid]
);

// ── Load sessions for this company ────────────────────────────────────────────
// JOIN retro_rooms only if the retro_room_id column exists (added in v1.7.3)
$_hasRoomLink = db_row(
    "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_sessions' AND COLUMN_NAME='retro_room_id'"
)['n'] ?? 0;

if ($_hasRoomLink) {
    $sessions = db_query(
        'SELECT s.*,
                r.name AS room_name, r.status AS room_status,
                (SELECT COUNT(*) FROM pp_players p WHERE p.session_code=s.code) AS player_count,
                (SELECT COUNT(*) FROM pp_history h WHERE h.session_code=s.code AND h.company_id=s.company_id) AS story_count,
                (SELECT COALESCE(SUM(h.final_sp),0) FROM pp_history h WHERE h.session_code=s.code AND h.company_id=s.company_id) AS total_sp
         FROM pp_sessions s
         LEFT JOIN retro_rooms r ON r.id = s.retro_room_id AND r.company_id = s.company_id
         WHERE s.company_id=?
         ORDER BY s.created_at DESC',
        [$cid]
    );
} else {
    // retro_room_id column not yet added — query without JOIN
    $sessions = db_query(
        'SELECT s.*,
                NULL AS room_name, NULL AS room_status,
                (SELECT COUNT(*) FROM pp_players p WHERE p.session_code=s.code) AS player_count,
                (SELECT COUNT(*) FROM pp_history h WHERE h.session_code=s.code AND h.company_id=s.company_id) AS story_count,
                (SELECT COALESCE(SUM(h.final_sp),0) FROM pp_history h WHERE h.session_code=s.code AND h.company_id=s.company_id) AS total_sp
         FROM pp_sessions s
         WHERE s.company_id=?
         ORDER BY s.created_at DESC',
        [$cid]
    );
}

$pageTitle  = 'Planning Poker';
$activePage = 'poker';
require_once ROOT_PATH . '/templates/admin-header.php';
?>

<div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">

  <!-- ── Create form ───────────────────────────────────────────── -->
  <div style="flex:0 0 300px;min-width:260px;">
    <div class="card">
      <div class="card__header"><h2 class="card__title">New Poker Session</h2></div>
      <div class="card__body">
        <form method="POST" action="<?= e(BASE_URL) ?>/admin/poker.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">

          <?php if (!empty($retroRooms)): ?>
          <div class="form-group">
            <label class="form-label">Link to Session <span style="font-weight:400;color:var(--text-secondary)">(optional)</span></label>
            <select name="retro_room_id" class="form-select" id="retro-room-sel" onchange="onRoomSelect(this)">
              <option value="">— Standalone poker session —</option>
              <?php foreach ($retroRooms as $rr): ?>
              <option value="<?= (int)$rr['id'] ?>" data-name="<?= e($rr['name']) ?>" <?= $preRoomId === (int)$rr['id'] ? 'selected' : '' ?>>
                <?= e($rr['name']) ?><?= $rr['session_date'] ? ' ('.$rr['session_date'].')' : '' ?> — <?= ucfirst($rr['status']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <p class="form-hint">Linking pre-fills the sprint name with the session name and shows this poker session inside room-manage.</p>
          </div>
          <?php endif; ?>

          <div class="form-group" id="sprint-group">
            <label class="form-label">Sprint / Meeting Name</label>
            <input type="text" name="sprint" id="poker-sprint" class="form-input"
              value="Sprint 1" maxlength="100" placeholder="e.g. Sprint 42">
            <p class="form-hint">Rename anytime inside the poker session.</p>
          </div>

          <button type="submit" class="btn btn--primary" style="width:100%;">🃏 Create Session</button>
        </form>
      </div>

    <div class="card" style="margin-top:1rem;">
      <div class="card__header"><h2 class="card__title">Join Existing</h2></div>
      <div class="card__body">
        <div class="form-group">
          <label class="form-label">Session Code</label>
          <input type="text" id="join-code" class="form-input"
            placeholder="e.g. AB12CD34"
            style="letter-spacing:2px;text-transform:uppercase;"
            oninput="this.value=this.value.toUpperCase()" maxlength="8">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Your Name</label>
          <input type="text" id="join-name" class="form-input"
            placeholder="e.g. Alex" maxlength="100">
        </div>
        <button type="button" class="btn btn--outline" style="width:100%;margin-top:.75rem;"
          onclick="joinSession()">Join Session →</button>
        <p id="join-err" class="form-hint" aria-live="polite" role="alert" style="color:var(--color-danger);display:none;"></p>
      </div>
    </div>
  </div>

  <!-- ── Sessions list ─────────────────────────────────────────── -->
  <div style="flex:1;min-width:0;">
    <div class="card">
      <div class="card__header">
        <h2 class="card__title">Active Sessions</h2>
        <span class="text-sm" style="color:var(--muted);"><?= count($sessions) ?> session<?= count($sessions) !== 1 ? 's' : '' ?></span>
      </div>
      <?php if (empty($sessions)): ?>
      <div class="empty-state">
        <p>No poker sessions yet. Create one to get started.</p>
      </div>
      <?php else: ?>
      <div style="padding:.35rem 0;">
        <?php foreach ($sessions as $sess):
            $phaseMap = [
                'voting'   => ['bg'=>'var(--color-success-bg)',  'color'=>'var(--color-success)',  'label'=>'🗳 Voting'],
                'revealed' => ['bg'=>'var(--color-info-bg)',     'color'=>'var(--color-info)',     'label'=>'👁 Revealed'],
                'closed'   => ['bg'=>'var(--color-danger-bg)',   'color'=>'var(--color-danger)',   'label'=>'🔒 Closed'],
                'waiting'  => ['bg'=>'var(--surface-2)',         'color'=>'var(--muted)',          'label'=>'⏳ Waiting'],
            ];
            $pm = $phaseMap[$sess['phase']] ?? $phaseMap['waiting'];
            $phaseColor = $pm['bg']; $phaseText = $pm['color']; $phaseLabel = $pm['label'];
        ?>
        <div style="padding:.9rem 1.25rem;border-bottom:1px solid var(--divider);display:flex;align-items:center;gap:1rem;flex-wrap:wrap;<?= $sess['phase']==="closed" ? "opacity:.7;background:var(--surface);" : "" ?>">
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.35rem;">
              <code style="font-size:.9rem;font-weight:700;letter-spacing:2px;background:var(--brand-lt);padding:.15rem .5rem;border-radius:6px;color:var(--brand-mid);"><?= e($sess['code']) ?></code>
              <strong style="font-size:.9rem;"><?= e($sess['sprint']) ?></strong>
              <span style="font-size:.7rem;padding:.1rem .45rem;border-radius:999px;background:<?= $phaseColor ?>;color:<?= $phaseText ?? 'var(--body)' ?>;font-weight:700;border:1px solid currentColor;"><?= $phaseLabel ?></span>
            </div>
            <div style="font-size:.78rem;color:var(--muted);display:flex;gap:1rem;flex-wrap:wrap;">
              <span>👥 <?= (int)$sess['player_count'] ?> player<?= $sess['player_count'] != 1 ? 's' : '' ?></span>
              <span>📋 <?= (int)$sess['story_count'] ?> stor<?= $sess['story_count'] != 1 ? 'ies' : 'y' ?></span>
              <span>⭐ <?= (int)$sess['total_sp'] ?> SP total</span>
              <span>🕐 <?= date('M j H:i', strtotime($sess['created_at'])) ?></span>
            </div>
          </div>
          <div style="display:flex;gap:.5rem;flex-shrink:0;">
            <a href="<?= e(BASE_URL) ?>/poker-room.php?code=<?= e($sess['code']) ?>&mod=1&name=<?= urlencode($_SESSION['admin_username'] ?? 'Moderator') ?>"
               class="btn btn--sm btn--primary">Open →</a>
            <form method="POST" style="display:inline" id="del-poker-<?= e($sess['code']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="code" value="<?= e($sess['code']) ?>">
              <button type="button" class="btn btn--sm btn--ghost" style="color:var(--color-danger);"
                data-confirm="Delete session <?= e($sess['code']) ?> and all its history?"
                data-confirm-title="Delete Session"
                data-confirm-btn="Delete"
                data-confirm-form="del-poker-<?= e($sess['code']) ?>">🗑</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
async function joinSession() {
    var code = document.getElementById('join-code').value.trim().toUpperCase();
    var name = document.getElementById('join-name').value.trim();
    var err  = document.getElementById('join-err');
    err.style.display = 'none';
    if (!code) { err.textContent = 'Enter a session code.'; err.style.display = 'block'; return; }
    if (!name) { err.textContent = 'Enter your name.'; err.style.display = 'block'; return; }
    // Verify session exists then redirect
    try {
        var res = await fetch('<?= e(BASE_URL) ?>/poker-api.php?action=check&code=' + encodeURIComponent(code));
        var data = await res.json();
        if (!data.ok) { err.textContent = data.e || 'Session not found.'; err.style.display = 'block'; return; }
        window.location.href = '<?= e(BASE_URL) ?>/poker-room.php?code=' + encodeURIComponent(code) + '&name=' + encodeURIComponent(name);
    } catch(e) {
        err.textContent = 'Could not connect. Please try again.';
        err.style.display = 'block';
    }
}
async function onRoomSelect(sel) {
    var name = sel.options[sel.selectedIndex].dataset.name || '';
    var inp  = document.getElementById('poker-sprint');
    if (name && inp) inp.value = name;
}
</script>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
