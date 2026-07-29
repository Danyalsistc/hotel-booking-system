<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Logout
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  POST ONLY, and a valid CSRF token is required.
 *
 *  Logging out is a state-changing action, so it must not be reachable by a
 *  plain link. If it were, any <img src="logout.php"> on another site - or a
 *  browser prefetching a link - could sign the user out without their intent.
 *  A GET request therefore logs nobody out; it renders a confirmation form.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';

auth_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {

    // Deliberately does NOT log the user out. Offers a real POST form instead.
    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/html; charset=utf-8');
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Log Out - Hotel Booking System</title>
        <link rel="stylesheet" href="css.css">
    </head>
    <body>
    <main class="container">
        <form action="logout.php" method="post">
            <p class="logo">HOTEL BOOKING SYSTEM</p>
            <h1>Log Out</h1>
            <p class="form-intro">Are you sure you want to log out?</p>
            <?php echo csrf_field(); ?>
            <button type="submit">Yes, log me out</button>
            <p class="form-footer">
                <a href="index.html">Return to the home page</a>
            </p>
        </form>
    </main>
    </body>
    </html>
    <?php
    exit;
}

csrf_require();

// 1) Drop every session variable.
$_SESSION = [];

// 2) Expire the session cookie using the SAME parameters it was created with,
//    otherwise the browser keeps the old cookie and it is not truly removed.
if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    } else {
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }
}

// 3) Destroy the session on the server.
session_destroy();

// The confirmation message is carried by a query flag rather than a flash,
// so no new session has to be created just to greet a now-anonymous visitor.
auth_redirect('login.php?logged_out=1');
