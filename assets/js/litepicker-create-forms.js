/**
 * Litepicker for project create (start/end range) and task create (due date).
 */
(function () {
    'use strict';

    var THEME_CLASS = 'lp-create-theme';
    var BOOT_RETRIES = 40;
    var BOOT_INTERVAL_MS = 50;

    function getLitepicker() {
        return window.Litepicker || null;
    }

    function todayDate() {
        var d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }

    function formatYmd(dateObj) {
        if (!dateObj) {
            return '';
        }
        if (typeof dateObj === 'string') {
            var match = dateObj.match(/^(\d{4}-\d{2}-\d{2})/);
            return match ? match[1] : '';
        }
        if (typeof dateObj.format === 'function') {
            return dateObj.format('YYYY-MM-DD');
        }
        var d = typeof dateObj.toJSDate === 'function' ? dateObj.toJSDate() : dateObj;
        if (!(d instanceof Date) || isNaN(d.getTime())) {
            return '';
        }
        var y = d.getFullYear();
        var m = d.getMonth() + 1;
        var day = d.getDate();
        return y + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    function setInputValue(input, value) {
        if (!input || !value) {
            return;
        }
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function applyTheme(picker) {
        if (!picker || !picker.ui) {
            return;
        }
        picker.ui.classList.add(THEME_CLASS);
    }

    function syncFromPicker(picker, startInput, endInput) {
        if (!picker) {
            return;
        }
        var start = typeof picker.getStartDate === 'function' ? picker.getStartDate() : null;
        var end = typeof picker.getEndDate === 'function' ? picker.getEndDate() : null;
        var startStr = formatYmd(start);
        var endStr = formatYmd(end);
        if (startInput && startStr) {
            setInputValue(startInput, startStr);
        }
        if (endInput && endStr) {
            setInputValue(endInput, endStr);
        }
    }

    function wirePickerEvents(picker, startInput, endInput) {
        picker.on('selected', function (date1, date2) {
            var startStr = formatYmd(date1);
            var endStr = formatYmd(date2);
            if (startInput && startStr) {
                setInputValue(startInput, startStr);
            }
            if (endInput && endStr) {
                setInputValue(endInput, endStr);
            }
            syncFromPicker(picker, startInput, endInput);
        });
        picker.on('hide', function () {
            syncFromPicker(picker, startInput, endInput);
        });
    }

    function baseOptions(allowPastDates) {
        var opts = {
            format: 'YYYY-MM-DD',
            autoApply: true,
            numberOfMonths: 1,
            lockInput: false,
            dropdowns: {
                minYear: todayDate().getFullYear() - 5,
                maxYear: todayDate().getFullYear() + 15,
                months: true,
                years: true
            },
            setup: function (picker) {
                picker.on('show', function () {
                    applyTheme(picker);
                });
            }
        };
        if (!allowPastDates) {
            opts.minDate = todayDate();
        }
        return opts;
    }

    function initProjectCreate(Litepicker) {
        var start = document.getElementById('projectCreateStartDate');
        var end = document.getElementById('projectCreateEndDate');
        if (!start || !end || start.dataset.lpBound === '1') {
            return;
        }

        var opts = baseOptions(true);
        opts.element = start;
        opts.elementEnd = end;
        opts.singleMode = false;

        var picker = new Litepicker(opts);
        wirePickerEvents(picker, start, end);

        start.dataset.lpBound = '1';
        end.dataset.lpBound = '1';
    }

    function initTaskCreate(Litepicker) {
        var due = document.getElementById('taskCreateDueDate');
        if (!due || due.dataset.lpBound === '1') {
            return;
        }

        var opts = baseOptions(false);
        opts.element = due;
        opts.singleMode = true;

        var picker = new Litepicker(opts);
        wirePickerEvents(picker, due, null);

        due.dataset.lpBound = '1';
    }

    function boot() {
        var Litepicker = getLitepicker();
        if (!Litepicker) {
            return false;
        }
        document.body.classList.add('lp-create-datepicker-page');
        initProjectCreate(Litepicker);
        initTaskCreate(Litepicker);
        return true;
    }

    function scheduleBoot() {
        if (boot()) {
            return;
        }
        var attempts = 0;
        var timer = window.setInterval(function () {
            attempts += 1;
            if (boot() || attempts >= BOOT_RETRIES) {
                window.clearInterval(timer);
            }
        }, BOOT_INTERVAL_MS);
        window.addEventListener('load', function () {
            if (!document.getElementById('projectCreateStartDate') &&
                !document.getElementById('taskCreateDueDate')) {
                return;
            }
            boot();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleBoot);
    } else {
        scheduleBoot();
    }
})();
