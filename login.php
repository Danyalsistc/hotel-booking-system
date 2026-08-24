<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Login
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Single endpoint:
 *      GET  - renders the login form
 *      POST - authenticates the user and starts a privileged session
 *
 *  Security notes:
 *    - The user lookup uses a prepared statement selecting only the four
 *      columns needed. No user input is ever concatenated into SQL.
 *    - Reads users.password_hash, matching the schema in database.sql.
 *    - An unknown email and a wrong password produce the SAME generic
 *      message, so this page cannot be used to discover which addresses are
 *      registered.
 *    - The session ID is regenerated on success to defeat session fixation.
 *    - Only the user ID, display name and role are placed in the session.
 *      Passwords, hashes and email addresses are never stored there.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

auth_session_start();

// Already signed in? Go straight to the right dashboard.
if (auth_is_logged_in()) {
    auth_redirect(auth_dashboard_for_role(auth_user_role()));
}

/**
 * Decoy hash used to equalise response time when no account matches.
 *
 * Without this, a missing account would skip password_verify() and answer
 * measurably faster than a wrong password, leaking which emails exist. This
 * value is the hash of a random string, belongs to no account, and cannot be
 * used to log in.
 */
const LOGIN_DECOY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.5S1Sg9y1qYVGZ9YQ0F3rF3.mQe0Wq7C';

/** Shown for every failed attempt, whatever the underlying cause. */
const LOGIN_GENERIC_ERROR = 'The email address or password you entered is incorrect.';

/* ---------------------------------------------------------------------------
 *  LOGIN RATE LIMITING
 *
 *  Failures are counted PER CLIENT IP inside a rolling window. Reaching the
 *  threshold refuses further attempts from that address until enough of the
 *  window has passed.
 *
 *  Why per IP rather than per email: counting against an email address would
 *  let anybody lock a real customer out of their own account just by guessing
 *  wrong at it. That turns a defence into a denial-of-service tool aimed at
 *  the victim. Counting per IP throttles the guesser instead.
 *
 *  The check also runs BEFORE the email is looked up, so being throttled says
 *  nothing whatsoever about whether an address is registered - the generic
 *  error behaviour of this page is preserved exactly.
 *
 *  Nobody is locked out permanently: the window is rolling, expired rows are
 *  deleted every time the limiter runs, and a successful sign-in clears the
 *  address immediately.
 *
 *  These helpers live here rather than in auth.php on purpose - auth.php is
 *  documented as containing no database access, and that stays true.
 * ------------------------------------------------------------------------ */

/** Failures from one address within the window before attempts are refused. */
const LOGIN_MAX_FAILURES = 5;

/** Length of the rolling window, in seconds. */
const LOGIN_WINDOW_SECONDS = 900;   // 15 minutes

/**
 * The client's IP address, as a short safe string.
 *
 * REMOTE_ADDR only. X-Forwarded-For and friends are set by the client unless
 * a trusted proxy overwrites them, so honouring them here would let a guesser
 * reset their own throttle just by inventing a new header value.
 */
function login_client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
        ? $_SERVER['REMOTE_ADDR']
        : '';

    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        // Bucket anything unrecognisable together rather than skipping the
        // limiter entirely.
        return 'unknown';
    }

    return substr($ip, 0, 45);
}

/**
 * Delete attempts that have aged out of the window.
 *
 * Keeps the table small and means IP addresses are not retained indefinitely.
 *
 * The interval is written from a PHP integer CONSTANT, never from request
 * data, so no user input reaches the SQL text. The address itself is always
 * bound as a parameter.
 */
function login_prune_attempts(mysqli $conn): void
{
    $conn->prepare(
        'DELETE FROM login_attempts
          WHERE attempted_at < (NOW() - INTERVAL ' . (int) LOGIN_WINDOW_SECONDS . ' SECOND)'
    )->execute();
}

/** How many failures this address has recorded inside the window. */
function login_recent_failures(mysqli $conn, string $ip): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip_address = ?
            AND attempted_at >= (NOW() - INTERVAL ' . (int) LOGIN_WINDOW_SECONDS . ' SECOND)'
    );

    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count;
}

/** Record one failed attempt. No email, password or hash is ever stored. */
function login_record_failure(mysqli $conn, string $ip): void
{
    $stmt = $conn->prepare('INSERT INTO login_attempts (ip_address) VALUES (?)');
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
}

/** Clear an address's failures after a successful sign-in. */
function login_clear_failures(mysqli $conn, string $ip): void
{
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
}

$error  = '';
$values = ['email' => ''];

// Set by logout.php. Not echoed back, just used to pick a fixed message.
$justLoggedOut = isset($_GET['logged_out']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

    csrf_require();

    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $values['email'] = $email;

    /* -----------------------------------------------------------------------
     *  Throttle check, before anything is looked up.
     *
     *  Placed first so that a throttled response cannot depend on - and
     *  therefore cannot leak - whether the address exists.
     *
     *  Fails OPEN if the limiter itself errors: authentication still requires
     *  the correct password, so a broken counter must not lock everybody out
     *  of the site.
     * -------------------------------------------------------------------- */
    $clientIp  = login_client_ip();
    $throttled = false;

    try {
        login_prune_attempts($conn);
        $throttled = login_recent_failures($conn, $clientIp) >= LOGIN_MAX_FAILURES;
    } catch (mysqli_sql_exception $exception) {
        error_log('[Hotel Booking System] Login throttle check failed: ' . $exception->getMessage());
    }

    if ($throttled) {
        // Keeps the generic wording and adds only that they should wait. No
        // count, no remaining time, no hint about the account.
        $error = LOGIN_GENERIC_ERROR
               . ' Too many attempts have been made from this connection. '
               . 'Please wait a few minutes and try again.';

    } elseif ($email === '' || $password === '') {
        // Not a credential guess, so this is not recorded as a failure.
        $error = 'Please enter both your email address and your password.';
    } elseif (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        // Same generic message as a failed lookup - a malformed address must
        // not be distinguishable from an unregistered one.
        $error = LOGIN_GENERIC_ERROR;
    } else {
        try {
            $stmt = $conn->prepare(
                'SELECT id, fullname, password_hash, role FROM users WHERE email = ? LIMIT 1'
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->bind_result($userId, $userFullname, $userPasswordHash, $userRole);

            $found = (bool) $stmt->fetch();
            $stmt->close();

            if ($found) {
                $passwordCorrect = password_verify($password, (string) $userPasswordHash);
            } else {
                // Burn equivalent time so timing cannot reveal the answer.
                password_verify($password, LOGIN_DECOY_HASH);
                $passwordCorrect = false;
            }

            if ($found && $passwordCorrect) {

                // New session ID now that privileges have changed.
                session_regenerate_id(true);

                // Retire the pre-login CSRF token along with the old session.
                csrf_rotate();

                $role = in_array((string) $userRole, ['customer', 'admin'], true)
                    ? (string) $userRole
                    : 'customer';

                $_SESSION['user_id']  = (int) $userId;
                $_SESSION['fullname'] = (string) $userFullname;
                $_SESSION['role']     = $role;

                // Start the idle and absolute timeout clocks for this login.
                auth_mark_login();

                // A correct password clears this address immediately, so a
                // customer who mistyped a few times is not left waiting out
                // the window once they get it right.
                login_clear_failures($conn, $clientIp);

                auth_redirect(auth_dashboard_for_role($role));
            }

            // Wrong password, or no such account - recorded identically, so
            // the table itself holds no clue about which it was.
            login_record_failure($conn, $clientIp);

            $error = LOGIN_GENERIC_ERROR;

        } catch (mysqli_sql_exception $exception) {
            error_log('[Hotel Booking System] Login failed: ' . $exception->getMessage());
            $error = 'We could not sign you in right now. Please try again shortly.';
        }
    }
}

$flash = flash_render();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - Hotel Booking System</title>
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

        <h1>Welcome back</h1>
        <p class="auth-intro">Sign in to manage your bookings.</p>

        <?php echo $flash; ?>

        <?php if ($justLoggedOut): ?>
            <p class="flash flash-success" role="alert">You have been logged out.</p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="flash flash-error" role="alert"><?php echo e($error); ?></p>
        <?php endif; ?>

        <form action="login.php" method="post" novalidate>

        <?php echo csrf_field(); ?>

        <div class="field">
            <label for="email">Email address</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?php echo e($values['email']); ?>"
                autocomplete="email"
                required
                <?php if ($error !== ''): ?>aria-invalid="true"<?php endif; ?>
            >
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
                <?php if ($error !== ''): ?>aria-invalid="true"<?php endif; ?>
            >
        </div>

        <div class="auth-actions">
            <button type="submit" class="btn btn-primary btn-block">Log in</button>
        </div>

        </form>

        <div class="auth-links">
            <p>
                Don't have an account?
                <a href="register.php">Create one</a>
            </p>
            <p>
                <a href="index.html">Return to the home page</a>
            </p>
        </div>

    </div>

</main>

<footer class="auth-footer">
    <p>&copy; 2026 Hotel Booking System &mdash; student coursework for ICT304.</p>
</footer>

</body>
</html>
