/**
 * Workspace Shell - shared click/hover modal + sidebar helpers.
 *
 * Provides:
 *   - window.openWorkspaceModal(id)
 *   - window.closeWorkspaceModal(id)
 *   - Auto-wires elements with [data-ws-open="modalId"] and [data-ws-close]
 *   - Hover preview flyouts via [data-ws-hover="cardId"]
 *   - Sidebar mobile toggle via [data-ws-sidebar-toggle]
 *   - Modal tab switching via [data-ws-tab] / [data-ws-tab-target]
 *   - onFirstOpen callback registry via registerWorkspaceModal(id, fn)
 */
(function (global) {
    'use strict';

    var openedOnce = {};
    var firstOpenHandlers = {};
    var activeModals = [];

    function query(selector, root) {
        return (root || document).querySelector(selector);
    }

    function queryAll(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function closeAllHoverCards() {
        queryAll('.todo-hover-card.is-open').forEach(function (card) {
            card.classList.remove('is-open');
        });
    }

    function openModal(id) {
        if (!id) return;
        var el = document.getElementById(id);
        if (!el) return;
        closeAllHoverCards();
        el.classList.add('is-active');
        el.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ws-modal-open');
        document.dispatchEvent(new CustomEvent('ajax:modal-open', { detail: { modalId: id } }));
        if (activeModals.indexOf(id) === -1) activeModals.push(id);

        if (!openedOnce[id]) {
            openedOnce[id] = true;
            if (typeof firstOpenHandlers[id] === 'function') {
                try { firstOpenHandlers[id](el); } catch (err) { /* swallow */ }
            }
        }
    }

    function closeModal(id) {
        var el = id ? document.getElementById(id) : null;
        if (el) {
            el.classList.remove('is-active');
            el.setAttribute('aria-hidden', 'true');
        }
        activeModals = activeModals.filter(function (x) { return x !== id; });
        if (activeModals.length === 0) {
            document.body.classList.remove('ws-modal-open');
        }
    }

    function closeTopModal() {
        if (!activeModals.length) return;
        closeModal(activeModals[activeModals.length - 1]);
    }

    function registerModal(id, onFirstOpen) {
        firstOpenHandlers[id] = onFirstOpen;
    }

    // ---------------- Hover flyouts ----------------
    function bindHoverCards() {
        queryAll('[data-ws-hover]').forEach(function (trigger) {
            var cardId = trigger.getAttribute('data-ws-hover');
            if (!cardId) return;
            var card = document.getElementById(cardId);
            if (!card) return;

            var timer = null;
            var show = function () {
                clearTimeout(timer);
                if (document.body.classList.contains('ws-modal-open')) return;
                timer = setTimeout(function () {
                    if (document.body.classList.contains('ws-modal-open')) return;
                    card.classList.add('is-open');
                }, 140);
            };
            var hide = function () {
                clearTimeout(timer);
                timer = setTimeout(function () { card.classList.remove('is-open'); }, 120);
            };

            trigger.addEventListener('pointerenter', show);
            trigger.addEventListener('pointerleave', hide);
            trigger.addEventListener('focus', show);
            trigger.addEventListener('blur', hide);
            card.addEventListener('pointerenter', show);
            card.addEventListener('pointerleave', hide);
        });
    }

    // ---------------- Tabs ----------------
    function bindTabs() {
        queryAll('[data-ws-tab-group]').forEach(function (group) {
            var groupId = group.getAttribute('data-ws-tab-group');
            var tabs = queryAll('[data-ws-tab][data-ws-tab-group-ref="' + groupId + '"]');
            var panels = queryAll('[data-ws-tab-panel][data-ws-tab-group-ref="' + groupId + '"]');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var target = tab.getAttribute('data-ws-tab');
                    tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
                    panels.forEach(function (p) {
                        p.classList.toggle('is-active', p.getAttribute('data-ws-tab-panel') === target);
                    });
                });
            });
        });
    }

    // ---------------- Global wiring ----------------
    function init() {
        // Clickable openers
        document.addEventListener('click', function (e) {
            var opener = e.target.closest('[data-ws-open]');
            if (opener) {
                var id = opener.getAttribute('data-ws-open');
                if (id) {
                    e.preventDefault();
                    openModal(id);
                    return;
                }
            }
            var closer = e.target.closest('[data-ws-close]');
            if (closer) {
                var target = closer.getAttribute('data-ws-close');
                var modal = closer.closest('.todo-modal-overlay');
                e.preventDefault();
                if (target) {
                    closeModal(target);
                } else if (modal && modal.id) {
                    closeModal(modal.id);
                }
            }
        });

        // Click-outside closes modal
        queryAll('.todo-modal-overlay').forEach(function (overlay) {
            overlay.setAttribute('aria-hidden', 'true');
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay && overlay.id) {
                    closeModal(overlay.id);
                }
            });
        });

        // Keyboard accessibility
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeTopModal();
        });

        // Sidebar toggle
        queryAll('[data-ws-sidebar-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-ws-sidebar-toggle');
                var sidebar = target ? document.getElementById(target) : query('.todo-sidebar');
                if (sidebar) sidebar.classList.toggle('is-open');
            });
        });

        bindHoverCards();
        bindTabs();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    global.openWorkspaceModal = openModal;
    global.closeWorkspaceModal = closeModal;
    global.registerWorkspaceModal = registerModal;
})(window);
