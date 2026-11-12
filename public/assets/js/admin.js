/* Admin dashboard: loads /admin/charts with fetch() and draws three charts.
   Plain SVG and HTML, no chart library, so the page stays light on slow phones.
   Each chart also gets a visually hidden table for screen readers. */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    function svg(name, attrs, text) {
        var node = document.createElementNS(SVG_NS, name);
        Object.keys(attrs || {}).forEach(function (key) {
            node.setAttribute(key, attrs[key]);
        });
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function html(name, className, text) {
        var node = document.createElement(name);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    /** Accessible fallback: a table with the same numbers (hidden from sight only). */
    function dataTable(caption, headings, rows) {
        var table = html('table', 'visually-hidden');
        table.appendChild(html('caption', '', caption));
        var head = html('tr');
        headings.forEach(function (text) {
            var th = html('th', '', text);
            th.setAttribute('scope', 'col');
            head.appendChild(th);
        });
        table.appendChild(head);
        rows.forEach(function (row) {
            var tr = html('tr');
            row.forEach(function (cell) {
                tr.appendChild(html('td', '', String(cell)));
            });
            table.appendChild(tr);
        });
        return table;
    }

    /**
     * Vertical bar chart. series = [{name, className, values: []}], labels = [].
     * One series gives plain bars, two give side-by-side bars.
     */
    function barChart(box, title, labels, series) {
        var W = 640, H = 260, left = 36, right = 8, top = 16, bottom = 36;
        var plotW = W - left - right, plotH = H - top - bottom;

        var max = 1;
        series.forEach(function (s) {
            s.values.forEach(function (v) { max = Math.max(max, v); });
        });
        // top of the scale: a multiple of 4, so the 4 grid steps are whole numbers
        var niceMax = Math.max(4, Math.ceil(max / 4) * 4);

        var root = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': title, 'class': 'chart-svg' });

        for (var i = 0; i <= 4; i++) {
            var value = niceMax / 4 * i;
            var y = top + plotH - (value / niceMax) * plotH;
            root.appendChild(svg('line', { x1: left, x2: W - right, y1: y, y2: y, 'class': 'chart-grid-line' }));
            root.appendChild(svg('text', { x: left - 6, y: y + 4, 'text-anchor': 'end', 'class': 'chart-text' },
                String(Math.round(value * 10) / 10)));
        }

        var slot = plotW / labels.length;
        var barW = Math.min(28, (slot * 0.7) / series.length);

        labels.forEach(function (label, index) {
            var groupX = left + slot * index + slot / 2 - (barW * series.length) / 2;
            series.forEach(function (s, sIndex) {
                var v = s.values[index];
                var h = (v / niceMax) * plotH;
                var bar = svg('rect', {
                    x: groupX + sIndex * barW, y: top + plotH - h, width: barW - 2, height: h,
                    rx: 2, 'class': s.className
                });
                bar.appendChild(svg('title', {}, label + ' - ' + s.name + ': ' + v));
                root.appendChild(bar);
            });
            root.appendChild(svg('text', {
                x: left + slot * index + slot / 2, y: H - 14, 'text-anchor': 'middle', 'class': 'chart-text'
            }, label));
        });

        box.textContent = '';
        if (series.length > 1) {
            var legend = html('ul', 'chart-legend');
            series.forEach(function (s) {
                var item = html('li');
                item.appendChild(html('span', 'legend-swatch ' + s.className));
                item.appendChild(document.createTextNode(s.name));
                legend.appendChild(item);
            });
            box.appendChild(legend);
        }
        box.appendChild(root);

        var headings = [''].concat(series.map(function (s) { return s.name; }));
        var rows = labels.map(function (label, index) {
            return [label].concat(series.map(function (s) { return s.values[index]; }));
        });
        box.appendChild(dataTable(title, headings, rows));
    }

    /** Horizontal progress bars for the fulfilment rate by urgency. */
    function rateBars(box, title, rows) {
        box.textContent = '';
        var list = html('ul', 'rate-list');

        rows.forEach(function (row) {
            var item = html('li', 'rate-row');
            var head = html('div', 'rate-head');
            head.appendChild(html('span', 'rate-name', row.urgency.charAt(0).toUpperCase() + row.urgency.slice(1)));
            head.appendChild(html('span', 'rate-value', row.rate + '% (' + row.fulfilled + ' of ' + row.needed + ' units)'));
            item.appendChild(head);

            var track = html('div', 'rate-track');
            var fill = html('div', 'rate-fill rate-' + row.urgency);
            fill.style.width = Math.min(100, row.rate) + '%';
            track.appendChild(fill);
            track.setAttribute('role', 'progressbar');
            track.setAttribute('aria-valuemin', '0');
            track.setAttribute('aria-valuemax', '100');
            track.setAttribute('aria-valuenow', String(row.rate));
            track.setAttribute('aria-label', row.urgency + ' requests fulfilled');
            item.appendChild(track);
            list.appendChild(item);
        });

        box.appendChild(list);
    }

    function drawAll(data) {
        var weekly = data.requests_per_week;
        barChart(document.querySelector('#chart-weekly .chart-body'), 'Requests per week',
            weekly.map(function (w) { return w.label; }),
            [{ name: 'Requests', className: 'bar-primary', values: weekly.map(function (w) { return w.count; }) }]);

        var supply = data.demand_vs_supply;
        barChart(document.querySelector('#chart-supply .chart-body'), 'Blood-group demand vs supply',
            supply.map(function (g) { return g.group; }),
            [
                { name: 'Units needed', className: 'bar-primary', values: supply.map(function (g) { return g.demand; }) },
                { name: 'Eligible donors', className: 'bar-secondary', values: supply.map(function (g) { return g.supply; }) }
            ]);

        rateBars(document.querySelector('#chart-fulfilment .chart-body'), 'Fulfilment rate', data.fulfilment);
    }

    function showError() {
        document.querySelectorAll('.chart-body').forEach(function (box) {
            box.textContent = 'The chart could not be loaded. Refresh the page to try again.';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var section = document.querySelector('[data-charts-url]');
        if (!section || !window.DonorNexa) {
            return;
        }

        window.DonorNexa.fetchJson(section.getAttribute('data-charts-url'))
            .then(drawAll)
            .catch(showError);
    });
})();
