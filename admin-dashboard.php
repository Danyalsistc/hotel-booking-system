<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Administrator dashboard (shell)
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Protected page. require_admin() sends guests to the login page and
 *  logged-in customers back to their own dashboard, so this page is reachable
 *  only by an account whose users.role is 'admin'.
 *
 *  This is intentionally a SHELL:
 *    - No hotel, room, user or booking totals are shown. The old static page
 *      displayed invented figures (12 hotels, 150 rooms, 85 bookings, 210
 *      users); presenting made-up numbers as real data would be misleading.
 *    - Nothing is read from localStorage.
 *    - No booking-management actions exist yet. Both arrive in Phase 3.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';

require_admin();

$flash = flash_render();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Dashboard - Hotel Booking System</title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>

<a class="skip-link" href="#main-content">Skip to main content</a>

<header class="dash-header">
    <div class="dash-header-inner">
        <p class="dash-brand">Hotel Booking System <span class="dash-badge">Admin</span></p>

        <nav class="dash-nav" aria-label="Dashboard">
            <ul>
                <li><a href="index.html">Home</a></li>
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

    <h1>Administrator Dashboard</h1>
    <p class="dash-subtitle">
        Signed in as <?php echo e(auth_user_name()); ?> (administrator).
    </p>

    <section class="panel" aria-labelledby="management-heading">
        <h2 id="management-heading">Booking Management</h2>

        <p class="notice">
            <strong>Not yet available.</strong>
            Live booking management will be implemented in Phase&nbsp;3, once
            bookings are stored in the database.
        </p>

        <p>
            No figures are shown on this page. The previous version of this
            dashboard displayed fixed sample totals that were not read from any
            data source; those have been removed rather than left in place as
            if they were real.
        </p>
    </section>

    <section class="panel" aria-labelledby="planned-heading">
        <h2 id="planned-heading">Planned for Phase 3</h2>

        <ul class="link-list plain">
            <li>Live booking list read from the <code>bookings</code> table</li>
            <li>Confirm and cancel actions for individual bookings</li>
            <li>Counts calculated from the database rather than hard-coded</li>
            <li>Room and room-type management</li>
        </ul>
    </section>

</main>

<footer class="dash-footer">
    <p>&copy; 2025 Hotel Booking System. All Rights Reserved.</p>
</footer>

</body>
</html>
