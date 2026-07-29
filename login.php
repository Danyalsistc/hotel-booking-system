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

$error  = '';
$values = ['email' => ''];

// Set by logout.php. Not echoed back, just used to pick a fixed message.
$justLoggedOut = isset($_GET['logged_out']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

    csrf_require();

    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $values['email'] = $email;

    if ($email === '' || $password === '') {
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

                auth_redirect(auth_dashboard_for_role($role));
            }

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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - Hotel Booking System</title>
    <link rel="stylesheet" href="css.css">
</head>
<body>

<main class="container">

    <form action="login.php" method="post" novalidate>

        <p class="logo">HOTEL BOOKING SYSTEM</p>

        <h1>Welcome Back</h1>

        <?php echo $flash; ?>

        <?php if ($justLoggedOut): ?>
            <p class="flash flash-success" role="alert">You have been logged out.</p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="flash flash-error" role="alert"><?php echo e($error); ?></p>
        <?php endif; ?>

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

        <button type="submit">Log in</button>

        <p class="form-footer">
            Don't have an account?
            <a href="register.php">Register</a>
        </p>

        <p class="form-footer">
            <a href="index.html">Return to the home page</a>
        </p>

    </form>

</main>

</body>
</html>
