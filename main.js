/* ===========================================================================
   Hotel Booking System - Homepage behaviour
   ICT304 Capstone 2
   ---------------------------------------------------------------------------
   Progressive enhancement only. The homepage is complete and usable with this
   file absent or blocked:

     - the hero poster image and all hero text are plain HTML;
     - every navigation item and call to action is a real link.

   This script only adds the background video. The <video> element carries no
   autoplay attribute, so playback starts here and ONLY when the visitor has
   not asked for reduced motion.

   No innerHTML is used anywhere, so no value can be interpreted as markup.
   =========================================================================== */

(function () {
    'use strict';

    var video    = document.getElementById('hero-video');
    var controls = document.getElementById('hero-video-controls');
    var toggle   = document.getElementById('hero-video-toggle');

    if (!video || !controls || !toggle) {
        return;
    }

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Keep the button label in step with the actual playback state. */
    function syncLabel() {
        var playing = !video.paused && !video.ended;

        toggle.textContent = playing
            ? 'Pause background video'
            : 'Play background video';

        toggle.setAttribute('aria-pressed', playing ? 'false' : 'true');
    }

    /* If the file is missing or the format is unsupported, hide the video so
       the poster and overlay carry the hero on their own. */
    video.addEventListener('error', function () {
        video.hidden = true;
        controls.hidden = true;
    });

    toggle.addEventListener('click', function () {
        if (video.paused) {
            var attempt = video.play();

            /* play() rejects when the browser blocks playback. Reflect the
               real state rather than assuming success. */
            if (attempt && typeof attempt.catch === 'function') {
                attempt.catch(function () { syncLabel(); });
            }
        } else {
            video.pause();
        }

        syncLabel();
    });

    video.addEventListener('play', syncLabel);
    video.addEventListener('pause', syncLabel);

    /* Reduced motion: leave the video paused on its poster frame. The control
       is still offered, so a visitor can start it deliberately if they want. */
    if (!reduceMotion) {
        var started = video.play();

        if (started && typeof started.catch === 'function') {
            started.catch(function () { syncLabel(); });
        }
    }

    controls.hidden = false;
    syncLabel();
}());
