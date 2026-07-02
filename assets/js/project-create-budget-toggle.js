/**
 * Project create form — budget fields enable/disable (moved out of inline script).
 */
(function () {
    'use strict';

    function init() {
        var toggle = document.getElementById('createBudgetToggle');
        var fields = document.getElementById('createBudgetFields');
        if (!toggle || !fields) {
            return;
        }

        function sync() {
            var on = toggle.checked;
            fields.classList.toggle('opacity-50', !on);
            fields.classList.toggle('pointer-events-none', !on);
        }

        toggle.addEventListener('change', sync);
        sync();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
