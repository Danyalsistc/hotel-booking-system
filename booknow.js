/* ===========================================================================
   Hotel Booking System - Booking form progressive enhancement
   ICT304 Capstone 2
   ---------------------------------------------------------------------------
   This script is OPTIONAL. Everything it does is convenience only:

     - keeps the check-out date after the check-in date
     - shows the guest limit for the chosen room type
     - shows an estimated night count and price
     - asks check_availability.php whether rooms are free

   It is deliberately NOT authoritative. It does not:

     - store bookings or any personal data in localStorage
     - generate a booking reference
     - decide the price that is charged
     - decide whether a room is available
     - redirect anyone to the administrator dashboard

   The server recalculates the nights, the rate and the total from the
   database, and re-checks availability inside a locking transaction, every
   time the form is submitted. If this file were deleted the booking process
   would still work correctly.

   All output uses textContent, never innerHTML, so no value can be
   interpreted as markup.
   =========================================================================== */

(function () {
    'use strict';

    var form = document.querySelector('form[action="booknow.php"]');

    if (!form) {
        return;
    }

    var roomType     = document.getElementById('room_type_id');
    var checkIn      = document.getElementById('check_in');
    var checkOut     = document.getElementById('check_out');
    var guestCount   = document.getElementById('guest_count');
    var guestHint    = document.getElementById('guest-hint');
    var estimateText = document.getElementById('estimate-text');
    var availBtn     = document.getElementById('check-availability-btn');
    var availResult  = document.getElementById('availability-result');

    /* ---------------------------------------------------------------------
       Helpers
       --------------------------------------------------------------------- */

    /** Parse YYYY-MM-DD into a UTC date, or null. */
    function parseDate(value) {
        if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return null;
        }

        var parts = value.split('-');
        var date  = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));

        // Reject dates the browser silently rolled over, e.g. 2026-02-30.
        if (date.getUTCFullYear() !== +parts[0]
            || date.getUTCMonth() !== +parts[1] - 1
            || date.getUTCDate() !== +parts[2]) {
            return null;
        }

        return date;
    }

    /** Whole nights between two dates. */
    function nightsBetween(from, to) {
        return Math.round((to - from) / 86400000);
    }

    /** Add days to a YYYY-MM-DD string, returning the same format. */
    function addDays(value, days) {
        var date = parseDate(value);

        if (!date) {
            return '';
        }

        date.setUTCDate(date.getUTCDate() + days);

        return date.toISOString().slice(0, 10);
    }

    function selectedOption() {
        if (!roomType || roomType.selectedIndex < 0) {
            return null;
        }

        var option = roomType.options[roomType.selectedIndex];

        return (option && option.value) ? option : null;
    }

    function setText(element, message) {
        if (element) {
            element.textContent = message;
        }
    }

    /* ---------------------------------------------------------------------
       Date guidance: check-out must always follow check-in.
       --------------------------------------------------------------------- */

    function syncDates() {
        if (!checkIn || !checkOut || !checkIn.value) {
            return;
        }

        var earliestOut = addDays(checkIn.value, 1);

        if (earliestOut) {
            checkOut.min = earliestOut;

            if (checkOut.value && checkOut.value < earliestOut) {
                checkOut.value = earliestOut;
            }
        }
    }

    /* ---------------------------------------------------------------------
       Guest guidance: reflect the capacity of the chosen room type.
       The server still validates this against room_types.capacity.
       --------------------------------------------------------------------- */

    function syncGuests() {
        var option = selectedOption();

        if (!option || !guestCount) {
            return;
        }

        var capacity = parseInt(option.getAttribute('data-capacity'), 10);

        if (!isFinite(capacity) || capacity < 1) {
            return;
        }

        guestCount.max = String(capacity);

        if (parseInt(guestCount.value, 10) > capacity) {
            guestCount.value = String(capacity);
        }

        setText(
            guestHint,
            'This room sleeps up to ' + capacity + ' guest'
                + (capacity === 1 ? '' : 's') + '.'
        );
    }

    /* ---------------------------------------------------------------------
       Estimate. Clearly labelled as a guide in the page itself.
       --------------------------------------------------------------------- */

    function updateEstimate() {
        var option = selectedOption();

        if (!option || !checkIn || !checkOut) {
            setText(estimateText, 'Choose a room type and your dates to see an estimate.');
            return;
        }

        var from = parseDate(checkIn.value);
        var to   = parseDate(checkOut.value);

        if (!from || !to) {
            setText(estimateText, 'Choose a room type and your dates to see an estimate.');
            return;
        }

        var nights = nightsBetween(from, to);

        if (nights < 1) {
            setText(estimateText, 'The check-out date must be after the check-in date.');
            return;
        }

        var rate = parseFloat(option.getAttribute('data-price'));

        if (!isFinite(rate)) {
            setText(estimateText, nights + ' night' + (nights === 1 ? '' : 's') + '.');
            return;
        }

        setText(
            estimateText,
            nights + ' night' + (nights === 1 ? '' : 's')
                + ' at AUD ' + rate.toFixed(2) + ' per night'
                + ' - estimated total AUD ' + (rate * nights).toFixed(2) + '.'
        );
    }

    /* ---------------------------------------------------------------------
       Availability lookup. Read-only; reserves nothing.
       --------------------------------------------------------------------- */

    function checkAvailability() {
        var option = selectedOption();

        if (!option) {
            setText(availResult, 'Please choose a room type first.');
            return;
        }

        if (!checkIn.value || !checkOut.value) {
            setText(availResult, 'Please enter both dates first.');
            return;
        }

        setText(availResult, 'Checking availability...');

        var url = 'check_availability.php'
            + '?room_type_id=' + encodeURIComponent(option.value)
            + '&check_in='     + encodeURIComponent(checkIn.value)
            + '&check_out='    + encodeURIComponent(checkOut.value);

        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                var data = result.data || {};

                if (!data.ok) {
                    setText(availResult, data.error || 'Availability could not be checked.');
                    return;
                }

                if (!data.available) {
                    setText(
                        availResult,
                        'No ' + data.room_type + ' is free for those dates. Please try different dates.'
                    );
                    return;
                }

                setText(
                    availResult,
                    data.rooms_available + ' ' + data.room_type
                        + (data.rooms_available === 1 ? ' is' : 's are') + ' free for those dates'
                        + ' - estimated total AUD ' + data.estimated_total + '.'
                        + ' Submit the form to request the booking.'
                );
            })
            .catch(function () {
                setText(availResult, 'Availability could not be checked. Please try again.');
            });
    }

    /* ---------------------------------------------------------------------
       Wiring
       --------------------------------------------------------------------- */

    if (roomType) {
        roomType.addEventListener('change', function () {
            syncGuests();
            updateEstimate();
            setText(availResult, '');
        });
    }

    if (checkIn) {
        checkIn.addEventListener('change', function () {
            syncDates();
            updateEstimate();
            setText(availResult, '');
        });
    }

    if (checkOut) {
        checkOut.addEventListener('change', function () {
            updateEstimate();
            setText(availResult, '');
        });
    }

    if (guestCount) {
        guestCount.addEventListener('change', updateEstimate);
    }

    if (availBtn) {
        // Only offer the button when the browser can actually make the call.
        if (window.fetch) {
            availBtn.addEventListener('click', checkAvailability);
        } else {
            availBtn.hidden = true;
        }
    }

    // Reflect whatever the server rendered on first load.
    syncDates();
    syncGuests();
    updateEstimate();
}());
