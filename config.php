<?php
/**
 * RetroApp — Central Configuration
 * Edit this file directly, or let install.php generate it.
 */

// ─── Application ──────────────────────────────────────────────────────────────
define('APP_NAME',    'RetroApp');
define('APP_VERSION', '1.9.9');
define('APP_ENV',     'development'); // 'development' | 'production'
define('BASE_URL',    'http://localhost/retroapp'); // no trailing slash — edit this

// ─── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'retroapp');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ─── Session ──────────────────────────────────────────────────────────────────
define('SESSION_NAME',     'retroapp_sess');
define('SESSION_LIFETIME', 7200);
define('CSRF_TOKEN_NAME',  '_csrf_token');

// ─── Features ─────────────────────────────────────────────────────────────────
define('MAX_COLUMNS',       8);
define('MAX_VOTES_DEFAULT', 5);
define('NOTE_MAX_LENGTH',   1000);
define('ALLOW_EDIT_NOTES',  true);

// ─── v1.2+ additions ──────────────────────────────────────────────────────────
define('TYPING_EXPIRE_SECS', 4);
define('LOGO_MAX_SIZE',      500000);

// ─── Paths ────────────────────────────────────────────────────────────────────
define('ROOT_PATH',     __DIR__);
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ADMIN_PATH',    ROOT_PATH . '/admin');
define('UPLOADS_PATH',  ROOT_PATH . '/uploads');
define('INSTALL_LOCK',  ROOT_PATH . '/.installed');

// ─── Error reporting ──────────────────────────────────────────────────────────
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
