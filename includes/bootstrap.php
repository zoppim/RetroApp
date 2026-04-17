<?php
/**
 * Bootstrap — loaded at the top of every page.
 * Loads config, helpers, starts session.
 */

declare(strict_types=1);

// ── PHP 7.x polyfills ─────────────────────────────────────────────────────────
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strlen($needle) === 0 || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return strlen($needle) === 0 || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return strlen($needle) === 0 || strpos($haystack, $needle) !== false;
    }
}

// Block direct access to includes/
if (!defined('ROOT_PATH')) {
    // Allow if config hasn't been loaded yet (install.php scenario)
    $configPath = __DIR__ . '/../config.php';
    if (!file_exists($configPath)) {
        die('Application not configured. Please run install.php');
    }
    require_once $configPath;
}

require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/helpers.php';

// ── Validate config completeness ─────────────────────────────────────────────
// Catches truncated config.php (missing closing brace = fatal 500 on every page)
if (!defined('TYPING_EXPIRE_SECS') || !defined('INSTALL_LOCK') || !defined('LOGO_MAX_SIZE')) {
    // Config is incomplete — show a clear diagnostic instead of a blank 500
    if (!headers_sent()) http_response_code(500);
    $missing = [];
    foreach (['TYPING_EXPIRE_SECS','LOGO_MAX_SIZE','INSTALL_LOCK','BASE_URL','DB_NAME'] as $k) {
        if (!defined($k)) $missing[] = $k;
    }
    die('<html><body style="font-family:system-ui;padding:2rem;max-width:600px;">'
      . '<h2 style="color:#c0392b;">&#9888; Config Error: constants missing</h2>'
      . '<p>Your <code>config.php</code> appears to be <strong>truncated</strong> (likely missing the closing <code>}</code>).</p>'
      . '<p style="margin-top:.75rem;"><strong>Missing:</strong> ' . implode(', ', $missing) . '</p>'
      . '<p style="margin-top:.75rem;">Open <code>config.php</code> in a text editor. '
      . 'The very last line should be a single <code>}</code>. '
      . 'If it is cut off, replace the file with a fresh copy from the RetroApp zip.</p>'
      . '</body></html>');
}

session_start_safe();

// ── Auto-heal: add missing columns silently ───────────────────────────────────
// Columns added in v1.3–v1.6 may be absent on older installs that haven't run
// upgrade.php. We add them here transparently so no page ever crashes on a
// missing column. This is idempotent — safe to run on every request (the
// information_schema check costs ~1ms and is skipped after columns exist).
function _bootstrap_ensure_columns(array $cols) {  // no return type for PHP 7.0 compat
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = db();
        foreach ($cols as $col) {
            $table  = $col[0];
            $column = $col[1];
            $ddl    = $col[2];
            $row = db_row(
                "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
                [$table, $column]
            );
            if ((int)($row['n'] ?? 0) === 0) {
                $s = $pdo->query("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
                $s->closeCursor();
            }
        }
    } catch (Throwable $e) {
        error_log('RetroApp auto-heal: ' . $e->getMessage());
    }
}

_bootstrap_ensure_columns([
    // v1.3: session guidance on templates
    ['board_templates', 'guidance',      '`guidance` TEXT DEFAULT NULL AFTER `columns_json`'],
    // v1.5: participant permissions
    ['participants',    'is_guest',       '`is_guest` TINYINT(1) NOT NULL DEFAULT 0 AFTER `nickname`'],
    ['participants',    'can_vote',       '`can_vote` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_guest`'],
    ['participants',    'can_add_notes',  '`can_add_notes` TINYINT(1) NOT NULL DEFAULT 1 AFTER `can_vote`'],
    // v1.3: session type on rooms
    ['retro_rooms',     'session_type',   "`session_type` ENUM('retrospective','daily') NOT NULL DEFAULT 'retrospective' AFTER `name`"],
    // v1.3: join password
    ['retro_rooms',     'join_password',  '`join_password` VARCHAR(100) DEFAULT NULL AFTER `allow_edit_notes`'],
    // v1.5: participant nickname
    ['participants',    'nickname',       '`nickname` VARCHAR(80) DEFAULT NULL AFTER `session_token`'],
    // v1.7.3: poker session linked to retro room
    ['pp_sessions',     'retro_room_id',  '`retro_room_id` INT UNSIGNED DEFAULT NULL AFTER `code`'],
    // v1.9.2: per-player comment on each poker vote
    ['pp_players',      'comment',        '`comment` TEXT NULL AFTER `vote`'],
]);

// ── Poker session_type ENUM — extend to include 'poker' if not present ────────
try {
    $colDef = db_row("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='retro_rooms' AND COLUMN_NAME='session_type'");
    if ($colDef && strpos($colDef['COLUMN_TYPE'], "'poker'") === false) {
        db()->exec("ALTER TABLE `retro_rooms` MODIFY COLUMN `session_type`
            ENUM('retrospective','daily','poker') NOT NULL DEFAULT 'retrospective'");
    }
} catch (Throwable $e) { error_log('RetroApp auto-heal poker enum: ' . $e->getMessage()); }

// ── pp_sessions.phase ENUM — extend to include 'closed' if not present ────────
try {
    $ppPhase = db_row("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_sessions' AND COLUMN_NAME='phase'");
    if ($ppPhase && strpos($ppPhase['COLUMN_TYPE'], "'closed'") === false) {
        db()->exec("ALTER TABLE `pp_sessions` MODIFY COLUMN `phase`
            ENUM('waiting','voting','revealed','closed') NOT NULL DEFAULT 'waiting'");
    }
} catch (Throwable $e) { error_log('RetroApp auto-heal pp_sessions phase: ' . $e->getMessage()); }
