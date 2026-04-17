<?php
/**
 * RetroApp — Upgrade Runner v1.2
 * Detects schema version, applies pending migrations safely.
 * Admin access required.
 */
declare(strict_types=1);

// Polyfills for PHP 7.x compatibility
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

if (!file_exists(__DIR__ . '/config.php') || !file_exists(__DIR__ . '/.installed')) {
    die('Not installed. Run install.php first.');
}
// ROOT_PATH and INCLUDES_PATH are defined by config.php
require_once __DIR__ . '/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/helpers.php';
session_start_safe();

if (!is_admin_logged_in()) {
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit;
}

// ── Validate required constants (catches truncated config.php) ────────────────
$required_constants = [
    'APP_VERSION','APP_ENV','BASE_URL',
    'DB_HOST','DB_NAME','DB_USER',
    'SESSION_NAME','CSRF_TOKEN_NAME',
    'TYPING_EXPIRE_SECS','LOGO_MAX_SIZE','INSTALL_LOCK',
];
$missing = array_filter($required_constants, function($c) { return !defined($c); });
if (!empty($missing)) {
    http_response_code(500);
    echo '<h2 style="font-family:system-ui;padding:2rem;color:#c0392b;">&#9888; Configuration Error</h2>';
    echo '<p style="font-family:system-ui;padding:0 2rem;">The following constants are missing from <code>config.php</code>. ';
    echo 'The file may be truncated. Please replace it with a complete copy from the zip.</p>';
    echo '<ul style="font-family:monospace;padding:1rem 3rem;">';
    foreach ($missing as $m) { echo "<li>$m</li>"; }
    echo '</ul>';
    echo '<p style="font-family:system-ui;padding:0 2rem;margin-top:1rem;">
        The most common cause is a missing closing <code>}</code> at the end of the file.<br>
        Open <code>config.php</code> in a text editor and ensure the last line is a single <code>}</code>.
    </p>';
    exit;
}

// ── Helper: check if a column exists ─────────────────────────────────────────
function column_exists(string $table, string $column): bool
{
    $row = db_row(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
    return (int)($row['n'] ?? 0) > 0;
}

function table_exists(string $table): bool
{
    $row = db_row(
        "SELECT COUNT(*) AS n FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );
    return (int)($row['n'] ?? 0) > 0;
}

// ── Migrations ────────────────────────────────────────────────────────────────
$migrations = [

    [
        'from'        => '1.0.0',
        'to'          => '1.2.0',
        'description' => 'Add companies, multi-admin, session types, typing indicators, company isolation',
        'steps' => [

            // 1. companies table
            "CREATE TABLE IF NOT EXISTS `companies` (
                `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `name`       VARCHAR(200)  NOT NULL DEFAULT 'My Team',
                `logo_path`  VARCHAR(500)  DEFAULT NULL,
                `team_name`  VARCHAR(100)  DEFAULT NULL,
                `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 2. seed one default company (safe: won't duplicate)
            "INSERT INTO `companies` (`id`, `name`, `team_name`)
             SELECT 1, 'My Team', 'Engineering'
             WHERE NOT EXISTS (SELECT 1 FROM `companies` WHERE id = 1)",

            // 3. users — add company_id if missing
            "ALTER_IF_MISSING:users:company_id:
             ALTER TABLE `users` ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`",

            // 4. users — add status
            "ALTER_IF_MISSING:users:status:
             ALTER TABLE `users` ADD COLUMN `status` ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `password_hash`",

            // 5. users — add last_login_at
            "ALTER_IF_MISSING:users:last_login_at:
             ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME DEFAULT NULL AFTER `status`",

            // 6. retro_rooms — add company_id
            "ALTER_IF_MISSING:retro_rooms:company_id:
             ALTER TABLE `retro_rooms` ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`",

            // 6b. retro_rooms — add join_password
            "ALTER_IF_MISSING:retro_rooms:join_password:
             ALTER TABLE `retro_rooms` ADD COLUMN `join_password` VARCHAR(100) DEFAULT NULL AFTER `allow_edit_notes`",

            // 7. retro_rooms — add session_type
            "ALTER_IF_MISSING:retro_rooms:session_type:
             ALTER TABLE `retro_rooms` ADD COLUMN `session_type` ENUM('retrospective','daily') NOT NULL DEFAULT 'retrospective' AFTER `name`",

            // 8. participants — add company_id
            "ALTER_IF_MISSING:participants:company_id:
             ALTER TABLE `participants` ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`",

            // 9. retro_notes — add company_id
            "ALTER_IF_MISSING:retro_notes:company_id:
             ALTER TABLE `retro_notes` ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`",

            // 10. note_votes — add company_id
            "ALTER_IF_MISSING:note_votes:company_id:
             ALTER TABLE `note_votes` ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`",

            // 11. action_items — add company_id
            "ALTER_IF_MISSING:action_items:company_id:
             ALTER TABLE `action_items` ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`",

            // 12. typing_indicators table
            "CREATE TABLE IF NOT EXISTS `typing_indicators` (
                `room_id`        INT UNSIGNED NOT NULL,
                `participant_id` INT UNSIGNED NOT NULL,
                `column_id`      INT UNSIGNED DEFAULT NULL,
                `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`room_id`, `participant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 13. uploads directory placeholder (handled in PHP, not SQL)
            "SELECT 1 AS noop",

            // 14. Daily standup template
            "INSERT IGNORE INTO `board_templates` (`name`, `columns_json`, `is_default`) VALUES
             ('Daily Standup: Yesterday · Today · Blockers',
              '[{\"title\":\"Yesterday\",\"color\":\"#3b82f6\"},{\"title\":\"Today\",\"color\":\"#22c55e\"},{\"title\":\"Blockers\",\"color\":\"#ef4444\"}]',
              0)",

            // 15. Update app version in meta
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.2.0')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.2.0'",
        ],
    ],

    // ────────────────────────────────────────────────────────────────────────
    [
        'from'        => '1.2.0',
        'to'          => '1.3.0',
        'description' => 'Session passwords, participant visibility, board improvements, template management, session deletion',
        'steps' => [

            // join_password on retro_rooms (session password feature)
            "ALTER_IF_MISSING:retro_rooms:join_password:
             ALTER TABLE `retro_rooms` ADD COLUMN `join_password` VARCHAR(100) DEFAULT NULL AFTER `allow_edit_notes`",

            // Ensure typing_indicators exists (safe if already created in 1.2.0)
            "CREATE TABLE IF NOT EXISTS `typing_indicators` (
                `room_id`        INT UNSIGNED NOT NULL,
                `participant_id` INT UNSIGNED NOT NULL,
                `column_id`      INT UNSIGNED DEFAULT NULL,
                `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`room_id`, `participant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Ensure daily standup template exists
            "INSERT IGNORE INTO `board_templates` (`name`, `columns_json`, `is_default`) VALUES
             ('Daily Standup',
              '[{\"title\":\"Yesterday\",\"color\":\"#3b82f6\"},{\"title\":\"Today\",\"color\":\"#22c55e\"},{\"title\":\"Blockers\",\"color\":\"#ef4444\"}]',
              0)",

            // Bump stored version
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.3.0')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.3.0'",
        ],
    ],

    // ────────────────────────────────────────────────────────────────────────
    [
        'from'        => '1.3.0',
        'to'          => '1.4.0',
        'description' => 'Real-time note sync: optimistic UI, live polling infrastructure',
        'steps' => [
            // No schema changes in v1.4.0 — improvements are client-side only.
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.4.0')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.4.0'",
        ],
    ],

    [
        'from'        => '1.4.0',
        'to'          => '1.4.1',
        'description' => 'Fix typing indicators visibility, admin board shows note content + participant names',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.4.1')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.4.1'",
        ],
    ],

    [
        'from'        => '1.4.1',
        'to'          => '1.4.2',
        'description' => 'Notes hidden until reveal, participant names on all notes, typing visible to everyone',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.4.2')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.4.2'",
        ],
    ],

    [
        'from'        => '1.4.2',
        'to'          => '1.4.3',
        'description' => 'Admin board: note content hidden until reveal, participant name always visible',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.4.3')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.4.3'",
        ],
    ],

    [
        'from'        => '1.4.3',
        'to'          => '1.4.4',
        'description' => 'Fix admin typing indicator: removed duplicate display:none, self-contained CSS animation',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.4.4')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.4.4'",
        ],
    ],

    [
        'from'        => '1.4.4',
        'to'          => '1.5.0',
        'description' => 'Participant identity fix (session-based), guest permissions, in-app notifications, notes attribution in report',
        'steps' => [
            "ALTER_IF_MISSING:participants:is_guest:
             ALTER TABLE `participants` ADD COLUMN `is_guest` TINYINT(1) NOT NULL DEFAULT 0 AFTER `nickname`",
            "ALTER_IF_MISSING:participants:can_vote:
             ALTER TABLE `participants` ADD COLUMN `can_vote` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_guest`",
            "ALTER_IF_MISSING:participants:can_add_notes:
             ALTER TABLE `participants` ADD COLUMN `can_add_notes` TINYINT(1) NOT NULL DEFAULT 1 AFTER `can_vote`",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.0')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.0'",
        ],
    ],

    [
        'from'        => '1.5.0',
        'to'          => '1.5.1',
        'description' => 'Fix guest permission toggle: auto-add missing columns, proper error handling',
        'steps' => [
            "ALTER_IF_MISSING:participants:is_guest:
             ALTER TABLE `participants` ADD COLUMN `is_guest` TINYINT(1) NOT NULL DEFAULT 0 AFTER `nickname`",
            "ALTER_IF_MISSING:participants:can_vote:
             ALTER TABLE `participants` ADD COLUMN `can_vote` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_guest`",
            "ALTER_IF_MISSING:participants:can_add_notes:
             ALTER TABLE `participants` ADD COLUMN `can_add_notes` TINYINT(1) NOT NULL DEFAULT 1 AFTER `can_vote`",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.1')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.1'",
        ],
    ],

    [
        'from'        => '1.5.1',
        'to'          => '1.5.2',
        'description' => 'Fix permission toggles: type=button missing caused form submit on click',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.2')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.2'",
        ],
    ],

    [
        'from'        => '1.5.2',
        'to'          => '1.5.3',
        'description' => 'Template session guidance: add guidance field to board_templates',
        'steps' => [
            "ALTER_IF_MISSING:board_templates:guidance:
             ALTER TABLE `board_templates` ADD COLUMN `guidance` TEXT DEFAULT NULL AFTER `columns_json`",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.3')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.3'",
        ],
    ],

    [
        'from'        => '1.5.3',
        'to'          => '1.5.4',
        'description' => 'Collapsible guidance panel in admin, typing indicator fix (class-based toggle, null column_id guard)',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.4')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.4'",
        ],
    ],

    [
        'from'        => '1.5.4',
        'to'          => '1.5.5',
        'description' => 'Polling: 1s notes/votes, 500ms typing, visibility-based pause on hidden tab',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.5')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.5'",
        ],
    ],

    [
        'from'        => '1.5.5',
        'to'          => '1.5.6',
        'description' => 'Install wizard: actionable fix instructions on every failed environment check',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.6')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.6'",
        ],
    ],


    [
        'from'        => '1.5.6',
        'to'          => '1.5.7',
        'description' => 'Code quality: fix stale CSS vars, add missing type=button attributes',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.7')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.7'",
        ],
    ],

    [
        'from'        => '1.5.7',
        'to'          => '1.5.8',
        'description' => 'Production deployment: auto-detect HTTPS/domain in installer, hardened .htaccess, post-install checklist',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.8')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.8'",
        ],
    ],

    [
        'from'        => '1.5.8',
        'to'          => '1.5.9',
        'description' => 'Install wizard: cPanel/production-first fix instructions, try/catch on env check, permission guidance',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.5.9')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.5.9'",
        ],
    ],

    [
        'from'        => '1.5.9',
        'to'          => '1.6.0',
        'description' => 'PHP 7.4 compatibility: remove mixed type hints, match() expressions, fn() arrow functions, str_starts_with()',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.0')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.0'",
        ],
    ],

    [
        'from'        => '1.6.0',
        'to'          => '1.6.1',
        'description' => 'PHP 7.4 compat: fix ENT_SUBSTITUTE, session_set_cookie_params, str_starts_with polyfills',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.1')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.1'",
        ],
    ],

    [
        'from'        => '1.6.1',
        'to'          => '1.6.2',
        'description' => 'Fix install on existing DB: DROP tables before CREATE, use query+closeCursor',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.2')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.2'",
        ],
    ],

    [
        'from'        => '1.6.2',
        'to'          => '1.6.3',
        'description' => 'Fix CSRF on cPanel: session isolation, self-contained install CSRF',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.3')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.3'",
        ],
    ],

    [
        'from'        => '1.6.3',
        'to'          => '1.6.4',
        'description' => 'Fix 500 on PHP-FPM: remove php_flag from .htaccess, fix index.php, add diagnostic tool',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.4')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.4'",
        ],
    ],

    [
        'from'        => '1.6.4',
        'to'          => '1.6.5',
        'description' => 'Auto-heal missing columns, SELECT * safety, fix room-create 500',
        'steps' => [
            "ALTER_IF_MISSING:board_templates:guidance:
             ALTER TABLE `board_templates` ADD COLUMN `guidance` TEXT DEFAULT NULL AFTER `columns_json`",
            "ALTER_IF_MISSING:participants:is_guest:
             ALTER TABLE `participants` ADD COLUMN `is_guest` TINYINT(1) NOT NULL DEFAULT 0 AFTER `nickname`",
            "ALTER_IF_MISSING:participants:can_vote:
             ALTER TABLE `participants` ADD COLUMN `can_vote` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_guest`",
            "ALTER_IF_MISSING:participants:can_add_notes:
             ALTER TABLE `participants` ADD COLUMN `can_add_notes` TINYINT(1) NOT NULL DEFAULT 1 AFTER `can_vote`",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.5')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.5'",
        ],
    ],

    [
        'from'        => '1.6.5',
        'to'          => '1.6.6',
        'description' => 'Fix backslash-dollar parse errors, templates delete form id, broken if/elseif, APP_VERSION literal',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.6')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.6'",
        ],
    ],

    [
        'from'        => '1.6.6',
        'to'          => '1.6.7',
        'description' => 'Fix template names: replace \\u00b7 literal with middle dot · character; fix upgrade.php array structure',
        'steps' => [
            "UPDATE `board_templates` SET `name` = REPLACE(`name`, '\\u00b7', 'Â·')",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.7')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.7'",
        ],
    ],

    [
        'from'        => '1.6.7',
        'to'          => '1.6.8',
        'description' => 'Fix double-define notices: remove redundant ROOT_PATH/INCLUDES_PATH defines from upgrade.php',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.8')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.8'",
        ],
    ],

    [
        'from'        => '1.6.8',
        'to'          => '1.6.9',
        'description' => 'New module: Planning Poker (pp_sessions, pp_players, pp_history tables, lobby, room, API)',
        'steps' => [
            "CREATE TABLE IF NOT EXISTS `pp_sessions` (
             `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
             `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
             `code` CHAR(8) NOT NULL,
             `mod_token` VARCHAR(64) NOT NULL,
             `sprint` VARCHAR(255) NOT NULL DEFAULT 'Sprint 1',
             `phase` ENUM('waiting','voting','revealed') NOT NULL DEFAULT 'waiting',
             `story` TEXT NULL,
             `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (`id`),
             UNIQUE KEY `ux_code` (`code`),
             INDEX `ix_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `pp_players` (
             `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             `session_code` CHAR(8) NOT NULL,
             `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
             `token` VARCHAR(64) NOT NULL,
             `name` VARCHAR(100) NOT NULL,
             `vote` TINYINT UNSIGNED NULL,
             `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             UNIQUE KEY `uk_session_token` (`session_code`, `token`),
             INDEX `ix_session` (`session_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `pp_history` (
             `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             `session_code` CHAR(8) NOT NULL,
             `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
             `sprint` VARCHAR(255) NOT NULL,
             `story` TEXT NOT NULL,
             `final_sp` TINYINT UNSIGNED NOT NULL,
             `avg_vote` DECIMAL(5,2) NULL,
             `consensus` TINYINT(1) NOT NULL DEFAULT 0,
             `votes_json` TEXT NULL,
             `saved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
             INDEX `ix_session` (`session_code`),
             INDEX `ix_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.6.9')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.6.9'",
        ],
    ],

    [
        'from'        => '1.6.9',
        'to'          => '1.7.0',
        'description' => 'Poker UI consistency: CSS token-based styles, sidebar overflow fix, JS/PHP headers updated',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.0')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.0'",
        ],
    ],

    [
        'from'        => '1.7.0',
        'to'          => '1.7.1',
        'description' => 'Fix copyCode() double-URL bug, sidebar min-height:0 for footer visibility, version header sync',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.1')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.1'",
        ],
    ],

    [
        'from'        => '1.7.1',
        'to'          => '1.7.2',
        'description' => 'Fix poker room: story input wiped by polling — smart DOM re-render on state change only',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.2')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.2'",
        ],
    ],

    [
        'from'        => '1.7.2',
        'to'          => '1.7.3',
        'description' => 'Fix edit-session data loss (UPDATE columns in place); link poker sessions to retro rooms',
        'steps' => [
            "ALTER_IF_MISSING:pp_sessions:retro_room_id:
             ALTER TABLE `pp_sessions` ADD COLUMN `retro_room_id` INT UNSIGNED DEFAULT NULL AFTER `code`",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.3')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.3'",
        ],
    ],

    [
        'from'        => '1.7.3',
        'to'          => '1.7.4',
        'description' => 'Fix migration runner: remove DDL transactions, add Mark All as Applied button',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.4')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.4'",
        ],
    ],

    [
        'from'        => '1.7.4',
        'to'          => '1.7.5',
        'description' => 'Graceful fallback when pp_sessions.retro_room_id column missing; auto-heal adds it',
        'steps' => [
            "ALTER_IF_MISSING:pp_sessions:retro_room_id:
             ALTER TABLE `pp_sessions` ADD COLUMN `retro_room_id` INT UNSIGNED DEFAULT NULL AFTER `code`",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.5')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.5'",
        ],
    ],

    [
        'from'        => '1.7.5',
        'to'          => '1.7.6',
        'description' => 'Fix Mark All as Applied: run safe schema steps, refresh pending after mark, bump to code version',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.6')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.6'",
        ],
    ],

    [
        'from'        => '1.7.6',
        'to'          => '1.7.7',
        'description' => 'Fix upgrade buttons: separate forms with hidden inputs so form.submit() sends correct action',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.7')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.7'",
        ],
    ],

    [
        'from'        => '1.7.7',
        'to'          => '1.7.8',
        'description' => 'Security fix: add company_id isolation to export.php (prevented cross-company data access)',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.8')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.8'",
        ],
    ],

    [
        'from'        => '1.7.8',
        'to'          => '1.7.9',
        'description' => 'New feature: Retrospective format picker (Start/Stop/Continue, Mad/Sad/Glad, 4Ls, DAKI, WWW, Lean Coffee, custom)',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.7.9')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.7.9'",
        ],
    ],

    [
        'from'        => '1.7.9',
        'to'          => '1.8.0',
        'description' => 'New module: Data Management — full backup, per-session JSON/CSV export, session import, template import',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.8.0')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.8.0'",
        ],
    ],

    [
        'from'        => '1.8.0',
        'to'          => '1.8.1',
        'description' => 'Fix data.php: board_templates has no company_id column — remove from backup/import queries',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.8.1')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.8.1'",
        ],
    ],

    [
        'from'        => '1.8.1',
        'to'          => '1.8.2',
        'description' => 'Accessible design system: WCAG AA contrast, solid surfaces, dark sidebar, focus indicators, skip link, aria-current nav',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.8.2')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.8.2'",
        ],
    ],

    [
        'from'        => '1.8.2',
        'to'          => '1.8.3',
        'description' => 'UX/A11Y: ARIA tabs, aria-live, aria-current, skip link, th scope, print styles, reduced-motion, high-contrast, sr-only',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.8.3')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.8.3'",
        ],
    ],

    [
        'from'        => '1.8.3',
        'to'          => '1.8.4',
        'description' => 'UX polish: dashboard action-items stat links to actions.php; all flows verified',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.8.4')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.8.4'",
        ],
    ],

    [
        'from'        => '1.8.4',
        'to'          => '1.8.5',
        'description' => 'Fix undefined $companyId in room-manage.php line 225 — replace with $cid',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.8.5')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.8.5'",
        ],
    ],

    [
        'from'        => '1.8.5',
        'to'          => '1.8.6',
        'description' => 'Unified session: embedded poker panel + share link/password/poker-code in one place on room.php and room-manage.php',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.8.6')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.8.6'",
        ],
    ],

    [
        'from'        => '1.8.6',
        'to'          => '1.8.7',
        'description' => 'Style consistency: poker-room uses board-header, auth-card, and app.css tokens — no more inline styles',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.8.7')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.8.7'",
        ],
    ],

    [
        'from'        => '1.8.7',
        'to'          => '1.9.0',
        'description' => 'Poker session type: ENUM extended, dashboard summary, auto-flag >8SP actions, poker board tab in room-manage, estimation guide refactored',
        'steps' => [
            "ALTER TABLE `retro_rooms` MODIFY COLUMN `session_type`
             ENUM('retrospective','daily','poker') NOT NULL DEFAULT 'retrospective'",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.0')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.0'",
        ],
    ],

    [
        'from'        => '1.9.0',
        'to'          => '1.9.1',
        'description' => 'Fix: toggleGuide not defined, rooms.php missing session_type in SELECT, room-create SQL quote error',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.1')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.1'",
        ],
    ],

    [
        'from'        => '1.9.1',
        'to'          => '1.9.2',
        'description' => 'Planning Poker comments: add comment column to pp_players; votes_json extended with {sp,comment} per player',
        'steps' => [
            "ALTER_IF_MISSING:pp_players:comment:ALTER TABLE `pp_players` ADD COLUMN `comment` TEXT NULL AFTER `vote`",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.2')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.2'",
        ],
    ],

    [
        'from'        => '1.9.2',
        'to'          => '1.9.3',
        'description' => 'Poker session close/reopen: extend pp_sessions.phase ENUM to include closed state',
        'steps' => [
            "ALTER TABLE `pp_sessions` MODIFY COLUMN `phase`
             ENUM('waiting','voting','revealed','closed') NOT NULL DEFAULT 'waiting'",
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.3')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.3'",
        ],
    ],

    [
        'from'        => '1.9.3',
        'to'          => '1.9.4',
        'description' => 'Polish: stale CSS vars fixed, rooms type filter, action-report split flags, poker chart legend, stop polling on close',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.4')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.4'",
        ],
    ],

    [
        'from'        => '1.9.4',
        'to'          => '1.9.5',
        'description' => 'Bug fix + polish: poker room redirect, lobby closed state, split-flag filters, type badges, stale CSS var cleanup across templates/settings/poker/export',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.5')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.5'",
        ],
    ],

    [
        'from'        => '1.9.5',
        'to'          => '1.9.6',
        'description' => 'Fix: undefined $filterSplit in actions.php — declaration was missing from input block',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.6')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.6'",
        ],
    ],

    [
        'from'        => '1.9.6',
        'to'          => '1.9.7',
        'description' => 'Security hardening: dynamic column name uses explicit $colMap; actions.php th scope=col; full test suite passed',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.7')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.7'",
        ],
    ],

    [
        'from'        => '1.9.7',
        'to'          => '1.9.8',
        'description' => 'Fix: number_format() TypeError on avg_vote DECIMAL string from MariaDB — cast to (float)',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.8')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.8'",
        ],
    ],

    [
        'from'        => '1.9.8',
        'to'          => '1.9.9',
        'description' => 'Design system: integrate WordPress block editor colour palette (slate scale, named colours) + WP font-size and spacing presets as CSS tokens',
        'steps' => [
            "INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES ('app_version','1.9.9')
             ON DUPLICATE KEY UPDATE `meta_value` = '1.9.9'",
        ],
    ]
];

// ── Already-run versions
$alreadyRun = array_column(
    db_query("SELECT version_to FROM upgrade_history ORDER BY id"),
    'version_to'
);

$pending = array_filter($migrations, function($m) use ($alreadyRun) {
    return !in_array($m['to'], $alreadyRun, true);
});

// ── Execute on POST ────────────────────────────────────────────────────────────
$results = [];
// ── Mark all pending as current (code already deployed manually) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mark_current'])) {
    csrf_verify();
    $marked   = 0;
    $schemaOk = 0;
    $pdo      = db();
    foreach ($pending as $migration) {
        // Still run safe schema steps (ALTER_IF_MISSING) — they're idempotent and harmless
        foreach ($migration['steps'] as $step) {
            try {
                if (strpos($step, 'ALTER_IF_MISSING:') === 0) {
                    $parts = explode(':', $step, 4);
                    $tbl   = $parts[1];
                    $col   = $parts[2];
                    $sql   = $parts[3] ?? '';
                    if (!column_exists($tbl, $col)) {
                        $pdo->exec(trim($sql));
                        $schemaOk++;
                    }
                }
                // CREATE TABLE IF NOT EXISTS steps are also safe to run
                $stepUp = strtoupper(ltrim($step));
                if (strpos($stepUp, 'CREATE TABLE IF NOT EXISTS') === 0) {
                    $pdo->exec($step);
                    $schemaOk++;
                }
            } catch (Throwable $e) { /* silent — column/table may already exist */ }
        }
        db_insert(
            "INSERT INTO upgrade_history (version_from, version_to, notes) VALUES (?,?,?)",
            [$migration['from'], $migration['to'], '[Marked as applied — code deployed manually] ' . $migration['description']]
        );
        $marked++;
    }
    // Bump app_meta to current code version
    db_exec(
        "INSERT INTO app_meta (meta_key, meta_value) VALUES ('app_version',?) ON DUPLICATE KEY UPDATE meta_value=?",
        [APP_VERSION, APP_VERSION]
    );
    $results[] = ['ok' => true, 'msg' => "✓ Marked {$marked} migration(s) as applied" . ($schemaOk ? " ({$schemaOk} schema step(s) applied)" : '') . ". Version set to " . APP_VERSION . "."];
    // Refresh from DB so the page renders the updated state
    $alreadyRun = array_column(
        db_query("SELECT version_to FROM upgrade_history ORDER BY id"),
        'version_to'
    );
    $pending = array_filter($migrations, function($m) use ($alreadyRun) {
        return !in_array($m['to'], $alreadyRun, true);
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run_upgrade'])) {
    csrf_verify();

    if (empty($pending)) {
        $results[] = ['ok' => true, 'msg' => 'Nothing to do — already at latest version.'];
    } else {
        foreach ($pending as $migration) {
            $pdo      = db();
            $failed   = false;
            $failMsg  = '';
            foreach ($migration['steps'] as $step) {
                try {
                    // ALTER_IF_MISSING: only run if column absent
                    if (strpos($step, 'ALTER_IF_MISSING:') === 0) {
                        $parts = explode(':', $step, 4);
                        $tbl   = $parts[1];
                        $col   = $parts[2];
                        $sql   = $parts[3] ?? '';
                        if (!column_exists($tbl, $col)) {
                            $s = $pdo->query(trim($sql));
                            $s->closeCursor();
                        }
                        continue;
                    }
                    // DDL (CREATE/ALTER/DROP) auto-commits in MySQL — run directly, no transaction
                    $stepUp = strtoupper(ltrim($step));
                    $isDDL  = (
                        strpos($stepUp, 'CREATE ') === 0 ||
                        strpos($stepUp, 'ALTER ')  === 0 ||
                        strpos($stepUp, 'DROP ')   === 0 ||
                        strpos($stepUp, 'RENAME ') === 0
                    );
                    if ($isDDL) {
                        $pdo->exec($step);
                    } else {
                        $s = $pdo->query($step);
                        $s->closeCursor();
                    }
                } catch (Throwable $stepEx) {
                    $failed  = true;
                    $failMsg = $stepEx->getMessage() . ' (step: ' . substr(trim($step), 0, 80) . ')';
                    break;
                }
            }

            if ($failed) {
                $results[] = ['ok' => false, 'msg' => "✗ Failed " . $migration['from'] . "→" . $migration['to'] . ": " . $failMsg];
                break;
            }

            // Ensure uploads directory exists
            $uploadsDir = ROOT_PATH . '/uploads';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
                file_put_contents($uploadsDir . '/.htaccess', "Options -Indexes\nDeny from all\n");
            }

            db_insert(
                "INSERT INTO upgrade_history (version_from, version_to, notes) VALUES (?,?,?)",
                [$migration['from'], $migration['to'], $migration['description']]
            );
            $results[] = ['ok' => true, 'msg' => "✓ " . $migration['from'] . " → " . $migration['to'] . ": " . $migration['description']];
        }
    }
    // Refresh pending list
    $alreadyRun = array_column(
        db_query("SELECT version_to FROM upgrade_history ORDER BY id"),
        'version_to'
    );
    $pending = array_filter($migrations, function($m) use ($alreadyRun) {
        return !in_array($m['to'], $alreadyRun, true);
    });
}

$history        = db_query("SELECT * FROM upgrade_history ORDER BY id DESC LIMIT 30");
$currentVersion = db_row("SELECT meta_value FROM app_meta WHERE meta_key='app_version'")['meta_value'] ?? '1.0.0';
$pageTitle      = 'Upgrade';
$activePage     = 'settings';
require_once ROOT_PATH . '/templates/admin-header.php';
?>

<div style="max-width:700px;">

  <!-- Version status -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="card__body" style="display:flex;gap:2rem;flex-wrap:wrap;align-items:center;">
      <div style="text-align:center;flex:1;">
        <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:.25rem;">Installed</div>
        <div style="font-size:1.75rem;font-weight:800;letter-spacing:-.04em;"><?= e($currentVersion) ?></div>
      </div>
      <div style="font-size:1.5rem;color:var(--muted);">→</div>
      <div style="text-align:center;flex:1;">
        <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:.25rem;">Target</div>
        <div style="font-size:1.75rem;font-weight:800;letter-spacing:-.04em;color:var(--accent);"><?= e(APP_VERSION) ?></div>
      </div>
    </div>
  </div>

  <?php foreach ($results as $r): ?>
  <div class="alert alert--<?= $r['ok'] ? 'success' : 'error' ?>" style="margin-bottom:.75rem;"><?= e($r['msg']) ?></div>
  <?php endforeach; ?>

  <!-- Pending migrations -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="card__header"><span class="card__title">Pending Migrations</span></div>
    <div class="card__body">
      <?php if (empty($pending)): ?>
        <div style="text-align:center;padding:1rem 0;">
          <div style="font-size:1.5rem;margin-bottom:.5rem;">✅</div>
          <p style="font-weight:600;"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> — no migrations pending.</p>
          <a href="<?= e(BASE_URL) ?>/admin/dashboard.php" class="btn btn--primary" style="margin-top:1rem;">Go to Dashboard →</a>
        </div>
      <?php else: ?>
        <?php foreach ($pending as $m): ?>
        <div style="background:rgba(255,255,255,.25);border:1px solid var(--glass-border);border-radius:var(--r-sm);padding:.65rem .9rem;margin-bottom:.5rem;">
          <strong style="font-size:.875rem;"><?= e($m['from']) ?> → <?= e($m['to']) ?></strong>
          <p style="font-size:.8rem;color:var(--muted);margin-top:.15rem;"><?= e($m['description']) ?></p>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:1rem;display:flex;gap:.65rem;flex-wrap:wrap;align-items:flex-start;">
          <div>
            <form method="POST" id="form-run">
              <?= csrf_field() ?>
              <input type="hidden" name="run_upgrade" value="1">
              <button type="button" class="btn btn--primary"
                onclick="confirmAction('form-run', 'Run <?= count($pending) ?> migration<?= count($pending) !== 1 ? 's' : '' ?>? Back up your database first.', 'Run Migrations', 'Run Now')">
                ▶ Run <?= count($pending) ?> Migration<?= count($pending) !== 1 ? 's' : '' ?>
              </button>
            </form>
            <p class="form-hint" style="margin-top:.35rem;">Executes all pending SQL changes.</p>
          </div>
          <div>
            <form method="POST" id="form-mark">
              <?= csrf_field() ?>
              <input type="hidden" name="mark_current" value="1">
              <button type="button" class="btn btn--outline"
                onclick="confirmAction('form-mark', 'Mark all <?= count($pending) ?> migration(s) as already applied? Use this only when code was deployed manually.', 'Mark as Applied', 'Mark All')">
                ✓ Mark All as Applied
              </button>
            </form>
            <p class="form-hint" style="margin-top:.35rem;">Use when code was uploaded manually.</p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- History -->
  <?php if (!empty($history)): ?>
  <div class="card">
    <div class="card__header"><span class="card__title">Migration History</span></div>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>From</th><th>To</th><th>Date</th><th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
          <tr>
            <td><?= e($h['version_from']) ?></td>
            <td><strong><?= e($h['version_to']) ?></strong></td>
            <td class="text-sm" style="color:var(--muted);white-space:nowrap;"><?= e(date('M j Y H:i', strtotime($h['executed_at']))) ?></td>
            <td class="text-sm"><?= e($h['notes'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
function confirmAction(formId, message, title, btnLabel) {
    showConfirm(message, { title: title, confirmText: btnLabel, danger: false })
        .then(function(ok) {
            if (ok) document.getElementById(formId).submit();
        });
}
</script>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
