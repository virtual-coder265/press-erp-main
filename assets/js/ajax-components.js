/**
 * Press ERP - Reusable AJAX component loader.
 *
 * Declarative API
 * ---------------
 *   data-ajax-component="<id>"
 *       Required. Marks an element as a refreshable component. The id is also
 *       used to resolve the default fragment endpoint (see below).
 *
 *   data-ajax-endpoint="<url>"
 *       Optional override. Defaults to:
 *           <baseUrl>modules/<module>/fragments?id=<componentId>
 *       where <module> is the leading segment of the component id, e.g.
 *           "dashboard.hero.metrics" -> modules/dashboard/fragments?id=...
 *
 *   data-ajax-poll="60000"
 *       Auto-refresh interval in ms. Skipped when document.hidden.
 *
 *   data-ajax-refresh-on="focus,action:reminder.create,modal-open:wsModalTasks"
 *       Comma-separated trigger list. Recognized triggers:
 *         focus                       -> tab/window focus + visibilitychange
 *         action:<actionType>         -> ajax:invalidate event whose
 *                                        detail.scopes / detail.actions match
 *         modal-open:<modalId>        -> ajax:modal-open event with that id
 *
 *   data-ajax-stale="20000"
 *       Minimum age (ms) before a focus-trigger refetches. Defaults to 0.
 *
 *   data-ajax-params='{"cal_month":"2026-05"}'
 *       JSON object merged into the request query string.
 *
 *   data-ajax-controls="1"
 *       Opt in to a manual refresh affordance. Any descendant
 *       [data-ajax-refresh] click triggers AjaxComponents.refresh(thisComponent).
 *
 * Events
 * ------
 *   document  ajax:invalidate     { detail: { scopes: string[], actions?: string[] } }
 *   document  ajax:modal-open     { detail: { modalId: string } }
 *   document  ajax:component:rendered  { detail: { id, root, fresh } }
 *
 * Public JS API
 * -------------
 *   AjaxComponents.refresh(idOrEl, params?)
 *   AjaxComponents.refreshAll(predicate?)
 *   AjaxComponents.invalidate(scopeTags)
 *   AjaxComponents.register(rootEl)
 */
(function (global) {
    'use strict';

    var BASE_URL = (function () {
        var meta = document.querySelector('meta[name="app-base-url"]');
        if (meta && meta.content) {
            return meta.content.endsWith('/') ? meta.content : meta.content + '/';
        }
        if (global.PRESS_ERP_ACTION_MODALS && global.PRESS_ERP_ACTION_MODALS.baseUrl) {
            var raw = global.PRESS_ERP_ACTION_MODALS.baseUrl;
            return raw.endsWith('/') ? raw : raw + '/';
        }
        return '/';
    })();

    var TICK_MS = 5000;
    var registry = new Map();
    var tickHandle = null;

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function parseTriggers(value) {
        var triggers = { focus: false, actions: [], modalOpens: [] };
        if (!value) return triggers;
        value.split(',').forEach(function (raw) {
            var part = String(raw || '').trim();
            if (!part) return;
            if (part === 'focus' || part === 'visibility') {
                triggers.focus = true;
                return;
            }
            if (part.indexOf('action:') === 0) {
                triggers.actions.push(part.slice('action:'.length));
                return;
            }
            if (part.indexOf('modal-open:') === 0) {
                triggers.modalOpens.push(part.slice('modal-open:'.length));
                return;
            }
        });
        return triggers;
    }

    function parseParams(value) {
        if (!value) return {};
        try {
            var parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (err) {
            console.warn('[ajax-components] invalid data-ajax-params JSON:', value, err);
            return {};
        }
    }

    function defaultEndpoint(componentId) {
        var firstDot = componentId.indexOf('.');
        var moduleSlug = firstDot === -1 ? componentId : componentId.slice(0, firstDot);
        return BASE_URL + 'modules/' + moduleSlug + '/fragments';
    }

    function buildUrl(state) {
        var endpoint = state.endpoint || defaultEndpoint(state.id);
        var url;
        try {
            url = new URL(endpoint, global.location.href);
        } catch (err) {
            url = new URL(BASE_URL, global.location.href);
        }
        if (!url.searchParams.has('id')) {
            url.searchParams.set('id', state.id);
        }
        Object.keys(state.params || {}).forEach(function (key) {
            var v = state.params[key];
            if (v === undefined || v === null) return;
            url.searchParams.set(key, String(v));
        });
        return url.toString();
    }

    function readState(el) {
        if (!el || !el.getAttribute) return null;
        var id = el.getAttribute('data-ajax-component');
        if (!id) return null;
        var existing = registry.get(el);
        var state = existing || {};
        state.el = el;
        state.id = id;
        state.endpoint = el.getAttribute('data-ajax-endpoint') || '';
        state.params = parseParams(el.getAttribute('data-ajax-params'));
        state.poll = parseInt(el.getAttribute('data-ajax-poll') || '0', 10) || 0;
        state.stale = parseInt(el.getAttribute('data-ajax-stale') || '0', 10) || 0;
        state.triggers = parseTriggers(el.getAttribute('data-ajax-refresh-on'));
        if (!state.lastFetchAt) state.lastFetchAt = Date.now();
        if (!state.nextPollAt && state.poll > 0) state.nextPollAt = Date.now() + state.poll;
        state.inFlight = state.inFlight || false;
        return state;
    }

    function register(el) {
        var state = readState(el);
        if (!state) return null;
        registry.set(el, state);
        ensureTick();
        return state;
    }

    function unregister(el) {
        registry.delete(el);
    }

    function scanRoot(root) {
        qsa('[data-ajax-component]', root || document).forEach(function (el) {
            register(el);
        });
    }

    function ensureTick() {
        if (tickHandle) return;
        tickHandle = setInterval(tick, TICK_MS);
    }

    function tick() {
        if (document.hidden) return;
        var now = Date.now();
        registry.forEach(function (state) {
            if (!state.poll || state.inFlight) return;
            if (!document.contains(state.el)) {
                registry.delete(state.el);
                return;
            }
            if (!state.nextPollAt) state.nextPollAt = now + state.poll;
            if (now >= state.nextPollAt) {
                fetchAndSwap(state).catch(function () { /* logged inside */ });
            }
        });
    }

    function setLoading(state, loading) {
        if (!state.el || !state.el.classList) return;
        state.el.classList.toggle('is-ajax-loading', !!loading);
        state.el.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    function emitRendered(detail) {
        document.dispatchEvent(new CustomEvent('ajax:component:rendered', {
            detail: detail
        }));
    }

    function fetchAndSwap(state, params) {
        if (!state || !state.el || !document.contains(state.el)) {
            return Promise.resolve(null);
        }
        if (state.inFlight) {
            return state.inFlight;
        }
        if (params && typeof params === 'object') {
            state.params = Object.assign({}, state.params || {}, params);
        }

        var url = buildUrl(state);
        setLoading(state, true);
        var promise = fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            skipGlobalLoader: true,
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Fragment ' + state.id + ' returned ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            return swapMarkup(state, html);
        }).catch(function (err) {
            console.warn('[ajax-components] refresh failed for ' + state.id, err);
            return null;
        }).finally(function () {
            state.inFlight = false;
            if (state.el && document.contains(state.el)) {
                setLoading(state, false);
                state.lastFetchAt = Date.now();
                if (state.poll > 0) {
                    state.nextPollAt = state.lastFetchAt + state.poll;
                }
            }
        });

        state.inFlight = promise;
        return promise;
    }

    function destroyChartsIn(el) {
        if (!el || typeof global.Chart === 'undefined' || typeof global.Chart.getChart !== 'function') {
            return;
        }
        el.querySelectorAll('canvas').forEach(function (canvas) {
            var ch = global.Chart.getChart(canvas);
            if (ch) {
                ch.destroy();
            }
        });
    }

    function swapMarkup(state, html) {
        var oldEl = state.el;
        if (!oldEl || !document.contains(oldEl) || typeof html !== 'string') {
            return null;
        }

        destroyChartsIn(oldEl);

        var template = document.createElement('template');
        template.innerHTML = html.trim();
        var fresh = template.content.querySelector('[data-ajax-component="' + state.id + '"]');
        if (!fresh) {
            // Fallback: take first element child if any (e.g. server forgot data-ajax-component)
            fresh = template.content.firstElementChild;
            if (!fresh) {
                return null;
            }
            fresh.setAttribute('data-ajax-component', state.id);
        }

        oldEl.replaceWith(fresh);
        registry.delete(oldEl);
        var newState = readState(fresh);
        if (newState) {
            // Preserve cumulative metadata
            newState.params = Object.assign({}, state.params || {}, newState.params || {});
            registry.set(fresh, newState);
        }
        emitRendered({ id: state.id, root: fresh, fresh: true });
        if (typeof global.refreshAppShellIcons === 'function') {
            global.refreshAppShellIcons();
        }
        return fresh;
    }

    function findComponent(idOrEl) {
        if (!idOrEl) return null;
        if (idOrEl.nodeType === 1) {
            return registry.get(idOrEl) || null;
        }
        var match = null;
        registry.forEach(function (state) {
            if (!match && state.id === idOrEl) match = state;
        });
        if (match) return match;
        var el = document.querySelector('[data-ajax-component="' + idOrEl + '"]');
        return el ? register(el) : null;
    }

    function refresh(idOrEl, params) {
        var state = findComponent(idOrEl);
        if (!state) return Promise.resolve(null);
        return fetchAndSwap(state, params);
    }

    function refreshAll(predicate) {
        var promises = [];
        registry.forEach(function (state) {
            if (typeof predicate !== 'function' || predicate(state)) {
                promises.push(fetchAndSwap(state));
            }
        });
        return Promise.all(promises);
    }

    function invalidate(scopeTags) {
        var detail = Array.isArray(scopeTags) ? { scopes: scopeTags } : (scopeTags || {});
        document.dispatchEvent(new CustomEvent('ajax:invalidate', { detail: detail }));
    }

    function actionMatches(state, detail) {
        if (!state.triggers.actions.length || !detail) return false;
        var scopes = Array.isArray(detail.scopes) ? detail.scopes : [];
        var actions = Array.isArray(detail.actions) ? detail.actions : [];
        var bag = scopes.concat(actions);
        return state.triggers.actions.some(function (token) {
            return bag.indexOf(token) !== -1;
        });
    }

    function handleInvalidate(event) {
        var detail = (event && event.detail) || {};
        registry.forEach(function (state) {
            if (actionMatches(state, detail)) {
                fetchAndSwap(state);
            }
        });
    }

    function handleModalOpen(event) {
        var modalId = event && event.detail && event.detail.modalId;
        if (!modalId) return;
        registry.forEach(function (state) {
            if (state.triggers.modalOpens.indexOf(modalId) !== -1) {
                fetchAndSwap(state);
            }
        });
    }

    function handleFocus() {
        if (document.hidden) return;
        var now = Date.now();
        registry.forEach(function (state) {
            if (!state.triggers.focus) return;
            var stale = state.stale || 0;
            if (stale > 0 && (now - (state.lastFetchAt || 0)) < stale) return;
            fetchAndSwap(state);
        });
    }

    function handleManualRefresh(event) {
        var trigger = event.target.closest && event.target.closest('[data-ajax-refresh]');
        if (!trigger) return;
        var explicit = trigger.getAttribute('data-ajax-refresh');
        var componentEl = explicit
            ? document.querySelector('[data-ajax-component="' + explicit + '"]')
            : trigger.closest('[data-ajax-component]');
        if (!componentEl) return;
        event.preventDefault();
        refresh(componentEl);
    }

    function init() {
        scanRoot(document);
        document.addEventListener('ajax:invalidate', handleInvalidate);
        document.addEventListener('ajax:modal-open', handleModalOpen);
        document.addEventListener('visibilitychange', handleFocus);
        global.addEventListener('focus', handleFocus);
        document.addEventListener('click', handleManualRefresh);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    global.AjaxComponents = {
        refresh: refresh,
        refreshAll: refreshAll,
        invalidate: invalidate,
        register: function (root) { scanRoot(root); },
        unregister: unregister,
        _registry: registry,
        _baseUrl: BASE_URL
    };
})(window);
