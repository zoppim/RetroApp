<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once INCLUDES_PATH . '/bootstrap.php';

// ── Load room ─────────────────────────────────────────────────────────────────
$roomUuid = input_str('id', 'GET', 36);
if (!$roomUuid) { http_response_code(404); die('Room not found.'); }

$room = db_row('SELECT * FROM retro_rooms WHERE room_uuid = ?', [$roomUuid]);
if (!$room) { http_response_code(404); die('Room not found or deleted.'); }

$roomId    = (int)$room['id'];
$companyId = (int)$room['company_id'];

// ── Load linked poker session (if any) ────────────────────────────────────────
$linkedPoker = null;
try {
    $ppColExists = db_row(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_sessions' AND COLUMN_NAME='retro_room_id'"
    )['n'] ?? 0;
    if ($ppColExists) {
        $linkedPoker = db_row(
            "SELECT code, sprint, phase FROM pp_sessions
             WHERE retro_room_id=? AND company_id=?
             ORDER BY created_at DESC LIMIT 1",
            [$roomId, $companyId]
        );
    }
} catch (Throwable $e) {}

// ── Fetch template guidance (live — always current from board_templates) ───────
$tplRow   = db_row('SELECT * FROM board_templates WHERE name=? LIMIT 1', [$room['template_name']]);
$guidance = trim($tplRow['guidance'] ?? '');
// Daily standups show all notes immediately — no hidden phase.
// Retrospectives keep notes hidden until admin explicitly reveals them.
$isDaily    = $room['session_type'] === 'daily';
$isRevealed = $isDaily || in_array($room['status'], ['revealed','closed','archived']);

// ── Session password gate ────────────────────────────────────────────────────
if ($room['join_password'] !== null && $room['join_password'] !== '') {
    $pwdSessionKey = 'room_pwd_ok_' . $roomId;
    if (empty($_SESSION[$pwdSessionKey])) {
        $pwdError = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['room_password'])) {
            $submitted = input_str('room_password', 'POST', 100);
            if ($submitted === $room['join_password']) {
                $_SESSION[$pwdSessionKey] = true;
            } else {
                $pwdError = 'Incorrect password. Please try again.';
            }
        }
        if (empty($_SESSION[$pwdSessionKey])) {
            $company = db_row('SELECT name FROM companies WHERE id=?', [$companyId]);
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>Join — <?= e($room['name']) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/app.css">
</head><body class="auth-page">
<div class="auth-card" style="max-width:400px;">
  <div style="text-align:center;margin-bottom:1.5rem;">
    <div style="font-size:2rem;margin-bottom:.5rem;">🔒</div>
    <h1 style="font-size:1.3rem;font-weight:800;letter-spacing:-.02em;margin-bottom:.25rem;"><?= e($room['name']) ?></h1>
    <p style="font-size:.875rem;color:var(--text-secondary);">This session is password protected</p>
  </div>
  <?php if ($pwdError): ?><div class="alert alert--error"><?= e($pwdError) ?></div><?php endif; ?>
  <form method="POST">
    <div class="form-group">
      <label class="form-label">Session Password</label>
      <input type="password" name="room_password" class="form-input"
        placeholder="Enter session password" required autofocus autocomplete="off">
    </div>
    <button type="submit" class="btn btn--primary btn--full">Unlock Session</button>
  </form>
</div></body></html>
<?php
            exit;
        }
    }
}

// ── Participant token ─────────────────────────────────────────────────────────
$token = get_participant_token($roomId);

$participant = db_row(
    'SELECT * FROM participants WHERE room_id=? AND session_token=?',
    [$roomId, $token]
);

// ── Nickname lobby — block entry if no nickname yet ───────────────────────────
$needNickname = false;
if (!$participant || !$participant['nickname']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_nickname'])) {
        $nick = trim(input_str('nickname', 'POST', 80));
        if ($nick !== '') {
            if (!$participant) {
                if (in_array($room['status'], ['closed','archived'])) {
                    die('<h2 style="font-family:system-ui;padding:2rem;">This session is closed.</h2>');
                }
                $newId = db_insert(
                    'INSERT INTO participants (company_id, room_id, session_token, nickname) VALUES (?,?,?,?)',
                    [$companyId, $roomId, $token, $nick]
                );
                $participant = db_row('SELECT * FROM participants WHERE id=?', [$newId]);
            } else {
                db_exec('UPDATE participants SET nickname=? WHERE id=?', [$nick, (int)$participant['id']]);
                $participant['nickname'] = $nick;
            }
        } else {
            $needNickname = true;
        }
    } else {
        $needNickname = true;
    }
}

// ── Show nickname lobby ───────────────────────────────────────────────────────
if ($needNickname) {
    $company = db_row('SELECT name FROM companies WHERE id=?', [$companyId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Join — <?= e($room['name']) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/app.css">
</head>
<body class="auth-page">
<div class="auth-card" style="max-width:420px;">
  <div style="font-size:2.5rem;margin-bottom:.5rem;text-align:center;"><?= $room['session_type']==='poker' ? '🃏' : ($room['session_type']==='daily' ? '☀' : '🔄') ?></div>
  <h1 style="font-size:1.4rem;font-weight:800;margin-bottom:.25rem;text-align:center;letter-spacing:-.03em;"><?= e($room['name']) ?></h1>
  <p style="color:var(--text-secondary);font-size:.875rem;margin-bottom:1.75rem;text-align:center;">
    <?php if ($room['session_type']==='poker'): ?>🃏 Planning Poker<?php elseif ($room['session_type']==='daily'): ?>☀ Daily Standup<?php else: ?>🔄 Retrospective<?php endif; ?>
    <?php if ($room['session_date']): ?> · <?= e(date('M j, Y', strtotime($room['session_date']))) ?><?php endif; ?>
  </p>

  <?php if ($room['status'] === 'draft'): ?>
    <div class="alert alert--warning">This session hasn't started yet. Check back soon.</div>
  <?php elseif (in_array($room['status'], ['closed','archived'])): ?>
    <div class="alert alert--error">This session is closed.</div>
  <?php else: ?>
  <form method="POST">
    <div class="form-group">
      <label class="form-label">Your Name / Nickname <span class="required">*</span></label>
      <input type="text" name="nickname" class="form-input"
        placeholder="e.g. Alex or Dev Team Lead"
        maxlength="80" required autofocus
        value="<?= e($_POST['nickname'] ?? '') ?>">
      <p class="form-hint">Shown during the session<?= $room['session_type'] === 'daily' || $room['session_type'] === 'poker' ? '.' : '. Notes remain anonymous.' ?></p>
    </div>
    <input type="hidden" name="set_nickname" value="1">
    <button type="submit" class="btn btn--primary btn--full">Join Session →</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
<?php
    exit;
}

// ── Now we have a valid participant ───────────────────────────────────────────
$participantId = (int)$participant['id'];

// Poker-type rooms don't have a note board — redirect to linked poker session
if ($room['session_type'] === 'poker' && $linkedPoker) {
    $pokerUrl = BASE_URL . '/poker-room.php?code=' . urlencode($linkedPoker['code'])
              . '&name=' . urlencode($participant['nickname']);
    header('Location: ' . $pokerUrl);
    exit;
}

if (in_array($room['status'], ['closed','archived']) && !$participant) {
    die('<h2 style="font-family:system-ui;padding:2rem;">This session is closed.</h2>');
}

// ── Load columns ──────────────────────────────────────────────────────────────
$columns = db_query(
    'SELECT * FROM retro_columns WHERE room_id=? ORDER BY display_order',
    [$roomId]
);

// ── Load notes ────────────────────────────────────────────────────────────────
// Respect per-participant permission flags (admin can restrict guests)
$canAddNotes  = $room['status'] === 'active'
                && (int)($participant['can_add_notes'] ?? 1) === 1;
$canVote      = in_array($room['status'], ['active','revealed'])
                && (int)($participant['can_vote'] ?? 1) === 1;
$canEditNotes = $canAddNotes && (bool)$room['allow_edit_notes'];
$isGuest      = (int)($participant['is_guest'] ?? 0) === 1;
$hiddenCounts = [];

if ($isRevealed) {
    // All notes visible (daily always, retro after reveal)
    $notesRaw = db_query("
        SELECT n.*, COUNT(DISTINCT v.id) AS vote_count,
               (n.participant_id = ?) AS is_mine,
               p.nickname AS author_name
        FROM retro_notes n
        LEFT JOIN note_votes v ON v.note_id = n.id
        LEFT JOIN participants p ON p.id = n.participant_id
        WHERE n.room_id = ?
        GROUP BY n.id
        ORDER BY n.column_id, vote_count DESC, n.created_at
    ", [$participantId, $roomId]);
} else {
    // Retro pre-reveal: participant sees only their own notes
    $notesRaw = db_query("
        SELECT n.*, 0 AS vote_count, (n.participant_id = ?) AS is_mine,
               p.nickname AS author_name
        FROM retro_notes n
        LEFT JOIN participants p ON p.id = n.participant_id
        WHERE n.room_id = ? AND n.participant_id = ?
        ORDER BY n.column_id, n.created_at
    ", [$participantId, $roomId, $participantId]);

    // Count other people's hidden notes per column (shown as "+N hidden")
    $allHidden = db_query(
        'SELECT column_id, COUNT(*) AS n FROM retro_notes WHERE room_id=? AND participant_id != ? GROUP BY column_id',
        [$roomId, $participantId]
    );
    foreach ($allHidden as $h) $hiddenCounts[(int)$h['column_id']] = (int)$h['n'];
}

$notesByColumn = [];
foreach ($columns as $col) $notesByColumn[$col['id']] = [];
foreach ($notesRaw as $note) {
    if (isset($notesByColumn[$note['column_id']])) {
        $notesByColumn[$note['column_id']][] = $note;
    }
}

// ── Votes ─────────────────────────────────────────────────────────────────────
$myVotes    = db_query('SELECT note_id FROM note_votes WHERE room_id=? AND participant_id=?', [$roomId, $participantId]);
$myVoteIds  = array_map('intval', array_column($myVotes, 'note_id'));
$myVoteCount = count($myVoteIds);

// Max note ID currently loaded — polling starts from here
$maxNoteId = 0;
foreach ($notesRaw as $n) { if ((int)$n['id'] > $maxNoteId) $maxNoteId = (int)$n['id']; }

// ── Action items ──────────────────────────────────────────────────────────────
$actionItems = [];
if ($isRevealed) {
    $actionItems = db_query(
        "SELECT * FROM action_items WHERE room_id=? AND status!='cancelled' ORDER BY created_at",
        [$roomId]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title><?= e($room['name']) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/app.css">
</head>
<body class="board-page">

<!-- Header -->
<header class="board-header">
  <div class="board-header__left">
    <span class="board-header__logo">🔄</span>
    <div>
      <div class="board-header__title"><?= e($room['name']) ?></div>
      <div class="board-header__sub">
        <?php if ($room['session_type']==='poker'): ?>🃏 Planning Poker<?php elseif ($room['session_type']==='daily'): ?>☀ Daily Standup<?php else: ?>🔄 Retrospective<?php endif; ?>
        <?php if ($room['session_date']): ?> · <?= e(date('M j, Y', strtotime($room['session_date']))) ?><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="board-header__right">
    <?php if ($linkedPoker): ?>
    <button type="button" id="poker-toggle-btn"
      onclick="togglePokerPanel()"
      style="display:inline-flex;align-items:center;gap:.4rem;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:var(--r-full);padding:.2rem .75rem;font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap;">
      🃏 Poker
      <span style="background:rgba(255,255,255,.2);border-radius:999px;padding:.05rem .4rem;font-size:.65rem;">
        <?= strtoupper(e($linkedPoker['phase'])) ?>
      </span>
    </button>
    <?php endif; ?>
    <button type="button" id="share-toggle-btn"
      onclick="toggleSharePanel()"
      style="display:inline-flex;align-items:center;gap:.3rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:rgba(255,255,255,.85);border-radius:var(--r-full);padding:.2rem .7rem;font-size:.75rem;font-weight:600;cursor:pointer;"
      title="Share this session">
      🔗 Share
    </button>
    <span class="board-header__nick">
      <?= $isGuest ? '👁' : '👤' ?> <?= e($participant['nickname']) ?>
      <?php if ($isGuest): ?><span style="font-size:.65rem;background:rgba(255,149,0,.25);color:#FCD34D;border-radius:var(--r-full);padding:.05rem .4rem;margin-left:.2rem;">guest</span><?php endif; ?>
    </span>
    <span class="badge badge--<?= e($room['status']) ?>"><?= e(ucfirst($room['status'])) ?></span>
    <?php if ($canVote && $room['status'] === 'active'): ?>
    <span class="votes-pill" id="votes-pill">
      👍 <span id="votes-remaining"><?= $room['max_votes'] - $myVoteCount ?></span> left
    </span>
    <?php endif; ?>
  </div>
</header>

<!-- Consolidated Share Panel ─────────────────────────────────────────────── -->
<div id="share-panel" style="display:none;background:#1E3A5F;border-bottom:1px solid rgba(255,255,255,.1);padding:.75rem 1rem;">
  <div style="max-width:900px;margin:0 auto;display:flex;flex-direction:column;gap:.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.25rem;">
      <span style="font-size:.8rem;font-weight:700;color:#93C5FD;">Share this session</span>
      <button type="button" onclick="toggleSharePanel()" style="background:none;border:none;color:rgba(255,255,255,.5);cursor:pointer;font-size:1rem;line-height:1;">×</button>
    </div>
    <!-- Room link -->
    <div style="display:flex;align-items:center;gap:.65rem;background:rgba(255,255,255,.08);border-radius:var(--r-sm);padding:.5rem .75rem;flex-wrap:wrap;">
      <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#93C5FD;white-space:nowrap;">Board Link</span>
      <code style="flex:1;font-size:.78rem;color:#E2E8F0;word-break:break-all;"><?= e(BASE_URL . '/room.php?id=' . urlencode($room['room_uuid'])) ?></code>
      <button type="button" class="btn btn--sm" onclick="copyText('<?= e(BASE_URL . '/room.php?id=' . urlencode($room['room_uuid'])) ?>', 'Board link copied!')"
        style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:#fff;min-height:28px;padding:0 .65rem;font-size:.75rem;">Copy</button>
    </div>
    <?php if (!empty($room['join_password'])): ?>
    <!-- Password -->
    <div style="display:flex;align-items:center;gap:.65rem;background:rgba(255,149,0,.15);border:1px solid rgba(255,149,0,.25);border-radius:var(--r-sm);padding:.5rem .75rem;flex-wrap:wrap;">
      <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#FCD34D;white-space:nowrap;">🔒 Password</span>
      <code style="flex:1;font-size:.9rem;font-weight:800;color:#FEF3C7;letter-spacing:.1em;"><?= e($room['join_password']) ?></code>
      <button type="button" class="btn btn--sm" onclick="copyText('<?= e($room['join_password']) ?>', 'Password copied!')"
        style="background:rgba(255,149,0,.2);border:1px solid rgba(255,149,0,.3);color:#FEF3C7;min-height:28px;padding:0 .65rem;font-size:.75rem;">Copy</button>
    </div>
    <?php endif; ?>
    <?php if ($linkedPoker): ?>
    <!-- Poker code -->
    <div style="display:flex;align-items:center;gap:.65rem;background:var(--color-info-bg);border:1px solid var(--color-info-border);border-radius:var(--r-sm);padding:.5rem .75rem;flex-wrap:wrap;">
      <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#A5B4FC;white-space:nowrap;">🃏 Poker Code</span>
      <code style="flex:1;font-size:.9rem;font-weight:800;color:#E0E7FF;letter-spacing:.2em;"><?= e($linkedPoker['code']) ?></code>
      <a href="<?= e(BASE_URL) ?>/poker-room.php?code=<?= e($linkedPoker['code']) ?>&name=<?= urlencode($participant['nickname']) ?>"
         target="_blank"
         style="display:inline-flex;align-items:center;gap:.3rem;background:var(--brand-mid);border:1px solid var(--brand-mid);color:#fff;border-radius:var(--r-sm);padding:.2rem .65rem;font-size:.75rem;font-weight:700;text-decoration:none;">
        Open →
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($linkedPoker): ?>
<!-- Embedded Poker Panel ─────────────────────────────────────────────────── -->
<div id="poker-panel" style="display:none;border-bottom:1px solid var(--border);background:var(--surface);">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.55rem 1rem;background:var(--brand);border-bottom:1px solid rgba(255,255,255,.1);">
    <div style="display:flex;align-items:center;gap:.65rem;">
      <span style="font-size:.875rem;font-weight:700;color:#fff;">🃏 Planning Poker</span>
      <span style="font-size:.7rem;background:rgba(255,255,255,.15);color:#E0E7FF;border-radius:999px;padding:.15rem .55rem;font-weight:600;">
        <?= e($linkedPoker['sprint']) ?>
      </span>
      <span id="poker-phase-badge" style="font-size:.68rem;font-weight:700;padding:.1rem .45rem;border-radius:999px;background:rgba(255,255,255,.12);color:#fff;">
        <?= strtoupper(e($linkedPoker['phase'])) ?>
      </span>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
      <a href="<?= e(BASE_URL) ?>/poker-room.php?code=<?= e($linkedPoker['code']) ?>&name=<?= urlencode($participant['nickname']) ?>"
         target="_blank"
         style="font-size:.75rem;color:rgba(255,255,255,.6);text-decoration:none;padding:.2rem .5rem;border:1px solid rgba(255,255,255,.15);border-radius:var(--r-sm);"
         title="Open full poker room in new tab">⛶ Full screen</a>
      <button type="button" onclick="togglePokerPanel()" style="background:none;border:none;color:rgba(255,255,255,.5);cursor:pointer;font-size:1.1rem;line-height:1;padding:.1rem .25rem;">×</button>
    </div>
  </div>
  <iframe
    id="poker-iframe"
    src=""
    data-src="<?= e(BASE_URL) ?>/poker-room.php?code=<?= e($linkedPoker['code']) ?>&name=<?= urlencode($participant['nickname']) ?>&embedded=1"
    style="width:100%;height:600px;border:none;display:block;"
    title="Planning Poker session"
    allow="clipboard-write">
  </iframe>
</div>
<?php endif; ?>

<!-- Status banner -->
<?php if ($room['status'] === 'draft'): ?>
<div class="status-banner status-banner--draft">⏳ Session not started yet. Waiting for facilitator…</div>
<?php elseif ($room['status'] === 'active' && $isDaily): ?>
<div class="status-banner status-banner--active">☀ Standup is live — add your updates. Everyone can see all notes.</div>
<?php elseif ($room['status'] === 'active'): ?>
<div class="status-banner status-banner--active">✅ Session is live — add your notes. They stay hidden until revealed.</div>
<?php elseif ($isRevealed && !$isDaily): ?>
<div class="status-banner status-banner--revealed">👁 Notes revealed — <?= count($notesRaw) ?> notes · <?= array_sum(array_column($notesRaw, 'vote_count')) ?> votes</div>
<?php elseif ($room['status'] === 'closed'): ?>
<div class="status-banner status-banner--closed">🔒 Session is closed.</div>
<?php endif; ?>

<!-- Session Guidance (shown when template has guidance text) -->
<?php if ($guidance !== ''): ?>
<div id="guidance-panel" class="guidance-panel">
  <button type="button" class="guidance-toggle" onclick="
    var body = document.getElementById('guidance-body');
    var icon = document.getElementById('guidance-icon');
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    icon.textContent   = open ? '▸' : '▾';
    try { localStorage.setItem('ra_guidance_open', open ? '0' : '1'); } catch(e){}
  ">
    <span id="guidance-icon">▾</span>
    <span style="font-weight:700;font-size:.875rem;">📋 Session Guidance</span>
    <span style="margin-left:auto;font-size:.75rem;color:var(--text-secondary);">Click to collapse</span>
  </button>
  <div id="guidance-body" class="guidance-body">
    <?= nl2br(e($guidance)) ?>
  </div>
</div>
<script>
// Restore collapsed state from localStorage
try {
  if (localStorage.getItem('ra_guidance_open') === '0') {
    document.getElementById('guidance-body').style.display = 'none';
    document.getElementById('guidance-icon').textContent = '▸';
  }
} catch(e){}
</script>
<?php endif; ?>

<!-- Board -->
<main class="board-wrap" id="main-content" role="main">
  <div class="board" id="board" style="--col-count:<?= count($columns) ?>;">
    <?php foreach ($columns as $col): ?>
    <?php
      $colId       = (int)$col['id'];
      $colNotes    = $notesByColumn[$colId] ?? [];
      $hiddenCount = $hiddenCounts[$colId] ?? 0;
    ?>
    <div class="board-col" data-col-id="<?= $colId ?>">

      <div class="board-col__head" style="border-top-color:<?= e($col['color']) ?>">
        <span class="board-col__name"><?= e($col['title']) ?></span>
        <span class="board-col__count" id="col-count-<?= $colId ?>">
          <?= count($colNotes) ?><?= !$isRevealed && $hiddenCount > 0 ? ' <span style="color:var(--text-secondary);font-size:.65rem;font-weight:400;">(+' . $hiddenCount . ' hidden)</span>' : '' ?>
        </span>
      </div>

      <?php if ($canAddNotes): ?>
      <div class="board-col__add">
        <form class="note-form" data-col-id="<?= $colId ?>">
          <textarea
            class="note-textarea"
            placeholder="Add a note… (Ctrl+Enter to submit)"
            maxlength="<?= NOTE_MAX_LENGTH ?>"
            rows="2"
            data-col-id="<?= $colId ?>"
          ></textarea>
          <button type="submit" class="btn btn--primary btn--sm btn--full">Add Note</button>
        </form>
      </div>
      <?php endif; ?>
      <!-- Typing indicator always rendered — visible to all participants -->
      <div class="typing-indicator" id="typing-<?= $colId ?>">
        <span class="typing-dots"><span></span><span></span><span></span></span>
        <span class="typing-text" id="typing-text-<?= $colId ?>"></span>
      </div>

      <div class="board-col__body" id="col-notes-<?= $colId ?>">
        <?php foreach ($colNotes as $note): ?>
          <?php
            $isMine    = (int)$note['is_mine'];
            $voteCount = (int)$note['vote_count'];
            $iVoted    = in_array((int)$note['id'], $myVoteIds, true);
            $isTop     = $isRevealed && $voteCount >= 3;
          ?>
          <div class="note <?= $isMine ? 'note--mine' : '' ?> <?= $isTop ? 'note--top' : '' ?>"
               data-note-id="<?= (int)$note['id'] ?>" data-col-id="<?= $colId ?>">
            <div class="note__view">
              <p class="note__text"><?= e($note['content']) ?></p>
            </div>
            <?php if ($canEditNotes && $isMine): ?>
            <div class="note__edit-area">
              <textarea class="note__edit-input"><?= e($note['content']) ?></textarea>
              <div class="note__edit-btns">
                <button class="btn btn--sm btn--primary note-save-btn">Save</button>
                <button class="btn btn--sm btn--ghost note-cancel-btn">Cancel</button>
              </div>
            </div>
            <?php endif; ?>
            <div class="note__foot">
              <div>
                <?php if ($isRevealed && $canVote): ?>
                <button class="note__vote-btn <?= $iVoted ? 'note__vote-btn--voted' : '' ?>" data-note-id="<?= (int)$note['id'] ?>">
                  👍 <span class="vote-count"><?= $voteCount ?></span>
                </button>
                <?php elseif ($isRevealed): ?>
                <span class="note__vote-static">👍 <?= $voteCount ?></span>
                <?php endif; ?>
              </div>
              <div style="display:flex;align-items:center;gap:.35rem;">
                <?php if ($isRevealed && !empty($note['author_name'])): ?>
                  <!-- Post-reveal: show who wrote the note -->
                  <span class="note__author">
                    <?= $isMine ? '👤 You' : '👤 ' . e($note['author_name']) ?>
                  </span>
                <?php elseif ($isMine && !$isRevealed): ?>
                  <!-- Pre-reveal: only show "You" on own notes -->
                  <span class="note__mine-tag">You</span>
                  <?php if ($canEditNotes): ?>
                  <button class="btn btn--icon note-edit-btn" title="Edit" aria-label="Edit">✏️</button>
                  <?php endif; ?>
                  <button class="btn btn--icon note-delete-btn" title="Delete" aria-label="Delete">🗑</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Action items (post-reveal) -->
<?php if ($isRevealed && !empty($actionItems)): ?>
<section class="actions-section">
  <div class="actions-section__inner">
    <h2 class="actions-section__title">✅ Action Items</h2>
    <div class="actions-grid">
      <?php foreach ($actionItems as $ai): ?>
      <div class="action-pub-card action-pub-card--<?= e($ai['status']) ?>">
        <span class="badge badge--status-<?= e($ai['status']) ?>"><?= e(str_replace('_',' ',ucfirst($ai['status']))) ?></span>
        <div class="action-pub-card__title"><?= e($ai['title']) ?></div>
        <?php if ($ai['description']): ?><p class="action-pub-card__meta" style="font-size:.8rem;color:var(--text-secondary);"><?= e($ai['description']) ?></p><?php endif; ?>
        <div class="action-pub-card__meta">
          <?php if ($ai['owner_name']): ?><span>👤 <?= e($ai['owner_name']) ?></span><?php endif; ?>
          <?php if ($ai['due_date']): ?><span>📅 <?= e(date('M j, Y', strtotime($ai['due_date']))) ?></span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<script>
const RETRO = {
    roomId:        <?= $roomId ?>,
    participantId: <?= $participantId ?>,
    status:        <?= json_safe($room['status']) ?>,
    maxVotes:      <?= (int)$room['max_votes'] ?>,
    myVoteCount:   <?= $myVoteCount ?>,
    myVoteIds:     <?= json_safe($myVoteIds) ?>,
    isRevealed:    <?= $isRevealed ? 'true' : 'false' ?>,
    canAddNotes:   <?= $canAddNotes ? 'true' : 'false' ?>,
    canVote:       <?= $canVote ? 'true' : 'false' ?>,
    canEditNotes:  <?= $canEditNotes ? 'true' : 'false' ?>,
    baseUrl:       <?= json_safe(BASE_URL) ?>,
    noteMaxLen:    <?= NOTE_MAX_LENGTH ?>,
    nickname:      <?= json_safe($participant['nickname'] ?? '') ?>,
    maxNoteId:     <?= $maxNoteId ?>,
    sessionType:   <?= json_safe($room['session_type']) ?>,
    isDailyRoom:   <?= $room['session_type'] === 'daily' ? 'true' : 'false' ?>,
    isGuest:       <?= $isGuest ? 'true' : 'false' ?>,
};
</script>
<script src="<?= e(BASE_URL) ?>/assets/js/app.js"></script>
<script src="<?= e(BASE_URL) ?>/assets/js/board.js"></script>
<script>
// ── Share panel toggle ─────────────────────────────────────────────────────
function toggleSharePanel() {
    var panel = document.getElementById('share-panel');
    var pokerPanel = document.getElementById('poker-panel');
    if (!panel) return;
    var open = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : 'block';
    if (!open && pokerPanel) pokerPanel.style.display = 'none'; // close poker if opening share
}

// ── Poker panel toggle ─────────────────────────────────────────────────────
function togglePokerPanel() {
    var panel = document.getElementById('poker-panel');
    var sharePanel = document.getElementById('share-panel');
    if (!panel) return;
    var open = panel.style.display !== 'none';
    if (open) {
        panel.style.display = 'none';
    } else {
        panel.style.display = 'block';
        if (sharePanel) sharePanel.style.display = 'none'; // close share if opening poker
        // Lazy-load iframe on first open
        var iframe = document.getElementById('poker-iframe');
        if (iframe && !iframe.src) {
            iframe.src = iframe.dataset.src;
        }
    }
}

// ── Copy helper ────────────────────────────────────────────────────────────
function copyText(text, msg) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text)
            .then(function() { showToast(msg || 'Copied!', 'success', 2000); })
            .catch(function() { showToast('Copy failed. Text: ' + text, 'info', 5000); });
    } else {
        showToast(text, 'info', 8000);
    }
}

// ── Auto-open poker panel if URL has #poker ────────────────────────────────
if (location.hash === '#poker') {
    var pp = document.getElementById('poker-panel');
    if (pp) {
        pp.style.display = 'block';
        var iframe = document.getElementById('poker-iframe');
        if (iframe && !iframe.src) iframe.src = iframe.dataset.src;
    }
}
</script>
</body>
</html>
