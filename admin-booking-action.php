<?php
declare(strict_types=1);

/**
 * Administrator booking status changes. POST only, admin session and CSRF
 * token required.
 *
 * The browser never sends a status. It sends an ACTION, mapped here to a fixed
 * (required current status -> new status) pair, so nobody can post
 * status=completed or move a cancelled booking back to confirmed.
 *
 * Permitted transitions:
 *     pending                -> confirmed
 *     pending                -> cancelled
 *     confirmed              -> cancelled
 *     cancellation_requested -> cancelled          (request approved)
 *     cancellation_requested -> previous_status    (request rejected)
 *
 * Only this file, behind require_admin(), can write 'cancelled'. A customer
 * can only move a booking into 'cancellation_requested'.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-filters.php';
require_once __DIR__ . '/booking-status.php';

require_admin();

/**
 * Return the administrator to the filtered view they were working in.
 *
 * This cannot become an open redirect: the path is a hard-coded literal and
 * only the four known filter parameters are appended, each re-validated by
 * admin_filters_read() first.
 */
$returnTo = 'admin-dashboard.php' . admin_filters_query(admin_filters_read($_POST));

// A status change must never happen on a GET request. Respond 405 and stop -
// calling auth_redirect() here would overwrite the status code with a 302.
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
       . '<p>Booking status changes must be submitted from the administrator '
       . 'dashboard, not opened as a link.</p>'
       . '<a class="btn btn-primary btn-block" href="admin-dashboard.php">'
       . 'Return to the dashboard</a>'
       . '</div></main></body></html>';

    exit;
}

csrf_require();

/**
 * Allowed actions: the statuses a booking must be in, and the status it may
 * move to.
 *
 * 'restore' marks the one action with no fixed destination - rejecting a
 * request returns the booking to bookings.previous_status, so that status is
 * never named by this file or by the browser.
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
// The UPDATE carries its own status guard in the WHERE clause. A single UPDATE
// is atomic in InnoDB, so there is no read-then-write gap for a second
// administrator to slip through, and affected_rows reports whether the booking
// really was in a state the action applies to.
$fromList = implode(', ', array_fill(0, count($rule['from']), '?'));

try {
    if ($rule['restore']) {

        /* Reject: restore the remembered previous status. It is copied from
           the row, so a request that began as 'pending' returns to 'pending'
           and nothing here can promote a booking staff never approved.

           previous_status IS NOT NULL is a real guard - without a remembered
           status this changes nothing rather than guessing 'confirmed'.

           SET order matters: MySQL evaluates the list left to right, so status
           reads previous_status before it is cleared. */
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

        /* Confirm, Cancel, Approve: a fixed destination status.
           previous_status is cleared in the same statement - a no-op for
           Confirm/Cancel, and for Approve it discards the remembered status
           because a cancelled booking has nothing left to reinstate. */
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

        // Read back the resulting status so a reinstated booking is reported
        // as what it actually became.
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
