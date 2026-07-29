<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Administrator dashboard
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Protected by require_admin(). Every figure on this page is calculated by a
 *  query against MySQL - nothing is hard-coded, and nothing is read from
 *  localStorage.
 *
 *  Customer email addresses are deliberately NOT displayed. The customer's
 *  name is enough to identify a booking on screen, and password hashes are
 *  never selected at all.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_admin();

/** How many bookings to list. */
const ADMIN_RECENT_BOOKING_LIMIT = 50;

$totals = [
    'active_rooms'       => null,
    'customers'          => null,
    'pending_bookings'   => null,
    'confirmed_bookings' => null,
];

$bookings  = [];
$loadError = '';

try {
    /* -----------------------------------------------------------------------
     *  Live totals. Each is a COUNT against the database.
     * -------------------------------------------------------------------- */

    // Active physical rooms
    $stmt = $conn->prepare('SELECT COUNT(*) FROM rooms WHERE status = ?');
    $available = 'available';
    $stmt->bind_param('s', $available);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $totals['active_rooms'] = (int) $count;
    $stmt->close();

    // Registered customers
    $stmt = $conn->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
    $customerRole = 'customer';
    $stmt->bind_param('s', $customerRole);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $totals['customers'] = (int) $count;
    $stmt->close();

    // Pending bookings
    $stmt = $conn->prepare('SELECT COUNT(*) FROM bookings WHERE status = ?');
    $pending = 'pending';
    $stmt->bind_param('s', $pending);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $totals['pending_bookings'] = (int) $count;
    $stmt->close();

    // Confirmed bookings
    $stmt = $conn->prepare('SELECT COUNT(*) FROM bookings WHERE status = ?');
    $confirmed = 'confirmed';
    $stmt->bind_param('s', $confirmed);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $totals['confirmed_bookings'] = (int) $count;
    $stmt->close();

    /* -----------------------------------------------------------------------
     *  Recent bookings. Password hashes and email addresses are not selected.
     * -------------------------------------------------------------------- */
    $limit = ADMIN_RECENT_BOOKING_LIMIT;

    $stmt = $conn->prepare(
        'SELECT b.id,
                b.booking_reference,
                u.fullname,
                rt.name  AS room_type,
                r.room_number,
                b.check_in,
                b.check_out,
                b.guest_count,
                b.total_price,
                b.status,
                b.created_at
           FROM bookings b
           JOIN users      u  ON u.id  = b.user_id
           JOIN rooms      r  ON r.id  = b.room_id
           JOIN room_types rt ON rt.id = r.room_type_id
       ORDER BY b.created_at DESC, b.id DESC
          LIMIT ?'
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $stmt->bind_result(
        $id, $reference, $customerName, $roomType, $roomNumber,
        $checkIn, $checkOut, $guests, $total, $status, $createdAt
    );

    while ($stmt->fetch()) {
        $bookings[] = [
            'id'          => (int) $id,
            'reference'   => (string) $reference,
            'customer'    => (string) $customerName,
            'room_type'   => (string) $roomType,
            'room_number' => (string) $roomNumber,
            'check_in'    => (string) $checkIn,
            'check_out'   => (string) $checkOut,
            'guests'      => (int) $guests,
            'total'       => (string) $total,
            'status'      => (string) $status,
            'created_at'  => (string) $createdAt,
        ];
    }

    $stmt->close();

} catch (mysqli_sql_exception $exception) {
    error_log('[Hotel Booking System] Admin dashboard load failed: ' . $exception->getMessage());
    $loadError = 'Dashboard data could not be loaded right now. Please try again shortly.';
}

/** Format a Y-m-d date for display. */
function admin_date(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

    return $date === false ? $value : $date->format('j M Y');
}

/** Format a DATETIME for display. */
function admin_datetime(string $value): string
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
    <title>Administrator Dashboard - Hotel Booking System</title>
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
            <span class="dash-badge">Admin</span>
        </a>

        <nav class="site-nav" aria-label="Main">
            <ul>
                <li><a href="index.html">Home</a></li>
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
        <h1>Administrator dashboard</h1>
        <p class="dash-subtitle">
            Signed in as <?php echo e(auth_user_name()); ?> (administrator).
        </p>
    </div>

    <?php if ($loadError !== ''): ?>
        <p class="flash flash-error" role="alert"><?php echo e($loadError); ?></p>
    <?php endif; ?>

    <section aria-labelledby="totals-heading">
        <h2 id="totals-heading" class="visually-hidden">Totals</h2>

        <div class="cards">
            <div class="card">
                <h3>Active Rooms</h3>
                <p class="card-number"><?php echo $totals['active_rooms'] === null ? '-' : e((string) $totals['active_rooms']); ?></p>
                <p class="card-note">Physical rooms in service</p>
            </div>

            <div class="card">
                <h3>Customers</h3>
                <p class="card-number"><?php echo $totals['customers'] === null ? '-' : e((string) $totals['customers']); ?></p>
                <p class="card-note">Registered accounts</p>
            </div>

            <div class="card">
                <h3>Pending Bookings</h3>
                <p class="card-number"><?php echo $totals['pending_bookings'] === null ? '-' : e((string) $totals['pending_bookings']); ?></p>
                <p class="card-note">Awaiting confirmation</p>
            </div>

            <div class="card">
                <h3>Confirmed Bookings</h3>
                <p class="card-number"><?php echo $totals['confirmed_bookings'] === null ? '-' : e((string) $totals['confirmed_bookings']); ?></p>
                <p class="card-note">Confirmed stays</p>
            </div>
        </div>

        <p class="field-hint">
            All four figures are counted from the database each time this page
            loads.
        </p>
    </section>

    <section class="panel" aria-labelledby="bookings-heading">
        <h2 id="bookings-heading">Recent Bookings</h2>

        <?php if ($loadError !== ''): ?>

            <p>Bookings could not be listed.</p>

        <?php elseif ($bookings === []): ?>

            <p class="notice">
                <strong>There are no bookings yet.</strong>
                Bookings made by customers will appear here as soon as they are
                submitted.
            </p>

        <?php else: ?>

            <p class="table-intro">
                Showing the <?php echo count($bookings); ?> most recent
                booking<?php echo count($bookings) === 1 ? '' : 's'; ?>
                (maximum <?php echo ADMIN_RECENT_BOOKING_LIMIT; ?>).
                Prices are in Australian dollars.
            </p>

            <div class="table-scroll">
                <table class="data-table">
                    <caption class="visually-hidden">Recent bookings</caption>
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Room type</th>
                            <th scope="col">Room</th>
                            <th scope="col">Check-in</th>
                            <th scope="col">Check-out</th>
                            <th scope="col">Guests</th>
                            <th scope="col">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td data-label="Reference"><code><?php echo e($booking['reference']); ?></code></td>
                                <td data-label="Customer"><?php echo e($booking['customer']); ?></td>
                                <td data-label="Room type"><?php echo e($booking['room_type']); ?></td>
                                <td data-label="Room"><?php echo e($booking['room_number']); ?></td>
                                <td data-label="Check-in"><?php echo e(admin_date($booking['check_in'])); ?></td>
                                <td data-label="Check-out"><?php echo e(admin_date($booking['check_out'])); ?></td>
                                <td data-label="Guests"><?php echo e((string) $booking['guests']); ?></td>
                                <td data-label="Total">
                                    <strong>AUD <?php echo e(number_format((float) $booking['total'], 2)); ?></strong>
                                </td>
                                <td data-label="Status">
                                    <span class="status status-<?php echo e($booking['status']); ?>">
                                        <?php echo e(ucfirst($booking['status'])); ?>
                                    </span>
                                </td>
                                <td data-label="Created"><?php echo e(admin_datetime($booking['created_at'])); ?></td>
                                <td data-label="Actions">
                                    <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>

                                        <div class="action-group">
                                            <?php if ($booking['status'] === 'pending'): ?>
                                                <form action="admin-booking-action.php" method="post">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="booking_id" value="<?php echo e((string) $booking['id']); ?>">
                                                    <input type="hidden" name="action" value="confirm">
                                                    <button type="submit" class="action-btn action-confirm">
                                                        Confirm<span class="visually-hidden"> booking <?php echo e($booking['reference']); ?></span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form action="admin-booking-action.php" method="post">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo e((string) $booking['id']); ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <button type="submit" class="action-btn action-cancel">
                                                    Cancel<span class="visually-hidden"> booking <?php echo e($booking['reference']); ?></span>
                                                </button>
                                            </form>
                                        </div>

                                    <?php else: ?>
                                        <span class="field-hint">No actions</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="field-hint">
                A pending booking can be confirmed or cancelled. A confirmed
                booking can be cancelled. Cancelled and completed bookings
                cannot be changed from this page.
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
