<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Authentication, session and CSRF helpers
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Shared helper library included by every page that needs to know who the
 *  current visitor is. Contains NO database access and NO page output of its
 *  own, so it is safe to include before any HTML is produced.
 *
 *  Typical use:
 *      require_once __DIR__ . '/auth.php';
 *      require_login();                  // or require_admin();
 *      echo e(auth_user_name());
 * ===========================================================================
 */

/** Session key holding the CSRF token. */
const AUTH_CSRF_KEY = 'csrf_token';

/** Session key holding queued flash messages. */
const AUTH_FLASH_KEY = 'flash_messages';


/* ===========================================================================
 *  SESSION
 * ======================================================================== */

/**
 * Is the current request being served over HTTPS?
 *
 * Deliberately does NOT trust X-Forwarded-Proto, which a client can forge.
 * On a plain http://localhost XAMPP install this returns false, which is what
 * keeps the Secure cookie flag off so local development still works.
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
 * Start the session, but only when one is not already running.
 *
 * Safe to call repeatedly and from any page. Applies the hardening settings
 * before the session starts, because most of them have no effect afterwards.
 */
function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Cookies must already have been sent if output has started; bail out
    // rather than emit a warning into the middle of a page.
    if (headers_sent()) {
        return;
    }

    // Reject any session ID the browser invents that the server never issued.
    // This is the main defence against session fixation.
    @ini_set('session.use_strict_mode', '1');

    // Never accept a session ID from the URL query string.
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');

    $secure = auth_is_https();

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,        // expires when the browser closes
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,  // only over HTTPS; off on localhost
            'httponly' => true,     // not readable by JavaScript
            'samesite' => 'Lax',    // blocks cross-site POST (CSRF defence)
        ]);
    } else {
        // SameSite is not supported by this signature before PHP 7.3.
        session_set_cookie_params(0, '/', '', $secure, true);
    }

    session_start();
}


/* ===========================================================================
 *  CURRENT USER
 * ======================================================================== */

/**
 * Is somebody logged in on this request?
 */
function auth_is_logged_in(): bool
{
    auth_session_start();

    return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
}

/**
 * Current user's database ID, or null when not logged in.
 */
function auth_user_id(): ?int
{
    return auth_is_logged_in() ? (int) $_SESSION['user_id'] : null;
}

/**
 * Current user's full name, or null when not logged in.
 *
 * NOTE: the value is raw. Always pass it through e() before printing.
 */
function auth_user_name(): ?string
{
    if (!auth_is_logged_in()) {
        return null;
    }

    return isset($_SESSION['fullname']) ? (string) $_SESSION['fullname'] : null;
}

/**
 * Current user's role: 'customer', 'admin', or null when not logged in.
 *
 * Anything unrecognised is downgraded to 'customer', so a corrupted session
 * can never grant administrative access.
 */
function auth_user_role(): ?string
{
    if (!auth_is_logged_in()) {
        return null;
    }

    $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'customer';

    return in_array($role, ['customer', 'admin'], true) ? $role : 'customer';
}

/**
 * Is the current user an administrator?
 */
function auth_is_admin(): bool
{
    return auth_user_role() === 'admin';
}

/**
 * The dashboard a given role belongs on after login.
 */
function auth_dashboard_for_role(?string $role): string
{
    return $role === 'admin' ? 'admin-dashboard.php' : 'customer-dashboard.php';
}


/* ===========================================================================
 *  ACCESS CONTROL
 * ======================================================================== */

/**
 * Require any logged-in user. Sends guests to the login page.
 */
function require_login(): void
{
    if (auth_is_logged_in()) {
        return;
    }

    flash_set('error', 'Please log in to continue.');
    auth_redirect('login.php');
}

/**
 * Require an administrator.
 *
 * A guest is sent to login. A logged-in customer is sent back to their own
 * dashboard with an explanation - they are authenticated but not authorised.
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


/* ===========================================================================
 *  REDIRECTS
 * ======================================================================== */

/**
 * Redirect to a path INSIDE this application, then stop.
 *
 * Only same-directory relative paths are accepted. Anything containing a
 * scheme, a host, a protocol-relative "//" prefix or a parent-directory
 * traversal is discarded and replaced with the home page, which prevents this
 * helper being abused as an open redirect.
 *
 * @param string $path e.g. 'login.php' or 'login.php?logged_out=1'
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


/* ===========================================================================
 *  OUTPUT ESCAPING
 * ======================================================================== */

/**
 * Escape a value for safe output inside HTML.
 *
 * Every piece of dynamic text printed by this application must go through
 * this function. ENT_QUOTES also escapes single quotes, so the result is safe
 * inside both double- and single-quoted attributes.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


/* ===========================================================================
 *  CSRF PROTECTION
 * ======================================================================== */

/**
 * Return this session's CSRF token, creating one on first use.
 */
function csrf_token(): string
{
    auth_session_start();

    if (empty($_SESSION[AUTH_CSRF_KEY]) || !is_string($_SESSION[AUTH_CSRF_KEY])) {
        $_SESSION[AUTH_CSRF_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[AUTH_CSRF_KEY];
}

/**
 * Discard the current CSRF token so the next call issues a fresh one.
 * Called after a privilege change (login) so the token does not outlive it.
 */
function csrf_rotate(): void
{
    auth_session_start();
    unset($_SESSION[AUTH_CSRF_KEY]);
}

/**
 * The hidden input to drop inside every state-changing <form>.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Is the supplied token valid for this session?
 *
 * Uses hash_equals for a timing-safe comparison.
 */
function csrf_validate(?string $token): bool
{
    auth_session_start();

    $stored = $_SESSION[AUTH_CSRF_KEY] ?? null;

    if (!is_string($stored) || $stored === '' || !is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($stored, $token);
}

/**
 * Validate the CSRF token on the current POST, or stop with HTTP 400.
 */
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


/* ===========================================================================
 *  FLASH MESSAGES
 *  One-shot messages that survive exactly one redirect.
 * ======================================================================== */

/**
 * Queue a message for the next page load.
 *
 * @param string $type    'success' or 'error'
 * @param string $message Plain text; escaped when rendered.
 */
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

/**
 * Return all queued messages and clear the queue.
 *
 * @return array<int, array{type: string, message: string}>
 */
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

/**
 * Render queued flash messages as an accessible HTML block.
 *
 * role="alert" makes assistive technology announce the message immediately.
 */
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
