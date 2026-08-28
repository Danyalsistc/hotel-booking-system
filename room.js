/*
   Room page gallery - progressive enhancement only. Without this script the
   page is still complete: the photograph is plain HTML and the booking links
   already carry the correct room type.

   No inline handlers, and innerHTML is never used, so no value can be
   interpreted as markup.
 */

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

    /** Show the image belonging to a thumbnail button. */
    function select(button) {
        var full  = button.getAttribute('data-full');
        var label = button.getAttribute('data-view-label') || '';

        if (!full) {
            return;
        }

        mainImage.setAttribute('src', full);

        /* Keep the alternative text specific to the view. */
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
        /* Native <button> elements, so Enter and Space already activate them. */
        thumbs[i].addEventListener('click', function (event) {
            select(event.currentTarget);
        });
    }

    /* Reflect the view the server rendered as the initially selected one. */
    if (thumbs[0].getAttribute('aria-pressed') !== 'true') {
        thumbs[0].setAttribute('aria-pressed', 'true');
    }
}());
