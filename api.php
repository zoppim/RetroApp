<?php
/**
 * Board API v1.2
 * Actions: add_note, edit_note, delete_note, vote,
 *          typing_start, typing_stop, get_typing, get_status
 * All responses: JSON. All writes: company-isolated.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once INCLUDES_PATH . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_ok(array $data = []): void {
    echo json_encode(['ok' => true] + $data);
    exit;
}
function api_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET actions ───────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = input_str('action', 'GET', 30);
    $roomId = input_int('room_id', 'GET');
    if (!$roomId) api_err('Missing room_id.');

    $room = db_row('SELECT * FROM retro_rooms WHERE id=?', [$roomId]);
    if (!$room) api_err('Room not found.', 404);

    // get_status — lightweight poll
    if ($action === 'get_status') {
        api_ok(['status' => $room['status']]);
    }

    // get_typing — returns list of active typists in this room
    if ($action === 'get_typing') {
        $token = get_participant_token($roomId);
        $me    = db_row('SELECT id FROM participants WHERE room_id=? AND session_token=?', [$roomId, $token]);
        $myId  = $me ? (int)$me['id'] : 0;

        $expire = date('Y-m-d H:i:s', time() - TYPING_EXPIRE_SECS);
        $byCol  = [];
        try {
            $rows = db_query(
                "SELECT t.column_id, p.nickname
                 FROM typing_indicators t
                 JOIN participants p ON p.id = t.participant_id
                 WHERE t.room_id=? AND t.participant_id != ? AND t.updated_at > ?
                 ORDER BY t.updated_at DESC",
                [$roomId, $myId, $expire]
            );
            foreach ($rows as $r) {
                $cid = (int)($r['column_id'] ?? 0);
                if ($cid > 0) {  // skip null / 0 column_id rows
                    $byCol[$cid][] = $r['nickname'] ?? 'Someone';
                }
            }
        } catch (Throwable $e) {
            // typing_indicators table may not exist yet — return empty gracefully
        }
        api_ok(['typing' => $byCol]);
    }

    // get_updates — single polling endpoint: new notes + vote counts + hidden counts
    if ($action === 'get_updates') {
        $sinceId   = max(0, (int)($_GET['since_id'] ?? 0));
        $isRevealed = in_array($room['status'], ['revealed','closed','archived']);
        $isDailyRoom = $room['session_type'] === 'daily';

        // Resolve participant (optional — if they have a token)
        $cookieName  = 'retroapp_room_' . $roomId;
        $cookieToken = $_COOKIE[$cookieName] ?? '';
        $participant = (!empty($cookieToken) && strlen($cookieToken) === 64)
            ? db_row('SELECT id FROM participants WHERE room_id=? AND session_token=?', [$roomId, $cookieToken])
            : null;
        $participantId = $participant ? (int)$participant['id'] : 0;

        $newNotes = [];

        if ($isRevealed || $isDailyRoom) {
            // Post-reveal / daily: return all new notes since sinceId, with nickname
            $rows = db_query("
                SELECT n.id, n.column_id, n.content, n.participant_id,
                       COUNT(DISTINCT v.id) AS vote_count,
                       p.nickname
                FROM retro_notes n
                LEFT JOIN note_votes v ON v.note_id = n.id
                LEFT JOIN participants p ON p.id = n.participant_id
                WHERE n.room_id = ? AND n.id > ? AND n.is_revealed = 1
                GROUP BY n.id
                ORDER BY n.column_id, n.created_at
            ", [$roomId, $sinceId]);

            foreach ($rows as $row) {
                $newNotes[] = [
                    'id'         => (int)$row['id'],
                    'column_id'  => (int)$row['column_id'],
                    'content'    => $row['content'],
                    'is_mine'    => ($participantId > 0 && (int)$row['participant_id'] === $participantId),
                    'vote_count' => (int)$row['vote_count'],
                    'nickname'   => $row['nickname'] ?? null,
                ];
            }

            // Vote counts for ALL notes (so existing cards stay in sync)
            $voteRows = db_query(
                'SELECT note_id, COUNT(*) AS n FROM note_votes WHERE room_id=? GROUP BY note_id',
                [$roomId]
            );
            $voteCounts = [];
            foreach ($voteRows as $vr) $voteCounts[(int)$vr['note_id']] = (int)$vr['n'];

            api_ok([
                'status'      => $room['status'],
                'new_notes'   => $newNotes,
                'vote_counts' => $voteCounts,
                'revealed'    => true,
            ]);
        } else {
            // Pre-reveal retro: only return THIS participant's own new notes
            if ($participantId > 0) {
                $rows = db_query("
                    SELECT id, column_id, content
                    FROM retro_notes
                    WHERE room_id=? AND participant_id=? AND id > ?
                    ORDER BY column_id, created_at
                ", [$roomId, $participantId, $sinceId]);

                foreach ($rows as $row) {
                    $newNotes[] = [
                        'id'        => (int)$row['id'],
                        'column_id' => (int)$row['column_id'],
                        'content'   => $row['content'],
                        'is_mine'   => true,
                        'vote_count'=> 0,
                        'nickname'  => null, // pre-reveal: keep anonymous
                    ];
                }
            }

            // Hidden counts: how many OTHER participants have notes per column
            $hiddenRows = db_query(
                'SELECT column_id, COUNT(*) AS n FROM retro_notes WHERE room_id=? AND participant_id != ? GROUP BY column_id',
                [$roomId, $participantId ?: 0]
            );
            $hiddenCounts = [];
            foreach ($hiddenRows as $hr) $hiddenCounts[(int)$hr['column_id']] = (int)$hr['n'];

            api_ok([
                'status'        => $room['status'],
                'new_notes'     => $newNotes,
                'hidden_counts' => $hiddenCounts,
                'revealed'      => false,
            ]);
        }
    }

    api_err('Unknown GET action.');
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($method !== 'POST') api_err('Method not allowed.', 405);

$roomId = input_int('room_id');
if (!$roomId) api_err('Missing room_id.');

$room = db_row('SELECT * FROM retro_rooms WHERE id=?', [$roomId]);
if (!$room) api_err('Room not found.', 404);

// Resolve participant from cookie token
$token       = get_participant_token($roomId);
$participant = db_row('SELECT * FROM participants WHERE room_id=? AND session_token=?', [$roomId, $token]);
if (!$participant) api_err('Not a participant.', 403);

$participantId = (int)$participant['id'];
$companyId     = (int)$room['company_id'];

$action = input_str('action');

// ── TYPING START ──────────────────────────────────────────────────────────────
if ($action === 'typing_start') {
    if ($room['status'] !== 'active') api_ok();
    $colId = input_int('column_id');
    if ($colId <= 0) api_ok(); // ignore if no valid column sent
    try {
        db_exec(
            "INSERT INTO typing_indicators (room_id, participant_id, column_id, updated_at)
             VALUES (?,?,?,NOW())
             ON DUPLICATE KEY UPDATE column_id=VALUES(column_id), updated_at=NOW()",
            [$roomId, $participantId, $colId]
        );
    } catch (Throwable $e) { /* table may not exist yet */ }
    api_ok();
}

// ── TYPING STOP ───────────────────────────────────────────────────────────────
if ($action === 'typing_stop') {
    try { db_exec('DELETE FROM typing_indicators WHERE room_id=? AND participant_id=?', [$roomId, $participantId]); }
    catch (Throwable $e) {}
    api_ok();
}

// ── ADD NOTE ──────────────────────────────────────────────────────────────────
if ($action === 'add_note') {
    if ($room['status'] !== 'active') api_err('Session is not accepting notes.');

    $colId   = input_int('column_id');
    $content = input_str('content', 'POST', NOTE_MAX_LENGTH);
    if ($content === '') api_err('Note cannot be empty.');

    $col = db_row('SELECT id FROM retro_columns WHERE id=? AND room_id=?', [$colId, $roomId]);
    if (!$col) api_err('Invalid column.');

    // Stop typing indicator on submit
    db_exec('DELETE FROM typing_indicators WHERE room_id=? AND participant_id=?', [$roomId, $participantId]);

    // Daily standups: notes are visible to everyone immediately (no reveal phase)
    $isRevealedOnInsert = $room['session_type'] === 'daily' ? 1 : 0;

    $noteId = db_insert(
        'INSERT INTO retro_notes (company_id,room_id,column_id,participant_id,content,is_revealed) VALUES (?,?,?,?,?,?)',
        [$companyId, $roomId, $colId, $participantId, $content, $isRevealedOnInsert]
    );
    api_ok(['note_id' => (int)$noteId, 'content' => $content, 'column_id' => $colId, 'is_daily' => (bool)$isRevealedOnInsert]);
}

// ── EDIT NOTE ─────────────────────────────────────────────────────────────────
if ($action === 'edit_note') {
    if (!$room['allow_edit_notes']) api_err('Editing disabled.');
    if ($room['status'] !== 'active') api_err('Cannot edit in this state.');

    $noteId  = input_int('note_id');
    $content = input_str('content', 'POST', NOTE_MAX_LENGTH);
    if ($content === '') api_err('Note cannot be empty.');

    $note = db_row('SELECT * FROM retro_notes WHERE id=? AND room_id=? AND participant_id=?',
        [$noteId, $roomId, $participantId]);
    if (!$note) api_err('Note not found or not yours.', 403);

    db_exec('UPDATE retro_notes SET content=? WHERE id=?', [$content, $noteId]);
    api_ok(['note_id' => $noteId, 'content' => $content]);
}

// ── DELETE NOTE ───────────────────────────────────────────────────────────────
if ($action === 'delete_note') {
    if ($room['status'] !== 'active') api_err('Cannot delete in this state.');

    $noteId = input_int('note_id');
    $note   = db_row('SELECT * FROM retro_notes WHERE id=? AND room_id=? AND participant_id=?',
        [$noteId, $roomId, $participantId]);
    if (!$note) api_err('Note not found or not yours.', 403);

    db_exec('DELETE FROM retro_notes WHERE id=?', [$noteId]);
    api_ok(['note_id' => $noteId]);
}

// ── VOTE ──────────────────────────────────────────────────────────────────────
if ($action === 'vote') {
    if (!in_array($room['status'], ['active','revealed'], true)) api_err('Voting not available.');

    $noteId = input_int('note_id');
    $note   = db_row('SELECT id FROM retro_notes WHERE id=? AND room_id=?', [$noteId, $roomId]);
    if (!$note) api_err('Note not found.', 404);

    $existing = db_row('SELECT id FROM note_votes WHERE note_id=? AND participant_id=?',
        [$noteId, $participantId]);

    if ($existing) {
        db_exec('DELETE FROM note_votes WHERE id=?', [(int)$existing['id']]);
        $voted = false;
    } else {
        $used = (int)(db_row(
            'SELECT COUNT(*) AS n FROM note_votes WHERE room_id=? AND participant_id=?',
            [$roomId, $participantId]
        )['n'] ?? 0);
        if ($used >= (int)$room['max_votes']) api_err('No votes remaining.');

        db_insert(
            'INSERT INTO note_votes (company_id,room_id,note_id,participant_id) VALUES (?,?,?,?)',
            [$companyId, $roomId, $noteId, $participantId]
        );
        $voted = true;
    }

    $voteCount = (int)(db_row('SELECT COUNT(*) AS n FROM note_votes WHERE note_id=?', [$noteId])['n'] ?? 0);
    $usedNow   = (int)(db_row('SELECT COUNT(*) AS n FROM note_votes WHERE room_id=? AND participant_id=?',
        [$roomId, $participantId])['n'] ?? 0);

    api_ok([
        'note_id'         => $noteId,
        'voted'           => $voted,
        'vote_count'      => $voteCount,
        'votes_remaining' => (int)$room['max_votes'] - $usedNow,
    ]);
}

api_err('Unknown action.');
