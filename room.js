/* ===========================================================================
   Hotel Booking System - Room page behaviour
   ICT304 Capstone 2
   ---------------------------------------------------------------------------
   Called from the "Check Availability" button on each room page.

   The original version of this file showed a fake alert ("Checking
   availability for ...") and did nothing else. Real availability depends on a
   date range, which a room page does not collect, so the honest behaviour is
   to take the customer to the booking page with this room type already
   selected, where they can enter dates and get a real answer from the
   database.
   =========================================================================== */

/**
 * Open the booking page with a room type preselected.
 *
 * @param {string} roomTypeName Must match a room_types.name value exactly,
 *                              e.g. "Deluxe Suite". The value is resolved on
 *                              the server against the list of room types; an
 *                              unrecognised name simply preselects nothing.
 */
function goToBooking(roomTypeName) {
    var url = 'booknow.php';

    if (typeof roomTypeName === 'string' && roomTypeName !== '') {
        url += '?room_type=' + encodeURIComponent(roomTypeName);
    }

    window.location.href = url;
}

/**
 * Retained under its original name so the existing room-page buttons keep
 * working. It no longer shows a fake alert.
 */
function checkAvailability(roomTypeName) {
    goToBooking(roomTypeName);
}
