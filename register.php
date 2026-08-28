<?php
declare(strict_types=1);

/**
 * Customer registration. GET renders the form, POST creates the account.
 *
 * The role column is never accepted from the browser - it is omitted from the
 * INSERT so the database default ('customer') applies, which makes privilege
 * escalation via a crafted form impossible. Passwords are stored only as
 * password_hash() output.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

auth_session_start();

// Someone already signed in has no reason to be here.
if (auth_is_logged_in()) {
    auth_redirect(auth_dashboard_for_role(auth_user_role()));
}

/** @var array<string, string> Field-level error messages, keyed by field name. */
$errors = [];

/** @var array<string, string> Non-sensitive values re-displayed after an error. */
$values = ['fullname' => '', 'email' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

    csrf_require();

    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    // Repopulate the form with non-sensitive values only.
    // Password fields are deliberately never sent back to the browser.
    $values['fullname'] = $fullname;
    $values['email']    = $email;

    // ----- Full name ------------------------------------------------------
    if ($fullname === '') {
        $errors['fullname'] = 'Please enter your full name.';
    } elseif (mb_strlen($fullname) < 2) {
        $errors['fullname'] = 'Your name must be at least 2 characters long.';
    } elseif (mb_strlen($fullname) > 120) {
        $errors['fullname'] = 'Your name must be 120 characters or fewer.';
    } elseif (preg_match("/^\p{L}[\p{L}\p{M}\p{Zs}'\-.]*$/u", $fullname) !== 1) {
        // Unicode-aware: accepts accented characters, hyphens, apostrophes and
        // full stops, so names like "Anne-Marie O'Brien" are not rejected.
        $errors['fullname'] = 'Your name can contain letters, spaces, hyphens, apostrophes and full stops only.';
    }

    // ----- Email ----------------------------------------------------------
    if ($email === '') {
        $errors['email'] = 'Please enter your email address.';
    } elseif (strlen($email) > 190) {
        $errors['email'] = 'Your email address must be 190 characters or fewer.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // ----- Password -------------------------------------------------------
    if ($password === '') {
        $errors['password'] = 'Please choose a password.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Your password must be at least 8 characters long.';
    } elseif (strlen($password) > 1024) {
        // Upper bound guards against very large inputs being hashed.
        $errors['password'] = 'Your password must be 1024 characters or fewer.';
    }

    if ($confirm === '') {
        $errors['password_confirm'] = 'Please re-enter your password.';
    } elseif (!isset($errors['password']) && !hash_equals($password, $confirm)) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }

    // ----- Create the account --------------------------------------------
    if ($errors === []) {
        try {
            // 1) Friendly duplicate check (prepared statement).
            $check = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $check->bind_param('s', $email);
            $check->execute();
            $check->store_result();
            $alreadyRegistered = $check->num_rows > 0;
            $check->close();

            if ($alreadyRegistered) {
                $errors['email'] = 'An account with this email address already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                // 2) Insert. `role` is intentionally absent from this
                //    statement so the database default 'customer' is applied.
                $insert = $conn->prepare(
                    'INSERT INTO users (fullname, email, password_hash) VALUES (?, ?, ?)'
                );
                $insert->bind_param('sss', $fullname, $email, $hash);
                $insert->execute();
                $insert->close();

                flash_set('success', 'Your account has been created. You can now log in.');
                auth_redirect('login.php');
            }
        } catch (mysqli_sql_exception $exception) {
            // 1062 = duplicate entry. Two people registering the same address
            // at the same moment can slip past the check above; the UNIQUE key
            // on users.email is the real guarantee, and this handles the race.
            if ((int) $exception->getCode() === 1062) {
                $errors['email'] = 'An account with this email address already exists.';
            } else {
                error_log('[Hotel Booking System] Registration failed: ' . $exception->getMessage());
                $errors['form'] = 'We could not create your account right now. Please try again shortly.';
            }
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
    <title>Create an Account - Hotel Booking System</title>
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

    <div class="auth-card auth-card-wide">

        <h1>Create your account</h1>
        <p class="auth-intro">
            You need an account to request a booking. It takes a moment, and no
            payment details are ever collected.
        </p>

        <?php echo $flash; ?>

        <?php if (isset($errors['form'])): ?>
            <p class="flash flash-error" role="alert"><?php echo e($errors['form']); ?></p>
        <?php endif; ?>

        <form action="register.php" method="post" novalidate>

        <?php echo csrf_field(); ?>

        <div class="field">
            <label for="fullname">Full name</label>
            <input
                type="text"
                id="fullname"
                name="fullname"
                value="<?php echo e($values['fullname']); ?>"
                autocomplete="name"
                required
                <?php if (isset($errors['fullname'])): ?>
                    aria-invalid="true" aria-describedby="fullname-error"
                <?php endif; ?>
            >
            <?php if (isset($errors['fullname'])): ?>
                <p class="field-error" id="fullname-error" role="alert"><?php echo e($errors['fullname']); ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="email">Email address</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?php echo e($values['email']); ?>"
                autocomplete="email"
                required
                <?php if (isset($errors['email'])): ?>
                    aria-invalid="true" aria-describedby="email-error"
                <?php endif; ?>
            >
            <?php if (isset($errors['email'])): ?>
                <p class="field-error" id="email-error" role="alert"><?php echo e($errors['email']); ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="new-password"
                required
                minlength="8"
                aria-describedby="password-hint<?php echo isset($errors['password']) ? ' password-error' : ''; ?>"
                <?php if (isset($errors['password'])): ?>aria-invalid="true"<?php endif; ?>
            >
            <p class="field-hint" id="password-hint">At least 8 characters.</p>
            <?php if (isset($errors['password'])): ?>
                <p class="field-error" id="password-error" role="alert"><?php echo e($errors['password']); ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="password_confirm">Confirm password</label>
            <input
                type="password"
                id="password_confirm"
                name="password_confirm"
                autocomplete="new-password"
                required
                <?php if (isset($errors['password_confirm'])): ?>
                    aria-invalid="true" aria-describedby="password-confirm-error"
                <?php endif; ?>
            >
            <?php if (isset($errors['password_confirm'])): ?>
                <p class="field-error" id="password-confirm-error" role="alert"><?php echo e($errors['password_confirm']); ?></p>
            <?php endif; ?>
        </div>

        <div class="auth-actions">
            <button type="submit" class="btn btn-primary btn-block">Create account</button>
        </div>

        </form>

        <div class="auth-links">
            <p>
                Already have an account?
                <a href="login.php">Log in</a>
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
