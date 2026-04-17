<?php
/**
 * Planning Poker — API endpoint
 * Handles all AJAX calls from poker-room.php
 * Version: 1.9.9
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once INCLUDES_PATH . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ── Session token for anonymous participants ───────────────────────────────────
if (empty($_SESSION['pp_token'])) {
    $_SESSION['pp_token'] = bin2hex(random_bytes(16));
}
$PT = $_SESSION['pp_token'];

// ── Input helper ──────────────────────────────────────────────────────────────
function pp_input(string $key, string $default = ''): string {
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default));
}

// ── Fibonacci values ──────────────────────────────────────────────────────────
function pp_fib(): array { return [1, 2, 3, 5, 8, 13, 21]; }
function pp_nearest_fib(float $n): int {
    return (int)array_reduce(pp_fib(), function($best, $f) use ($n) {
        return abs($f - $n) < abs($best - $n) ? $f : $best;
    }, 1);
}

// ── Check if current session token is moderator ───────────────────────────────
function pp_is_mod(string $code): bool {
    global $PT;
    // Check PHP session-stored mod token (set when admin creates/opens session)
    $sessionKey = 'pp_mod_' . $code;
    if (!empty($_SESSION[$sessionKey])) {
        $row = db_row('SELECT mod_token FROM pp_sessions WHERE code=?', [$code]);
        return $row && $_SESSION[$sessionKey] === $row['mod_token'];
    }
    // Also check via DB mod_token match
    return (bool)db_row('SELECT id FROM pp_sessions WHERE code=? AND mod_token=?', [$code, $PT]);
}

// ── Prune stale players (not seen in 30 seconds) ──────────────────────────────
function pp_prune(string $code): void {
    try {
        db_exec(
            'DELETE FROM pp_players WHERE session_code=? AND last_seen < NOW() - INTERVAL 30 SECOND',
            [$code]
        );
    } catch (Throwable $e) {}
}

// ── Route ─────────────────────────────────────────────────────────────────────
$action = pp_input('action');

try {
    $result = pp_route($action);
    echo json_encode(['ok' => true, 'd' => $result]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'e' => $e->getMessage()]);
}

function pp_route(string $action) { // return type omitted for PHP 7.4 compat
    global $PT;
    $code = strtoupper(pp_input('code'));

    switch ($action) {

        // Check session exists (used by join form)
        case 'check':
            if (!$code) throw new RuntimeException('Code required');
            $s = db_row('SELECT code, sprint, phase FROM pp_sessions WHERE code=?', [$code]);
            if (!$s) throw new RuntimeException('Session not found');
            return ['code' => $s['code'], 'sprint' => $s['sprint'], 'phase' => $s['phase']];

        // Register/update player in session
        case 'join':
            $name = pp_input('name');
            if (!$name) throw new RuntimeException('Name required');
            if (!$code) throw new RuntimeException('Code required');
            $s = db_row('SELECT id, company_id FROM pp_sessions WHERE code=?', [$code]);
            if (!$s) throw new RuntimeException('Session not found');
            db_exec(
                'INSERT INTO pp_players (session_code, company_id, token, name)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name), last_seen=NOW()',
                [$code, $s['company_id'], $PT, $name]
            );
            // If mod token in session, register as mod
            $isMod = pp_is_mod($code);
            return ['code' => $code, 'is_mod' => $isMod];

        // Poll full game state
        case 'state':
            if (!$code) throw new RuntimeException('Code required');
            pp_prune($code);
            // Heartbeat
            db_exec(
                'UPDATE pp_players SET last_seen=NOW() WHERE session_code=? AND token=?',
                [$code, $PT]
            );
            $s = db_row('SELECT * FROM pp_sessions WHERE code=?', [$code]);
            if (!$s) throw new RuntimeException('Session not found');

            $rows = db_query(
                'SELECT name, token, vote, comment FROM pp_players WHERE session_code=? ORDER BY last_seen',
                [$code]
            );
            $players = array_map(function($r) use ($PT, $s) {
                return [
                    'name'  => $r['name'],
                    'is_me' => $r['token'] === $PT,
                    'voted'   => $r['vote'] !== null,
                    'vote'    => $s['phase'] === 'revealed' ? (int)$r['vote'] : null,
                    'comment' => $s['phase'] === 'revealed' ? ($r['comment'] ?? null) : null,
                ];
            }, $rows);

            $me   = db_row('SELECT vote, comment FROM pp_players WHERE session_code=? AND token=?', [$code, $PT]);
            $stats = null;

            if ($s['phase'] === 'revealed') {
                $voteRows = db_query(
                    'SELECT vote FROM pp_players WHERE session_code=? AND vote IS NOT NULL',
                    [$code]
                );
                $vv = array_column($voteRows, 'vote');
                if ($vv) {
                    $avg = array_sum($vv) / count($vv);
                    $vvStr = array_map('strval', $vv);
                    $stats = [
                        'avg'  => round($avg, 1),
                        'sug'  => pp_nearest_fib($avg),
                        'con'  => count(array_unique($vv)) === 1,
                        'dist' => array_count_values($vvStr),
                    ];
                }
            }

            $hist = db_query(
                'SELECT sprint, story, final_sp, avg_vote, consensus, saved_at
                 FROM pp_history WHERE session_code=? ORDER BY id DESC LIMIT 200',
                [$code]
            );

            return [
                'phase'    => $s['phase'],
                'story'    => $s['story'],
                'sprint'   => $s['sprint'],
                'is_mod'   => pp_is_mod($code),
                'my_vote'  => $me ? $me['vote'] : null,
                'my_comment' => $me ? ($me['comment'] ?? null) : null,
                'players'  => $players,
                'stats'    => $stats,
                'history'  => $hist,
            ];

        // Moderator: start voting on a story
        case 'start':
            if (!pp_is_mod($code)) throw new RuntimeException('Not moderator');
            $story = pp_input('story');
            if (!$story) throw new RuntimeException('Story required');
            $sCheck = db_row("SELECT phase FROM pp_sessions WHERE code=?", [$code]);
            if ($sCheck && $sCheck['phase'] === 'closed') throw new RuntimeException('Session is closed');
            db_exec("UPDATE pp_sessions SET phase='voting', story=? WHERE code=?", [$story, $code]);
            db_exec('UPDATE pp_players SET vote=NULL, comment=NULL WHERE session_code=?', [$code]);
            return true;

        // Participant: cast a vote
        case 'vote':
            $v       = (int)pp_input('vote');
            $comment = mb_substr(trim(pp_input('comment') ?? ''), 0, 500);
            if (!in_array($v, pp_fib(), true)) throw new RuntimeException('Invalid Fibonacci value');
            $s = db_row("SELECT phase FROM pp_sessions WHERE code=?", [$code]);
            if (!$s || $s['phase'] === 'closed') throw new RuntimeException('Session is closed');
            if ($s['phase'] !== 'voting') throw new RuntimeException('Not in voting phase');
            db_exec(
                'UPDATE pp_players SET vote=?, comment=? WHERE session_code=? AND token=?',
                [$v, $comment ?: null, $code, $PT]
            );
            return true;

        // Moderator: reveal all votes
        case 'reveal':
            if (!pp_is_mod($code)) throw new RuntimeException('Not moderator');
            db_exec("UPDATE pp_sessions SET phase='revealed' WHERE code=?", [$code]);
            return true;

        // Moderator: save story with final SP and reset for next story
        case 'save':
            if (!pp_is_mod($code)) throw new RuntimeException('Not moderator');
            $sp = (int)pp_input('sp');
            if (!in_array($sp, pp_fib(), true)) throw new RuntimeException('Invalid SP value');
            $s = db_row('SELECT * FROM pp_sessions WHERE code=?', [$code]);
            if (!$s) throw new RuntimeException('Session not found');
            $voteRows = db_query(
                'SELECT name, vote, comment FROM pp_players WHERE session_code=? AND vote IS NOT NULL',
                [$code]
            );
            $vv  = array_column($voteRows, 'vote');
            $avg = count($vv) ? round(array_sum($vv) / count($vv), 2) : null;
            $con = count($vv) > 0 && count(array_unique($vv)) === 1 ? 1 : 0;
            $votesMap = [];
            foreach ($voteRows as $vr) {
                $votesMap[$vr['name']] = [
                    'sp'      => (int)$vr['vote'],
                    'comment' => $vr['comment'] ?? null,
                ];
            }
            db_exec(
                'INSERT INTO pp_history (session_code, company_id, sprint, story, final_sp, avg_vote, consensus, votes_json)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$code, (int)$s['company_id'], $s['sprint'], $s['story'], $sp, $avg, $con, json_encode($votesMap)]
            );
            db_exec("UPDATE pp_sessions SET phase='waiting', story=NULL WHERE code=?", [$code]);
            db_exec('UPDATE pp_players SET vote=NULL, comment=NULL WHERE session_code=?', [$code]);

            // Auto-flag oversized tasks: >8 SP → create a "split task" action item
            if ($sp >= 8 && !empty($s['retro_room_id'])) {
                try {
                    $flag = $sp >= 13 ? '[MUST SPLIT]' : '[Consider splitting]';
                    $flagTitle = $flag . ' ' . mb_substr($s['story'] ?? '', 0, 150);
                    db_exec(
                        "INSERT INTO action_items (company_id, room_id, title, status, created_by)
                         VALUES (?,?,?,'open',0)",
                        [(int)$s['company_id'], (int)$s['retro_room_id'], $flagTitle]
                    );
                } catch (Throwable $e) { /* non-fatal */ }
            }
            return true;

        // Moderator: close the poker session
        case 'close':
            if (!pp_is_mod($code)) throw new RuntimeException('Not moderator');
            $s = db_row('SELECT * FROM pp_sessions WHERE code=?', [$code]);
            if (!$s) throw new RuntimeException('Session not found');
            db_exec("UPDATE pp_sessions SET phase='closed' WHERE code=?", [$code]);
            // Also close the linked retro_rooms record so the lifecycle is consistent
            if (!empty($s['retro_room_id'])) {
                db_exec(
                    "UPDATE retro_rooms SET status='closed' WHERE id=? AND status NOT IN ('closed','archived')",
                    [(int)$s['retro_room_id']]
                );
            }
            return true;

        // Moderator: reopen the poker session (back to waiting)
        case 'reopen':
            if (!pp_is_mod($code)) throw new RuntimeException('Not moderator');
            db_exec("UPDATE pp_sessions SET phase='waiting', story=NULL WHERE code=?", [$code]);
            db_exec('UPDATE pp_players SET vote=NULL, comment=NULL WHERE session_code=?', [$code]);
            return true;

        // Moderator: rename sprint
        case 'sprint':
            if (!pp_is_mod($code)) throw new RuntimeException('Not moderator');
            $sprint = pp_input('sprint');
            if (!$sprint) throw new RuntimeException('Sprint name required');
            db_exec(
                "UPDATE pp_sessions SET sprint=?, phase='waiting', story=NULL WHERE code=?",
                [$sprint, $code]
            );
            db_exec('UPDATE pp_players SET vote=NULL, comment=NULL WHERE session_code=?', [$code]);
            return true;

        default:
            throw new RuntimeException('Unknown action: ' . $action);
    }
}
