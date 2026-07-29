<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Shared booking helpers
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Small, dependency-light helpers shared by booknow.php and
 *  check_availability.php so the date rules and the availability overlap rule
 *  are written once and cannot drift apart.
 *
 *  Contains no page output. Requires config.php to have supplied $conn before
 *  any function that takes a mysqli argument is called.
 * ===========================================================================
 */

/**
 * Timezone used for every "what is today" decision.
 *
 * This is an Australian demonstration hotel (prices are in AUD) and the
 * project is being completed in New South Wales, so dates are judged in
 * Australian Eastern time rather than in whatever timezone the web server
 * happens to be configured with. Change this one constant if the hotel is
 * located elsewhere.
 */
const BOOKING_TIMEZONE = 'Australia/Sydney';

/** Longest stay a single booking may cover. */
const BOOKING_MAX_NIGHTS = 30;

/** How far ahead a check-in date may be placed, in days. */
const BOOKING_MAX_ADVANCE_DAYS = 365;

/** Booking statuses that occupy a room and therefore block availability. */
const BOOKING_BLOCKING_STATUSES = ['pending', 'confirmed'];


/**
 * Today's date in the hotel's timezone, with the time component stripped.
 */
function booking_today(): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', new DateTimeZone(BOOKING_TIMEZONE));

    return $now->setTime(0, 0, 0);
}

/**
 * Strictly parse a YYYY-MM-DD date.
 *
 * Strict means the string must be exactly that format AND describe a real
 * calendar date. '2026-02-30' and '2026-13-01' are rejected rather than being
 * silently rolled forward, which is what a plain strtotime() would do.
 *
 * @return DateTimeImmutable|null Null when the input is not a valid date.
 */
function booking_parse_date(?string $value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    // Reject anything that is not literally 10 characters of YYYY-MM-DD before
    // handing it to the date parser.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',                              // "!" zeroes the time component
        $value,
        new DateTimeZone(BOOKING_TIMEZONE)
    );

    if ($date === false) {
        return null;
    }

    // createFromFormat accepts overflowing values such as 2026-02-30 and
    // reports them as warnings, so those must be checked explicitly.
    // PHP 8.2+ returns false from getLastErrors() when there were none.
    $errors = DateTimeImmutable::getLastErrors();

    if (is_array($errors)
        && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }

    // Belt and braces: the parsed date must format back to the input exactly.
    if ($date->format('Y-m-d') !== $value) {
        return null;
    }

    return $date;
}

/**
 * Validate a requested stay against the project's date business rules.
 *
 * @param  DateTimeImmutable $checkIn
 * @param  DateTimeImmutable $checkOut
 * @return string|null  Error message, or null when the stay is acceptable.
 */
function booking_validate_stay(DateTimeImmutable $checkIn, DateTimeImmutable $checkOut): ?string
{
    $today = booking_today();

    if ($checkIn < $today) {
        return 'The check-in date cannot be in the past.';
    }

    if ($checkOut <= $checkIn) {
        return 'The check-out date must be after the check-in date.';
    }

    $latestCheckIn = $today->modify('+' . BOOKING_MAX_ADVANCE_DAYS . ' days');

    if ($checkIn > $latestCheckIn) {
        return 'Bookings can only be made up to ' . BOOKING_MAX_ADVANCE_DAYS . ' days in advance.';
    }

    $nights = booking_nights($checkIn, $checkOut);

    if ($nights > BOOKING_MAX_NIGHTS) {
        return 'A single booking can cover at most ' . BOOKING_MAX_NIGHTS . ' nights.';
    }

    return null;
}

/**
 * Number of nights between two dates.
 *
 * The stay occupies the half-open interval [check_in, check_out), so a guest
 * arriving on the 10th and leaving on the 12th stays 2 nights.
 */
function booking_nights(DateTimeImmutable $checkIn, DateTimeImmutable $checkOut): int
{
    return (int) $checkIn->diff($checkOut)->days;
}

/**
 * Generate an unpredictable, customer-facing booking reference.
 *
 * Format: HBS-YYYYMMDD-XXXXXXXX  (21 characters, fits VARCHAR(32))
 *
 * The random component comes from random_bytes(), a cryptographically secure
 * source, so a reference cannot be guessed from another one. The original
 * prototype used "B" + Date.now() in the browser, which was both guessable
 * and forgeable.
 */
function booking_generate_reference(): string
{
    return sprintf(
        'HBS-%s-%s',
        booking_today()->format('Ymd'),
        strtoupper(bin2hex(random_bytes(4)))
    );
}

/**
 * Count how many physical rooms of a type are free for a date range.
 *
 * READ-ONLY. This is an indication for the user interface, not a reservation:
 * the count can be stale by the time a booking is submitted, which is exactly
 * why booknow.php re-checks inside a locking transaction before inserting.
 *
 * Availability rule - a room is free when NO pending or confirmed booking
 * overlaps the requested range:
 *
 *      existing.check_in  <  requested.check_out
 *      AND existing.check_out >  requested.check_in
 *
 * Strict inequalities allow same-day changeover: a guest leaving on the 12th
 * does not block a guest arriving on the 12th.
 *
 * @param  mysqli $conn
 * @param  int    $roomTypeId
 * @param  string $checkIn  'Y-m-d'
 * @param  string $checkOut 'Y-m-d'
 * @return int    Number of available physical rooms.
 */
function booking_count_available_rooms(
    mysqli $conn,
    int $roomTypeId,
    string $checkIn,
    string $checkOut
): int {
    $sql = 'SELECT COUNT(*)
              FROM rooms r
             WHERE r.room_type_id = ?
               AND r.status = ?
               AND NOT EXISTS (
                     SELECT 1
                       FROM bookings b
                      WHERE b.room_id = r.id
                        AND b.status IN (?, ?)
                        AND b.check_in  < ?
                        AND b.check_out > ?
                   )';

    $available  = 'available';
    $pending    = BOOKING_BLOCKING_STATUSES[0];
    $confirmed  = BOOKING_BLOCKING_STATUSES[1];

    $stmt = $conn->prepare($sql);

    // Parameter order matters: the first date compared is check_out, because
    // the rule is "existing.check_in < requested.check_out".
    $stmt->bind_param(
        'isssss',
        $roomTypeId,
        $available,
        $pending,
        $confirmed,
        $checkOut,
        $checkIn
    );

    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count;
}

/**
 * Load every active room type, ordered by price.
 *
 * @param  mysqli $conn
 * @return array<int, array{id:int, name:string, capacity:int, price:string}>
 */
function booking_load_room_types(mysqli $conn): array
{
    $stmt = $conn->prepare(
        'SELECT id, name, capacity, price_per_night
           FROM room_types
          WHERE active = 1
       ORDER BY price_per_night ASC, name ASC'
    );

    $stmt->execute();
    $stmt->bind_result($id, $name, $capacity, $price);

    $types = [];

    while ($stmt->fetch()) {
        $types[] = [
            'id'       => (int) $id,
            'name'     => (string) $name,
            'capacity' => (int) $capacity,
            'price'    => (string) $price,
        ];
    }

    $stmt->close();

    return $types;
}

/**
 * Resolve a room-type NAME supplied in a URL to its ID.
 *
 * Room pages link here by name rather than by numeric ID, because the IDs in
 * database.sql are auto-increment values and are not guaranteed to be stable
 * across a re-import. The name is matched against the list already loaded
 * from the database, so an unrecognised value simply resolves to null and no
 * user input ever reaches a query.
 *
 * @param array<int, array{id:int, name:string, capacity:int, price:string}> $types
 */
function booking_resolve_room_type_name(array $types, ?string $name): ?int
{
    if (!is_string($name) || $name === '') {
        return null;
    }

    $needle = strtolower(trim($name));

    foreach ($types as $type) {
        if (strtolower($type['name']) === $needle) {
            return $type['id'];
        }
    }

    return null;
}

// No closing PHP tag.
