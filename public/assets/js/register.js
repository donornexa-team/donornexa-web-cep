/* Registration forms: instant checks in the browser (mobile numbers, CNIC, passwords ...).
   These repeat the rules of AccountValidator.php for quick feedback only; the server
   checks everything again, because JavaScript can be switched off or bypassed. */
(function () {
    'use strict';

    var NAME = /^[A-Za-z\u00C0-\u024F\u0600-\u06FF][A-Za-z\u00C0-\u024F\u0600-\u06FF .'\-]{1,99}$/;
    var PHONE = /^03\d{2}-\d{7}$/;
    var CNIC = /^\d{5}-\d{7}-\d$/;
    var LICENCE = /^[A-Za-z0-9\/\-]{4,50}$/;
    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    // Each rule returns an error message, or '' when the value is fine.
    var rules = {
        name: function (v) {
            return NAME.test(v) ? '' : 'Enter your full name (letters, spaces, . \' and - only).';
        },
        hospital_name: function (v) {
            return (v.length >= 3 && v.length <= 150) ? '' : 'Enter the hospital name (3 to 150 characters).';
        },
        email: function (v) {
            return (EMAIL.test(v) && v.length <= 150) ? '' : 'Enter a valid email address.';
        },
        password: function (v) {
            var ok = v.length >= 8 && v.length <= 72 && /[A-Za-z]/.test(v) && /\d/.test(v);
            return ok ? '' : 'Use 8 to 72 characters with at least one letter and one number.';
        },
        password_confirm: function (v, input) {
            var first = input.form.elements.password;
            return (first && first.value === v) ? '' : 'The two passwords do not match.';
        },
        phone: function (v) {
            return PHONE.test(v) ? '' : 'Enter a mobile number like 0300-1234567.';
        },
        cnic: function (v) {
            return CNIC.test(v) ? '' : 'Enter your CNIC like 12345-1234567-1.';
        },
        licence_no: function (v) {
            return LICENCE.test(v) ? '' : 'Enter the licence number (4 to 50 letters, numbers, / or -).';
        },
        address: function (v) {
            return v.length <= 255 ? '' : 'The address is too long (255 characters at most).';
        },
        weight: function (v) {
            var n = parseFloat(v);
            return (!isNaN(n) && n >= 30 && n <= 250) ? '' : 'Enter your weight in kg (between 30 and 250).';
        },
        dob: function (v) {
            var born = new Date(v + 'T00:00:00');
            if (isNaN(born.getTime())) {
                return 'Enter your date of birth.';
            }
            var today = new Date();
            var age = today.getFullYear() - born.getFullYear();
            var birthdayPassed = (today.getMonth() > born.getMonth()) ||
                (today.getMonth() === born.getMonth() && today.getDate() >= born.getDate());
            if (!birthdayPassed) {
                age -= 1;
            }
            if (born > today) {
                return 'Date of birth cannot be in the future.';
            }
            return age >= 18 ? '' : 'You must be at least 18 years old to register as a donor.';
        },
        pdf: function (v, input) {
            var file = input.files && input.files[0];
            if (!file) {
                return '';
            }
            if (!/\.pdf$/i.test(file.name)) {
                return 'The licence must be a PDF file.';
            }
            return file.size <= 5 * 1024 * 1024 ? '' : 'The file is too large (5 MB at most).';
        }
    };

    function showMessage(input, message) {
        var box = document.getElementById(input.id + '-error');
        if (box) {
            box.textContent = message;
        }
        input.classList.toggle('input-invalid', message !== '');
        if (message !== '') {
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.removeAttribute('aria-invalid');
        }
    }

    // Returns true when the field is valid; shows or clears its message.
    function checkField(input) {
        var value = (input.type === 'password' || input.type === 'file') ? input.value : input.value.trim();
        var rule = input.getAttribute('data-rule');
        var message = '';

        if (input.required && value === '') {
            message = 'This field is required.';
        } else if (rules[rule] && (value !== '' || rule === 'pdf')) {
            message = rules[rule](value, input);
        }

        showMessage(input, message);
        return message === '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-validate]');
        if (!form) {
            return;
        }

        var fields = Array.prototype.slice.call(form.querySelectorAll('input[data-rule], select[data-rule]'))
            .filter(function (el) { return el.type !== 'hidden'; });

        fields.forEach(function (input) {
            // Check when the user leaves a field, and keep re-checking once it has been marked wrong.
            input.addEventListener('blur', function () { checkField(input); });
            input.addEventListener('change', function () { checkField(input); });
            input.addEventListener('input', function () {
                if (input.classList.contains('input-invalid')) {
                    checkField(input);
                }
            });
        });

        // Changing the first password must re-check the confirmation field.
        if (form.elements.password && form.elements.password_confirm) {
            form.elements.password.addEventListener('input', function () {
                if (form.elements.password_confirm.value !== '') {
                    checkField(form.elements.password_confirm);
                }
            });
        }

        form.addEventListener('submit', function (event) {
            var firstBad = null;
            fields.forEach(function (input) {
                if (!checkField(input) && firstBad === null) {
                    firstBad = input;
                }
            });
            if (firstBad !== null) {
                event.preventDefault();
                firstBad.focus();
            }
        });
    });
})();
