/**
 * Session ping + soft re-auth modal for in-page form recovery without navigation.
 */
(function (global) {
    'use strict';

    let config = null;
    let pingTimer = null;
    let sessionExpired = false;
    let modalEl = null;
    let pendingRetry = null;

    function ensureModal() {
        if (modalEl) {
            return modalEl;
        }

        modalEl = document.createElement('div');
        modalEl.id = 'sessionGuardModal';
        modalEl.className = 'fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-[100]';
        modalEl.innerHTML =
            '<div class="bg-white rounded-xl shadow-2xl p-8 w-full max-w-md mx-4">' +
                '<h3 class="text-2xl font-bold text-gray-800 mb-2">Session expired</h3>' +
                '<p class="text-gray-600 mb-6">Sign in again to sync your work. Your form data stays on this page.</p>' +
                '<form id="sessionGuardForm" class="space-y-4">' +
                    '<div>' +
                        '<label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>' +
                        '<input type="email" id="sessionGuardEmail" required autocomplete="username"' +
                            ' class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">' +
                    '</div>' +
                    '<div>' +
                        '<label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>' +
                        '<input type="password" id="sessionGuardPassword" required autocomplete="current-password"' +
                            ' class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">' +
                    '</div>' +
                    '<p id="sessionGuardError" class="hidden text-sm text-red-600"></p>' +
                    '<button type="submit" id="sessionGuardSubmit"' +
                        ' class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition">Sign in and continue</button>' +
                '</form>' +
            '</div>';
        document.body.appendChild(modalEl);

        modalEl.querySelector('#sessionGuardForm').addEventListener('submit', function (event) {
            event.preventDefault();
            submitReauth();
        });

        return modalEl;
    }

    function showModal() {
        ensureModal();
        const emailInput = modalEl.querySelector('#sessionGuardEmail');
        if (config && config.userEmail && emailInput && !emailInput.value) {
            emailInput.value = config.userEmail;
        }
        modalEl.classList.remove('hidden');
    }

    function hideModal() {
        if (modalEl) {
            modalEl.classList.add('hidden');
            const errorEl = modalEl.querySelector('#sessionGuardError');
            if (errorEl) {
                errorEl.classList.add('hidden');
                errorEl.textContent = '';
            }
        }
    }

    function submitReauth() {
        if (!config || !config.reauthUrl) {
            return;
        }

        const email = modalEl.querySelector('#sessionGuardEmail').value.trim();
        const password = modalEl.querySelector('#sessionGuardPassword').value;
        const submitBtn = modalEl.querySelector('#sessionGuardSubmit');
        const errorEl = modalEl.querySelector('#sessionGuardError');

        submitBtn.disabled = true;
        submitBtn.textContent = 'Signing in...';
        errorEl.classList.add('hidden');

        const body = new URLSearchParams();
        body.append('email', email);
        body.append('password', password);

        fetch(config.reauthUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    throw new Error(result.data.message || 'Sign in failed.');
                }
                sessionExpired = false;
                hideModal();
                if (typeof config.onSessionRestored === 'function') {
                    config.onSessionRestored(result.data);
                }
                if (typeof pendingRetry === 'function') {
                    const retry = pendingRetry;
                    pendingRetry = null;
                    retry();
                }
            })
            .catch(function (err) {
                errorEl.textContent = err.message || 'Sign in failed. Please try again.';
                errorEl.classList.remove('hidden');
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Sign in and continue';
            });
    }

    function handleUnauthorized(retryFn) {
        sessionExpired = true;
        pendingRetry = retryFn || null;
        if (typeof config.onSessionExpired === 'function') {
            config.onSessionExpired();
        }
        showModal();
    }

    function authFetch(url, options) {
        options = options || {};
        options.credentials = options.credentials || 'same-origin';

        return fetch(url, options).then(function (response) {
            if (response.status === 401) {
                return new Promise(function (resolve, reject) {
                    handleUnauthorized(function () {
                        authFetch(url, options).then(resolve).catch(reject);
                    });
                });
            }
            return response;
        });
    }

    function ping() {
        if (!config || !config.pingUrl || !navigator.onLine) {
            return Promise.resolve();
        }

        return fetch(config.pingUrl, {
            method: 'GET',
            credentials: 'same-origin',
            skipGlobalLoader: true,
            headers: { 'Accept': 'application/json' },
        }).then(function (response) {
            if (response.status === 401) {
                handleUnauthorized(null);
            }
        }).catch(function () {
            /* network errors handled elsewhere */
        });
    }

    function startPing() {
        stopPing();
        if (!config || !config.pingInterval) {
            return;
        }
        pingTimer = setInterval(ping, config.pingInterval);
    }

    function stopPing() {
        if (pingTimer) {
            clearInterval(pingTimer);
            pingTimer = null;
        }
    }

    function init(options) {
        config = options || {};
        ensureModal();
        startPing();
        ping();
    }

    function isSessionExpired() {
        return sessionExpired;
    }

    function destroy() {
        stopPing();
        config = null;
        pendingRetry = null;
    }

    global.SessionGuard = {
        init: init,
        destroy: destroy,
        authFetch: authFetch,
        ping: ping,
        isSessionExpired: isSessionExpired,
        showModal: showModal,
    };
})(typeof window !== 'undefined' ? window : this);
