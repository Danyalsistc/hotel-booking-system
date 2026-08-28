<?php
declare(strict_types=1);

/**
 * Booking status vocabulary.
 *
 * Shared by both dashboards, the admin filters and the confirmation page so a
 * status is spelled the same way everywhere.
 */

/**
 * Display order for the status list. This is deliberately NOT the ENUM
 * declaration order - see migrations/2026-08-20-add-cancellation-request.sql.
 */
const BOOKING_STATUSES = [
    'pending',
    'confirmed',
    'cancellation_requested',
    'cancelled',
    'completed',
];

/**
 * Statuses a customer may request cancellation from. Excluding
 * 'cancellation_requested' is what prevents duplicate requests.
 */
const BOOKING_CANCELLABLE_STATUSES = ['pending', 'confirmed'];

/** Human-readable label. Unknown values never echo the raw column value. */
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

/** CSS modifier class for a status badge. */
function booking_status_class(string $status): string
{
    return in_array($status, BOOKING_STATUSES, true)
        ? 'status-' . $status
        : 'status-unknown';
}

/** May the customer raise a cancellation request against this status? */
function booking_status_can_request_cancellation(string $status): bool
{
    return in_array($status, BOOKING_CANCELLABLE_STATUSES, true);
}
