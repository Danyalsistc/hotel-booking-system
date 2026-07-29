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
 *      pending   -> confirmed
 *      pending   -> cancelled
 *      confirmed -> cancelled
 *
 *  Anything else is rejected, including repeating a transition that has
 *  already happened.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

require_admin();

// ---------------------------------------------------------------------------
//  A status change must never happen on a GET request.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: POST');
    }

    flash_set('error', 'That action must be submitted from the dashboard.');
    auth_redirect('admin-dashboard.php');
}

csrf_require();

/**
 * Allowed actions.
 *
 * Each maps to the statuses a booking is allowed to be in for the action to
 * apply, plus the single status it may be moved to.
 *
 * @var array<string, array{from: array<int, string>, to: string, done: string}>
 */
$allowedActions = [
    'confirm' => [
        'from' => ['pending'],
        'to'   => 'confirmed',
        'done' => 'confirmed',
    ],
    'cancel' => [
        'from' => ['pending', 'confirmed'],
        'to'   => 'cancelled',
        'done' => 'cancelled',
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
    auth_redirect('admin-dashboard.php');
}

if (!isset($allowedActions[$action])) {
    flash_set('error', 'That action is not recognised.');
    auth_redirect('admin-dashboard.php');
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
// bind_param takes its arguments by reference, so the values are copied into
// plain local variables first rather than being passed as array elements.
$newStatus  = $rule['to'];
$fromFirst  = $rule['from'][0];
$fromSecond = $rule['from'][1] ?? null;

try {
    if ($fromSecond !== null) {
        $stmt = $conn->prepare(
            'UPDATE bookings SET status = ? WHERE id = ? AND status IN (?, ?)'
        );
        $stmt->bind_param('siss', $newStatus, $bookingId, $fromFirst, $fromSecond);
    } else {
        $stmt = $conn->prepare(
            'UPDATE bookings SET status = ? WHERE id = ? AND status = ?'
        );
        $stmt->bind_param('sis', $newStatus, $bookingId, $fromFirst);
    }

    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed === 1) {

        // Read the reference back so the confirmation names the booking.
        $reference = '';

        $refStmt = $conn->prepare('SELECT booking_reference FROM bookings WHERE id = ? LIMIT 1');
        $refStmt->bind_param('i', $bookingId);
        $refStmt->execute();
        $refStmt->bind_result($fetchedReference);

        if ($refStmt->fetch()) {
            $reference = (string) $fetchedReference;
        }

        $refStmt->close();

        flash_set(
            'success',
            $reference === ''
                ? 'Booking ' . $rule['done'] . '.'
                : 'Booking ' . $reference . ' ' . $rule['done'] . '.'
        );

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
auth_redirect('admin-dashboard.php');
