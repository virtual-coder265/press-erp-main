/**
 * Global animated loader for fetch, jQuery AJAX, and form submissions.
 *
 * Opt out per request:
 *   fetch(url, { skipGlobalLoader: true })
 *   $.ajax({ url, skipGlobalLoader: true })
 *
 * Background polls (alarm feed, session ping, fragment refresh) are skipped automatically.
 */
(function (global) {
    'use strict';

    var SHOW_DELAY_MS = 180;
    var MIN_VISIBLE_MS = 320;
    var activeCount = 0;
    var showTimer = null;
    var visibleSince = 0;
    var hideTimer = null;
    var root = null;
    var bar = null;
    var labelEl = null;
    var defaultLabel = 'Please wait…';

    var SKIP_URL_RE = [
        /alarm_feed/i,
        /session_ping/i,
        /\/fragments(?:\?|$|[?&]id=)/i,
        /push[/_-]?subscription/i,
        /browser-push/i
    ];

    function getRoot() {
        if (!root) {
            root = document.getElementById('globalRequestLoader');
            bar = document.getElementById('globalRequestLoaderBar');
            labelEl = document.getElementById('globalRequestLoaderLabel');
        }
        return root;
    }

    function shouldSkip(url, options) {
        options = options || {};
        if (options.skipGlobalLoader === true) {
            return true;
        }
        if (options.keepalive === true) {
            return true;
        }
        var urlStr = '';
        if (typeof url === 'string') {
            urlStr = url;
        } else if (url && typeof url === 'object' && url.url) {
            urlStr = url.url;
        }
        if (!urlStr && options && options.url) {
            urlStr = String(options.url);
        }
        try {
            if (urlStr.indexOf('http') !== 0 && global.location && global.location.href) {
                urlStr = new URL(urlStr, global.location.href).pathname + new URL(urlStr, global.location.href).search;
            }
        } catch (err) {
            /* use raw string */
        }
        return SKIP_URL_RE.some(function (re) {
            return re.test(urlStr);
        });
    }

    function setLabel(message) {
        var el = labelEl || (getRoot() && document.getElementById('globalRequestLoaderLabel'));
        if (el) {
            el.textContent = message || defaultLabel;
        }
    }

    function renderVisible() {
        var node = getRoot();
        if (!node) {
            return;
        }
        node.classList.add('is-visible');
        node.setAttribute('aria-hidden', 'false');
        if (bar) {
            bar.classList.add('is-active');
        }
        visibleSince = Date.now();
    }

    function renderHidden() {
        var node = getRoot();
        if (!node) {
            return;
        }
        node.classList.remove('is-visible');
        node.setAttribute('aria-hidden', 'true');
        if (bar) {
            bar.classList.remove('is-active');
        }
        visibleSince = 0;
    }

    function scheduleShow(message) {
        if (message) {
            setLabel(message);
        }
        if (showTimer) {
            return;
        }
        showTimer = setTimeout(function () {
            showTimer = null;
            if (activeCount > 0) {
                renderVisible();
            }
        }, SHOW_DELAY_MS);
    }

    function scheduleHide() {
        if (showTimer) {
            clearTimeout(showTimer);
            showTimer = null;
        }
        if (activeCount > 0) {
            return;
        }

        var elapsed = visibleSince ? Date.now() - visibleSince : MIN_VISIBLE_MS;
        var wait = Math.max(0, MIN_VISIBLE_MS - elapsed);

        if (hideTimer) {
            clearTimeout(hideTimer);
        }
        hideTimer = setTimeout(function () {
            hideTimer = null;
            if (activeCount === 0) {
                renderHidden();
                setLabel(defaultLabel);
            }
        }, wait);
    }

    function begin(message) {
        activeCount += 1;
        scheduleShow(message);
    }

    function end() {
        activeCount = Math.max(0, activeCount - 1);
        scheduleHide();
    }

    function track(promise, message) {
        begin(message);
        return Promise.resolve(promise).finally(function () {
            end();
        });
    }

    function patchFetch() {
        if (!global.fetch || global.fetch.__pressErpLoaderPatched) {
            return;
        }
        var nativeFetch = global.fetch.bind(global);
        function wrappedFetch(input, init) {
            init = init || {};
            if (shouldSkip(input, init)) {
                return nativeFetch(input, init);
            }
            var message = init.loaderMessage || null;
            begin(message);
            return nativeFetch(input, init).finally(function () {
                end();
            });
        }
        wrappedFetch.__pressErpLoaderPatched = true;
        global.fetch = wrappedFetch;
    }

    function patchJquery() {
        if (!global.jQuery || global.jQuery.__pressErpLoaderPatched) {
            return;
        }
        var $ = global.jQuery;
        $.ajaxPrefilter(function (options) {
            if (options.skipGlobalLoader === true || shouldSkip(options.url, options)) {
                return;
            }
            var message = options.loaderMessage || null;
            var previousBeforeSend = options.beforeSend;
            options.beforeSend = function (xhr, settings) {
                var allow = true;
                if (typeof previousBeforeSend === 'function') {
                    allow = previousBeforeSend.call(this, xhr, settings);
                }
                if (allow === false) {
                    return false;
                }
                settings.__grlTracked = true;
                begin(message);
            };
            var previousComplete = options.complete;
            options.complete = function (xhr, status) {
                if (settings && settings.__grlTracked) {
                    end();
                }
                if (typeof previousComplete === 'function') {
                    previousComplete.call(this, xhr, status);
                }
            };
        });
        $.ajaxSetup = $.ajaxSetup || function () {};
        global.jQuery.__pressErpLoaderPatched = true;
    }

    function bindFormSubmissions() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || form.tagName !== 'FORM') {
                return;
            }
            if (form.getAttribute('data-skip-global-loader') === '1') {
                return;
            }
            if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') {
                return;
            }
            if (form.getAttribute('target') === '_blank') {
                return;
            }
            var message = form.getAttribute('data-loader-message') || 'Saving…';
            begin(message);
        }, true);

        global.addEventListener('pagehide', function () {
            activeCount = 0;
            renderHidden();
        });
    }

    function init() {
        getRoot();
        patchFetch();
        patchJquery();
        bindFormSubmissions();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    global.PressErpLoader = {
        track: track,
        show: function (message) {
            begin(message);
        },
        hide: function () {
            end();
        },
        shouldSkip: shouldSkip
    };
})(window);
