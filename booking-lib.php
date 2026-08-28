<?php
declare(strict_types=1);

/**
 * Shared booking helpers.
 *
 * Used by booknow.php and check_availability.php so the date rules and the
 * availability overlap rule are written once. Produces no output; requires
 * config.php to have supplied $conn before any function taking a mysqli.
 */

/**
 * Timezone for every "what is today" decision. Dates are judged in Australian
 * Eastern time rather than the server's timezone.
 */
const BOOKING_TIMEZONE = 'Australia/Sydney';

/** Longest stay a single booking may cover. */
const BOOKING_MAX_NIGHTS = 30;

/** How far ahead a check-in date may be placed, in days. */
const BOOKING_MAX_ADVANCE_DAYS = 365;

/**
 * Booking statuses that occupy a room and therefore block availability.
 *
 * 'cancellation_requested' MUST be in this list. A customer asking to cancel
 * has not cancelled anything yet - an administrator may still reject the
 * request, putting the booking back to pending or confirmed. If the room were
 * released when the request was raised, someone else could book it during the
 * review window and a rejection would produce two live bookings for the same
 * room on the same dates. A room is released only at 'cancelled'.
 */
const BOOKING_BLOCKING_STATUSES = ['pending', 'confirmed', 'cancellation_requested'];


/** Render a stay as one compact range, e.g. "8 - 10 Aug 2026". */
function booking_format_stay(string $in, string $out): string
{
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', substr($in, 0, 10));
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', substr($out, 0, 10));

    if ($a === false || $b === false) {
        return $in . ' - ' . $out;
    }

    if ($a->format('Y-m') === $b->format('Y-m')) {
        return $a->format('j') . ' - ' . $b->format('j M Y');
    }

    if ($a->format('Y') === $b->format('Y')) {
        return $a->format('j M') . ' - ' . $b->format('j M Y');
    }

    return $a->format('j M Y') . ' - ' . $b->format('j M Y');
}

/** Today's date in the hotel's timezone, with the time stripped. */
function booking_today(): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', new DateTimeZone(BOOKING_TIMEZONE));

    return $now->setTime(0, 0, 0);
}

/**
 * Strictly parse a YYYY-MM-DD date. The string must be exactly that format and
 * a real calendar date: '2026-02-30' is rejected rather than rolled forward,
 * which is what a plain strtotime() would do.
 */
function booking_parse_date(?string $value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

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

    // createFromFormat accepts overflowing values such as 2026-02-30 and only
    // reports them as warnings, so they must be checked explicitly.
    $errors = DateTimeImmutable::getLastErrors();

    if (is_array($errors)
        && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }

    // The parsed date must format back to the input exactly.
    if ($date->format('Y-m-d') !== $value) {
        return null;
    }

    return $date;
}

/**
 * Validate a requested stay against the date business rules.
 *
 * @return string|null Error message, or null when the stay is acceptable.
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
 * Number of nights between two dates. The stay occupies the half-open interval
 * [check_in, check_out), so the 10th to the 12th is 2 nights.
 */
function booking_nights(DateTimeImmutable $checkIn, DateTimeImmutable $checkOut): int
{
    return (int) $checkIn->diff($checkOut)->days;
}

/**
 * Generate a customer-facing booking reference: HBS-YYYYMMDD-XXXXXXXX.
 *
 * The random part uses random_bytes(), so a reference cannot be guessed from
 * another one. The original prototype used "B" + Date.now() in the browser,
 * which was both guessable and forgeable.
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
 * READ-ONLY - an indication for the interface, not a reservation. The count can
 * be stale by the time a booking is submitted, which is why booknow.php
 * re-checks inside a locking transaction before inserting.
 *
 * Overlap rule - a room is free when no blocking booking satisfies:
 *      existing.check_in  <  requested.check_out
 *      AND existing.check_out >  requested.check_in
 *
 * The strict inequalities allow same-day changeover: a guest leaving on the
 * 12th does not block a guest arriving on the 12th.
 */
function booking_count_available_rooms(
    mysqli $conn,
    int $roomTypeId,
    string $checkIn,
    string $checkOut
): int {
    // One placeholder per blocking status, built from a compile-time constant
    // and never from user input, so the statement stays fully prepared.
    $blocking     = BOOKING_BLOCKING_STATUSES;
    $blockingList = implode(', ', array_fill(0, count($blocking), '?'));

    $sql = 'SELECT COUNT(*)
              FROM rooms r
             WHERE r.room_type_id = ?
               AND r.status = ?
               AND NOT EXISTS (
                     SELECT 1
                       FROM bookings b
                      WHERE b.room_id = r.id
                        AND b.status IN (' . $blockingList . ')
                        AND b.check_in  < ?
                        AND b.check_out > ?
                   )';

    $available = 'available';

    $stmt = $conn->prepare($sql);

    // Order matters: check_out is bound first, per the overlap rule above.
    $args = array_merge([$roomTypeId, $available], $blocking, [$checkOut, $checkIn]);

    $stmt->bind_param('is' . str_repeat('s', count($blocking)) . 'ss', ...$args);

    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count;
}

/**
 * Load every active room type, ordered by price.
 *
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
 * Resolve a room-type name from a URL to its ID.
 *
 * Room pages link by name because the auto-increment IDs in database.sql are
 * not stable across a re-import. The name is matched against the list already
 * loaded from the database, so no user input reaches a query.
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
