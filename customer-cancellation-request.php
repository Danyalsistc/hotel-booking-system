<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Customer cancellation request
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  POST ONLY. Requires a signed-in customer and a valid CSRF token.
 *
 *  A customer CANNOT cancel a booking. This endpoint only ever moves a booking
 *  into 'cancellation_requested', which is a request for staff to review. The
 *  only code in the system that can write 'cancelled' is
 *  admin-booking-action.php, behind require_admin().
 *
 *  Permitted transition:
 *      pending   -> cancellation_requested   (previous_status = 'pending')
 *      confirmed -> cancellation_requested   (previous_status = 'confirmed')
 *
 *  Everything else - a second request against the same booking, a cancelled or
 *  completed booking, or a booking belonging to somebody else - changes zero
 *  rows and is reported as rejected.
 *
 *  The booking is identified by its REFERENCE rather than its primary key, so
 *  no internal row ID is put into the page. The reference alone is not
 *  authority: every statement below is additionally scoped to
 *  user_id = auth_user_id(), which comes from the session and can never be
 *  supplied by the browser.
 * ===========================================================================
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

// ---------------------------------------------------------------------------
//  A status change must never happen on a GET request.
//
//  Answered 405 and stopped, rather than redirecting - matching
//  admin-booking-action.php and logout.php, so the advertised status code is
//  the one actually sent.
// ---------------------------------------------------------------------------
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

/* ---------------------------------------------------------------------------
 *  Validate the reference before it reaches the database.
 *
 *  Same shape check booking-confirmation.php uses. This is belt-and-braces -
 *  the value is bound as a parameter regardless - but it keeps obviously
 *  malformed input out of the query entirely.
 * ------------------------------------------------------------------------ */
$reference = isset($_POST['reference']) && is_string($_POST['reference'])
    ? trim($_POST['reference'])
    : '';

if ($reference === '' || preg_match('/^[A-Za-z0-9-]{1,32}$/', $reference) !== 1) {
    flash_set('error', 'That booking could not be identified.');
    auth_redirect('customer-dashboard.php');
}

/* ---------------------------------------------------------------------------
 *  Raise the request.
 *
 *  A single guarded UPDATE does the whole job. Ownership, eligibility and
 *  duplicate-prevention all live in the WHERE clause, so there is no
 *  read-then-write gap in which a booking could be cancelled by staff, or
 *  requested twice from two browser tabs, between the check and the write. A
 *  single UPDATE is atomic in InnoDB; affected_rows reports whether the
 *  booking really was in a state this action applies to.
 *
 *  The two assignments are ordered deliberately. MySQL and MariaDB evaluate a
 *  SET list left to right using the values current at that point, so
 *  previous_status is captured from the OLD status before status is
 *  overwritten. Writing them the other way round would store the new status
 *  and lose the very thing the column exists to remember.
 * ------------------------------------------------------------------------ */
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

    /* -----------------------------------------------------------------------
     *  Nothing changed. Work out why, so the message is useful rather than a
     *  blanket failure.
     *
     *  This second read is ALSO scoped to user_id. That matters: it means a
     *  customer probing another customer's reference gets exactly the same
     *  "we could not find that booking" answer as for a reference that does
     *  not exist at all, so the response cannot be used to discover whether
     *  somebody else's booking reference is real.
     * -------------------------------------------------------------------- */
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
