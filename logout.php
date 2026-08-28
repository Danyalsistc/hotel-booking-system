<?php
declare(strict_types=1);

/**
 * Logout. POST only with a valid CSRF token.
 *
 * Logging out is state-changing, so it must not be reachable by a plain link -
 * otherwise an <img src="logout.php"> on another site could sign the user out.
 * A GET request logs nobody out; it renders a confirmation form.
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
    <html lang="en-AU">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Log Out - Hotel Booking System</title>
        <link rel="stylesheet" href="theme.css">
        <link rel="stylesheet" href="css.css">
    </head>
    <body class="auth-page">

    <a class="skip-link" href="#main-content">Skip to main content</a>

    <header class="auth-header">
        <a class="brand" href="index.html">
            <span class="brand-mark" aria-hidden="true">HB</span>
            <span>Hotel Booking System</span>
        </a>
    </header>

    <main id="main-content" class="auth-main">
        <div class="auth-card">
            <h1>Log out</h1>
            <p class="auth-intro">Are you sure you want to log out?</p>

            <form action="logout.php" method="post">
                <?php echo csrf_field(); ?>
                <div class="auth-actions">
                    <button type="submit" class="btn btn-primary btn-block">Yes, log me out</button>
                </div>
            </form>

            <div class="auth-links">
                <p><a href="index.html">Return to the home page</a></p>
            </div>
        </div>
    </main>

    <footer class="auth-footer">
        <p>&copy; 2026 Hotel Booking System &mdash; student coursework for ICT304.</p>
    </footer>

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
