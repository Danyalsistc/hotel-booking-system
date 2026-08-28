<?php
declare(strict_types=1);

/**
 * Customer cancellation request. POST only, signed-in customer and CSRF token
 * required.
 *
 * A customer cannot cancel a booking. This endpoint only moves it into
 * 'cancellation_requested' for staff to review; only admin-booking-action.php,
 * behind require_admin(), can write 'cancelled'.
 *
 * Permitted: pending or confirmed -> cancellation_requested, recording the old
 * status in previous_status. Anything else changes zero rows.
 *
 * The booking is identified by reference, not primary key, so no internal row
 * ID reaches the page. The reference is not authority on its own - every
 * statement is also scoped to user_id from the session.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/booking-status.php';

require_login();

// An administrator manages bookings from the administrator dashboard; this
// customer-facing endpoint is not the route for that.
if (auth_is_admin()) {
    auth_redirect('admin-dashboard.php');
}

// A status change must never happen on a GET request.

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {

    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="en-AU"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Action not allowed - Hotel Booking System</title>'
       . '<link rel="stylesheet" href="theme.css"><link rel="stylesheet" href="css.css">'
       . '</head><body class="auth-page"><main id="main-content" class="auth-main">'
       . '<div class="auth-card legacy-notice"><h1>Action not allowed</h1>'
       . '<p>A cancellation request must be submitted from your bookings page, '
       . 'not opened as a link.</p>'
       . '<a class="btn btn-primary btn-block" href="customer-dashboard.php">'
       . 'Return to my bookings</a>'
       . '</div></main></body></html>';

    exit;
}

csrf_require();

$userId = (int) auth_user_id();

// Shape-check the reference. The value is bound as a parameter regardless,
// but this keeps obviously malformed input out of the query.

$reference = isset($_POST['reference']) && is_string($_POST['reference'])
    ? trim($_POST['reference'])
    : '';

if ($reference === '' || preg_match('/^[A-Za-z0-9-]{1,32}$/', $reference) !== 1) {
    flash_set('error', 'That booking could not be identified.');
    auth_redirect('customer-dashboard.php');
}

/* One guarded UPDATE does the whole job: ownership, eligibility and
   duplicate-prevention all live in the WHERE clause, so there is no
   read-then-write gap between the check and the write.

   SET order is deliberate - MySQL evaluates the list left to right, so
   previous_status captures the OLD status before status is overwritten. */
$requested = 'cancellation_requested';
$fromA     = BOOKING_CANCELLABLE_STATUSES[0];   // pending
$fromB     = BOOKING_CANCELLABLE_STATUSES[1];   // confirmed

try {
    $stmt = $conn->prepare(
        'UPDATE bookings
            SET previous_status = status,
                status          = ?
          WHERE booking_reference = ?
            AND user_id           = ?
            AND status IN (?, ?)'
    );

    $stmt->bind_param('ssiss', $requested, $reference, $userId, $fromA, $fromB);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed === 1) {
        flash_set(
            'success',
            'Cancellation requested for booking ' . $reference . '. '
                . 'Our staff will review it and you will see the result here.'
        );

        auth_redirect('customer-dashboard.php');
    }

    /* Nothing changed - work out why so the message is useful. This read is
       also scoped to user_id, so probing another customer's reference gives
       the same answer as a reference that does not exist. */
    $currentStatus = null;

    $check = $conn->prepare(
        'SELECT status FROM bookings
          WHERE booking_reference = ? AND user_id = ? LIMIT 1'
    );

    $check->bind_param('si', $reference, $userId);
    $check->execute();
    $check->bind_result($fetchedStatus);

    if ($check->fetch()) {
        $currentStatus = (string) $fetchedStatus;
    }

    $check->close();

    if ($currentStatus === null) {
        flash_set('error', 'We could not find that booking on your account.');

    } elseif ($currentStatus === 'cancellation_requested') {
        flash_set(
            'error',
            'A cancellation request for booking ' . $reference
                . ' has already been sent and is waiting for staff review.'
        );

    } else {
        flash_set(
            'error',
            'Booking ' . $reference . ' cannot be cancelled because it is '
                . strtolower(booking_status_label($currentStatus)) . '.'
        );
    }

} catch (mysqli_sql_exception $exception) {
    error_log('[Hotel Booking System] Cancellation request failed: ' . $exception->getMessage());
    flash_set('error', 'Your request could not be sent right now. Please try again shortly.');
}

// Redirect-after-POST: refreshing the dashboard cannot repeat the request.
auth_redirect('customer-dashboard.php');
