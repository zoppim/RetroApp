<?php
/**
 * RetroApp — Server Diagnostic Tool
 * Checks PHP version, extensions, DB, file permissions, and config.
 * DELETE THIS FILE after debugging — it exposes server information.
 */

// Simple token protection — change this before using on production
$token = $_GET['t'] ?? '';
if ($token !== 'retroapp_diag_2024') {
    http_response_code(403);
    die('<p>Add ?t=retroapp_diag_2024 to the URL to run diagnostics.</p>');
}

header('Content-Type: text/plain; charset=utf-8');
$dir = __DIR__;

echo "RetroApp Diagnostic Report\n";
echo "Generated: " . date('Y-m-d H:i:s T') . "\n";
echo str_repeat('=', 60) . "\n\n";

// PHP
echo "PHP VERSION: " . PHP_VERSION . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
echo "PHP_VERSION_ID: " . PHP_VERSION_ID . "\n\n";

// Extensions
$required = ['pdo','pdo_mysql','mbstring','json','openssl','session'];
echo "EXTENSIONS:\n";
foreach ($required as $ext) {
    echo "  " . ($ext) . ": " . (extension_loaded($ext) ? "OK" : "MISSING") . "\n";
}
echo "\n";

// File permissions
echo "FILE PERMISSIONS:\n";
$paths = [
    $dir,
    $dir . '/config.php',
    $dir . '/includes',
    $dir . '/uploads',
    $dir . '/.htaccess',
    $dir . '/.installed',
];
foreach ($paths as $p) {
    if (file_exists($p)) {
        $perms = substr(sprintf('%o', fileperms($p)), -4);
        $writable = is_writable($p) ? 'writable' : 'not-writable';
        echo "  $perms $writable  " . basename($p) . "\n";
    } else {
        echo "  MISSING: " . basename($p) . "\n";
    }
}
echo "\n";

// Config
echo "CONFIG:\n";
if (file_exists($dir . '/config.php')) {
    require_once $dir . '/config.php';
    $consts = ['APP_NAME','APP_VERSION','APP_ENV','BASE_URL','DB_HOST','DB_NAME','DB_USER',
               'SESSION_NAME','CSRF_TOKEN_NAME','TYPING_EXPIRE_SECS','INSTALL_LOCK'];
    foreach ($consts as $c) {
        echo "  " . $c . ": " . (defined($c) ? constant($c) : 'NOT DEFINED') . "\n";
    }
} else {
    echo "  config.php: MISSING\n";
}
echo "\n";

// DB
echo "DATABASE:\n";
if (defined('DB_HOST')) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "  Connection: OK\n";
        $v = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo "  MySQL version: $v\n";
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "  Tables (" . count($tables) . "): " . implode(', ', $tables) . "\n";
    } catch (Exception $e) {
        echo "  Connection FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "  Skipped (config.php not loaded)\n";
}
echo "\n";

// Session
echo "SESSION:\n";
echo "  session.auto_start: " . ini_get('session.auto_start') . "\n";
echo "  session.save_path: " . (ini_get('session.save_path') ?: '(default)') . "\n";
echo "  session.gc_probability: " . ini_get('session.gc_probability') . "\n";
echo "\n";

// .htaccess
echo "HTACCESS:\n";
echo "  mod_rewrite: " . (function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules()) ? 'loaded' : 'unknown') . "\n";
echo "  AllowOverride: (check Apache vhost config — cannot read from PHP)\n";
echo "\n";

echo str_repeat('=', 60) . "\n";
echo "END OF REPORT — delete check.php after use\n";
