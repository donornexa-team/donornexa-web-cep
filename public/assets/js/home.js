/* Landing page: load the live statistics with AJAX (Fetch) and refresh them every 30 s. */
(function () {
    'use strict';

    // app.js (which defines window.DonorNexa) is loaded after this file,
    // so wait until the whole page has been parsed.
    document.addEventListener('DOMContentLoaded', function () {
        var box = document.querySelector('[data-stats-url]');
        if (!box || !window.DonorNexa) {
            return;
        }

        var url = box.getAttribute('data-stats-url');
        var fields = {
            active_requests: document.getElementById('stat-active'),
            registered_donors: document.getElementById('stat-donors'),
            units_fulfilled: document.getElementById('stat-units')
        };

        function show(data) {
            Object.keys(fields).forEach(function (key) {
                if (fields[key] && data[key] !== undefined) {
                    fields[key].textContent = Number(data[key]).toLocaleString();
                }
            });
        }

        function load() {
            window.DonorNexa.fetchJson(url)
                .then(show)
                .catch(function () {
                    // Keep the last numbers; show n/a only if nothing was ever loaded.
                    Object.keys(fields).forEach(function (key) {
                        if (fields[key] && fields[key].textContent.trim() === '–') {
                            fields[key].textContent = 'n/a';
                        }
                    });
                });
        }

        load();
        setInterval(load, 30000);
    });
})();
