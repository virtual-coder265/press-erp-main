/**
 * Warns before leaving pages with unsaved form changes (reload, tab close, in-app links).
 * Opt-in via data-unsaved-guard on a form element.
 *
 * Optional attributes:
 *   data-unsaved-discard="reload"  — discard reloads the page (best for multi-step / dynamic create forms)
 */
(function () {
    'use strict';

    var DEFAULT_MESSAGE = 'You have unsaved changes. Leave this page and lose your progress?';
    var DISCARD_CONFIRM = 'Discard all unsaved changes? Your edits will be lost.';
    var guards = new Map();
    var globalBanner = null;
    var historyTrapInstalled = false;
    var historyPopListenerInstalled = false;
    var beforeUnloadInstalled = false;
    var clickGuardInstalled = false;
    var discardHandlerInstalled = false;

    function isIgnorableField(el) {
        if (!el || el.disabled) {
            return true;
        }
        if (el.type === 'hidden' && el.name === 'csrf_token') {
            return true;
        }
        if (el.closest('[data-unsaved-ignore]')) {
            return true;
        }
        return false;
    }

    function fieldKey(form, el) {
        if (el.name) {
            var group = form.querySelectorAll('[name="' + CSS.escape(el.name) + '"]');
            var index = Array.prototype.indexOf.call(group, el);
            return el.name + '::' + index;
        }
        return (el.id || 'field') + '::0';
    }

    function resolveField(form, key) {
        var parts = String(key).split('::');
        var name = parts[0];
        var index = parseInt(parts[1], 10) || 0;

        if (name && name !== 'field') {
            var group = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
            return group[index] || null;
        }
        return null;
    }

    function captureFieldStates(form) {
        var states = [];
        var elements = form.querySelectorAll('input, select, textarea');

        elements.forEach(function (el) {
            if (isIgnorableField(el)) {
                return;
            }
            if (!el.name && !el.id) {
                return;
            }

            states.push({
                key: fieldKey(form, el),
                type: el.type || '',
                tag: el.tagName,
                value: el.value,
                checked: !!el.checked,
                selectedIndex: typeof el.selectedIndex === 'number' ? el.selectedIndex : -1
            });
        });

        return states;
    }

    function restoreFieldStates(form, states) {
        if (!Array.isArray(states)) {
            return;
        }

        states.forEach(function (state) {
            var el = resolveField(form, state.key);
            if (!el) {
                return;
            }

            if (state.type === 'checkbox' || state.type === 'radio') {
                el.checked = !!state.checked;
            } else if (state.tag === 'SELECT') {
                el.value = state.value;
                if (state.selectedIndex >= 0 && el.options.length > state.selectedIndex) {
                    el.selectedIndex = state.selectedIndex;
                }
            } else if (state.type !== 'file') {
                el.value = state.value;
            }
        });

        form.dispatchEvent(new Event('input', { bubbles: true }));
        form.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function snapshotForm(form) {
        var parts = [];
        var elements = form.querySelectorAll('input, select, textarea');

        elements.forEach(function (el) {
            if (isIgnorableField(el)) {
                return;
            }
            if (!el.name && !el.id) {
                return;
            }

            var key = el.name || el.id;
            if (el.type === 'checkbox' || el.type === 'radio') {
                parts.push(key + '=' + (el.checked ? '1' : '0'));
            } else if (el.type === 'file') {
                parts.push(key + '=' + (el.files && el.files[0] ? el.files[0].name + ':' + el.files[0].size : ''));
            } else {
                parts.push(key + '=' + String(el.value || ''));
            }
        });

        parts.sort();
        return parts.join('\n');
    }

    function ensureGlobalBanner() {
        if (globalBanner) {
            return globalBanner;
        }

        globalBanner = document.createElement('div');
        globalBanner.id = 'formUnsavedBanner';
        globalBanner.className = 'form-unsaved-banner';
        globalBanner.setAttribute('role', 'status');
        globalBanner.setAttribute('aria-live', 'polite');
        globalBanner.innerHTML =
            '<span class="form-unsaved-banner-icon" aria-hidden="true">!</span>' +
            '<span class="form-unsaved-banner-copy">' +
            '<strong>Unsaved changes</strong> <span data-unsaved-banner-detail>Save before leaving or you may lose your work.</span>' +
            '</span>' +
            '<div class="form-unsaved-banner-actions">' +
            '<button type="button" class="form-unsaved-discard-btn" data-unsaved-discard-btn>Discard changes</button>' +
            '</div>';

        var page = document.querySelector('.app-page');
        if (page && page.parentNode) {
            page.parentNode.insertBefore(globalBanner, page);
        } else {
            document.body.insertBefore(globalBanner, document.body.firstChild);
        }

        installDiscardHandler();

        return globalBanner;
    }

    function installDiscardHandler() {
        if (discardHandlerInstalled || !globalBanner) {
            return;
        }
        discardHandlerInstalled = true;

        globalBanner.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('[data-unsaved-discard-btn]');
            if (!button) {
                return;
            }
            discardAllChanges();
        });
    }

    function updateGlobalBanner() {
        var banner = ensureGlobalBanner();
        var dirtyLabels = [];

        guards.forEach(function (guard) {
            if (guard.isDirty()) {
                dirtyLabels.push(guard.label);
            }
        });

        var visible = dirtyLabels.length > 0;
        banner.classList.toggle('is-visible', visible);

        var detail = banner.querySelector('[data-unsaved-banner-detail]');
        if (detail) {
            detail.textContent = visible
                ? ('on ' + dirtyLabels.join(', ') + '. Save or discard before leaving.')
                : 'Save before leaving or you may lose your work.';
        }
    }

    function isAnyDirty() {
        var dirty = false;
        guards.forEach(function (guard) {
            if (guard.isDirty()) {
                dirty = true;
            }
        });
        return dirty;
    }

    function getGuardMessage() {
        var message = DEFAULT_MESSAGE;
        guards.forEach(function (guard) {
            if (guard.isDirty() && guard.message) {
                message = guard.message;
            }
        });
        return message;
    }

    function shouldReloadOnDiscard() {
        var reload = false;
        guards.forEach(function (guard) {
            if (guard.isDirty() && guard.form.getAttribute('data-unsaved-discard') === 'reload') {
                reload = true;
            }
        });
        return reload;
    }

    function discardAllChanges() {
        if (!isAnyDirty()) {
            return;
        }

        if (!window.confirm(DISCARD_CONFIRM)) {
            return;
        }

        guards.forEach(function (guard) {
            guard.markClean();
        });

        if (shouldReloadOnDiscard()) {
            document.dispatchEvent(new CustomEvent('form-unsaved-discarded', {
                bubbles: true,
                detail: { action: 'reload' }
            }));
            window.location.reload();
            return;
        }

        guards.forEach(function (guard) {
            restoreFieldStates(guard.form, guard.baselineStates);
            guard.markClean();
        });

        document.dispatchEvent(new CustomEvent('form-unsaved-discarded', {
            bubbles: true,
            detail: { action: 'restore' }
        }));
    }

    function installBeforeUnload() {
        if (beforeUnloadInstalled) {
            return;
        }
        beforeUnloadInstalled = true;

        window.addEventListener('beforeunload', function (event) {
            if (!isAnyDirty()) {
                return;
            }
            event.preventDefault();
            event.returnValue = getGuardMessage();
            return event.returnValue;
        });
    }

    function installClickGuard() {
        if (clickGuardInstalled) {
            return;
        }
        clickGuardInstalled = true;

        document.body.addEventListener('click', function (event) {
            if (!isAnyDirty()) {
                return;
            }

            var anchor = event.target.closest && event.target.closest('a[href]');
            if (!anchor) {
                return;
            }
            if (anchor.hasAttribute('data-unsaved-allow')) {
                return;
            }
            if (anchor.closest('[data-unsaved-ignore]')) {
                return;
            }

            var href = anchor.getAttribute('href') || '';
            if (href === '' || href.charAt(0) === '#') {
                return;
            }
            if (anchor.target && anchor.target !== '' && anchor.target !== '_self') {
                return;
            }
            if (anchor.hasAttribute('download')) {
                return;
            }
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            if (event.button !== 0) {
                return;
            }

            if (!window.confirm(getGuardMessage())) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);
    }

    function installHistoryTrap() {
        if (historyTrapInstalled || !window.history || !window.history.pushState) {
            return;
        }
        historyTrapInstalled = true;

        try {
            history.pushState({ formUnsavedGuard: true }, '', window.location.href);
        } catch (e) {
            return;
        }

        if (historyPopListenerInstalled) {
            return;
        }

        window.addEventListener('popstate', function () {
            if (!isAnyDirty()) {
                return;
            }
            if (!window.confirm(getGuardMessage())) {
                try {
                    history.pushState({ formUnsavedGuard: true }, '', window.location.href);
                } catch (e) {
                    /* best-effort */
                }
            }
        });
        historyPopListenerInstalled = true;
    }

    function attach(form, options) {
        if (!form || form.nodeName !== 'FORM') {
            return null;
        }

        options = options || {};
        var guard = {
            form: form,
            label: options.label || form.getAttribute('data-unsaved-label') || 'this form',
            message: options.message || form.getAttribute('data-unsaved-message') || DEFAULT_MESSAGE,
            baseline: '',
            baselineStates: [],
            dirty: false,
            isDirty: function () {
                return guard.dirty;
            },
            resetBaseline: function () {
                guard.baselineStates = captureFieldStates(form);
                guard.baseline = snapshotForm(form);
                guard.dirty = false;
                updateGlobalBanner();
            },
            markClean: function () {
                guard.resetBaseline();
            },
            markDirty: function () {
                guard.dirty = true;
                updateGlobalBanner();
            },
            checkDirty: function () {
                var wasDirty = guard.dirty;
                var nextDirty = snapshotForm(form) !== guard.baseline;
                guard.dirty = nextDirty;
                if (nextDirty && !wasDirty) {
                    installHistoryTrap();
                }
                updateGlobalBanner();
                return nextDirty;
            },
            discard: function () {
                if (guard.form.getAttribute('data-unsaved-discard') === 'reload') {
                    guard.markClean();
                    window.location.reload();
                    return;
                }
                restoreFieldStates(guard.form, guard.baselineStates);
                guard.markClean();
            }
        };

        guard.resetBaseline();

        var onChange = function () {
            guard.checkDirty();
        };

        form.addEventListener('input', onChange);
        form.addEventListener('change', onChange);

        form.addEventListener('submit', function (event) {
            guard.markClean();

            setTimeout(function () {
                if (event.defaultPrevented) {
                    guard.checkDirty();
                }
            }, 0);
        });

        guards.set(form, guard);
        installBeforeUnload();
        installClickGuard();
        updateGlobalBanner();

        return guard;
    }

    function init() {
        document.querySelectorAll('form[data-unsaved-guard]').forEach(function (form) {
            if (guards.has(form)) {
                return;
            }
            attach(form);
        });
    }

    window.FormUnsavedGuard = {
        attach: attach,
        init: init,
        resetBaseline: function (form) {
            var guard = guards.get(form);
            if (guard) {
                guard.resetBaseline();
            }
        },
        markClean: function (form) {
            var guard = guards.get(form);
            if (guard) {
                guard.markClean();
            }
        },
        discardAll: discardAllChanges,
        isDirty: isAnyDirty
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
