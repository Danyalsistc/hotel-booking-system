/*
   Booking form progressive enhancement. Convenience only - it keeps check-out
   after check-in, shows the guest limit and an estimated total, and asks
   check_availability.php whether rooms are free.

   Deliberately NOT authoritative: it stores nothing, generates no reference,
   and decides neither the price nor availability. The server recalculates all
   of it and re-checks availability inside a locking transaction on every
   submit, so the booking process still works if this file is deleted.

   All output uses textContent, never innerHTML.
 */

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
    var availBtn     = document.getElementById('check-availability-btn');
    var availResult  = document.getElementById('availability-result');

    /* Booking summary elements. */
    var sumEmpty   = document.getElementById('summary-empty');
    var sumList    = document.getElementById('summary-list');
    var sumProblem = document.getElementById('summary-problem');
    var sumRoom    = document.getElementById('sum-room');
    var sumCheckIn = document.getElementById('sum-checkin');
    var sumCheckOut= document.getElementById('sum-checkout');
    var sumNights  = document.getElementById('sum-nights');
    var sumGuests  = document.getElementById('sum-guests');
    var sumRate    = document.getElementById('sum-rate');
    var sumTotal   = document.getElementById('sum-total');

    /* Helpers */

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

    /* Date guidance: check-out must always follow check-in. */

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

    /* Guest guidance. The server still validates against room_types.capacity. */

    /** Capacity of the currently selected room type, or 0 if unknown. */
    function selectedCapacity() {
        var option = selectedOption();

        if (!option) {
            return 0;
        }

        var capacity = parseInt(option.getAttribute('data-capacity'), 10);

        return (isFinite(capacity) && capacity > 0) ? capacity : 0;
    }

    /**
     * Narrow the guest field to the chosen room's capacity. The field starts at
     * the largest capacity any type offers, because the server renders it
     * before a type is chosen. Guidance only - the server re-checks against the
     * locked room_types row.
     */
    function syncGuests() {
        var capacity = selectedCapacity();

        if (!capacity || !guestCount) {
            return;
        }

        guestCount.max = String(capacity);

        setText(
            guestHint,
            'The ' + selectedRoomName() + ' sleeps up to ' + capacity
                + ' guest' + (capacity === 1 ? '' : 's') + '.'
        );
    }

    /** Display name of the chosen room type, without the price suffix. */
    function selectedRoomName() {
        var option = selectedOption();

        if (!option) {
            return 'selected room';
        }

        /* Option text is "Deluxe Suite - sleeps 3 - AUD 250.00 per night". */
        return option.textContent.split(' - ')[0].trim();
    }

    /* Booking summary. Display only - nothing computed here is submitted and
       there is no hidden price, total or night-count field. The server
       recalculates all of it from the locked room_types row. */

    /** Show the neutral "not enough information yet" state. */
    function summaryEmpty(message) {
        if (sumEmpty) {
            sumEmpty.textContent = message ||
                'Complete your dates and guest details to see the booking estimate.';
            sumEmpty.hidden = false;
        }
        if (sumList) { sumList.hidden = true; }
        if (sumProblem) { sumProblem.hidden = true; sumProblem.textContent = ''; }
    }

    /** Show a specific problem with what the customer has entered. */
    function summaryProblem(message) {
        if (sumProblem) {
            sumProblem.textContent = message;
            sumProblem.hidden = false;
        }
        if (sumEmpty) { sumEmpty.hidden = true; }
        if (sumList) { sumList.hidden = true; }
    }

    /** Format a date as "Sun 9 Aug 2026", matching the dashboards. */
    function formatDate(d) {
        var days   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return days[d.getUTCDay()] + ' ' + d.getUTCDate() + ' '
             + months[d.getUTCMonth()] + ' ' + d.getUTCFullYear();
    }

    /** Consistent AUD formatting, matching the server's output. */
    function money(amount) {
        return 'AUD ' + amount.toFixed(2);
    }

    function updateSummary() {
        var option = selectedOption();

        if (!option) {
            summaryEmpty('Choose a room type, your dates and the number of guests to see the booking estimate.');
            return;
        }

        if (!checkIn || !checkOut || !checkIn.value || !checkOut.value) {
            summaryEmpty();
            return;
        }

        var from = parseDate(checkIn.value);
        var to   = parseDate(checkOut.value);

        if (!from || !to) {
            summaryProblem('Please enter both dates in the format YYYY-MM-DD.');
            return;
        }

        var nights = nightsBetween(from, to);

        if (nights < 1) {
            summaryProblem('The check-out date must be after the check-in date.');
            return;
        }

        if (nights > 30) {
            summaryProblem('A single booking can cover at most 30 nights. Please shorten the stay.');
            return;
        }

        var guests   = parseInt(guestCount ? guestCount.value : '', 10);
        var capacity = selectedCapacity();

        if (!isFinite(guests) || guests < 1) {
            summaryEmpty('Enter how many guests are staying to see the booking estimate.');
            return;
        }

        if (capacity && guests > capacity) {
            summaryProblem(
                'The ' + selectedRoomName() + ' sleeps up to ' + capacity
                    + ' guest' + (capacity === 1 ? '' : 's') + ', but ' + guests
                    + ' are entered. Please reduce the number of guests or choose a larger room.'
            );
            if (guestCount) { guestCount.setAttribute('aria-invalid', 'true'); }
            return;
        }

        if (guestCount) { guestCount.removeAttribute('aria-invalid'); }

        var rate = parseFloat(option.getAttribute('data-price'));

        setText(sumRoom, selectedRoomName());
        setText(sumCheckIn, formatDate(from));
        setText(sumCheckOut, formatDate(to));
        setText(sumNights, String(nights) + (nights === 1 ? ' night' : ' nights'));
        setText(sumGuests, String(guests) + (guests === 1 ? ' guest' : ' guests'));

        if (isFinite(rate)) {
            setText(sumRate, money(rate) + ' per night');
            setText(sumTotal, money(rate * nights));
        } else {
            setText(sumRate, 'Shown at confirmation');
            setText(sumTotal, 'Shown at confirmation');
        }

        if (sumEmpty) { sumEmpty.hidden = true; }
        if (sumProblem) { sumProblem.hidden = true; sumProblem.textContent = ''; }
        if (sumList) { sumList.hidden = false; }
    }

    /* Availability lookup. Read-only; reserves nothing. */

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

    /* Wiring */

    if (roomType) {
        roomType.addEventListener('change', function () {
            syncGuests();
            updateSummary();
            setText(availResult, '');
        });
    }

    if (checkIn) {
        checkIn.addEventListener('change', function () {
            syncDates();
            updateSummary();
            setText(availResult, '');
        });
    }

    if (checkOut) {
        checkOut.addEventListener('change', function () {
            updateSummary();
            setText(availResult, '');
        });
    }

    if (guestCount) {
        /* "input" as well as "change" so the guest-capacity warning appears as
           the number is typed or stepped, not only when the field is left. */
        guestCount.addEventListener('input', updateSummary);
        guestCount.addEventListener('change', updateSummary);
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
    updateSummary();
}());
