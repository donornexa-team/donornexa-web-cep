/* Admin donor search: sends the filters to api/donors-search.php with fetch()
   while the admin types (debounced, so a request is sent only after a short
   pause), then redraws the table without reloading the page. */
(function () {
    'use strict';

    var DEBOUNCE_MS = 300;

    document.addEventListener('DOMContentLoaded', function () {
        var section = document.getElementById('donor-search');
        if (!section || !window.DonorNexa) {
            return;
        }

        var apiUrl = section.getAttribute('data-api-url');
        var form = document.getElementById('donor-filter');
        var body = document.getElementById('ds-rows');
        var status = document.getElementById('ds-status');
        var prev = document.getElementById('ds-prev');
        var next = document.getElementById('ds-next');
        var pageLabel = document.getElementById('ds-page');

        var page = 1;
        var timer = null;
        var controller = null;   // lets a newer search cancel the older one still in flight

        function cell(text, className) {
            var td = document.createElement('td');
            td.textContent = text;
            if (className) {
                td.className = className;
            }
            return td;
        }

        function yesNo(value) {
            return value ? 'Yes' : 'No';
        }

        function render(data) {
            body.textContent = '';

            if (!data.rows.length) {
                var tr = document.createElement('tr');
                var td = cell('No donors match your search.', 'empty-cell');
                td.colSpan = 7;
                tr.appendChild(td);
                body.appendChild(tr);
            }

            data.rows.forEach(function (row) {
                var tr = document.createElement('tr');
                tr.appendChild(cell(row.name));
                tr.appendChild(cell(row.blood_group));
                tr.appendChild(cell(row.city));
                tr.appendChild(cell(row.status === 'active' ? 'Active' : 'Blocked'));
                tr.appendChild(cell(yesNo(row.available)));
                tr.appendChild(cell(row.last_donation_date || 'Never'));
                tr.appendChild(cell(yesNo(row.eligible), row.eligible ? 'is-yes' : 'is-no'));
                body.appendChild(tr);
            });

            var from = data.total === 0 ? 0 : (data.page - 1) * data.per_page + 1;
            var to = Math.min(data.page * data.per_page, data.total);
            status.textContent = data.total === 0
                ? 'No donors found.'
                : 'Showing ' + from + '-' + to + ' of ' + data.total + ' donors';

            page = data.page;
            pageLabel.textContent = 'Page ' + data.page + ' of ' + data.pages;
            prev.disabled = data.page <= 1;
            next.disabled = data.page >= data.pages;
        }

        function queryString() {
            var params = new URLSearchParams();
            ['q', 'blood_group', 'city_id', 'sort', 'dir'].forEach(function (name) {
                var value = form.elements[name].value.trim();
                if (value !== '') {
                    params.set(name, value);
                }
            });
            if (form.elements.eligible.checked) {
                params.set('eligible', '1');
            }
            if (page > 1) {
                params.set('page', String(page));
            }
            return params.toString();
        }

        function search() {
            if (controller) {
                controller.abort();
            }
            controller = new AbortController();
            status.textContent = 'Searching...';

            window.DonorNexa.fetchJson(apiUrl + '?' + queryString(), { signal: controller.signal })
                .then(render)
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;   // replaced by a newer search
                    }
                    body.textContent = '';
                    status.textContent = 'The search failed. Check your connection and try again.';
                });
        }

        // A new filter always starts again from page 1.
        function newSearch() {
            page = 1;
            search();
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            newSearch();
        });

        form.elements.q.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(newSearch, DEBOUNCE_MS);
        });

        ['blood_group', 'city_id', 'sort', 'dir', 'eligible'].forEach(function (name) {
            form.elements[name].addEventListener('change', newSearch);
        });

        prev.addEventListener('click', function () {
            page = Math.max(1, page - 1);
            search();
        });
        next.addEventListener('click', function () {
            page += 1;
            search();
        });

        search();
    });
})();
