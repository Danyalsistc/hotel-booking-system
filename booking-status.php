<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Booking status vocabulary
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  One place that knows what the booking statuses ARE and how they are shown
 *  to a human. Shared by the customer dashboard, the administrator dashboard,
 *  the admin filters and the booking confirmation page so that a status can
 *  never be spelled one way on one screen and another way on the next.
 *
 *  Why this file exists at all: the display used to be ucfirst($status), which
 *  is fine for 'pending' but renders the fifth status as
 *  "Cancellation_requested". Rather than sprinkle str_replace() through four
 *  templates, the label is defined once here.
 *
 *  The CSS class is derived separately from the label because a status value
 *  containing an underscore is a perfectly good class name but a poor English
 *  sentence.
 * ===========================================================================
 */

/**
 * Every value in the bookings.status ENUM, in the order a human would expect
 * to see them in a drop-down (which is NOT the order they are declared in the
 * ENUM - the declaration order is chosen to keep an existing database
 * migratable, see migrations/2026-08-20-add-cancellation-request.sql).
 */
const BOOKING_STATUSES = [
    'pending',
    'confirmed',
    'cancellation_requested',
    'cancelled',
    'completed',
];

/**
 * The statuses a customer is allowed to raise a cancellation request from.
 *
 * A 'pending' booking is included: the customer has asked for a room and has
 * not been given one yet, but the request still holds a room against the
 * availability count, so walking away from it needs to be a recorded decision
 * rather than a silent one. Treating pending and confirmed the same way also
 * means the customer sees one consistent control instead of having to work out
 * why the button is missing on some of their bookings.
 *
 * 'cancellation_requested' is excluded, which is what prevents duplicate
 * requests. 'cancelled' and 'completed' are excluded because there is nothing
 * left to cancel.
 */
const BOOKING_CANCELLABLE_STATUSES = ['pending', 'confirmed'];

/**
 * Human-readable label for a status value.
 *
 * Unknown values are never echoed back as-is; they fall back to a neutral
 * label, so a status added to the database but not to this file cannot put a
 * raw column value on screen.
 */
function booking_status_label(string $status): string
{
    $labels = [
        'pending'                => 'Pending',
        'confirmed'              => 'Confirmed',
        'cancellation_requested' => 'Cancellation requested',
        'cancelled'              => 'Cancelled',
        'completed'              => 'Completed',
    ];

    return $labels[$status] ?? 'Unknown';
}

/**
 * CSS modifier class for a status badge.
 *
 * Every badge in theme.css pairs a colour with its own text label, so colour
 * is never the only thing distinguishing one status from another.
 */
function booking_status_class(string $status): string
{
    return in_array($status, BOOKING_STATUSES, true)
        ? 'status-' . $status
        : 'status-unknown';
}

/**
 * May the customer raise a cancellation request against this status?
 */
function booking_status_can_request_cancellation(string $status): bool
{
    return in_array($status, BOOKING_CANCELLABLE_STATUSES, true);
}

// No closing PHP tag.
