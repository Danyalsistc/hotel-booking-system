<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Customer dashboard (shell)
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Protected page. Any logged-in customer may view it; guests are sent to the
 *  login page and administrators are sent to their own dashboard.
 *
 *  This is intentionally a SHELL. Booking history is not displayed because
 *  database-backed bookings do not exist yet - they arrive in Phase 3. No
 *  placeholder bookings or invented statistics are shown.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';

require_login();

// An administrator belongs on the administrator dashboard.
if (auth_is_admin()) {
    auth_redirect('admin-dashboard.php');
}

$flash = flash_render();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - Hotel Booking System</title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>

<a class="skip-link" href="#main-content">Skip to main content</a>

<header class="dash-header">
    <div class="dash-header-inner">
        <p class="dash-brand">Hotel Booking System</p>

        <nav class="dash-nav" aria-label="Dashboard">
            <ul>
                <li><a href="index.html">Home</a></li>
                <li><a href="booknow.html">Book a Room</a></li>
                <li>
                    <form action="logout.php" method="post" class="logout-form">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="logout-btn">Log out</button>
                    </form>
                </li>
            </ul>
        </nav>
    </div>
</header>

<main id="main-content" class="dash-main">

    <?php echo $flash; ?>

    <h1>Welcome, <?php echo e(auth_user_name()); ?></h1>
    <p class="dash-subtitle">You are signed in as a customer.</p>

    <section class="panel" aria-labelledby="bookings-heading">
        <h2 id="bookings-heading">My Bookings</h2>

        <p class="notice">
            <strong>Not yet available.</strong>
            Booking history will be implemented in Phase&nbsp;3, once bookings
            are stored in the database. Nothing is shown here yet because there
            is no booking data to display.
        </p>

        <p>
            The booking form is currently still saving to your browser only, so
            any booking you make will not appear on this page.
        </p>
    </section>

    <section class="panel" aria-labelledby="actions-heading">
        <h2 id="actions-heading">Where to next</h2>

        <ul class="link-list">
            <li><a href="index.html">Browse rooms on the home page</a></li>
            <li><a href="booknow.html">Go to the booking form</a></li>
        </ul>
    </section>

</main>

<footer class="dash-footer">
    <p>&copy; 2025 Hotel Booking System. All Rights Reserved.</p>
</footer>

</body>
</html>
