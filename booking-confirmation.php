<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Booking confirmation
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Shown once, immediately after booknow.php commits a booking, so the
 *  customer can see exactly what was requested. It is read-only: it creates,
 *  updates and deletes nothing, and it never changes a booking's status.
 *
 *  OWNERSHIP
 *  The booking reference arrives in the query string, so it must be treated as
 *  untrusted. The lookup is filtered by BOTH the reference AND the signed-in
 *  user's id, taken from the session:
 *
 *      WHERE b.booking_reference = ? AND b.user_id = ?
 *
 *  A customer who edits the URL to another customer's reference therefore
 *  matches zero rows and is sent back to their own dashboard - the page cannot
 *  be used to read somebody else's booking. The reference itself is also
 *  unguessable (random_bytes), but that is defence in depth, not the control.
 *
 *  No internal database id is ever placed in the URL or in the page.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/booking-status.php';

require_login();

// Administrators manage bookings rather than place them, and this page shows a
// customer their own booking. Keep the same rule booknow.php uses.
if (auth_is_admin()) {
    auth_redirect('admin-dashboard.php');
}

$userId = (int) auth_user_id();

/* ---------------------------------------------------------------------------
 *  Read and shape-check the reference before it goes near the database.
 *  Generated references look like HBS-20260730-A1B2C3D4.
 * ------------------------------------------------------------------------ */
$reference = isset($_GET['ref']) && is_string($_GET['ref']) ? trim($_GET['ref']) : '';

if ($reference === '' || preg_match('/^[A-Za-z0-9-]{1,32}$/', $reference) !== 1) {
    flash_set('error', 'That booking could not be found.');
    auth_redirect('customer-dashboard.php');
}

$booking = null;

try {
    $stmt = $conn->prepare(
        'SELECT b.booking_reference,
                rt.name        AS room_type,
                b.check_in,
                b.check_out,
                b.number_of_nights,
                b.guest_count,
                b.nightly_rate,
                b.total_price,
                b.status,
                b.created_at
           FROM bookings b
           JOIN rooms      r  ON r.id  = b.room_id
           JOIN room_types rt ON rt.id = r.room_type_id
          WHERE b.booking_reference = ?
            AND b.user_id = ?
          LIMIT 1'
    );

    $stmt->bind_param('si', $reference, $userId);
    $stmt->execute();
    $stmt->bind_result(
        $bRef, $bRoomType, $bCheckIn, $bCheckOut, $bNights,
        $bGuests, $bRate, $bTotal, $bStatus, $bCreated
    );

    if ($stmt->fetch()) {
        $booking = [
            'reference'  => (string) $bRef,
            'room_type'  => (string) $bRoomType,
            'check_in'   => (string) $bCheckIn,
            'check_out'  => (string) $bCheckOut,
            'nights'     => (int) $bNights,
            'guests'     => (int) $bGuests,
            'rate'       => (string) $bRate,
            'total'      => (string) $bTotal,
            'status'     => (string) $bStatus,
            'created_at' => (string) $bCreated,
        ];
    }

    $stmt->close();

} catch (mysqli_sql_exception $exception) {
    error_log('[Hotel Booking System] Booking confirmation lookup failed: ' . $exception->getMessage());
    flash_set('error', 'We could not load that booking right now. Please try again shortly.');
    auth_redirect('customer-dashboard.php');
}

// No row means either the reference does not exist or it belongs to somebody
// else. Both are answered identically, so this page cannot be used to discover
// whether a reference exists.
if ($booking === null) {
    flash_set('error', 'That booking could not be found.');
    auth_redirect('customer-dashboard.php');
}

/** Format a Y-m-d date for display. */
function confirm_date(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

    return $date === false ? $value : $date->format('D j M Y');
}

/** Format a DATETIME for display. */
function confirm_datetime(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    return $date === false ? $value : $date->format('j M Y, g:ia');
}

$flash = flash_render();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Received - Hotel Booking System</title>
    <link rel="stylesheet" href="theme.css">
    <link rel="stylesheet" href="dashboard.css">
</head>
<body class="dash-page">

<a class="skip-link" href="#main-content">Skip to main content</a>

<header class="site-header">
    <div class="container site-header-inner">
        <a class="brand" href="index.html">
            <span class="brand-mark" aria-hidden="true">HB</span>
            <span>Hotel Booking System</span>
        </a>

        <nav class="site-nav" aria-label="Main">
            <ul>
                <li><a href="index.html">Home</a></li>
                <li><a href="index.html#rooms">Rooms</a></li>
                <li><a href="customer-dashboard.php">My Bookings</a></li>
                <li>
                    <form action="logout.php" method="post">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-sm btn-ghost-on-dark">Log out</button>
                    </form>
                </li>
            </ul>
        </nav>
    </div>
</header>

<main id="main-content" class="dash-main">
  <div class="container">

    <?php echo $flash; ?>

    <div class="dash-heading">
        <h1>Booking request received</h1>
        <p class="dash-subtitle">
            Thank you, <?php echo e(auth_user_name()); ?>. Your request has been
            saved and is now waiting for our staff to review it.
        </p>
    </div>

    <section class="panel confirmation-panel" aria-labelledby="confirmation-heading">

        <h2 id="confirmation-heading">Your booking</h2>

        <p class="confirmation-reference">
            <span class="confirmation-reference-label">Booking reference</span>
            <code class="confirmation-reference-value"><?php echo e($booking['reference']); ?></code>
        </p>

        <p class="field-hint">
            Please keep this reference. You will need it if you contact the
            hotel about this booking.
        </p>

        <dl class="confirmation-list">
            <div class="summary-row">
                <dt>Room type</dt>
                <dd><?php echo e($booking['room_type']); ?></dd>
            </div>
            <div class="summary-row">
                <dt>Check-in</dt>
                <dd><?php echo e(confirm_date($booking['check_in'])); ?></dd>
            </div>
            <div class="summary-row">
                <dt>Check-out</dt>
                <dd><?php echo e(confirm_date($booking['check_out'])); ?></dd>
            </div>
            <div class="summary-row">
                <dt>Nights</dt>
                <dd><?php echo e((string) $booking['nights']); ?></dd>
            </div>
            <div class="summary-row">
                <dt>Guests</dt>
                <dd><?php echo e((string) $booking['guests']); ?></dd>
            </div>
            <div class="summary-row">
                <dt>Nightly rate</dt>
                <dd>AUD <?php echo e(number_format((float) $booking['rate'], 2)); ?></dd>
            </div>
            <div class="summary-row summary-row-total">
                <dt>Total price</dt>
                <dd>AUD <?php echo e(number_format((float) $booking['total'], 2)); ?></dd>
            </div>
            <div class="summary-row">
                <dt>Status</dt>
                <dd>
                    <span class="status <?php echo e(booking_status_class($booking['status'])); ?>">
                        <?php echo e(booking_status_label($booking['status'])); ?>
                    </span>
                </dd>
            </div>
            <div class="summary-row">
                <dt>Requested on</dt>
                <dd><?php echo e(confirm_datetime($booking['created_at'])); ?></dd>
            </div>
        </dl>

        <?php /* This page is reachable by reference at any time, not only in
                 the moment after booking, so the status shown above may have
                 moved on since. The wording follows the status rather than
                 assuming "pending" - a cancelled booking previously read
                 "your booking is pending", which contradicted its own badge.

                 No cancellation controls live here. They stay on the dashboard,
                 which is the page that lists every booking and is where a
                 customer goes to manage them; duplicating them here would mean
                 two places to keep in step for no benefit. */ ?>
        <p class="notice confirmation-next">
            <strong>What happens next.</strong>
            <?php if ($booking['status'] === 'pending'): ?>
                Your booking is <strong>pending</strong>. It is not confirmed
                until a member of our staff reviews and approves it, and you
                will see the status change on your dashboard when they do.
            <?php elseif ($booking['status'] === 'confirmed'): ?>
                Your booking is <strong>confirmed</strong>. Our staff have
                approved it and your room is held for the dates shown above.
            <?php elseif ($booking['status'] === 'cancellation_requested'): ?>
                You have asked us to cancel this booking and the request is
                <strong>waiting for staff review</strong>. It is not cancelled
                yet, and your room is still held until our staff reply. You can
                follow the outcome on your bookings page.
            <?php elseif ($booking['status'] === 'cancelled'): ?>
                This booking has been <strong>cancelled</strong> and no longer
                holds a room. It is kept here for your records.
            <?php elseif ($booking['status'] === 'completed'): ?>
                This stay is <strong>completed</strong>. It is kept here for
                your records.
            <?php else: ?>
                You can check the current state of this booking on your
                bookings page.
            <?php endif; ?>
            No payment has been taken and no card details were collected.
        </p>

        <div class="confirmation-actions">
            <a class="btn btn-primary" href="customer-dashboard.php">View all my bookings</a>
            <a class="btn btn-secondary" href="index.html#rooms">Browse more rooms</a>
        </div>

    </section>

  </div>
</main>

<footer class="site-footer">
    <div class="container">
        <p class="footer-bottom">
            &copy; 2026 Hotel Booking System &mdash; student coursework. All prices in AUD.
        </p>
    </div>
</footer>

</body>
</html>
