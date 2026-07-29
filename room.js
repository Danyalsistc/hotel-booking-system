/* ===========================================================================
   Hotel Booking System - Room page gallery
   ICT304 Capstone 2
   ---------------------------------------------------------------------------
   Progressive enhancement only.

   Without this script the room page is still complete: the main photograph is
   plain HTML, the thumbnails are still visible, and "Book this room" and
   "Check availability" are ordinary links that already carry the correct
   room type in their href. Nothing essential depends on JavaScript.

   With the script, selecting a thumbnail swaps the main photograph, updates
   its alternative text and marks the selected thumbnail.

   Implementation notes:
     - No inline onclick handlers anywhere; listeners are attached here.
     - The room type comes from a data attribute on the gallery container.
     - Only textContent and attribute setters are used. innerHTML is never
       used, so no value can be interpreted as markup.
   =========================================================================== */

(function () {
    'use strict';

    var gallery = document.querySelector('[data-gallery]');

    if (!gallery) {
        return;
    }

    var mainImage = gallery.querySelector('[data-gallery-main]');
    var thumbs    = gallery.querySelectorAll('[data-gallery-thumb]');

    if (!mainImage || thumbs.length === 0) {
        return;
    }

    /* Room type is read from a data attribute rather than being hard-coded,
       so this one script serves all six room pages. */
    var roomType = gallery.getAttribute('data-room-type') || 'Room';

    /**
     * Show the image belonging to a thumbnail button.
     *
     * @param {HTMLElement} button The thumbnail that was chosen.
     */
    function select(button) {
        var full  = button.getAttribute('data-full');
        var label = button.getAttribute('data-view-label') || '';

        if (!full) {
            return;
        }

        mainImage.setAttribute('src', full);

        /* Keep the alternative text meaningful and specific to the view. */
        mainImage.setAttribute(
            'alt',
            label === '' ? roomType : roomType + ' - ' + label
        );

        for (var i = 0; i < thumbs.length; i++) {
            thumbs[i].setAttribute(
                'aria-pressed',
                thumbs[i] === button ? 'true' : 'false'
            );
        }
    }

    for (var i = 0; i < thumbs.length; i++) {
        /* Native <button> elements are used, so Enter and Space already
           activate them. No extra key handling is required. */
        thumbs[i].addEventListener('click', function (event) {
            select(event.currentTarget);
        });
    }

    /* Reflect the view the server rendered as the initially selected one. */
    if (thumbs[0].getAttribute('aria-pressed') !== 'true') {
        thumbs[0].setAttribute('aria-pressed', 'true');
    }
}());
