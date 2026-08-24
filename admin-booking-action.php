<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Administrator booking status changes
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  POST ONLY. Requires an administrator session and a valid CSRF token.
 *
 *  This endpoint never accepts a status value from the browser. The form sends
 *  an ACTION ("confirm" or "cancel"), which is mapped here to a fixed pair of
 *  (required current status -> new status). An attacker cannot post
 *  status=completed, or move a cancelled booking back to confirmed.
 *
 *  Permitted transitions:
 *      pending                -> confirmed
 *      pending                -> cancelled
 *      confirmed              -> cancelled
 *      cancellation_requested -> cancelled          (request approved)
 *      cancellation_requested -> previous_status    (request rejected)
 *
 *  Anything else is rejected, including repeating a transition that has
 *  already happened.
 *
 *  Only this file - behind require_admin() - can write 'cancelled'. A customer
 *  raising a cancellation request (customer-cancellation-request.php) can only
 *  move a booking into 'cancellation_requested'.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-filters.php';
require_once __DIR__ . '/booking-status.php';

require_admin();

/**
 * Where to send the administrator afterwards.
 *
 * The dashboard's search and filter values are posted along with the action so
 * that confirming one booking in a filtered list does not throw the
 * administrator back to the unfiltered view.
 *
 * This CANNOT become an open redirect. The path is the hard-coded literal
 * 'admin-dashboard.php'; only the four known filter parameters are appended,
 * and each is re-validated by admin_filters_read() first - so a tampered
 * hidden field can at most produce a different (still valid) filter, never a
 * different destination. auth_redirect() then applies its own same-directory
 * check on top.
 */
$returnTo = 'admin-dashboard.php' . admin_filters_query(admin_filters_read($_POST));

// ---------------------------------------------------------------------------
//  A status change must never happen on a GET request.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {

    // Respond 405 and stop here. An earlier version set 405 and then called
    // auth_redirect(), whose header('Location: ...', true, 302) silently
    // overwrote the status code - so the endpoint advertised 405 but actually
    // answered 302. No booking was ever modified either way, but the status
    // now matches what the code says, and matches logout.php's behaviour for
    // the same situation.
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
       . '<p>Booking status changes must be submitted from the administrator '
       . 'dashboard, not opened as a link.</p>'
       . '<a class="btn btn-primary btn-block" href="admin-dashboard.php">'
       . 'Return to the dashboard</a>'
       . '</div></main></body></html>';

    exit;
}

csrf_require();

/**
 * Allowed actions.
 *
 * Each maps to the statuses a booking is allowed to be in for the action to
 * apply, plus the single status it may be moved to.
 *
 * 'restore' marks the one action whose destination is not a fixed value:
 * rejecting a cancellation request must return the booking to whatever it was
 * before the customer asked, which is read from bookings.previous_status. The
 * status is therefore never named by this file for that action, and never by
 * the browser for any of them.
 *
 * @var array<string, array{from: array<int, string>, to: ?string,
 *                          restore: bool, done: string}>
 */
$allowedActions = [
    'confirm' => [
        'from'    => ['pending'],
        'to'      => 'confirmed',
        'restore' => false,
        'done'    => 'confirmed',
    ],
    'cancel' => [
        'from'    => ['pending', 'confirmed'],
        'to'      => 'cancelled',
        'restore' => false,
        'done'    => 'cancelled',
    ],
    // Staff agree to the customer's request: the booking is finally cancelled
    // and the room is released.
    'approve_cancellation' => [
        'from'    => ['cancellation_requested'],
        'to'      => 'cancelled',
        'restore' => false,
        'done'    => 'cancelled',
    ],
    // Staff decline the request: the booking goes back exactly as it was.
    'reject_cancellation' => [
        'from'    => ['cancellation_requested'],
        'to'      => null,
        'restore' => true,
        'done'    => 'reinstated',
    ],
];

// ---------------------------------------------------------------------------
//  Validate input
// ---------------------------------------------------------------------------
$bookingId = filter_var(
    $_POST['booking_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$action = isset($_POST['action']) && is_string($_POST['action'])
    ? $_POST['action']
    : '';

if ($bookingId === false || $bookingId === null) {
    flash_set('error', 'That booking could not be identified.');
    auth_redirect($returnTo);
}

if (!isset($allowedActions[$action])) {
    flash_set('error', 'That action is not recognised.');
    auth_redirect($returnTo);
}

$rule = $allowedActions[$action];

// ---------------------------------------------------------------------------
//  Apply the change.
//
//  The UPDATE carries its own status guard in the WHERE clause:
//
//      WHERE id = ? AND status IN (...allowed current statuses...)
//
//  A single UPDATE statement is atomic in InnoDB, and because the guard is
//  part of the same statement there is no read-then-write gap for a second
//  administrator to slip through. affected_rows tells us whether the booking
//  really was in a state the action applies to, so a repeated or invalid
//  transition changes zero rows and is reported as rejected.
// ---------------------------------------------------------------------------
// One placeholder per permitted starting status, generated from the rule
// rather than written out by hand, so an action may list as many as it needs.
$fromList = implode(', ', array_fill(0, count($rule['from']), '?'));

try {
    if ($rule['restore']) {

        /* -------------------------------------------------------------------
         *  Reject: put the booking back to its remembered previous status.
         *
         *  The new status is copied from the row itself - this file never
         *  names it - so a request that began as 'pending' returns to
         *  'pending' and one that began as 'confirmed' returns to 'confirmed'.
         *  Nothing here can promote a booking staff never approved.
         *
         *  previous_status IS NOT NULL is a genuine guard, not decoration: if
         *  a row ever reached 'cancellation_requested' without a remembered
         *  status, this refuses to guess and changes nothing, rather than
         *  writing NULL into a NOT NULL column or defaulting to 'confirmed'.
         *
         *  SET order matters and is deliberate. MySQL and MariaDB evaluate a
         *  SET list left to right using the values current at that point, so
         *  status reads previous_status before previous_status is cleared.
         * ---------------------------------------------------------------- */
        $stmt = $conn->prepare(
            'UPDATE bookings
                SET status          = previous_status,
                    previous_status = NULL
              WHERE id = ?
                AND status IN (' . $fromList . ')
                AND previous_status IS NOT NULL'
        );

        $restoreArgs = array_merge([$bookingId], $rule['from']);
        $stmt->bind_param('i' . str_repeat('s', count($rule['from'])), ...$restoreArgs);

    } else {

        /* -------------------------------------------------------------------
         *  Confirm, Cancel, Approve cancellation: a fixed destination status.
         *
         *  previous_status is cleared in the same statement. For Confirm and
         *  Cancel it is already NULL and this is a no-op; for Approve it
         *  discards the remembered status, because once a booking is cancelled
         *  there is nothing left to reinstate.
         * ---------------------------------------------------------------- */
        $newStatus = $rule['to'];

        $stmt = $conn->prepare(
            'UPDATE bookings
                SET status          = ?,
                    previous_status = NULL
              WHERE id = ?
                AND status IN (' . $fromList . ')'
        );

        $updateArgs = array_merge([$newStatus, $bookingId], $rule['from']);
        $stmt->bind_param('si' . str_repeat('s', count($rule['from'])), ...$updateArgs);
    }

    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed === 1) {

        // Read the reference and the resulting status back, so a reinstated
        // booking can be reported as what it actually became rather than as a
        // vague "reinstated".
        $reference    = '';
        $finalStatus  = '';

        $refStmt = $conn->prepare(
            'SELECT booking_reference, status FROM bookings WHERE id = ? LIMIT 1'
        );
        $refStmt->bind_param('i', $bookingId);
        $refStmt->execute();
        $refStmt->bind_result($fetchedReference, $fetchedStatus);

        if ($refStmt->fetch()) {
            $reference   = (string) $fetchedReference;
            $finalStatus = (string) $fetchedStatus;
        }

        $refStmt->close();

        $named = $reference === '' ? 'Booking' : 'Booking ' . $reference;

        if ($action === 'approve_cancellation') {
            $message = 'Cancellation approved. ' . $named . ' is now cancelled.';

        } elseif ($action === 'reject_cancellation') {
            $message = 'Cancellation request declined. ' . $named
                     . ' has been returned to '
                     . strtolower(booking_status_label($finalStatus)) . '.';

        } else {
            $message = $named . ' ' . $rule['done'] . '.';
        }

        flash_set('success', $message);

    } else {
        // Zero rows: either no such booking, or it was not in a status this
        // action applies to (for example, already cancelled).
        flash_set(
            'error',
            'That booking could not be ' . $rule['done']
                . '. It may have already been updated by someone else.'
        );
    }

} catch (mysqli_sql_exception $exception) {
    error_log('[Hotel Booking System] Booking status change failed: ' . $exception->getMessage());
    flash_set('error', 'The booking could not be updated right now. Please try again shortly.');
}

// Redirect-after-POST: refreshing the dashboard cannot repeat the action.
auth_redirect($returnTo);
