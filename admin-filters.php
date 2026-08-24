<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Hotel Booking System - Administrator booking filters
 *  ICT304 Capstone 2
 * ---------------------------------------------------------------------------
 *  Shared by admin-dashboard.php (which applies the filters) and
 *  admin-booking-action.php (which carries them back after Confirm/Cancel so
 *  the administrator returns to the view they were working in).
 *
 *  Both files parse the request through admin_filters_read(), so the rules are
 *  written once and cannot drift apart. Everything is validated here:
 *
 *    - the search term is trimmed and length-capped;
 *    - the status is checked against a whitelist of the four values that
 *      genuinely exist in the bookings.status ENUM;
 *    - dates must be a real YYYY-MM-DD calendar date;
 *    - a reversed date range is detected and reported rather than silently
 *      returning nothing.
 *
 *  No value from this file is ever concatenated into SQL. The caller builds a
 *  parameterised WHERE clause and binds these values as parameters.
 * ===========================================================================
 */

require_once __DIR__ . '/booking-lib.php';
require_once __DIR__ . '/booking-status.php';

/** Longest search term accepted. Longer input is rejected, not silently cut. */
const ADMIN_SEARCH_MAX_LENGTH = 100;

/**
 * The only status values that may be filtered on.
 *
 * Taken straight from BOOKING_STATUSES rather than re-typed, so the filter
 * drop-down and the badges on the two dashboards can never fall out of step:
 * adding a status to booking-status.php makes it filterable automatically.
 * Nothing is invented here - every entry is a real value in the
 * bookings.status ENUM.
 */
const ADMIN_FILTER_STATUSES = BOOKING_STATUSES;

/**
 * Read and validate the filter state from a request array (normally $_GET).
 *
 * Always returns a complete, safe structure - never null - so callers can use
 * it without further checking.
 *
 * @param  array<string, mixed> $request
 * @return array{q:string, status:string, from:string, to:string,
 *               active:bool, notices:array<int,string>}
 */
function admin_filters_read(array $request): array
{
    $notices = [];

    /* ----- Search term ------------------------------------------------- */
    $q = isset($request['q']) && is_string($request['q']) ? trim($request['q']) : '';

    if ($q !== '' && mb_strlen($q) > ADMIN_SEARCH_MAX_LENGTH) {
        // Rejected rather than truncated: silently searching for something
        // different from what was typed would be confusing.
        $notices[] = 'Your search was too long and has been ignored. '
                   . 'Please use ' . ADMIN_SEARCH_MAX_LENGTH . ' characters or fewer.';
        $q = '';
    }

    /* ----- Status ------------------------------------------------------- */
    $status = isset($request['status']) && is_string($request['status'])
        ? trim($request['status'])
        : '';

    // Anything not on the whitelist - including a crafted value - falls back
    // to "All statuses" silently. An unknown status is not an error worth
    // shouting about; it simply does not filter.
    if ($status !== '' && !in_array($status, ADMIN_FILTER_STATUSES, true)) {
        $status = '';
    }

    /* ----- Check-in date range ------------------------------------------ */
    $from = admin_filters_date($request, 'from', $notices);
    $to   = admin_filters_date($request, 'to', $notices);

    // A reversed range would match nothing at all, which looks like a bug to
    // the user. Say so and drop the range instead.
    if ($from !== '' && $to !== '' && $from > $to) {
        $notices[] = 'The "check-in from" date was after the "check-in to" date, '
                   . 'so the date range has been ignored.';
        $from = '';
        $to   = '';
    }

    return [
        'q'       => $q,
        'status'  => $status,
        'from'    => $from,
        'to'      => $to,
        'active'  => ($q !== '' || $status !== '' || $from !== '' || $to !== ''),
        'notices' => $notices,
    ];
}

/**
 * Validate one date field, returning '' when absent or invalid.
 *
 * @param array<string, mixed> $request
 * @param array<int, string>   $notices Appended to by reference.
 */
function admin_filters_date(array $request, string $key, array &$notices): string
{
    $raw = isset($request[$key]) && is_string($request[$key]) ? trim($request[$key]) : '';

    if ($raw === '') {
        return '';
    }

    // Reuses the same strict parser the booking form uses, so '2026-02-30' is
    // rejected here exactly as it is when a customer books.
    if (booking_parse_date($raw) === null) {
        $notices[] = 'A date filter was not a valid date (YYYY-MM-DD) and has been ignored.';
        return '';
    }

    return $raw;
}

/**
 * Escape the LIKE wildcards in a search term.
 *
 * Without this, a term containing % or _ would behave as a wildcard - so
 * searching for "100%" would match far more than expected. The value is still
 * bound as a parameter; this only neutralises LIKE's own metacharacters.
 */
function admin_filters_like(string $term): string
{
    return '%' . addcslashes($term, '%_\\') . '%';
}

/**
 * Build the query string that reproduces this filter state.
 *
 * Only the four known keys are ever emitted, and only after they have been
 * validated by admin_filters_read(). This is what makes it safe to carry the
 * filters through a Confirm/Cancel action: the destination path is hard-coded
 * by the caller and only these whitelisted parameters are appended, so no
 * caller-supplied URL is ever followed.
 *
 * @param array{q:string, status:string, from:string, to:string} $filters
 * @return string Query string beginning with '?', or '' when nothing is set.
 */
function admin_filters_query(array $filters): string
{
    $pairs = [];

    foreach (['q', 'status', 'from', 'to'] as $key) {
        if (isset($filters[$key]) && $filters[$key] !== '') {
            $pairs[$key] = $filters[$key];
        }
    }

    if ($pairs === []) {
        return '';
    }

    return '?' . http_build_query($pairs);
}

// No closing PHP tag.
