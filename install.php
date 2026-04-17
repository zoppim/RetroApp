<?php
/**
 * RetroApp Installer
 * Guides through: env check → DB config → admin account → schema creation → done
 *
 * SECURITY: Delete or rename this file after installation,
 * or the .installed lock file will block re-running it.
 */

declare(strict_types=1);

define('ROOT_PATH',     __DIR__);
define('INCLUDES_PATH', __DIR__ . '/includes');
define('INSTALL_LOCK',  __DIR__ . '/.installed');
define('APP_VERSION',   '1.9.9'); // version written into generated config.php

// ── If already installed, block access ────────────────────────────────────────
if (file_exists(INSTALL_LOCK)) {
    // Already installed — show a clean redirect page
    $baseUrl = 'http://localhost/retroapp';
    if (file_exists(__DIR__ . '/config.php')) {
        include_once __DIR__ . '/config.php';
        $baseUrl = defined('BASE_URL') ? BASE_URL : $baseUrl;
    }
    header('Location: ' . $baseUrl . '/admin/dashboard.php');
    exit;
}

// Detect HTTPS and build the real base URL for this server
$_detectedProto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_detectedHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_detectedScript = dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php');
$_detectedBase   = $_detectedProto . '://' . $_detectedHost
                 . rtrim($_detectedScript === '/' ? '' : $_detectedScript, '/');

// ── Session setup — hardened for shared hosting ─────────────────────────────
// Use a unique session name that cannot collide with the main app session.
// On cPanel servers with session.auto_start=1, we must close any auto-started
// session first, then restart under our own name.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // close auto-started session
}
session_name('_ra_install_wiz'); // distinct from main app 'retroapp_sess'
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 7200,
        'path'     => '/',
        'secure'   => ($_detectedProto === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    session_set_cookie_params(7200, '/', '', ($_detectedProto === 'https'), true);
}
session_start();

// ── Self-contained CSRF for installer ────────────────────────────────────────
// Does NOT use helpers.php — install is standalone to avoid any session conflicts.
if (empty($_SESSION['_install_csrf'])) {
    $_SESSION['_install_csrf'] = bin2hex(random_bytes(32));
}
$_installCsrfToken = $_SESSION['_install_csrf'];

function install_csrf_field(string $token): string {
    return '<input type="hidden" name="_install_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}
function install_csrf_verify(string $token): void {
    $submitted = $_POST['_install_csrf'] ?? '';
    if (!hash_equals($token, $submitted)) {
        http_response_code(403);
        die('<p style="font-family:system-ui;padding:2rem;color:#c0392b;">
            <strong>Session expired or invalid form submission.</strong><br><br>
            Please <a href="install.php">start the installer again</a>.
            This can happen if your session expired, or if you pressed Back and resubmitted.<br><br>
            <small>Technical: CSRF token mismatch in install wizard.</small>
        </p>');
    }
}

$step   = (int)($_GET['step'] ?? $_POST['step'] ?? 1);
$errors = [];

// ── Helpers ───────────────────────────────────────────────────────────────────
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | (defined('ENT_SUBSTITUTE') ? ENT_SUBSTITUTE : 8), 'UTF-8');
}
function check_php_ext(string $ext): bool {
    return extension_loaded($ext);
}

// ── Step handlers ─────────────────────────────────────────────────────────────

// STEP 2: Environment check (auto-run, no user input)
function run_env_check(): array {
    $checks = [];

    // PHP version
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks[] = [
        'label' => 'PHP version ≥ 7.4',
        'pass'  => $phpOk,
        'info'  => 'Detected: ' . PHP_VERSION,
        'fix'   => $phpOk ? '' :
            'Your server is running PHP ' . PHP_VERSION . ' — RetroApp requires PHP 7.4 or higher.\n\n'
            . 'On cPanel/shared hosting (e.g. ikluu.com):\n'
            . '  1. Log into cPanel → "MultiPHP Manager" or "PHP Selector".\n'
            . '  2. Set PHP version to 7.4 or higher for this domain.\n'
            . '  3. Save and reload this page.\n\n'
            . 'On a VPS/Linux server:\n'
            . '  sudo apt install php7.4 libapache2-mod-php7.4 && sudo a2enmod php7.4 && sudo systemctl restart apache2\n'            . '  (PHP 8.0+ also supported)\n\n'
            . 'On XAMPP (Windows): download XAMPP 8.0+ from apachefriends.org',
    ];

    // PDO core
    $pdoOk = check_php_ext('pdo');
    $checks[] = [
        'label' => 'PDO extension',
        'pass'  => $pdoOk,
        'info'  => $pdoOk ? 'Enabled' : 'Not loaded',
        'fix'   => $pdoOk ? '' :
            'The PHP PDO extension is not loaded.\n\n'
            . 'On cPanel/shared hosting: go to MultiPHP INI Editor → enable "pdo" → save.\n\n'
            . 'On VPS/Linux: sudo apt install php-pdo && sudo systemctl restart apache2\n\n'
            . 'On XAMPP: open php.ini, uncomment ";extension=pdo", restart Apache',
    ];

    // PDO MySQL driver
    $pdoMyOk = check_php_ext('pdo_mysql');
    $checks[] = [
        'label' => 'PDO MySQL driver',
        'pass'  => $pdoMyOk,
        'info'  => $pdoMyOk ? 'Enabled' : 'Not loaded',
        'fix'   => $pdoMyOk ? '' :
            'The PDO MySQL driver is not loaded — needed to connect to MySQL/MariaDB.\n\n'
            . 'On cPanel/shared hosting: go to MultiPHP INI Editor → enable "pdo_mysql" and "mysqli" → save.\n\n'
            . 'On VPS/Linux: sudo apt install php-mysql && sudo systemctl restart apache2\n\n'
            . 'On XAMPP: open php.ini, uncomment ";extension=pdo_mysql", restart Apache',
    ];

    // mbstring
    $mbOk = check_php_ext('mbstring');
    $checks[] = [
        'label' => 'mbstring extension',
        'pass'  => $mbOk,
        'info'  => $mbOk ? 'Enabled' : 'Not loaded',
        'fix'   => $mbOk ? '' :
            'The mbstring extension handles multi-byte/Unicode text encoding.\n\n'
            . 'On cPanel/shared hosting: go to MultiPHP INI Editor → enable "mbstring" → save.\n\n'
            . 'On VPS/Linux: sudo apt install php-mbstring && sudo systemctl restart apache2\n\n'
            . 'On XAMPP: open php.ini, uncomment ";extension=mbstring", restart Apache',
    ];

    // json
    $jsonOk = check_php_ext('json');
    $checks[] = [
        'label' => 'json extension',
        'pass'  => $jsonOk,
        'info'  => $jsonOk ? 'Enabled' : 'Not loaded',
        'fix'   => $jsonOk ? '' :
            'The JSON extension is normally built into PHP and should always be present.\n\n'
            . 'On cPanel: go to MultiPHP INI Editor, enable "json", save.\n\n'
            . 'On VPS/Linux: sudo apt install php-json && sudo systemctl restart apache2\n\n'
            . 'If this persists, try selecting a different PHP version in your hosting control panel.',
    ];

    // openssl
    $sslOk = check_php_ext('openssl');
    $checks[] = [
        'label' => 'openssl extension',
        'pass'  => $sslOk,
        'info'  => $sslOk ? 'Enabled' : 'Not loaded',
        'fix'   => $sslOk ? '' :
            'The OpenSSL extension is required for generating secure random tokens.\n\n'
            . 'On cPanel/shared hosting: go to MultiPHP INI Editor → enable "openssl" → save.\n\n'
            . 'On VPS/Linux: sudo apt install php-openssl && sudo systemctl restart apache2\n\n'
            . 'On XAMPP: open php.ini, uncomment ";extension=openssl", restart Apache',
    ];

    // Writable directory
    $writeOk = is_writable(__DIR__);
    $checks[] = [
        'label' => 'Installation folder is writable',
        'pass'  => $writeOk,
        'info'  => $writeOk ? __DIR__ : __DIR__ . ' — not writable',
        'fix'   => $writeOk ? '' :
            'The installer cannot write config.php to: ' . __DIR__ . '\n\n'
            . 'On cPanel/shared hosting (most common cause):\n'
            . '  The folder needs to be owned by your hosting account user.\n'
            . '  Correct permission: 755 on the folder, 644 on files.\n'
            . '  In cPanel File Manager: right-click the folder → Permissions → set to 755.\n'
            . '  Or via SSH: chmod 755 ' . __DIR__ . '\n\n'
            . 'On VPS/Linux (Apache runs as www-data):\n'
            . '  sudo chown -R www-data:www-data ' . __DIR__ . '\n'
            . '  sudo chmod -R 755 ' . __DIR__ . '\n\n'
            . 'Do NOT set 777 — that is a security risk on production servers.\n\n'
            . 'On XAMPP (Windows): right-click folder → Properties → Security → give write access to "IIS_IUSRS" or "Everyone"',
    ];

    return $checks;
}

// STEP 3: Test DB connection
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    install_csrf_verify($_installCsrfToken);
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? 'retroapp');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';
    $baseUrl = rtrim(trim($_POST['base_url'] ?? ''), '/');

    // Persist to session
    $_SESSION['install'] = compact('dbHost','dbPort','dbName','dbUser','dbPass','baseUrl');

    // Test connection
    try {
        $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Create DB if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        $_SESSION['install']['db_ok'] = true;
        header('Location: install.php?step=4');
        exit;
    } catch (PDOException $e) {
        $errors[] = 'Database connection failed: ' . $e->getMessage();
        $step = 3;
    }
}

// STEP 4: Create admin account
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    install_csrf_verify($_installCsrfToken);
    $username  = trim($_POST['admin_user'] ?? '');
    $password  = $_POST['admin_pass'] ?? '';
    $password2 = $_POST['admin_pass2'] ?? '';

    if (strlen($username) < 3)       $errors[] = 'Username must be at least 3 characters.';
    if (strlen($password) < 8)       $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $password2)    $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $_SESSION['install']['admin_user'] = $username;
        $_SESSION['install']['admin_pass'] = $password;
        header('Location: install.php?step=5');
        exit;
    }
}

// STEP 5: Write config + run schema + insert admin
if ($step === 5 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $i = $_SESSION['install'] ?? [];
    if (empty($i['db_ok'])) {
        header('Location: install.php?step=3');
        exit;
    }

    // Connect with DB selected
    try {
        $dsn = "mysql:host={$i['dbHost']};port={$i['dbPort']};dbname={$i['dbName']};charset=utf8mb4";
        $pdo = new PDO($dsn, $i['dbUser'], $i['dbPass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // ── Schema v1.2 ──────────────────────────────────────────────────────────
        $ddl = [];

        $ddl[] = "CREATE TABLE IF NOT EXISTS `companies` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(200)  NOT NULL DEFAULT 'My Team',
            `logo_path`  VARCHAR(500)  DEFAULT NULL,
            `team_name`  VARCHAR(100)  DEFAULT NULL,
            `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `users` (
            `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `company_id`    INT UNSIGNED    NOT NULL DEFAULT 1,
            `username`      VARCHAR(80)     NOT NULL,
            `password_hash` VARCHAR(255)    NOT NULL,
            `role`          ENUM('admin')   NOT NULL DEFAULT 'admin',
            `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `last_login_at` DATETIME        DEFAULT NULL,
            `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ux_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `retro_rooms` (
            `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `company_id`       INT UNSIGNED  NOT NULL DEFAULT 1,
            `room_uuid`        CHAR(36)      NOT NULL,
            `name`             VARCHAR(200)  NOT NULL,
            `session_type`     ENUM('retrospective','daily','poker') NOT NULL DEFAULT 'retrospective',
            `description`      TEXT          DEFAULT NULL,
            `template_name`    VARCHAR(100)  NOT NULL DEFAULT 'Start-Stop-Continue',
            `status`           ENUM('draft','active','revealed','closed','archived') NOT NULL DEFAULT 'draft',
            `max_votes`        TINYINT UNSIGNED NOT NULL DEFAULT 5,
            `allow_edit_notes` TINYINT(1)    NOT NULL DEFAULT 1,
            `join_password`    VARCHAR(100)  DEFAULT NULL,
            `session_date`     DATE          DEFAULT NULL,
            `reveal_at`        DATETIME      DEFAULT NULL,
            `created_by`       INT UNSIGNED  NOT NULL,
            `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ux_uuid` (`room_uuid`),
            INDEX `idx_company_status` (`company_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `retro_columns` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `room_id`       INT UNSIGNED  NOT NULL,
            `title`         VARCHAR(100)  NOT NULL,
            `color`         VARCHAR(7)    NOT NULL DEFAULT '#6366f1',
            `display_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_room_order` (`room_id`, `display_order`),
            FOREIGN KEY (`room_id`) REFERENCES `retro_rooms`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `participants` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `company_id`    INT UNSIGNED  NOT NULL DEFAULT 1,
            `room_id`       INT UNSIGNED  NOT NULL,
            `session_token` CHAR(64)      NOT NULL,
            `nickname`      VARCHAR(80)   DEFAULT NULL,
            `is_guest`      TINYINT(1)    NOT NULL DEFAULT 0,
            `can_vote`      TINYINT(1)    NOT NULL DEFAULT 1,
            `can_add_notes` TINYINT(1)    NOT NULL DEFAULT 1,
            `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ux_room_token` (`room_id`, `session_token`),
            INDEX `idx_token` (`session_token`),
            FOREIGN KEY (`room_id`) REFERENCES `retro_rooms`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `retro_notes` (
            `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `company_id`     INT UNSIGNED  NOT NULL DEFAULT 1,
            `room_id`        INT UNSIGNED  NOT NULL,
            `column_id`      INT UNSIGNED  NOT NULL,
            `participant_id` INT UNSIGNED  NOT NULL,
            `content`        TEXT          NOT NULL,
            `is_revealed`    TINYINT(1)    NOT NULL DEFAULT 0,
            `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_room_column` (`room_id`, `column_id`),
            FOREIGN KEY (`room_id`)        REFERENCES `retro_rooms`(`id`)   ON DELETE CASCADE,
            FOREIGN KEY (`column_id`)      REFERENCES `retro_columns`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`participant_id`) REFERENCES `participants`(`id`)  ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `note_votes` (
            `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `company_id`     INT UNSIGNED  NOT NULL DEFAULT 1,
            `room_id`        INT UNSIGNED  NOT NULL,
            `note_id`        INT UNSIGNED  NOT NULL,
            `participant_id` INT UNSIGNED  NOT NULL,
            `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ux_note_participant` (`note_id`, `participant_id`),
            FOREIGN KEY (`room_id`)        REFERENCES `retro_rooms`(`id`)  ON DELETE CASCADE,
            FOREIGN KEY (`note_id`)        REFERENCES `retro_notes`(`id`)  ON DELETE CASCADE,
            FOREIGN KEY (`participant_id`) REFERENCES `participants`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `action_items` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `company_id`  INT UNSIGNED  NOT NULL DEFAULT 1,
            `room_id`     INT UNSIGNED  NOT NULL,
            `note_id`     INT UNSIGNED  DEFAULT NULL,
            `title`       VARCHAR(300)  NOT NULL,
            `description` TEXT          DEFAULT NULL,
            `owner_name`  VARCHAR(100)  DEFAULT NULL,
            `status`      ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
            `due_date`    DATE          DEFAULT NULL,
            `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_company_status` (`company_id`, `status`),
            FOREIGN KEY (`room_id`) REFERENCES `retro_rooms`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`note_id`) REFERENCES `retro_notes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `typing_indicators` (
            `room_id`        INT UNSIGNED NOT NULL,
            `participant_id` INT UNSIGNED NOT NULL,
            `column_id`      INT UNSIGNED DEFAULT NULL,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`room_id`, `participant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `board_templates` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `name`         VARCHAR(100)  NOT NULL,
            `columns_json` TEXT          NOT NULL,
            `guidance`     TEXT          DEFAULT NULL,
            `is_default`   TINYINT(1)    NOT NULL DEFAULT 0,
            `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `app_meta` (
            `meta_key`   VARCHAR(100) NOT NULL,
            `meta_value` TEXT         DEFAULT NULL,
            PRIMARY KEY (`meta_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `upgrade_history` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `version_from` VARCHAR(20)   NOT NULL,
            `version_to`   VARCHAR(20)   NOT NULL,
            `executed_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `notes`        TEXT          DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        // ── Drop all app tables first (clean slate) ──────────────────────────────
        // Handles partial/failed previous installs. CREATE TABLE IF NOT EXISTS
        // would silently keep old broken tables — this ensures a fresh schema.
        // Disable FK checks to drop in any order
        $appTables = [
            'typing_indicators','note_votes','retro_notes','action_items',
            'participants','retro_columns','retro_rooms',
            'board_templates','upgrade_history','app_meta','users','companies'
        ];
        $stmt = $pdo->query('SET FOREIGN_KEY_CHECKS = 0');
        $stmt->closeCursor();
        foreach ($appTables as $tbl) {
            $s = $pdo->query("DROP TABLE IF EXISTS `{$tbl}`");
            $s->closeCursor();
        }
        $s2 = $pdo->query('SET FOREIGN_KEY_CHECKS = 1');
        $s2->closeCursor();

        // ── Run DDL — use query()+closeCursor() to avoid unbuffered result errors ──
        foreach ($ddl as $ddlStmt) {
            try {
                $s = $pdo->query($ddlStmt);
                $s->closeCursor();
            } catch (PDOException $ddlEx) {
                throw new RuntimeException(
                    'Schema creation failed on statement: '
                    . substr($ddlStmt, 0, 120) . '… — '
                    . $ddlEx->getMessage()
                );
            }
        }



        // ── Seed data ──────────────────────────────────────────────────────────────
        $seeds = [
            "INSERT IGNORE INTO `board_templates` (`name`, `columns_json`, `is_default`) VALUES
             ('Start · Stop · Continue', '[{\"title\":\"Start\",\"color\":\"#22c55e\"},{\"title\":\"Stop\",\"color\":\"#ef4444\"},{\"title\":\"Continue\",\"color\":\"#3b82f6\"}]', 1),
             ('Mad · Sad · Glad',        '[{\"title\":\"Mad\",\"color\":\"#ef4444\"},{\"title\":\"Sad\",\"color\":\"#f59e0b\"},{\"title\":\"Glad\",\"color\":\"#22c55e\"}]', 0),
             ('What Went Well', '[{\"title\":\"What Went Well\",\"color\":\"#22c55e\"},{\"title\":\"Improvements\",\"color\":\"#f59e0b\"},{\"title\":\"Questions\",\"color\":\"#8b5cf6\"}]', 0),
             ('4Ls', '[{\"title\":\"Liked\",\"color\":\"#22c55e\"},{\"title\":\"Learned\",\"color\":\"#3b82f6\"},{\"title\":\"Lacked\",\"color\":\"#ef4444\"},{\"title\":\"Longed For\",\"color\":\"#8b5cf6\"}]', 0),
             ('Daily Standup', '[{\"title\":\"Yesterday\",\"color\":\"#3b82f6\"},{\"title\":\"Today\",\"color\":\"#22c55e\"},{\"title\":\"Blockers\",\"color\":\"#ef4444\"}]', 0)",
            "INSERT IGNORE INTO `app_meta` (`meta_key`, `meta_value`) VALUES
             ('app_version', '1.9.9'), ('installed_at', NOW()), ('schema_version', '1')",
            "INSERT IGNORE INTO `companies` (`id`,`name`,`team_name`) VALUES (1,'My Team','Engineering')",
        ];
        foreach ($seeds as $seed) {
            $s = $pdo->query($seed);
            $s->closeCursor();
        }

        // Insert admin user linked to company
        $hash = password_hash($i['admin_pass'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO users (company_id, username, password_hash, role) VALUES (1, ?, ?, ?)');
        $stmt->execute([$i['admin_user'], $hash, 'admin']);

        // Create uploads directory
        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
            file_put_contents($uploadsDir . '/.htaccess', "Options -Indexes
Deny from all
");
        }

        // Write config.php
        $configContent = <<<'CFGTPL'
<?php
define('APP_NAME',    'RetroApp');
define('APP_VERSION', '__VER__');
define('APP_ENV',     'production'); // change to 'development' for debug output
define('BASE_URL',    '__URL__');

define('DB_HOST',    '__HOST__');
define('DB_PORT',    '__PORT__');
define('DB_NAME',    '__NAME__');
define('DB_USER',    '__USER__');
define('DB_PASS',    '__PASS__');
define('DB_CHARSET', 'utf8mb4');

define('SESSION_NAME',     'retroapp_sess');
define('SESSION_LIFETIME', 7200);
define('CSRF_TOKEN_NAME',  '_csrf_token');

define('MAX_COLUMNS',       8);
define('MAX_VOTES_DEFAULT', 5);
define('NOTE_MAX_LENGTH',   1000);
define('ALLOW_EDIT_NOTES',  true);

define('TYPING_EXPIRE_SECS', 4);
define('LOGO_MAX_SIZE',      500000);

define('ROOT_PATH',     __DIR__);
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ADMIN_PATH',    ROOT_PATH . '/admin');
define('UPLOADS_PATH',  ROOT_PATH . '/uploads');
define('INSTALL_LOCK',  ROOT_PATH . '/.installed');

ini_set('display_errors', 0);
error_reporting(0);
CFGTPL;
        // Replace placeholders with actual values (avoids heredoc variable interpolation issues)
        $configContent = str_replace(
            ['__VER__', '__URL__', '__HOST__', '__PORT__', '__NAME__', '__USER__', '__PASS__'],
            [APP_VERSION, $i['baseUrl'], $i['dbHost'], $i['dbPort'], $i['dbName'], $i['dbUser'], $i['dbPass']],
            $configContent
        );
        file_put_contents(__DIR__ . '/config.php', $configContent);

        // Write lock file
        file_put_contents(INSTALL_LOCK, date('Y-m-d H:i:s'));

        $step = 6; // success
    } catch (Throwable $e) {
        $errors[] = 'Installation failed: ' . $e->getMessage();
        $step = 5;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RetroApp — Installation Wizard</title>
<style>
  /* Standalone glass styles — no external CSS needed for installer */
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  html{font-size:16px}
  body{
    font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',sans-serif;
    min-height:100vh;display:flex;align-items:center;justify-content:center;
    padding:1.5rem;
    background:
      radial-gradient(ellipse 80% 60% at 20% 0%,rgba(167,139,250,.45) 0%,transparent 65%),
      radial-gradient(ellipse 60% 50% at 80% 10%,rgba(99,179,237,.35) 0%,transparent 60%),
      radial-gradient(ellipse 50% 40% at 0% 60%,rgba(134,239,172,.25) 0%,transparent 55%),
      #dde6f5;
    -webkit-font-smoothing:antialiased;
  }
  .card{
    width:100%;max-width:500px;
    background:rgba(255,255,255,.45);
    backdrop-filter:blur(24px) saturate(200%);
    -webkit-backdrop-filter:blur(24px) saturate(200%);
    border:1px solid rgba(255,255,255,.6);
    border-top-color:rgba(255,255,255,.85);
    border-radius:24px;
    box-shadow:0 20px 60px rgba(50,40,100,.20),0 8px 20px rgba(50,40,100,.10),inset 0 1px 0 rgba(255,255,255,.7);
    padding:2.25rem 2rem;
  }
  .logo{font-size:1.5rem;font-weight:800;letter-spacing:-.03em;margin-bottom:.2rem;color:#1e293b}
  .subtitle{color:rgba(0,0,0,.5);font-size:.875rem;margin-bottom:1.75rem}
  .steps{display:flex;gap:.4rem;margin-bottom:2rem}
  .step{flex:1;height:5px;border-radius:3px;background:rgba(0,0,0,.1)}
  .step.done{background:#5856D6}
  .step.active{background:rgba(88,86,214,.4)}
  h2{font-size:1.1rem;font-weight:700;margin-bottom:1.1rem;letter-spacing:-.02em}
  label{display:block;font-size:.8rem;font-weight:600;color:rgba(0,0,0,.6);margin-bottom:.3rem}
  input[type=text],input[type=password],input[type=url]{
    width:100%;min-height:44px;
    background:rgba(255,255,255,.5);
    border:1.5px solid rgba(255,255,255,.65);
    border-top-color:rgba(255,255,255,.85);
    border-radius:10px;
    padding:.55rem .85rem;font-size:.9375rem;color:#1e293b;
    margin-bottom:.9rem;outline:none;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    transition:border-color .2s,box-shadow .2s;
  }
  input:focus{border-color:#5856D6;box-shadow:0 0 0 3px rgba(88,86,214,.15)}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
  .btn{
    display:block;width:100%;min-height:48px;
    background:linear-gradient(180deg,#6b69e0 0%,#5856D6 100%);
    color:#fff;border:none;border-radius:999px;
    padding:.65rem 1.5rem;font-size:1rem;font-weight:600;
    cursor:pointer;text-decoration:none;text-align:center;
    box-shadow:0 2px 12px rgba(88,86,214,.35),inset 0 1px 0 rgba(255,255,255,.25);
    transition:opacity .15s,transform .1s;line-height:1.5;
    display:flex;align-items:center;justify-content:center;
  }
  .btn:hover{opacity:.92}
  .btn:active{transform:scale(.98)}
  .btn-outline{
    background:rgba(255,255,255,.3);
    backdrop-filter:blur(10px);
    border:1.5px solid rgba(255,255,255,.6);
    color:#1e293b;
    box-shadow:0 2px 8px rgba(0,0,0,.08),inset 0 1px 0 rgba(255,255,255,.65);
  }
  .errors{
    background:rgba(255,59,48,.10);border:1px solid rgba(255,59,48,.25);
    border-radius:10px;padding:.85rem 1rem;margin-bottom:1.1rem;
    color:#c0392b;font-size:.875rem;
  }
  .errors li{margin-left:1rem}
  .check-row{display:flex;align-items:center;gap:.75rem;padding:.5rem 0;border-bottom:1px solid rgba(0,0,0,.06);font-size:.875rem}
  .check-row:last-child{border-bottom:none}
  .badge{font-size:.725rem;font-weight:700;padding:.18rem .55rem;border-radius:999px}
  .badge.pass{background:rgba(52,199,89,.15);color:#1a7a38;border:1px solid rgba(52,199,89,.3)}
  .badge.fail{background:rgba(255,59,48,.12);color:#c0392b;border:1px solid rgba(255,59,48,.25)}
  .info-text{color:rgba(0,0,0,.45);font-size:.775rem;margin-left:auto}
  .hint{font-size:.775rem;color:rgba(0,0,0,.4);margin-top:-.65rem;margin-bottom:.9rem;padding-left:.1rem}
  code{background:rgba(0,0,0,.07);padding:.1rem .35rem;border-radius:4px;font-size:.875rem}
  a.link{color:#5856D6;text-decoration:none;font-weight:600}
  .success-wrap{text-align:center;padding:.5rem 0}
</style>
</head>
<body>
<div class="card">
  <div class="logo">🔄 RetroApp</div>
  <p class="subtitle">Installation Wizard</p>

  <div class="steps">
    <?php for ($i = 1; $i <= 5; $i++): ?>
    <div class="step <?= $i < $step ? 'done' : ($i === $step ? 'active' : '') ?>"></div>
    <?php endfor; ?>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="errors"><ul><?php foreach ($errors as $e_msg): ?><li><?= e($e_msg) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
  <!-- STEP 1: Welcome -->
  <h2>Welcome to RetroApp</h2>
  <p style="color:rgba(0,0,0,.55);margin-bottom:1.25rem;font-size:.9rem;line-height:1.6;">
    This wizard installs RetroApp on your XAMPP server. Takes about 2 minutes.
  </p>
  <p style="font-size:.8rem;font-weight:600;color:rgba(0,0,0,.5);margin-bottom:.6rem;">YOU'LL NEED</p>
  <ul style="color:rgba(0,0,0,.55);font-size:.875rem;margin-left:1.25rem;margin-bottom:1.75rem;line-height:2.2;">
    <li>PHP 7.4 or higher (8.0+ recommended)</li>
    <li>MySQL / MariaDB credentials</li>
    <li>Write permission on this folder</li>
  </ul>
  <a href="install.php?step=2" class="btn">Begin Installation →</a>

  <?php elseif ($step === 2): ?>
  <!-- STEP 2: Environment check -->
  <?php
    try {
        $checks = run_env_check();
    } catch (Throwable $envEx) {
        $checks = [['label'=>'Environment check failed','pass'=>false,'info'=>'','fix'=>'A PHP error occurred running the checks: '.$envEx->getMessage().'. Enable display_errors temporarily to see the full error.']];
    }
    $allPass = !in_array(false, array_column($checks, 'pass'), true);
  ?>
  <h2>Environment Check</h2>
  <div style="margin-bottom:1.5rem;">
    <?php foreach ($checks as $chk): ?>
    <div class="check-row" style="flex-wrap:wrap;align-items:flex-start;row-gap:.3rem;">
      <div style="display:flex;align-items:center;gap:.65rem;width:100%;">
        <span class="badge <?= $chk['pass'] ? 'pass' : 'fail' ?>"><?= $chk['pass'] ? '✓ OK' : '✗ FAIL' ?></span>
        <span style="font-weight:<?= $chk['pass'] ? '400' : '600' ?>;
                     color:<?= $chk['pass'] ? 'inherit' : '#c0392b' ?>;">
          <?= e($chk['label']) ?>
        </span>
        <?php if ($chk['info']): ?>
          <span class="info-text"><?= e($chk['info']) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!$chk['pass']): ?>
      <div style="width:100%;padding:.6rem .85rem;margin-top:.25rem;
                  background:rgba(255,59,48,.06);border:1px solid rgba(255,59,48,.18);
                  border-left:3px solid #FF3B30;border-radius:0 6px 6px 0;
                  font-size:.8rem;line-height:1.6;">
        <?php if (!empty($chk['fix'])): ?>
          <strong style="display:block;margin-bottom:.35rem;color:#9b1c1c;">🔧 How to fix:</strong>
          <div style="color:#7f1d1d;"><?= nl2br(e($chk['fix'])) ?></div>
        <?php else: ?>
          <span style="color:#9b1c1c;">✗ This check failed. No automatic fix available — please review your PHP/server configuration.</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($allPass): ?>
    <a href="install.php?step=3" class="btn">Continue →</a>
  <?php else: ?>
    <p style="color:#c0392b;font-size:.875rem;margin-bottom:.75rem;">
      ⚠ Fix the issues above, then click Re-check.
    </p>
    <a href="install.php?step=2" class="btn btn-outline">Re-check</a>
  <?php endif; ?>

  <?php elseif ($step === 3): ?>
  <!-- STEP 3: Database settings -->
  <h2>Database Configuration</h2>
  <form method="POST" action="install.php?step=3">
    <input type="hidden" name="step" value="3">
    <?= install_csrf_field($_installCsrfToken) ?>
    <div class="row">
      <div>
        <label>DB Host</label>
        <input type="text" name="db_host" value="<?= e($_SESSION['install']['dbHost'] ?? 'localhost') ?>" required>
      </div>
      <div>
        <label>DB Port</label>
        <input type="text" name="db_port" value="<?= e($_SESSION['install']['dbPort'] ?? '3306') ?>" required>
      </div>
    </div>
    <label>Database Name</label>
    <input type="text" name="db_name" value="<?= e($_SESSION['install']['dbName'] ?? 'retroapp') ?>" required>
    <p class="hint">Will be created automatically if it does not exist.</p>
    <label>DB Username</label>
    <input type="text" name="db_user" value="<?= e($_SESSION['install']['dbUser'] ?? 'root') ?>" required>
    <label>DB Password</label>
    <input type="password" name="db_pass" value="" autocomplete="off">
    <label>Base URL</label>
    <input type="text" name="base_url"
          value="<?= e($_SESSION['install']['baseUrl'] ?? $_detectedBase) ?>"
          required autocomplete="off">
    <p class="hint">
      Auto-detected from your server — edit if wrong. No trailing slash.<br>
      Examples: <code>https://ikluu.com</code> &nbsp;&middot;&nbsp;
      <code>https://ikluu.com/retroapp</code> &nbsp;&middot;&nbsp;
      <code>http://localhost/retroapp</code><br>
      Use <strong>https://</strong> if your domain has an SSL certificate.
    </p>
    <button type="submit" class="btn">Test Connection →</button>
  </form>

  <?php elseif ($step === 4): ?>
  <!-- STEP 4: Admin account -->
  <h2>Create Admin Account</h2>
  <form method="POST" action="install.php?step=4">
    <input type="hidden" name="step" value="4">
    <?= install_csrf_field($_installCsrfToken) ?>
    <label>Username</label>
    <input type="text" name="admin_user" value="<?= e($_SESSION['install']['admin_user'] ?? 'admin') ?>" required minlength="3" autocomplete="username">
    <label>Password</label>
    <input type="password" name="admin_pass" required minlength="8" autocomplete="new-password">
    <label>Confirm Password</label>
    <input type="password" name="admin_pass2" required minlength="8" autocomplete="new-password">
    <p class="hint">Minimum 8 characters.</p>
    <button type="submit" class="btn">Create &amp; Install →</button>
  </form>

  <?php elseif ($step === 5): ?>
  <!-- STEP 5: Installing -->
  <h2>Installing…</h2>
  <p style="color:rgba(0,0,0,.55);font-size:.9rem;margin-bottom:1.5rem;">Creating database tables and writing configuration…</p>
  <?php if (!empty($errors)): ?>
    <a href="install.php?step=3" class="btn btn-outline">← Fix Database Settings</a>
  <?php else: ?>
    <meta http-equiv="refresh" content="0;url=install.php?step=5">
    <p style="font-size:.8rem;color:rgba(0,0,0,.4);text-align:center;margin-top:1rem;">Redirecting…</p>
  <?php endif; ?>

  <?php elseif ($step === 6): ?>
  <!-- STEP 6: Success -->
  <div class="success-wrap">
    <div style="font-size:3rem;margin-bottom:.75rem;">🎉</div>
    <h2 style="margin-bottom:.5rem;">Installation Complete!</h2>
    <p style="color:rgba(0,0,0,.55);font-size:.875rem;margin-bottom:1.25rem;line-height:1.6;">
      RetroApp is ready at <strong><?= e($_SESSION['install']['baseUrl'] ?? '') ?></strong>
    </p>

    <div style="text-align:left;background:rgba(52,199,89,.08);border:1px solid rgba(52,199,89,.2);
      border-radius:10px;padding:.9rem 1rem;margin-bottom:1.25rem;font-size:.8rem;line-height:1.75;">
      <strong style="display:block;margin-bottom:.4rem;color:#166534;">✅ Post-installation checklist</strong>
      <div style="color:#1a5c38;">
        □ Delete or rename <code>install.php</code> from your server for security<br>
        <?php if (!empty($_SESSION['install']['baseUrl']) && strpos($_SESSION['install']['baseUrl'],'https') === 0): ?>
        □ Uncomment the HTTPS redirect lines in <code>.htaccess</code><br>
        □ Uncomment the HSTS header in <code>.htaccess</code><br>
        <?php endif; ?>
        □ Ensure <code>uploads/</code> folder exists and is writable by the web server<br>
        □ Check error logs are being written (not displayed) in production<br>
        □ Run <a href="upgrade.php" style="color:var(--accent,#5856D6);">upgrade.php</a> after future updates
      </div>
    </div>

    <p style="font-size:.75rem;color:rgba(0,0,0,.4);margin-bottom:1.25rem;">
      Visiting <code>install.php</code> again redirects to the dashboard automatically.
    </p>
    <a href="admin/login.php" class="btn">Go to Admin Login →</a>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
