<?php
declare(strict_types=1);

/**
 * Login. GET renders the form, POST authenticates and starts the session.
 *
 * An unknown email and a wrong password give the SAME generic message, so this
 * page cannot be used to discover which addresses are registered. The session
 * ID is regenerated on success to defeat session fixation, and only the user
 * ID, name and role are stored in the session.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

auth_session_start();

// Already signed in? Go straight to the right dashboard.
if (auth_is_logged_in()) {
    auth_redirect(auth_dashboard_for_role(auth_user_role()));
}

/**
 * Decoy hash that equalises response time when no account matches. Without it
 * a missing account would skip password_verify() and answer measurably faster
 * than a wrong password, leaking which emails exist. It belongs to no account.
 */
const LOGIN_DECOY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.5S1Sg9y1qYVGZ9YQ0F3rF3.mQe0Wq7C';

/** Shown for every failed attempt, whatever the underlying cause. */
const LOGIN_GENERIC_ERROR = 'The email address or password you entered is incorrect.';

/* Login rate limiting. Failures are counted per client IP in a rolling window.

   Per IP rather than per email on purpose: counting against an email would let
   anybody lock a real customer out of their own account just by guessing wrong
   at it. The check also runs BEFORE the email is looked up, so being throttled
   reveals nothing about whether an address is registered.

   Nobody is locked out permanently - the window rolls, expired rows are pruned
   on every run, and a successful sign-in clears the address.

   These live here rather than in auth.php, which stays database-free. */

/** Failures from one address within the window before attempts are refused. */
const LOGIN_MAX_FAILURES = 5;

/** Length of the rolling window, in seconds. */
const LOGIN_WINDOW_SECONDS = 900;   // 15 minutes

/**
 * The client's IP address. REMOTE_ADDR only - honouring X-Forwarded-For would
 * let a guesser reset their own throttle by inventing a header value.
 */
function login_client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
        ? $_SERVER['REMOTE_ADDR']
        : '';

    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        // Bucket anything unrecognisable rather than skipping the limiter.
        return 'unknown';
    }

    return substr($ip, 0, 45);
}

/**
 * Delete attempts that have aged out, so IP addresses are not kept
 * indefinitely. The interval comes from a PHP constant, never request data.
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

    /* Throttle check first, so a throttled response cannot leak whether the
       address exists. Fails open if the limiter itself errors - a broken
       counter must not lock everybody out, and the password is still required. */
    $clientIp  = login_client_ip();
    $throttled = false;

    try {
        login_prune_attempts($conn);
        $throttled = login_recent_failures($conn, $clientIp) >= LOGIN_MAX_FAILURES;
    } catch (mysqli_sql_exception $exception) {
        error_log('[Hotel Booking System] Login throttle check failed: ' . $exception->getMessage());
    }

    if ($throttled) {
        // Keeps the generic wording; no count, no timing, no account hint.
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

                // New session ID and CSRF token now privileges have changed.
                session_regenerate_id(true);
                csrf_rotate();

                $role = in_array((string) $userRole, ['customer', 'admin'], true)
                    ? (string) $userRole
                    : 'customer';

                $_SESSION['user_id']  = (int) $userId;
                $_SESSION['fullname'] = (string) $userFullname;
                $_SESSION['role']     = $role;

                // Start the idle and absolute timeout clocks for this login.
                auth_mark_login();

                // A correct password clears the address immediately.
                login_clear_failures($conn, $clientIp);

                auth_redirect(auth_dashboard_for_role($role));
            }

            // Wrong password and unknown account are recorded identically.
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
