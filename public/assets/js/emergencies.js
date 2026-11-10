/* Emergencies board: live countdown for every request card.
   The server sends data-seconds-left (calculated by MySQL), and we count down
   from the moment the page loaded, so the visitor's clock does not matter. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var timers = document.querySelectorAll('.countdown');
        if (!timers.length) {
            return;
        }

        var loadedAt = Date.now();

        function pad(n) {
            return n < 10 ? '0' + n : String(n);
        }

        function format(total) {
            var days = Math.floor(total / 86400);
            var hours = Math.floor((total % 86400) / 3600);
            var minutes = Math.floor((total % 3600) / 60);
            var seconds = total % 60;
            var text = '';

            if (days > 0) {
                text += days + 'd ';
            }
            if (days > 0 || hours > 0) {
                text += hours + 'h ';
            }
            return text + pad(minutes) + 'm ' + pad(seconds) + 's';
        }

        function tick() {
            var elapsed = Math.floor((Date.now() - loadedAt) / 1000);

            timers.forEach(function (el) {
                var left = parseInt(el.getAttribute('data-seconds-left'), 10) - elapsed;

                if (left <= 0) {
                    el.textContent = 'Expired';
                    el.classList.remove('is-soon');
                    el.classList.add('is-expired');
                } else {
                    el.textContent = format(left);
                    el.classList.toggle('is-soon', left < 3600);
                }
            });
        }

        tick();
        setInterval(tick, 1000);
    });
})();
