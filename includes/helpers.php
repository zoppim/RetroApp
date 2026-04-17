<?php
/**
 * Security helpers — CSRF, escaping, validation, session management
 */

declare(strict_types=1);

// ─── Session ──────────────────────────────────────────────────────────────────

function session_start_safe(): void
{
    // If a different session is already active (e.g. install wizard session on
    // shared hosting with session.auto_start=1), close it first so the app
    // session starts cleanly under its own name.
    if (session_status() === PHP_SESSION_ACTIVE && session_name() !== SESSION_NAME) {
        session_write_close();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params(SESSION_LIFETIME, '/', '', isset($_SERVER['HTTPS']), true);
        }
        session_start();
    }
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────

function csrf_token(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrf_token() . '">';
}

function csrf_verify(): void
{
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }
}

// ─── Output escaping ──────────────────────────────────────────────────────────

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | (defined('ENT_SUBSTITUTE') ? ENT_SUBSTITUTE : 8), 'UTF-8');
}

function json_safe($data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// ─── Input sanitisation ───────────────────────────────────────────────────────

function input_str(string $key, string $source = 'POST', int $maxLen = 1000): string
{
    switch ($source) {
        case 'GET':    $bag = $_GET;    break;
        case 'COOKIE': $bag = $_COOKIE; break;
        default:       $bag = $_POST;
    }
    $val = trim($bag[$key] ?? '');
    return mb_substr($val, 0, $maxLen);
}

function input_int(string $key, string $source = 'POST', int $default = 0): int
{
    switch ($source) {
        case 'GET':    $bag = $_GET;    break;
        case 'COOKIE': $bag = $_COOKIE; break;
        default:       $bag = $_POST;
    }
    $val = $bag[$key] ?? $default;
    return filter_var($val, FILTER_VALIDATE_INT) !== false
        ? (int)$val
        : $default;
}

function input_date(string $key, string $source = 'POST'): ?string
{
    $val = input_str($key, $source, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        return $val;
    }
    return null;
}

// ─── Admin auth ───────────────────────────────────────────────────────────────

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']) && !empty($_SESSION['admin_username']);
}

function require_admin_login(): void
{
    if (!is_admin_logged_in()) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
}

function admin_login(int $id, string $username, int $companyId): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id']         = $id;
    $_SESSION['admin_username']   = $username;
    $_SESSION['company_id']       = $companyId;
}

// ─── Company context ───────────────────────────────────────────────────────────

function current_company_id(): int
{
    return (int)($_SESSION['company_id'] ?? 0);
}

function current_admin_id(): int
{
    return (int)($_SESSION['admin_id'] ?? 0);
}

function require_company(): void
{
    if (current_company_id() === 0) {
        admin_logout();
        redirect(BASE_URL . '/admin/login.php');
    }
}

function admin_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

// ─── Participant token (cookie-based anonymous identity) ──────────────────────

/**
 * Get or create the participant token for the given room.
 *
 * v1.5.0: Moved from per-browser-cookie to per-PHP-session storage.
 * Cookies are shared across all tabs on the same browser, meaning two
 * people using the same device would appear as the same participant.
 * PHP sessions are per-browser-tab (each tab has its own session when
 * opened in a new window/incognito), preventing identity collision.
 *
 * Falls back to cookie for backward compatibility with existing sessions.
 */
function get_participant_token(int $roomId): string
{
    $sessionKey = 'participant_token_' . $roomId;

    // 1. Check PHP session first (new primary storage)
    if (!empty($_SESSION[$sessionKey]) && strlen($_SESSION[$sessionKey]) === 64) {
        return $_SESSION[$sessionKey];
    }

    // 2. Migrate existing cookie token into session (backward compat)
    $cookieName = 'retroapp_room_' . $roomId;
    if (!empty($_COOKIE[$cookieName]) && strlen($_COOKIE[$cookieName]) === 64) {
        $token = $_COOKIE[$cookieName];
        $_SESSION[$sessionKey] = $token;
        // Clear the cookie to avoid future sharing issues
        setcookie($cookieName, '', ['expires' => time() - 3600, 'path' => '/']);
        return $token;
    }

    // 3. Issue a fresh token stored only in session
    $token = bin2hex(random_bytes(32));
    $_SESSION[$sessionKey] = $token;
    return $token;
}

// ─── Redirect helpers ─────────────────────────────────────────────────────────

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function redirect_back(string $fallback = '/'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    // Guard against open redirect: only allow same-origin Referers.
    // Parse the Referer and compare host+scheme to the current request.
    if ($ref !== '') {
        $parsed = parse_url($ref);
        $selfHost = ($_SERVER['HTTP_HOST'] ?? '');
        $refHost  = ($parsed['host'] ?? '');
        // Reject if host differs or scheme is anything other than http/https
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($refHost !== $selfHost || !in_array($scheme, ['http','https'], true)) {
            $ref = '';
        }
    }
    redirect($ref ?: $fallback);
}

// ─── Flash messages ───────────────────────────────────────────────────────────

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function flash_html(): string
{
    $flash = flash_get();
    if (!$flash) return '';
    $type = in_array($flash['type'], ['success', 'error', 'warning', 'info'], true)
        ? $flash['type'] : 'info';
    return '<div class="flash flash--' . e($type) . '">' . e($flash['message']) . '</div>';
}

// ─── UUID v4 ──────────────────────────────────────────────────────────────────

function generate_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
