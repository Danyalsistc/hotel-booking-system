<?php
declare(strict_types=1);

/**
 * Availability endpoint (read-only JSON).
 *   GET check_availability.php?room_type_id=4&check_in=...&check_out=...
 *
 * This endpoint DOES NOT RESERVE ANYTHING. It reports what is free at the
 * instant it runs, and that answer can be stale by the time a booking is
 * submitted - booknow.php re-checks inside a locking transaction, and that
 * check is what actually decides whether a booking succeeds.
 *
 * Responses are deliberately thin: room numbers, row IDs and database errors
 * are never exposed.
 */

require_once __DIR__ . '/auth.php';

/**
 * Emit a JSON response and stop.
 *
 * @param array<string, mixed> $payload
 */
function availability_respond(int $status, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
//  Only GET makes sense for a read-only lookup.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    if (!headers_sent()) {
        header('Allow: GET');
    }
    availability_respond(405, [
        'ok'    => false,
        'error' => 'This endpoint accepts GET requests only.',
    ]);
}

// ---------------------------------------------------------------------------
//  Authentication. Occupancy is commercial information, and the booking page
//  that uses this endpoint already requires a login, so anonymous callers are
//  refused rather than being told how full the hotel is.
// ---------------------------------------------------------------------------
auth_session_start();

if (!auth_is_logged_in()) {
    availability_respond(401, [
        'ok'    => false,
        'error' => 'You must be logged in to check availability.',
    ]);
}

// config.php is included only after the cheap checks above, so an
// unauthenticated caller never touches the database.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/booking-lib.php';

// ---------------------------------------------------------------------------
//  Input validation - strict, and identical to the rules booknow.php applies.
// ---------------------------------------------------------------------------
$roomTypeId = filter_var(
    $_GET['room_type_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($roomTypeId === false || $roomTypeId === null) {
    availability_respond(400, [
        'ok'    => false,
        'error' => 'A valid room type must be supplied.',
    ]);
}

$checkIn  = booking_parse_date(isset($_GET['check_in'])  && is_string($_GET['check_in'])  ? $_GET['check_in']  : null);
$checkOut = booking_parse_date(isset($_GET['check_out']) && is_string($_GET['check_out']) ? $_GET['check_out'] : null);

if ($checkIn === null || $checkOut === null) {
    availability_respond(400, [
        'ok'    => false,
        'error' => 'Both dates must be supplied in YYYY-MM-DD format.',
    ]);
}

$stayError = booking_validate_stay($checkIn, $checkOut);

if ($stayError !== null) {
    availability_respond(400, [
        'ok'    => false,
        'error' => $stayError,
    ]);
}

// ---------------------------------------------------------------------------
//  Lookup
// ---------------------------------------------------------------------------
try {

    // Confirm the room type exists and is active, and read its details.
    $stmt = $conn->prepare(
        'SELECT name, capacity, price_per_night
           FROM room_types
          WHERE id = ? AND active = 1
          LIMIT 1'
    );
    $stmt->bind_param('i', $roomTypeId);
    $stmt->execute();
    $stmt->bind_result($name, $capacity, $price);
    $found = (bool) $stmt->fetch();
    $stmt->close();

    if (!$found) {
        availability_respond(404, [
            'ok'    => false,
            'error' => 'That room type is not available.',
        ]);
    }

    $checkInStr  = $checkIn->format('Y-m-d');
    $checkOutStr = $checkOut->format('Y-m-d');

    // Same overlap rule as the booking transaction, via the shared helper.
    $roomsAvailable = booking_count_available_rooms(
        $conn,
        $roomTypeId,
        $checkInStr,
        $checkOutStr
    );

    $nights     = booking_nights($checkIn, $checkOut);
    $rateCents  = (int) round(((float) $price) * 100);
    $totalCents = $rateCents * $nights;

    availability_respond(200, [
        'ok'              => true,
        'available'       => $roomsAvailable > 0,
        'rooms_available' => $roomsAvailable,
        'room_type'       => (string) $name,
        'capacity'        => (int) $capacity,
        'check_in'        => $checkInStr,
        'check_out'       => $checkOutStr,
        'nights'          => $nights,
        'currency'        => 'AUD',
        'nightly_rate'    => number_format($rateCents / 100, 2, '.', ''),
        'estimated_total' => number_format($totalCents / 100, 2, '.', ''),
        // Made explicit so no caller mistakes this for a reservation.
        'reserved'        => false,
        'note'            => 'Indicative only. Availability is re-checked when the booking is submitted.',
    ]);

} catch (mysqli_sql_exception $exception) {

    error_log('[Hotel Booking System] Availability check failed: ' . $exception->getMessage());

    availability_respond(500, [
        'ok'    => false,
        'error' => 'Availability could not be checked right now. Please try again shortly.',
    ]);
}
