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
require_once __DIR__ . '/admin-filters.php';
require_once __DIR__ . '/booking-status.php';

require_admin();

/** How many bookings to list on one page. */
const ADMIN_RECENT_BOOKING_LIMIT = 50;

/* ---------------------------------------------------------------------------
 *  Search and filter state.
 *
 *  Read from the query string so a filtered view can be bookmarked, shared
 *  between staff and returned to after a Confirm/Cancel action. Every value is
 *  validated inside admin_filters_read() before it is used.
 * ------------------------------------------------------------------------ */
$filters = admin_filters_read($_GET);

$totals = [
    'active_rooms'           => null,
    'customers'              => null,
    'pending_bookings'       => null,
    'confirmed_bookings'     => null,
    'cancellation_requests'  => null,
];

$bookings     = [];
$matchCount   = 0;
$loadError    = '';

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

    // Outstanding cancellation requests. Counted separately so the dashboard
    // can lead with them - a request sitting unreviewed is the one thing on
    // this page that is actually waiting on the administrator.
    $stmt = $conn->prepare('SELECT COUNT(*) FROM bookings WHERE status = ?');
    $awaitingReview = 'cancellation_requested';
    $stmt->bind_param('s', $awaitingReview);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $totals['cancellation_requests'] = (int) $count;
    $stmt->close();

    /* -----------------------------------------------------------------------
     *  Booking list.
     *
     *  The WHERE clause is assembled from fixed SQL fragments only - each one
     *  contains placeholders, never a user value. The values themselves are
     *  collected separately and bound, so nothing typed by an administrator
     *  ever reaches the SQL text.
     *
     *  Searchable fields are the booking reference, the customer's name and
     *  the customer's email address. Email is searched but deliberately NOT
     *  displayed in the table, so an administrator can find a booking from an
     *  address a guest quotes without the dashboard putting every customer's
     *  email on screen. Password hashes are never selected.
     * -------------------------------------------------------------------- */
    $where  = [];
    $params = [];
    $types  = '';

    if ($filters['q'] !== '') {
        $where[] = '(b.booking_reference LIKE ? OR u.fullname LIKE ? OR u.email LIKE ?)';
        $like    = admin_filters_like($filters['q']);
        array_push($params, $like, $like, $like);
        $types  .= 'sss';
    }

    if ($filters['status'] !== '') {
        $where[]  = 'b.status = ?';
        $params[] = $filters['status'];
        $types   .= 's';
    }

    if ($filters['from'] !== '') {
        $where[]  = 'b.check_in >= ?';
        $params[] = $filters['from'];
        $types   .= 's';
    }

    if ($filters['to'] !== '') {
        $where[]  = 'b.check_in <= ?';
        $params[] = $filters['to'];
        $types   .= 's';
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    /* How many bookings match in total, before the display limit is applied.
       This is what lets the page say "showing 50 of 120 matching bookings". */
    $countStmt = $conn->prepare(
        'SELECT COUNT(*)
           FROM bookings b
           JOIN users      u  ON u.id  = b.user_id
           JOIN rooms      r  ON r.id  = b.room_id
           JOIN room_types rt ON rt.id = r.room_type_id'
        . $whereSql
    );

    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $countStmt->bind_result($matched);
    $countStmt->fetch();
    $matchCount = (int) $matched;
    $countStmt->close();

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
           JOIN room_types rt ON rt.id = r.room_type_id'
        . $whereSql
        . ' ORDER BY b.created_at DESC, b.id DESC
            LIMIT ?'
    );

    $listParams = $params;
    $listParams[] = $limit;
    $stmt->bind_param($types . 'i', ...$listParams);
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

/** Format a DATETIME for display (date only - keeps the column narrow). */
function admin_datetime(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    return $date === false ? $value : $date->format('j M Y');
}

/**
 * The hidden inputs that carry the current search and filter state through an
 * action form.
 *
 * Built once rather than repeated inside every form: a booking awaiting
 * cancellation review offers two actions, a pending booking offers two more,
 * and writing four hidden fields out by hand in each of them is exactly how
 * one form quietly ends up missing a filter.
 *
 * Every value is escaped here. They are re-validated server-side by
 * admin_filters_read() before being used, and the redirect path they are
 * appended to is a hard-coded literal, so these can only ever become query
 * parameters - never a destination.
 */
function admin_filter_fields(array $filters): string
{
    $html = '';

    foreach (['q', 'status', 'from', 'to'] as $key) {
        $html .= '<input type="hidden" name="' . $key . '" value="'
               . e((string) ($filters[$key] ?? '')) . '">';
    }

    return $html;
}

$filterFields = admin_filter_fields($filters);

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
        <h2 id="bookings-heading">Bookings</h2>

        <?php /* Outstanding cancellation requests are the only thing on this
                 page genuinely waiting on the administrator, so they are
                 announced above the table with a direct link to the filtered
                 view. Shown only when there is something to review, so it
                 never becomes background noise that staff learn to ignore. */ ?>
        <?php if (!empty($totals['cancellation_requests'])): ?>
            <p class="notice admin-review-notice" role="status">
                <strong>
                    <?php echo e((string) $totals['cancellation_requests']); ?>
                    booking<?php echo $totals['cancellation_requests'] === 1 ? '' : 's'; ?>
                    awaiting cancellation review.
                </strong>
                A customer has asked to cancel and needs an answer.
                <a href="admin-dashboard.php?status=cancellation_requested">
                    Review cancellation request<?php echo $totals['cancellation_requests'] === 1 ? '' : 's'; ?>
                </a>
            </p>
        <?php endif; ?>

        <?php /* Search and filter. A plain GET form: no JavaScript is needed,
                 the resulting view can be bookmarked, and the back button
                 behaves as expected. */ ?>
        <form class="admin-filters" method="get" action="admin-dashboard.php"
              role="search" aria-labelledby="filters-heading">

            <h3 id="filters-heading" class="admin-filters-title">Search and filter</h3>

            <div class="admin-filters-grid">

                <div class="field admin-filter-search">
                    <label for="filter-q">Search</label>
                    <input type="search"
                           id="filter-q"
                           name="q"
                           value="<?php echo e($filters['q']); ?>"
                           maxlength="<?php echo ADMIN_SEARCH_MAX_LENGTH; ?>"
                           aria-describedby="filter-q-hint">
                    <p class="field-hint" id="filter-q-hint">
                        Booking reference, customer name or customer email address.
                    </p>
                </div>

                <div class="field">
                    <label for="filter-status">Status</label>
                    <select id="filter-status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach (ADMIN_FILTER_STATUSES as $statusOption): ?>
                            <option value="<?php echo e($statusOption); ?>"
                                <?php echo $filters['status'] === $statusOption ? 'selected' : ''; ?>>
                                <?php echo e(booking_status_label($statusOption)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="filter-from">Check-in from</label>
                    <input type="date" id="filter-from" name="from"
                           value="<?php echo e($filters['from']); ?>">
                </div>

                <div class="field">
                    <label for="filter-to">Check-in to</label>
                    <input type="date" id="filter-to" name="to"
                           value="<?php echo e($filters['to']); ?>">
                </div>

            </div>

            <div class="admin-filters-actions">
                <button type="submit" class="btn btn-primary">Apply filters</button>
                <?php if ($filters['active']): ?>
                    <a class="btn btn-secondary" href="admin-dashboard.php">Clear filters</a>
                <?php endif; ?>
            </div>
        </form>

        <?php foreach ($filters['notices'] as $notice): ?>
            <p class="notice admin-filter-notice" role="alert"><?php echo e($notice); ?></p>
        <?php endforeach; ?>

        <?php if ($loadError !== ''): ?>

            <p>Bookings could not be listed.</p>

        <?php elseif ($bookings === [] && $filters['active']): ?>

            <?php /* Filtered, but nothing matched - distinct from having no
                     bookings at all, so the administrator knows the filter is
                     the reason and how to undo it. */ ?>
            <p class="notice" role="status">
                <strong>No bookings match your search.</strong>
                Try a different reference, name or email address, or widen the
                status and date filters.
            </p>

            <p><a href="admin-dashboard.php">Clear filters and show all bookings</a></p>

        <?php elseif ($bookings === []): ?>

            <p class="notice">
                <strong>There are no bookings yet.</strong>
                Bookings made by customers will appear here as soon as they are
                submitted.
            </p>

        <?php else: ?>

            <p class="table-intro" role="status">
                <?php if ($filters['active']): ?>
                    Showing <?php echo count($bookings); ?>
                    of <?php echo $matchCount; ?>
                    matching booking<?php echo $matchCount === 1 ? '' : 's'; ?>.
                <?php else: ?>
                    Showing the <?php echo count($bookings); ?> most recent
                    booking<?php echo count($bookings) === 1 ? '' : 's'; ?>
                    of <?php echo $matchCount; ?> in total.
                <?php endif; ?>
                <?php if ($matchCount > count($bookings)): ?>
                    Only the first <?php echo ADMIN_RECENT_BOOKING_LIMIT; ?> are
                    listed &mdash; narrow the search to see the rest.
                <?php endif; ?>
                Prices are in Australian dollars.
            </p>

            <div class="table-scroll">
                <table class="data-table">
                    <caption class="visually-hidden">Bookings, most recently created first</caption>
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Room type</th>
                            <th scope="col">Room</th>
                            <th scope="col">Stay</th>
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
                                <td data-label="Stay"><?php echo e(booking_format_stay($booking['check_in'], $booking['check_out'])); ?></td>
                                <td data-label="Guests"><?php echo e((string) $booking['guests']); ?></td>
                                <td data-label="Total">
                                    <strong>AUD <?php echo e(number_format((float) $booking['total'], 2)); ?></strong>
                                </td>
                                <td data-label="Status">
                                    <span class="status <?php echo e(booking_status_class($booking['status'])); ?>">
                                        <?php echo e(booking_status_label($booking['status'])); ?>
                                    </span>
                                </td>
                                <td data-label="Created"><?php echo e(admin_datetime($booking['created_at'])); ?></td>
                                <td data-label="Actions">
                                    <?php /* One booking shows one set of actions. A booking
                                             awaiting cancellation review shows the review
                                             decision ONLY - not Confirm/Cancel as well - so an
                                             administrator is never offered two different ways to
                                             end the same booking, and cannot cancel a booking out
                                             from under a request that still needs an answer.

                                             The four filter values ride along in every form so
                                             the administrator lands back on the same filtered
                                             view. */ ?>
                                    <?php if ($booking['status'] === 'cancellation_requested'): ?>

                                        <div class="action-group">
                                            <form action="admin-booking-action.php" method="post">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo e((string) $booking['id']); ?>">
                                                <input type="hidden" name="action" value="approve_cancellation">
                                                <?php echo $filterFields; ?>
                                                <button type="submit" class="action-btn action-btn-wrap action-approve-cancel">
                                                    Approve cancellation<span class="visually-hidden"> for booking <?php echo e($booking['reference']); ?></span>
                                                </button>
                                            </form>

                                            <form action="admin-booking-action.php" method="post">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo e((string) $booking['id']); ?>">
                                                <input type="hidden" name="action" value="reject_cancellation">
                                                <?php echo $filterFields; ?>
                                                <button type="submit" class="action-btn action-btn-wrap action-reject-cancel">
                                                    Reject cancellation<span class="visually-hidden"> for booking <?php echo e($booking['reference']); ?></span>
                                                </button>
                                            </form>
                                        </div>

                                    <?php elseif ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>

                                        <div class="action-group">
                                            <?php if ($booking['status'] === 'pending'): ?>
                                                <form action="admin-booking-action.php" method="post">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="booking_id" value="<?php echo e((string) $booking['id']); ?>">
                                                    <input type="hidden" name="action" value="confirm">
                                                    <?php echo $filterFields; ?>
                                                    <button type="submit" class="action-btn action-confirm">
                                                        Confirm<span class="visually-hidden"> booking <?php echo e($booking['reference']); ?></span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form action="admin-booking-action.php" method="post">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo e((string) $booking['id']); ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <?php echo $filterFields; ?>
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
                booking can be cancelled. A booking marked
                <strong>Cancellation requested</strong> is waiting on you:
                approving it cancels the booking and releases the room, while
                rejecting it puts the booking back to whatever it was before
                the customer asked &mdash; pending stays pending, and confirmed
                stays confirmed. Cancelled and completed bookings cannot be
                changed from this page.
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
