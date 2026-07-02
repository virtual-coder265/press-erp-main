/**
 * Unified Press ERP date / date-time picker (Flatpickr).
 * Targets inputs with data-press-datepicker="1" and data-press-mode="date"|"datetime".
 *
 * Uses Flatpickr altInput: the visible field is human-readable; the original input
 * keeps ISO values (Y-m-d or Y-m-d\\TH:i) for form posts. Optional data-press-disable-past="1"
 * blocks earlier calendar days and, for datetimes, times earlier than "now" when today is selected.
 */
(function (global) {
    'use strict';

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function parseYmd(str) {
        if (!str || typeof str !== 'string') {
            return null;
        }
        var m = str.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) {
            return null;
        }
        var d = new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        return isNaN(d.getTime()) ? null : d;
    }

    function sameCalendarDay(a, b) {
        return a.getFullYear() === b.getFullYear()
            && a.getMonth() === b.getMonth()
            && a.getDate() === b.getDate();
    }

    function minTimeHHMM(now) {
        return pad2(now.getHours()) + ':' + pad2(now.getMinutes());
    }

    function applyDisablePastTime(instance) {
        if (!instance || !instance.config || !instance.config.enableTime) {
            return;
        }
        var inp = instance.input;
        if (!inp || inp.getAttribute('data-press-disable-past') !== '1') {
            instance.set('minTime', null);
            return;
        }
        var now = new Date();
        var sel = instance.selectedDates[0];
        if (!sel) {
            instance.set('minTime', null);
            return;
        }
        if (sameCalendarDay(sel, now)) {
            instance.set('minTime', minTimeHHMM(now));
        } else {
            instance.set('minTime', null);
        }
    }

    function syncAltInput(instance) {
        if (!instance || !instance.altInput) {
            return;
        }

        var hasValue = !!(instance.selectedDates && instance.selectedDates.length > 0);
        instance.altInput.classList.toggle('has-value', hasValue);

        if (!hasValue) {
            instance.altInput.value = '';
            return;
        }

        if (typeof instance.formatDate === 'function') {
            instance.altInput.value = instance.formatDate(instance.selectedDates[0], instance.config.altFormat);
            return;
        }

        instance.altInput.value = instance.input && instance.input.value ? instance.input.value : '';
    }

    function useInlineCalendar(input) {
        if (!input || !input.closest) {
            return false;
        }
        // Inline calendar only inside modals / project tabs. Full-page workspace forms use a
        // body-floating calendar so Tailwind-sized fields are not confused with the altInput layout.
        if (input.closest('.todo-modal-overlay, .todo-modal')) {
            return true;
        }
        var pvPanel = input.closest('.pv-tab-panel');
        if (pvPanel && pvPanel.classList.contains('is-active')) {
            return true;
        }
        return false;
    }

    function isElementVisible(input) {
        if (!input || typeof input.getClientRects !== 'function') {
            return false;
        }
        if (input.closest && input.closest('[hidden]')) {
            return false;
        }
        var node = input;
        while (node && node.nodeType === 1) {
            var styles = typeof global.getComputedStyle === 'function' ? global.getComputedStyle(node) : null;
            if (styles && (styles.display === 'none' || styles.visibility === 'hidden')) {
                return false;
            }
            node = node.parentElement;
        }
        return input.getClientRects().length > 0;
    }

    /**
     * Project view hides inactive tabs with display:none. Flatpickr must not bind until the panel is visible.
     */
    function isInsideInactiveProjectTab(input) {
        if (!input || !input.closest) {
            return false;
        }
        var panel = input.closest('.pv-tab-panel');
        return !!(panel && !panel.classList.contains('is-active'));
    }

    function refreshProjectViewTabPickers() {
        var pv = document.getElementById('pv-overview');
        if (!pv) {
            return;
        }
        var active = pv.querySelector('.pjs-main-column > .pv-tab-panel.is-active');
        if (!active) {
            return;
        }
        var nodes = active.querySelectorAll('input[data-press-datepicker="1"]');
        for (var i = 0; i < nodes.length; i++) {
            rebind(nodes[i]);
        }
    }

    function buildOptions(input, mode) {
        var isDateTime = mode === 'datetime';
        var disablePast = input.getAttribute('data-press-disable-past') === '1';
        var inlineCal = useInlineCalendar(input);

        var opts = {
            allowInput: false,
            clickOpens: true,
            disableMobile: true,
            altInput: true,
            altInputClass: 'press-dt-alt-input',
            altFormat: isDateTime ? 'M j, Y h:i K' : 'D, M j, Y',
            monthSelectorType: 'dropdown',
            animate: true,
            locale: { firstDayOfWeek: 1 },
            defaultHour: isDateTime ? 9 : undefined,
            defaultMinute: isDateTime ? 0 : undefined,
            onReady: function (selectedDates, dateStr, instance) {
                applyDisablePastTime(instance);
                syncAltInput(instance);
            },
            onOpen: function (selectedDates, dateStr, instance) {
                applyDisablePastTime(instance);
                syncAltInput(instance);
            },
            onMonthChange: function (selectedDates, dateStr, instance) {
                applyDisablePastTime(instance);
            },
            onValueUpdate: function (selectedDates, dateStr, instance) {
                syncAltInput(instance);
            },
            onChange: function (selectedDates, dateStr, instance) {
                applyDisablePastTime(instance);
                syncAltInput(instance);
            },
            onClose: function (selectedDates, dateStr, instance) {
                syncAltInput(instance);
            }
        };

        if (isDateTime) {
            opts.enableTime = true;
            opts.time_24hr = false;
            opts.dateFormat = 'Y-m-d\\TH:i';
        } else {
            opts.dateFormat = 'Y-m-d';
        }

        if (inlineCal) {
            opts.static = true;
        } else {
            opts.static = false;
            opts.appendTo = document.body;
        }

        if (disablePast) {
            opts.minDate = 'today';
        }

        var minStr = input.getAttribute('data-min-date');
        var maxStr = input.getAttribute('data-max-date');
        var minD = parseYmd(minStr);
        var maxD = parseYmd(maxStr);
        if (minD) {
            opts.minDate = minD;
        }
        if (maxD) {
            opts.maxDate = maxD;
        }

        return opts;
    }

    function initOne(input) {
        if (!input || input.nodeName !== 'INPUT') {
            return;
        }
        if (typeof global.flatpickr !== 'function') {
            return;
        }
        if (input._flatpickr) {
            return;
        }
        if (input.getAttribute('data-press-datepicker') !== '1') {
            return;
        }
        if (isInsideInactiveProjectTab(input)) {
            return;
        }
        if (!isElementVisible(input)) {
            return;
        }
        var mode = input.getAttribute('data-press-mode') === 'datetime' ? 'datetime' : 'date';
        var instance = global.flatpickr(input, buildOptions(input, mode));
        syncAltInput(instance);
    }

    function destroyOne(input) {
        if (input && input._flatpickr && typeof input._flatpickr.destroy === 'function') {
            input._flatpickr.destroy();
        }
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        if (!scope.querySelectorAll) {
            return;
        }
        var nodes = scope.querySelectorAll('input[data-press-datepicker="1"]');
        for (var i = 0; i < nodes.length; i++) {
            initOne(nodes[i]);
        }
    }

    function rebind(input) {
        if (typeof input === 'string') {
            input = document.querySelector(input);
        }
        if (!input) {
            return;
        }
        destroyOne(input);
        initOne(input);
    }

    function openInputPicker(input) {
        if (!input || !input._flatpickr) {
            return;
        }
        if (input._flatpickr.altInput && typeof input._flatpickr.altInput.focus === 'function') {
            try {
                input._flatpickr.altInput.focus({ preventScroll: true });
            } catch (err) {
                input._flatpickr.altInput.focus();
            }
        }
        if (typeof input._flatpickr.open === 'function') {
            input._flatpickr.open();
        }
    }

    function bindDeferredInit() {
        document.addEventListener('pointerdown', function (event) {
            var target = event.target;
            if (!target || !target.matches || !target.matches('input[data-press-datepicker="1"]')) {
                return;
            }
            if (target._flatpickr || !isElementVisible(target)) {
                return;
            }
            initOne(target);
            if (target._flatpickr) {
                event.preventDefault();
                openInputPicker(target);
            }
        });

        document.addEventListener('focusin', function (event) {
            var target = event.target;
            if (!target || !target.matches || !target.matches('input[data-press-datepicker="1"]')) {
                return;
            }
            if (target._flatpickr || !isElementVisible(target)) {
                return;
            }
            initOne(target);
            openInputPicker(target);
        });
    }

    /**
     * Sync picker UI and native value (e.g. after opening a modal with preset data).
     */
    function setValue(input, value) {
        if (typeof input === 'string') {
            input = document.querySelector(input);
        }
        if (!input) {
            return;
        }
        if (input._flatpickr) {
            if (!value) {
                input._flatpickr.clear();
            } else {
                input._flatpickr.setDate(value, false);
            }
            syncAltInput(input._flatpickr);
        } else {
            input.value = value || '';
        }
    }

    global.PressErpDateTimePicker = {
        init: init,
        rebind: rebind,
        setValue: setValue,
        refreshProjectViewTabPickers: refreshProjectViewTabPickers
    };

    function boot() {
        init(document);
        bindDeferredInit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(typeof window !== 'undefined' ? window : this);
