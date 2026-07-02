(function () {
    window.refreshAppShellIcons = function () {
        if (typeof lucide === 'undefined' || typeof lucide.createIcons !== 'function') {
            return;
        }
        lucide.createIcons();
    };
})();
