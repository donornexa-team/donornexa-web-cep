/* Shared JavaScript. Slice-specific scripts go in their own files. */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');

    window.DonorNexa = {
        csrfToken: meta ? meta.content : '',

        /**
         * fetch() wrapper for the api/ endpoints. Sends the CSRF header and
         * returns the parsed JSON.  Example:
         *   DonorNexa.fetchJson('/DonorNexa/api/stats.php').then(function (data) { ... });
         */
        fetchJson: function (url, options) {
            options = options || {};
            options.credentials = 'same-origin';
            options.headers = Object.assign({
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window.DonorNexa.csrfToken
            }, options.headers || {});

            return fetch(url, options).then(function (response) {
                if (!response.ok) {
                    throw new Error('Request failed (' + response.status + ')');
                }
                return response.json();
            });
        }
    };
})();
