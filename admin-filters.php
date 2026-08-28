<?php
declare(strict_types=1);

/**
 * Administrator booking filters.
 *
 * Shared by admin-dashboard.php (applies them) and admin-booking-action.php
 * (carries them back after an action), so the validation rules cannot drift.
 * No value here is ever concatenated into SQL - the caller builds a
 * parameterised WHERE clause and binds these as parameters.
 */

require_once __DIR__ . '/booking-lib.php';
require_once __DIR__ . '/booking-status.php';

/** Longest search term accepted. Longer input is rejected, not silently cut. */
const ADMIN_SEARCH_MAX_LENGTH = 100;

/**
 * Status values that may be filtered on. Taken from BOOKING_STATUSES rather
 * than re-typed, so the drop-down cannot fall out of step with the ENUM.
 */
const ADMIN_FILTER_STATUSES = BOOKING_STATUSES;

/**
 * Read and validate the filter state from a request array (normally $_GET).
 * Always returns a complete structure, never null.
 *
 * @param  array<string, mixed> $request
 * @return array{q:string, status:string, from:string, to:string,
 *               active:bool, notices:array<int,string>}
 */
function admin_filters_read(array $request): array
{
    $notices = [];

    $q = isset($request['q']) && is_string($request['q']) ? trim($request['q']) : '';

    if ($q !== '' && mb_strlen($q) > ADMIN_SEARCH_MAX_LENGTH) {
        // Rejected rather than truncated - silently searching for something
        // different from what was typed would be confusing.
        $notices[] = 'Your search was too long and has been ignored. '
                   . 'Please use ' . ADMIN_SEARCH_MAX_LENGTH . ' characters or fewer.';
        $q = '';
    }

    $status = isset($request['status']) && is_string($request['status'])
        ? trim($request['status'])
        : '';

    // Anything off the whitelist falls back to "All statuses" silently.
    if ($status !== '' && !in_array($status, ADMIN_FILTER_STATUSES, true)) {
        $status = '';
    }

    $from = admin_filters_date($request, 'from', $notices);
    $to   = admin_filters_date($request, 'to', $notices);

    // A reversed range matches nothing, which looks like a bug to the user.
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

    // Same strict parser the booking form uses, so '2026-02-30' is rejected.
    if (booking_parse_date($raw) === null) {
        $notices[] = 'A date filter was not a valid date (YYYY-MM-DD) and has been ignored.';
        return '';
    }

    return $raw;
}

/**
 * Escape LIKE wildcards so a term containing % or _ is matched literally.
 * The value is still bound as a parameter; this only neutralises LIKE's own
 * metacharacters.
 */
function admin_filters_like(string $term): string
{
    return '%' . addcslashes($term, '%_\\') . '%';
}

/**
 * Build the query string that reproduces this filter state.
 *
 * Only these four validated keys are ever emitted. Together with a hard-coded
 * destination path in the caller, that is what stops the filters being used to
 * redirect anywhere else.
 *
 * @param array{q:string, status:string, from:string, to:string} $filters
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
