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
require_once __DIR__ . '/booking-lib.php';
require_once __DIR__ . '/booking-status.php';

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

/** Format a DATETIME for display. */
function dash_datetime(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    return $date === false ? $value : $date->format('j M Y, g:ia');
}

/**
 * Build the guidance shown beneath the bookings table, from the statuses that
 * are actually present in this customer's list.
 *
 * This replaces a hard-coded sentence that always described bookings as
 * pending and awaiting staff confirmation, which contradicted the Confirmed,
 * Cancelled and Completed badges shown in the very same table.
 *
 * One clause is produced per status that is genuinely present, with correct
 * singular/plural agreement, so a mixed list reads correctly too. Cancelled
 * and completed bookings are never described as pending.
 *
 * @param  array<int, array{status: string}> $bookings
 * @return array<int, string> Plain-text sentences; the caller escapes them.
 */
function dash_status_guidance(array $bookings): array
{
    // Keys are in the order the sentences should read, which is why this is
    // built from BOOKING_STATUSES rather than written out again.
    $counts = array_fill_keys(BOOKING_STATUSES, 0);

    foreach ($bookings as $booking) {
        $status = (string) ($booking['status'] ?? '');

        if (array_key_exists($status, $counts)) {
            $counts[$status]++;
        }
    }

    // [singular clause, plural clause] for each status.
    $wording = [
        'pending' => [
            'One booking is pending: it has been received and is waiting for our staff to confirm it.',
            '%d bookings are pending: they have been received and are waiting for our staff to confirm them.',
        ],
        'confirmed' => [
            'One booking is confirmed: it has been approved by our staff.',
            '%d bookings are confirmed: they have been approved by our staff.',
        ],
        'cancellation_requested' => [
            'One booking has a cancellation request waiting for our staff to review. '
                . 'It is not cancelled yet, and it still holds your room until staff decide.',
            '%d bookings have cancellation requests waiting for our staff to review. '
                . 'They are not cancelled yet, and they still hold your rooms until staff decide.',
        ],
        'cancelled' => [
            'One booking has been cancelled and no longer holds a room.',
            '%d bookings have been cancelled and no longer hold a room.',
        ],
        'completed' => [
            'One booking is completed: that stay has finished.',
            '%d bookings are completed: those stays have finished.',
        ],
    ];

    $sentences = [];

    foreach ($counts as $status => $count) {
        if ($count === 0) {
            continue;
        }

        $sentences[] = $count === 1
            ? $wording[$status][0]
            : sprintf($wording[$status][1], $count);
    }

    return $sentences;
}

$flash = flash_render();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - Hotel Booking System</title>
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
                <li><a href="booknow.php">Book a Room</a></li>
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
        <h1>Welcome, <?php echo e(auth_user_name()); ?></h1>
        <p class="dash-subtitle">You are signed in as a customer.</p>
    </div>

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
                            <th scope="col">Stay</th>
                            <th scope="col">Nights</th>
                            <th scope="col">Guests</th>
                            <th scope="col">Rate/night</th>
                            <th scope="col">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col">Booked on</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td data-label="Reference">
                                    <code><?php echo e($booking['reference']); ?></code>
                                </td>
                                <td data-label="Room type"><?php echo e($booking['room_type']); ?></td>
                                <?php /* Check-in and check-out share one cell, as they do on the
                                         administrator dashboard. Two ~100px date columns plus the
                                         new Actions column pushed this table 252px wider than its
                                         container at 1440px, hiding the very button the column was
                                         added for. Both dates are still shown in full. */ ?>
                                <td data-label="Stay"><?php echo e(booking_format_stay($booking['check_in'], $booking['check_out'])); ?></td>
                                <td data-label="Nights"><?php echo e((string) $booking['nights']); ?></td>
                                <td data-label="Guests"><?php echo e((string) $booking['guests']); ?></td>
                                <td data-label="Rate/night">
                                    AUD <?php echo e(number_format((float) $booking['rate'], 2)); ?>
                                </td>
                                <td data-label="Total">
                                    <strong>AUD <?php echo e(number_format((float) $booking['total'], 2)); ?></strong>
                                </td>
                                <td data-label="Status">
                                    <span class="status <?php echo e(booking_status_class($booking['status'])); ?>">
                                        <?php echo e(booking_status_label($booking['status'])); ?>
                                    </span>

                                    <?php if ($booking['status'] === 'cancellation_requested'): ?>
                                        <?php /* Text, not colour alone: the badge and this
                                                 sentence both say what is happening, so the
                                                 state is clear without relying on being able
                                                 to distinguish the badge colours. */ ?>
                                        <span class="status-note">
                                            Waiting for staff to review your cancellation request.
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Booked on"><?php echo e(dash_datetime($booking['created_at'])); ?></td>
                                <td data-label="Actions">
                                    <?php if (booking_status_can_request_cancellation($booking['status'])): ?>

                                        <?php /* The booking is identified by its reference, not
                                                 by a database row ID. The server re-checks both
                                                 ownership and eligibility before changing
                                                 anything - this button being present is a
                                                 convenience, never the authority. */ ?>
                                        <form action="customer-cancellation-request.php" method="post">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="reference" value="<?php echo e($booking['reference']); ?>">
                                            <button type="submit" class="action-btn action-btn-wrap action-cancel">
                                                Request cancellation<span class="visually-hidden"> for booking <?php echo e($booking['reference']); ?></span>
                                            </button>
                                        </form>

                                    <?php elseif ($booking['status'] === 'cancellation_requested'): ?>
                                        <span class="field-hint">Request sent</span>
                                    <?php else: ?>
                                        <span class="field-hint">No actions</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php /* Guidance derived from the statuses actually present above. */ ?>
            <p class="field-hint">
                <?php foreach (dash_status_guidance($bookings) as $sentence): ?>
                    <?php echo e($sentence); ?>
                <?php endforeach; ?>
                To cancel a booking, use <strong>Request cancellation</strong>.
                That sends your request to our staff for review rather than
                cancelling immediately, so your room is held until they reply.
                To change the dates or details of a booking, please contact the
                hotel.
            </p>

        <?php endif; ?>
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
