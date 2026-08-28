<?php
declare(strict_types=1);

/**
 * Authentication, session and CSRF helpers.
 *
 * Included by every page that needs to know who the visitor is. Contains no
 * database access and no output, so it is safe to include before any HTML.
 */

const AUTH_CSRF_KEY = 'csrf_token';
const AUTH_FLASH_KEY = 'flash_messages';

// Session timeout policy. Both limits are enforced server-side from session
// timestamps; nothing here relies on JavaScript.
const AUTH_IDLE_TIMEOUT = 1800;          // 30 minutes since the last request
const AUTH_ABSOLUTE_LIFETIME = 28800;    // 8 hours since signing in

const AUTH_STARTED_KEY = 'auth_started_at';
const AUTH_LAST_SEEN_KEY = 'auth_last_seen_at';


/**
 * Is the current request served over HTTPS?
 *
 * Deliberately does not trust X-Forwarded-Proto, which a client can forge.
 * Returns false on http://localhost, which keeps the Secure cookie flag off
 * for local development.
 */
function auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    return false;
}

/**
 * Start the session unless one is already running. Safe to call repeatedly.
 * The hardening settings are applied first because most have no effect once
 * the session has started.
 */
function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    // use_strict_mode rejects any session ID the server never issued, which is
    // the main defence against session fixation. Cookies only - never accept a
    // session ID from the URL.
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');

    $secure = auth_is_https();

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,  // HTTPS only; off on localhost
            'httponly' => true,     // not readable by JavaScript
            'samesite' => 'Lax',    // blocks cross-site POST (CSRF defence)
        ]);
    } else {
        // SameSite is not supported by this signature before PHP 7.3.
        session_set_cookie_params(0, '/', '', $secure, true);
    }

    session_start();

    // Enforced here so no page can act on an expired session's identity.
    auth_enforce_timeouts();
}

/**
 * Stamp a freshly authenticated session, called by login.php after the session
 * ID is regenerated. A session carrying a user_id but no stamps is treated as
 * expired, so forgetting this call fails closed.
 */
function auth_mark_login(): void
{
    auth_session_start();

    $now = time();

    $_SESSION[AUTH_STARTED_KEY]   = $now;
    $_SESSION[AUTH_LAST_SEEN_KEY] = $now;
}

/**
 * Apply the idle and absolute session limits. Only authenticated sessions are
 * affected, so anonymous browsing is never interrupted.
 *
 * The static guard prevents re-entry: expiring a session calls flash_set(),
 * which calls auth_session_start() again.
 */
function auth_enforce_timeouts(): void
{
    static $ran = false;

    if ($ran) {
        return;
    }

    $ran = true;

    if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
        return;
    }

    $now = time();

    $startedAt  = isset($_SESSION[AUTH_STARTED_KEY]) && is_int($_SESSION[AUTH_STARTED_KEY])
        ? $_SESSION[AUTH_STARTED_KEY]
        : null;

    $lastSeenAt = isset($_SESSION[AUTH_LAST_SEEN_KEY]) && is_int($_SESSION[AUTH_LAST_SEEN_KEY])
        ? $_SESSION[AUTH_LAST_SEEN_KEY]
        : null;

    // These messages say why the session ended but never state the limits.
    $reason = null;

    if ($startedAt === null || $lastSeenAt === null) {
        // Fail closed: a user_id with no stamps was not issued by this code.
        $reason = 'Your session could not be verified. Please log in again.';

    } elseif (($now - $lastSeenAt) >= AUTH_IDLE_TIMEOUT) {
        $reason = 'You were signed out because your session was inactive. Please log in again.';

    } elseif (($now - $startedAt) >= AUTH_ABSOLUTE_LIFETIME) {
        $reason = 'You were signed out because your session reached its maximum length. Please log in again.';
    }

    if ($reason === null) {
        // Still valid: slide the idle window forward.
        $_SESSION[AUTH_LAST_SEEN_KEY] = $now;

        return;
    }

    auth_expire_session($reason);
}

/**
 * End an expired session and redirect to login.
 *
 * regenerate_id(true) deletes the old session server-side so it cannot be
 * resumed by replaying its cookie. The clean empty session left behind carries
 * the flash message, and having no user_id is why this cannot loop.
 */
function auth_expire_session(string $reason): void
{
    $_SESSION = [];

    if (!headers_sent()) {
        session_regenerate_id(true);
    }

    flash_set('error', $reason);
    auth_redirect('login.php');
}


/** Is somebody logged in on this request? */
function auth_is_logged_in(): bool
{
    auth_session_start();

    return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
}

/** Current user's database ID, or null when not logged in. */
function auth_user_id(): ?int
{
    return auth_is_logged_in() ? (int) $_SESSION['user_id'] : null;
}

/** Current user's full name (raw - always print through e()). */
function auth_user_name(): ?string
{
    if (!auth_is_logged_in()) {
        return null;
    }

    return isset($_SESSION['fullname']) ? (string) $_SESSION['fullname'] : null;
}

/**
 * Current user's role. Anything unrecognised is downgraded to 'customer', so
 * a corrupted session can never grant administrative access.
 */
function auth_user_role(): ?string
{
    if (!auth_is_logged_in()) {
        return null;
    }

    $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'customer';

    return in_array($role, ['customer', 'admin'], true) ? $role : 'customer';
}

/** Is the current user an administrator? */
function auth_is_admin(): bool
{
    return auth_user_role() === 'admin';
}

/** The dashboard a given role belongs on after login. */
function auth_dashboard_for_role(?string $role): string
{
    return $role === 'admin' ? 'admin-dashboard.php' : 'customer-dashboard.php';
}


/** Require any logged-in user. Sends guests to the login page. */
function require_login(): void
{
    if (auth_is_logged_in()) {
        return;
    }

    flash_set('error', 'Please log in to continue.');
    auth_redirect('login.php');
}

/**
 * Require an administrator. A logged-in customer is sent back to their own
 * dashboard: authenticated, but not authorised.
 */
function require_admin(): void
{
    require_login();

    if (auth_is_admin()) {
        return;
    }

    flash_set('error', 'You do not have permission to view that page.');
    auth_redirect('customer-dashboard.php');
}


/**
 * Redirect to a path inside this application, then stop.
 *
 * Only same-directory relative paths are accepted; a scheme, host,
 * protocol-relative "//" prefix or ".." traversal is discarded in favour of
 * the home page. This is what stops the helper becoming an open redirect.
 */
function auth_redirect(string $path): void
{
    $safe = 'index.html';

    $looksInternal = (strpos($path, '//') !== 0)
        && (strpos($path, ':') === false)
        && (strpos($path, "\r") === false)
        && (strpos($path, "\n") === false)
        && (strpos($path, '..') === false)
        && (preg_match('#^[A-Za-z0-9._\-/]+(\?[A-Za-z0-9._\-=&%+]*)?$#', $path) === 1);

    if ($looksInternal) {
        $safe = $path;
    }

    if (!headers_sent()) {
        header('Location: ' . $safe, true, 302);
    }

    // Fallback for the rare case where headers were already flushed.
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Redirecting</title></head><body><p>'
       . '<a href="' . e($safe) . '">Continue</a></p></body></html>';

    exit;
}


/**
 * Escape a value for output inside HTML. Every piece of dynamic text must go
 * through this. ENT_QUOTES makes it safe inside either quote style.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


/** Return this session's CSRF token, creating one on first use. */
function csrf_token(): string
{
    auth_session_start();

    if (empty($_SESSION[AUTH_CSRF_KEY]) || !is_string($_SESSION[AUTH_CSRF_KEY])) {
        $_SESSION[AUTH_CSRF_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[AUTH_CSRF_KEY];
}

/** Discard the token after a privilege change, so it does not outlive it. */
function csrf_rotate(): void
{
    auth_session_start();
    unset($_SESSION[AUTH_CSRF_KEY]);
}

/** The hidden input to drop inside every state-changing <form>. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Is the token valid for this session? Uses a timing-safe comparison. */
function csrf_validate(?string $token): bool
{
    auth_session_start();

    $stored = $_SESSION[AUTH_CSRF_KEY] ?? null;

    if (!is_string($stored) || $stored === '' || !is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($stored, $token);
}

/** Validate the CSRF token on the current POST, or stop with HTTP 400. */
function csrf_require(): void
{
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : null;

    if (csrf_validate($token)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Request could not be verified</title></head><body>'
       . '<h1>Request could not be verified</h1>'
       . '<p>Your session may have expired. Please go back and try again.</p>'
       . '<p><a href="login.php">Return to login</a></p>'
       . '</body></html>';

    exit;
}


// Flash messages: one-shot notices that survive exactly one redirect.
/** Queue a message ('success' or 'error') for the next page load. */
function flash_set(string $type, string $message): void
{
    auth_session_start();

    if (!in_array($type, ['success', 'error'], true)) {
        $type = 'error';
    }

    if (!isset($_SESSION[AUTH_FLASH_KEY]) || !is_array($_SESSION[AUTH_FLASH_KEY])) {
        $_SESSION[AUTH_FLASH_KEY] = [];
    }

    $_SESSION[AUTH_FLASH_KEY][] = ['type' => $type, 'message' => $message];
}

/** Return all queued messages and clear the queue. */
function flash_take(): array
{
    auth_session_start();

    $messages = [];

    if (isset($_SESSION[AUTH_FLASH_KEY]) && is_array($_SESSION[AUTH_FLASH_KEY])) {
        $messages = $_SESSION[AUTH_FLASH_KEY];
    }

    unset($_SESSION[AUTH_FLASH_KEY]);

    return $messages;
}

/** Render queued flash messages. role="alert" announces them immediately. */
function flash_render(): string
{
    $messages = flash_take();

    if ($messages === []) {
        return '';
    }

    $html = '';

    foreach ($messages as $message) {
        $type = ($message['type'] ?? 'error') === 'success' ? 'success' : 'error';

        $html .= '<p class="flash flash-' . $type . '" role="alert">'
               . e((string) ($message['message'] ?? ''))
               . '</p>';
    }

    return $html;
}

// No closing PHP tag: prevents stray whitespace breaking header() redirects.
