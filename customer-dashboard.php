<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Customer dashboard
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Shows the signed-in customer's own bookings, read from MySQL.
 *
 *  Ownership comes from the SESSION, never from the URL. The query filters on
 *  bookings.user_id = auth_user_id(), so there is no booking ID or customer ID
 *  a visitor could tamper with to see somebody else's reservations.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_login();

// An administrator belongs on the administrator dashboard.
if (auth_is_admin()) {
    auth_redirect('admin-dashboard.php');
}

$userId   = (int) auth_user_id();
$bookings = [];
$loadError = '';

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
          WHERE b.user_id = ?
       ORDER BY b.created_at DESC, b.id DESC'
    );

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result(
        $reference, $roomType, $checkIn, $checkOut, $nights,
        $guests, $rate, $total, $status, $createdAt
    );

    while ($stmt->fetch()) {
        $bookings[] = [
            'reference'  => (string) $reference,
            'room_type'  => (string) $roomType,
            'check_in'   => (string) $checkIn,
            'check_out'  => (string) $checkOut,
            'nights'     => (int) $nights,
            'guests'     => (int) $guests,
            'rate'       => (string) $rate,
            'total'      => (string) $total,
            'status'     => (string) $status,
            'created_at' => (string) $createdAt,
        ];
    }

    $stmt->close();

} catch (mysqli_sql_exception $exception) {
    error_log('[Hotel Booking System] Customer booking list failed: ' . $exception->getMessage());
    $loadError = 'We could not load your bookings right now. Please try again shortly.';
}

/** Format a Y-m-d date for display, falling back to the raw value. */
function dash_date(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

    return $date === false ? $value : $date->format('D j M Y');
}

/** Format a DATETIME for display. */
function dash_datetime(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    return $date === false ? $value : $date->format('j M Y, g:ia');
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
                <li><a href="booknow.php">Book a Room</a></li>
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

        <?php if ($loadError !== ''): ?>

            <p class="flash flash-error" role="alert"><?php echo e($loadError); ?></p>

        <?php elseif ($bookings === []): ?>

            <p class="notice">
                <strong>You have no bookings yet.</strong>
                When you make a booking it will appear here straight away.
            </p>

            <p><a href="booknow.php">Book a room now</a></p>

        <?php else: ?>

            <p class="table-intro">
                Showing <?php echo count($bookings); ?>
                booking<?php echo count($bookings) === 1 ? '' : 's'; ?>.
                All prices are in Australian dollars.
            </p>

            <div class="table-scroll">
                <table class="data-table">
                    <caption class="visually-hidden">Your bookings</caption>
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Room type</th>
                            <th scope="col">Check-in</th>
                            <th scope="col">Check-out</th>
                            <th scope="col">Nights</th>
                            <th scope="col">Guests</th>
                            <th scope="col">Rate/night</th>
                            <th scope="col">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col">Booked on</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td data-label="Reference">
                                    <code><?php echo e($booking['reference']); ?></code>
                                </td>
                                <td data-label="Room type"><?php echo e($booking['room_type']); ?></td>
                                <td data-label="Check-in"><?php echo e(dash_date($booking['check_in'])); ?></td>
                                <td data-label="Check-out"><?php echo e(dash_date($booking['check_out'])); ?></td>
                                <td data-label="Nights"><?php echo e((string) $booking['nights']); ?></td>
                                <td data-label="Guests"><?php echo e((string) $booking['guests']); ?></td>
                                <td data-label="Rate/night">
                                    AUD <?php echo e(number_format((float) $booking['rate'], 2)); ?>
                                </td>
                                <td data-label="Total">
                                    <strong>AUD <?php echo e(number_format((float) $booking['total'], 2)); ?></strong>
                                </td>
                                <td data-label="Status">
                                    <span class="status status-<?php echo e($booking['status']); ?>">
                                        <?php echo e(ucfirst($booking['status'])); ?>
                                    </span>
                                </td>
                                <td data-label="Booked on"><?php echo e(dash_datetime($booking['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="field-hint">
                A <strong>pending</strong> booking has been received and is
                waiting for our staff to confirm it. To change or cancel a
                booking, please contact the hotel.
            </p>

        <?php endif; ?>
    </section>

</main>

<footer class="dash-footer">
    <p>&copy; 2025 Hotel Booking System. All Rights Reserved.</p>
</footer>

</body>
</html>
