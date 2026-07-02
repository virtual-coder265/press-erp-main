/**
 * Keeps native end date on or after start date within forms marked data-native-date-range.
 */
(function (global) {
    'use strict';

    function syncPair(start, end) {
        if (!start || !end) {
            return;
        }
        if (start.value) {
            end.min = start.value;
            if (end.value && end.value < start.value) {
                end.value = start.value;
            }
        } else {
            end.removeAttribute('min');
        }
    }

    function bindForm(form) {
        if (!form || form.dataset.nativeDateRangeBound === '1') {
            return;
        }
        var start = form.querySelector('[data-native-date-start]');
        var end = form.querySelector('[data-native-date-end]');
        if (!start || !end) {
            return;
        }
        var onChange = function () {
            syncPair(start, end);
        };
        start.addEventListener('change', onChange);
        end.addEventListener('change', onChange);
        syncPair(start, end);
        form.dataset.nativeDateRangeBound = '1';
    }

    function init(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        root.querySelectorAll('form[data-native-date-range]').forEach(bindForm);
    }

    global.PressErpNativeDateRange = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window);
