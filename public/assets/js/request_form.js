/* New blood request form: instant checks in the browser.
   These repeat the rules of RequestValidator.php for quick feedback only; the
   server checks everything again, because JavaScript can be switched off or
   bypassed. Also stops a double click from sending the form twice. */
(function () {
    'use strict';

    var MAX_DAYS = 30;
    // Latin and Urdu letters, digits, spaces and . , ; : ( ) / # & ' + - (same idea as register.js: no \p{L}, so old phones can parse it)
    var NOTES = /^[A-Za-z\u00C0-\u024F\u0600-\u06FF0-9 .,;:()\/#&'+\-]*$/;

    // Each rule returns an error message, or '' when the value is fine.
    var rules = {
        blood_group: function (v) {
            return v !== '' ? '' : 'Choose the blood group the patient needs.';
        },
        units_needed: function (v) {
            var n = Number(v);
            return (/^\d+$/.test(v) && n >= 1 && n <= 20) ? '' : 'Enter the number of units (1 to 20).';
        },
        urgency: function (v) {
            return v !== '' ? '' : 'Choose how urgent the request is.';
        },
        required_by: function (v) {
            var when = new Date(v);
            if (v === '' || isNaN(when.getTime())) {
                return 'Enter the date and time the blood is needed by.';
            }
            var now = new Date();
            if (when <= now) {
                return 'The required-by time must be in the future.';
            }
            if (when > new Date(now.getTime() + MAX_DAYS * 86400000)) {
                return 'The required-by time can be at most ' + MAX_DAYS + ' days ahead.';
            }
            return '';
        },
        ward_notes: function (v) {
            if (v.length > 255) {
                return 'The notes are too long (255 characters at most).';
            }
            return NOTES.test(v) ? '' : 'Use letters, numbers and simple punctuation only. Never enter a patient name.';
        }
    };

    function showError(field, message) {
        var box = document.getElementById(field.id + '-error');
        if (box) {
            box.textContent = message;
        }
        field.classList.toggle('input-invalid', message !== '');
        if (message !== '') {
            field.setAttribute('aria-invalid', 'true');
        } else {
            field.removeAttribute('aria-invalid');
        }
    }

    function checkField(field) {
        var rule = rules[field.name];
        if (!rule) {
            return true;
        }
        var message = rule(field.value.trim());
        showError(field, message);
        return message === '';
    }

    // datetime-local wants "YYYY-MM-DDTHH:MM" in the visitor's local time.
    function toLocalInput(date) {
        function pad(n) { return n < 10 ? '0' + n : String(n); }
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
            'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-request-form]');
        if (!form) {
            return;
        }

        var when = form.elements.required_by;
        if (when) {
            var now = new Date();
            when.min = toLocalInput(now);
            when.max = toLocalInput(new Date(now.getTime() + MAX_DAYS * 86400000));
        }

        var fields = Array.prototype.slice.call(form.elements).filter(function (el) {
            return rules[el.name];
        });

        fields.forEach(function (field) {
            field.addEventListener('blur', function () { checkField(field); });
            field.addEventListener('change', function () { checkField(field); });
        });

        form.addEventListener('submit', function (event) {
            var firstBad = null;
            fields.forEach(function (field) {
                if (!checkField(field) && firstBad === null) {
                    firstBad = field;
                }
            });

            if (firstBad !== null) {
                event.preventDefault();
                firstBad.focus();
                return;
            }

            // Valid: block a second click while the first submission is on its way.
            var button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = 'Raising request...';
            }
        });
    });
})();
