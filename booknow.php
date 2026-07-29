<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Booking page (canonical)
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  GET  - shows the booking form, populated from the room_types table.
 *  POST - validates the request, finds a free physical room inside a locking
 *         transaction, and creates the booking.
 *
 *  Guest name and email are NOT collected here. The customer is logged in, so
 *  those values already exist on their account; re-collecting them would
 *  duplicate account data and allow the two copies to disagree. The booking is
 *  linked to the account through bookings.user_id.
 *
 *  NOTHING about money or capacity is taken from the browser. The nightly
 *  rate, the room capacity, the night count and the total price are all read
 *  or calculated on the server from room_types.
 *
 *  No payment card information is collected anywhere. Payment is out of scope.
 * ===========================================================================
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/booking-lib.php';

require_login();

// Administrators manage bookings; they do not place them.
if (auth_is_admin()) {
    auth_redirect('admin-dashboard.php');
}

/**
 * Room types are loaded defensively: if database.sql has not been imported
 * yet the tables will not exist, and an uncaught exception would render a
 * blank page (display_errors is off in production mode). An empty list is
 * handled further down with a clear on-screen message instead.
 */
$roomTypes    = [];
$roomTypeLoad = '';

try {
    $roomTypes = booking_load_room_types($conn);
} catch (mysqli_sql_exception $exception) {
    error_log('[Hotel Booking System] Could not load room types: ' . $exception->getMessage());
    $roomTypeLoad = 'Room information could not be loaded right now. Please try again shortly.';
}

// Ceiling for the guest field's HTML max attribute: the largest capacity any
// room type offers. The real per-room-type limit is enforced on the server
// against room_types.capacity; this only stops obviously absurd input early.
$maxCapacity = 1;

foreach ($roomTypes as $type) {
    if ($type['capacity'] > $maxCapacity) {
        $maxCapacity = $type['capacity'];
    }
}

$today       = booking_today();
$minDate     = $today->format('Y-m-d');
$maxDate     = $today->modify('+' . BOOKING_MAX_ADVANCE_DAYS . ' days')->format('Y-m-d');
$defaultOut  = $today->modify('+1 day')->format('Y-m-d');

/** @var array<string,string> Field-level errors. */
$errors = [];

/** Values echoed back into the form after a validation failure. */
$values = [
    'room_type_id' => '',
    'check_in'     => '',
    'check_out'    => '',
    'guest_count'  => '1',
];

/* ---------------------------------------------------------------------------
 *  GET - preselect a room type from the URL, e.g.
 *        booknow.php?room_type=Deluxe%20Suite
 *
 *  The value is resolved against the list already loaded from the database.
 *  An unknown name simply leaves nothing selected; the raw parameter is never
 *  used in a query and never printed.
 * ------------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {

    $requested = isset($_GET['room_type']) && is_string($_GET['room_type'])
        ? $_GET['room_type']
        : null;

    $resolved = booking_resolve_room_type_name($roomTypes, $requested);

    if ($resolved !== null) {
        $values['room_type_id'] = (string) $resolved;
    }

} else {

    /* -----------------------------------------------------------------------
     *  POST - create a booking
     * -------------------------------------------------------------------- */

    csrf_require();

    $roomTypeIdRaw = $_POST['room_type_id'] ?? '';
    $checkInRaw    = isset($_POST['check_in'])  && is_string($_POST['check_in'])  ? $_POST['check_in']  : '';
    $checkOutRaw   = isset($_POST['check_out']) && is_string($_POST['check_out']) ? $_POST['check_out'] : '';
    $guestsRaw     = $_POST['guest_count'] ?? '';

    $values['room_type_id'] = is_scalar($roomTypeIdRaw) ? (string) $roomTypeIdRaw : '';
    $values['check_in']     = $checkInRaw;
    $values['check_out']    = $checkOutRaw;
    $values['guest_count']  = is_scalar($guestsRaw) ? (string) $guestsRaw : '';

    // ----- Room type: must be a positive integer that exists in the list ---
    $roomTypeId = filter_var($roomTypeIdRaw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    $selectedType = null;

    if ($roomTypeId === false) {
        $errors['room_type_id'] = 'Please choose a room type.';
    } else {
        foreach ($roomTypes as $type) {
            if ($type['id'] === $roomTypeId) {
                $selectedType = $type;
                break;
            }
        }

        if ($selectedType === null) {
            $errors['room_type_id'] = 'That room type is not available.';
        }
    }

    // ----- Dates ----------------------------------------------------------
    $checkIn  = booking_parse_date($checkInRaw);
    $checkOut = booking_parse_date($checkOutRaw);

    if ($checkIn === null) {
        $errors['check_in'] = 'Please enter a valid check-in date (YYYY-MM-DD).';
    }

    if ($checkOut === null) {
        $errors['check_out'] = 'Please enter a valid check-out date (YYYY-MM-DD).';
    }

    if ($checkIn !== null && $checkOut !== null) {
        $stayError = booking_validate_stay($checkIn, $checkOut);

        if ($stayError !== null) {
            // Attach the message to whichever field it is about.
            if (strpos($stayError, 'check-out') !== false) {
                $errors['check_out'] = $stayError;
            } else {
                $errors['check_in'] = $stayError;
            }
        }
    }

    // ----- Guests: validated against the capacity stored in the database ---
    $guestCount = filter_var($guestsRaw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($guestCount === false) {
        $errors['guest_count'] = 'Please enter how many guests are staying.';
    } elseif ($selectedType !== null && $guestCount > $selectedType['capacity']) {
        $errors['guest_count'] = sprintf(
            'The %s sleeps a maximum of %d guest%s.',
            $selectedType['name'],
            $selectedType['capacity'],
            $selectedType['capacity'] === 1 ? '' : 's'
        );
    }

    /* -----------------------------------------------------------------------
     *  Race-safe room allocation.
     *
     *  A check performed before the transaction would be worthless on its own:
     *  two customers can both see "1 room left" and both submit. The allocation
     *  below therefore happens entirely inside one transaction, using locking
     *  reads.
     *
     *  Step 1 takes an exclusive row lock on the room_types row. That row acts
     *  as an explicit mutex: any other booking attempt for the SAME room type
     *  blocks here until this transaction finishes, so only one allocation for
     *  a given type can be in flight at a time. Locking the room_types row
     *  (rather than relying on subtler row-locking behaviour over the rooms and
     *  bookings tables) is the conservative choice, and it behaves identically
     *  on MySQL 8 and MariaDB.
     *
     *  Step 2 is also a locking read (FOR UPDATE). That matters under the
     *  default REPEATABLE READ isolation level: a plain SELECT would be served
     *  from the transaction's snapshot and could miss a booking another
     *  transaction committed moments earlier. A locking read always sees the
     *  latest committed data.
     *
     *  Lock ordering is always room_types then rooms, which keeps a consistent
     *  ordering and avoids deadlocks between concurrent bookings.
     * -------------------------------------------------------------------- */
    if ($errors === [] && $selectedType !== null && $checkIn !== null && $checkOut !== null) {

        $checkInStr  = $checkIn->format('Y-m-d');
        $checkOutStr = $checkOut->format('Y-m-d');
        $nights      = booking_nights($checkIn, $checkOut);

        // Tracks whether a transaction is actually open. The catch block uses
        // it so rollback() is only ever called when there is something to roll
        // back - calling it otherwise would raise a warning. It also stays
        // false if begin_transaction() itself fails, which is why that call now
        // sits inside the try: a failure there is reported through exactly the
        // same generic error path as any other database problem, with no raw
        // database message shown to the visitor.
        $inTransaction = false;

        try {
            $conn->begin_transaction();
            $inTransaction = true;

            // --- Step 1: lock the room type row (mutex) and read the
            //             authoritative capacity and price from the database.
            $typeStmt = $conn->prepare(
                'SELECT name, capacity, price_per_night
                   FROM room_types
                  WHERE id = ? AND active = 1
                  FOR UPDATE'
            );
            $typeStmt->bind_param('i', $roomTypeId);
            $typeStmt->execute();
            $typeStmt->bind_result($lockedName, $lockedCapacity, $lockedPrice);
            $typeFound = (bool) $typeStmt->fetch();
            $typeStmt->close();

            if (!$typeFound) {
                $conn->rollback();
                $inTransaction = false;
                $errors['room_type_id'] = 'That room type is no longer available.';
            } elseif ($guestCount > (int) $lockedCapacity) {
                // Re-checked against the locked row, not the earlier read.
                $conn->rollback();
                $inTransaction = false;
                $errors['guest_count'] = sprintf(
                    'The %s sleeps a maximum of %d guests.',
                    (string) $lockedName,
                    (int) $lockedCapacity
                );
            } else {

                // --- Step 2: find one free physical room, locking it.
                $available = 'available';
                $pending   = BOOKING_BLOCKING_STATUSES[0];
                $confirmed = BOOKING_BLOCKING_STATUSES[1];

                $roomStmt = $conn->prepare(
                    'SELECT r.id
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
                            )
                   ORDER BY r.id ASC
                      LIMIT 1
                      FOR UPDATE'
                );

                // Date order: check_out first (existing.check_in < requested.check_out).
                $roomStmt->bind_param(
                    'isssss',
                    $roomTypeId,
                    $available,
                    $pending,
                    $confirmed,
                    $checkOutStr,
                    $checkInStr
                );
                $roomStmt->execute();
                $roomStmt->bind_result($allocatedRoomId);
                $roomFound = (bool) $roomStmt->fetch();
                $roomStmt->close();

                if (!$roomFound) {
                    $conn->rollback();
                    $inTransaction = false;
                    $errors['form'] = sprintf(
                        'We have no %s available between %s and %s. Please try different dates.',
                        (string) $lockedName,
                        $checkInStr,
                        $checkOutStr
                    );
                } else {

                    // --- Step 3: money, calculated on the server from the
                    //             locked database price. Integer cents keeps
                    //             the arithmetic exact.
                    $rateCents  = (int) round(((float) $lockedPrice) * 100);
                    $totalCents = $rateCents * $nights;

                    $nightlyRate = number_format($rateCents / 100, 2, '.', '');
                    $totalPrice  = number_format($totalCents / 100, 2, '.', '');

                    $userId = (int) auth_user_id();
                    $roomId = (int) $allocatedRoomId;

                    // --- Step 4: insert, retrying if the random reference
                    //             happens to collide with an existing one.
                    $inserted  = false;
                    $reference = '';

                    for ($attempt = 0; $attempt < 5 && !$inserted; $attempt++) {

                        $reference = booking_generate_reference();

                        try {
                            $ins = $conn->prepare(
                                'INSERT INTO bookings
                                     (booking_reference, user_id, room_id,
                                      check_in, check_out, guest_count,
                                      nightly_rate, number_of_nights, total_price)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                            );
                            $ins->bind_param(
                                'siissisis',
                                $reference,
                                $userId,
                                $roomId,
                                $checkInStr,
                                $checkOutStr,
                                $guestCount,
                                $nightlyRate,
                                $nights,
                                $totalPrice
                            );
                            $ins->execute();
                            $ins->close();

                            $inserted = true;

                        } catch (mysqli_sql_exception $duplicate) {
                            // 1062 = duplicate key. Only booking_reference is
                            // unique on this table, so retry with a new one.
                            if ((int) $duplicate->getCode() !== 1062) {
                                throw $duplicate;
                            }
                        }
                    }

                    if (!$inserted) {
                        $conn->rollback();
                        $inTransaction = false;
                        error_log('[Hotel Booking System] Could not generate a unique booking reference.');
                        $errors['form'] = 'We could not complete your booking right now. Please try again shortly.';
                    } else {
                        $conn->commit();
                        $inTransaction = false;

                        // Redirect-after-POST: refreshing the confirmation page
                        // cannot create a second booking.
                        //
                        // The wording deliberately does not call the booking
                        // "confirmed": it is created with status pending and
                        // stays that way until an administrator confirms it.
                        flash_set(
                            'success',
                            'Your booking request has been submitted. Its current status is '
                                . 'pending. Your reference is ' . $reference . '.'
                        );
                        auth_redirect('customer-dashboard.php');
                    }
                }
            }

        } catch (mysqli_sql_exception $exception) {

            // Only roll back when a transaction was actually opened. If
            // begin_transaction() was what failed, there is nothing to undo and
            // calling rollback() here would itself raise a warning.
            if ($inTransaction) {
                try {
                    $conn->rollback();
                } catch (mysqli_sql_exception $ignored) {
                    // Nothing further can be done about a failed rollback here.
                }

                $inTransaction = false;
            }

            $code = (int) $exception->getCode();

            error_log('[Hotel Booking System] Booking failed: ' . $exception->getMessage());

            if ($code === 1213 || $code === 1205) {
                // 1213 deadlock, 1205 lock wait timeout - both are transient.
                $errors['form'] = 'The system was busy processing another booking. Please try again.';
            } else {
                $errors['form'] = 'We could not complete your booking right now. Please try again shortly.';
            }
        }
    }
}

$flash = flash_render();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Room - Hotel Booking System</title>
    <link rel="stylesheet" href="theme.css">
    <link rel="stylesheet" href="booknow.css">
</head>
<body class="booking-page">

<a class="skip-link" href="#main-content">Skip to main content</a>

<header class="site-header">
    <div class="container site-header-inner">
        <a class="brand" href="index.html">
            <span class="brand-mark" aria-hidden="true">HB</span>
            <span>Hotel Booking System</span>
        </a>

        <nav class="site-nav" aria-label="Main">
            <ul>
                <li><a href="index.html">Home</a></li>
                <li><a href="index.html#rooms">Rooms</a></li>
                <li><a href="customer-dashboard.php">My Bookings</a></li>
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

<main id="main-content" class="booking-main">
  <div class="container-narrow">

    <div class="booking-card">

    <h1>Book a room</h1>

    <p class="booking-intro">
        Signed in as <strong><?php echo e(auth_user_name()); ?></strong>.
        Your booking is linked to this account, so there is no need to
        re-enter your name or email address.
    </p>

    <?php echo $flash; ?>

    <?php if (isset($errors['form'])): ?>
        <p class="flash flash-error" role="alert"><?php echo e($errors['form']); ?></p>
    <?php endif; ?>

    <?php if ($roomTypeLoad !== ''): ?>

        <p class="flash flash-error" role="alert"><?php echo e($roomTypeLoad); ?></p>

    <?php elseif ($roomTypes === []): ?>

        <p class="flash flash-error" role="alert">
            No room types are currently set up. If you are running this project
            locally, import database.sql first.
        </p>

    <?php else: ?>

        <form action="booknow.php" method="post" novalidate>

            <?php echo csrf_field(); ?>

            <div class="field">
                <label for="room_type_id">Room type</label>
                <select id="room_type_id" name="room_type_id" required
                    <?php if (isset($errors['room_type_id'])): ?>
                        aria-invalid="true" aria-describedby="room-type-error"
                    <?php endif; ?>
                >
                    <option value="">Please choose a room type</option>
                    <?php foreach ($roomTypes as $type): ?>
                        <option
                            value="<?php echo e((string) $type['id']); ?>"
                            data-capacity="<?php echo e((string) $type['capacity']); ?>"
                            data-price="<?php echo e($type['price']); ?>"
                            <?php echo $values['room_type_id'] === (string) $type['id'] ? 'selected' : ''; ?>
                        >
                            <?php
                            printf(
                                '%s - sleeps %d - AUD %s per night',
                                e($type['name']),
                                $type['capacity'],
                                e(number_format((float) $type['price'], 2))
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['room_type_id'])): ?>
                    <p class="field-error" id="room-type-error" role="alert"><?php echo e($errors['room_type_id']); ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="check_in">Check-in date</label>
                <input
                    type="date"
                    id="check_in"
                    name="check_in"
                    value="<?php echo e($values['check_in']); ?>"
                    min="<?php echo e($minDate); ?>"
                    max="<?php echo e($maxDate); ?>"
                    required
                    <?php if (isset($errors['check_in'])): ?>
                        aria-invalid="true" aria-describedby="check-in-error"
                    <?php endif; ?>
                >
                <?php if (isset($errors['check_in'])): ?>
                    <p class="field-error" id="check-in-error" role="alert"><?php echo e($errors['check_in']); ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="check_out">Check-out date</label>
                <input
                    type="date"
                    id="check_out"
                    name="check_out"
                    value="<?php echo e($values['check_out']); ?>"
                    min="<?php echo e($defaultOut); ?>"
                    max="<?php echo e($maxDate); ?>"
                    required
                    aria-describedby="check-out-hint<?php echo isset($errors['check_out']) ? ' check-out-error' : ''; ?>"
                    <?php if (isset($errors['check_out'])): ?>aria-invalid="true"<?php endif; ?>
                >
                <p class="field-hint" id="check-out-hint">
                    Must be after the check-in date. Maximum stay <?php echo BOOKING_MAX_NIGHTS; ?> nights.
                </p>
                <?php if (isset($errors['check_out'])): ?>
                    <p class="field-error" id="check-out-error" role="alert"><?php echo e($errors['check_out']); ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="guest_count">Number of guests</label>
                <input
                    type="number"
                    id="guest_count"
                    name="guest_count"
                    value="<?php echo e($values['guest_count']); ?>"
                    min="1"
                    max="<?php echo e((string) $maxCapacity); ?>"
                    step="1"
                    required
                    aria-describedby="guest-hint<?php echo isset($errors['guest_count']) ? ' guest-error' : ''; ?>"
                    <?php if (isset($errors['guest_count'])): ?>aria-invalid="true"<?php endif; ?>
                >
                <p class="field-hint" id="guest-hint">
                    The maximum depends on the room type you choose.
                </p>
                <?php if (isset($errors['guest_count'])): ?>
                    <p class="field-error" id="guest-error" role="alert"><?php echo e($errors['guest_count']); ?></p>
                <?php endif; ?>
            </div>

            <!-- Filled in by booknow.js. Purely an on-screen estimate: the
                 server recalculates everything from the database when the form
                 is submitted. -->
            <!-- Estimate: clearly marked as indicative. The authoritative
                 figures are recalculated by the server on submission. -->
            <div class="estimate" id="estimate" aria-live="polite">
                <p class="estimate-label">Estimate</p>
                <p class="estimate-value" id="estimate-text">
                    Choose a room type and your dates to see an estimate.
                </p>
                <p class="estimate-note">
                    <strong>Guide only.</strong> The price you are charged and
                    the final availability check are always calculated on the
                    server when you submit this form.
                </p>
            </div>

            <div class="field availability-check">
                <button type="button" class="btn btn-secondary btn-block" id="check-availability-btn">
                    Check availability for these dates
                </button>
                <p id="availability-result" class="availability-result" role="status" aria-live="polite"></p>
            </div>

            <div class="booking-submit">
                <button type="submit" class="btn btn-primary btn-block">Request booking</button>
                <p class="form-note">
                    Submitting creates a booking <strong>request</strong> with
                    the status <strong>pending</strong>. It is not confirmed
                    until our staff review it. No payment is taken and no card
                    details are collected.
                </p>
            </div>

        </form>

    <?php endif; ?>

    </div>
  </div>
</main>

<footer class="site-footer">
    <div class="container">
        <p class="footer-bottom">
            &copy; 2026 Hotel Booking System &mdash; student coursework. All prices in AUD.
        </p>
    </div>
</footer>

<script src="booknow.js"></script>
</body>
</html>
