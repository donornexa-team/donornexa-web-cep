/* Asks "are you sure?" before a form with a data-confirm message is sent
   (accept a request, cancel a request, mark a donation). The server checks
   everything again; this only prevents accidental clicks. */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var button = event.submitter || form.querySelector('[data-confirm]');
        var message = (button && button.getAttribute('data-confirm')) || form.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
})();
