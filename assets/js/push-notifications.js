(function () {
    const config = window.PRESS_ERP_PUSH_CONFIG || null;
    if (!config) {
        return;
    }

    const state = {
        registration: null,
        lastStatus: null
    };

    function isSupported() {
        return !!(
            config.supported &&
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window
        );
    }

    function isSecureEnough() {
        return window.isSecureContext || config.secureContext;
    }

    function getCardElements() {
        return {
            status: document.getElementById('browser-push-status'),
            detail: document.getElementById('browser-push-detail'),
            enableButton: document.getElementById('browser-push-enable'),
            disableButton: document.getElementById('browser-push-disable')
        };
    }

    function updateCard(status) {
        const elements = getCardElements();
        if (!elements.status || !elements.detail) {
            return;
        }

        const active = !!(status && status.has_active_subscription);
        const permission = Notification.permission;

        if (!isSupported()) {
            elements.status.textContent = 'Unavailable';
            elements.detail.textContent = 'This browser does not support service-worker push notifications.';
        } else if (!isSecureEnough()) {
            elements.status.textContent = 'HTTPS Required';
            elements.detail.textContent = 'Browser push works only on HTTPS sites, except localhost during development.';
        } else if (!config.enabled) {
            elements.status.textContent = 'Disabled by Admin';
            elements.detail.textContent = 'System-wide browser push delivery is currently turned off.';
        } else if (permission === 'denied') {
            elements.status.textContent = 'Blocked';
            elements.detail.textContent = 'Browser notifications are blocked for this site. Re-enable them in your browser settings.';
        } else if (active) {
            const suffix = status && status.subscription_count > 1
                ? ' across ' + status.subscription_count + ' browser sessions.'
                : '.';
            elements.status.textContent = 'Active';
            elements.detail.textContent = 'Background notifications are enabled' + suffix;
        } else if (permission === 'granted') {
            elements.status.textContent = 'Ready';
            elements.detail.textContent = 'Permission is granted. Click enable to refresh this browser subscription if needed.';
        } else {
            elements.status.textContent = 'Not Enabled';
            elements.detail.textContent = 'Click enable to allow branded background notifications for messages, tasks, reminders, and alerts.';
        }

        if (elements.enableButton) {
            elements.enableButton.disabled = !isSupported() || !isSecureEnough() || !config.enabled;
        }

        if (elements.disableButton) {
            elements.disableButton.disabled = !active;
        }
    }

    function hasPushManagementUi() {
        const elements = getCardElements();
        return !!(elements.status || elements.detail || elements.enableButton || elements.disableButton);
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; i += 1) {
            outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
    }

    async function registerServiceWorker() {
        if (!isSupported()) {
            throw new Error('Browser push is not supported in this browser.');
        }

        if (state.registration) {
            return state.registration;
        }

        state.registration = await navigator.serviceWorker.register(config.serviceWorkerUrl, {
            scope: config.scope
        });

        return state.registration;
    }

    async function requestPermissionIfNeeded() {
        if (Notification.permission === 'granted') {
            return 'granted';
        }

        if (Notification.permission === 'denied') {
            return 'denied';
        }

        return Notification.requestPermission();
    }

    async function syncSubscription(requestPermission) {
        if (!config.enabled) {
            throw new Error('Browser push is disabled by the system administrator.');
        }

        if (!isSecureEnough()) {
            throw new Error('Browser push requires HTTPS outside localhost.');
        }

        const registration = await registerServiceWorker();
        const permission = requestPermission ? await requestPermissionIfNeeded() : Notification.permission;

        if (permission !== 'granted') {
            throw new Error(permission === 'denied'
                ? 'Browser notifications are blocked for this site.'
                : 'Notification permission has not been granted yet.');
        }

        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(config.publicKey)
            });
        }

        const response = await fetch(config.subscriptionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': config.csrfToken
            },
            body: JSON.stringify({
                action: 'save',
                subscription: subscription.toJSON()
            })
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Unable to save the browser push subscription.');
        }

        state.lastStatus = payload.status || null;
        updateCard(state.lastStatus);
        return payload;
    }

    async function disableSubscription() {
        const registration = await registerServiceWorker();
        const subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            const status = await refreshStatus();
            updateCard(status);
            return status;
        }

        const endpoint = subscription.endpoint;
        await subscription.unsubscribe();

        const response = await fetch(config.subscriptionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': config.csrfToken
            },
            body: JSON.stringify({
                action: 'delete',
                endpoint: endpoint
            })
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Unable to remove the browser push subscription.');
        }

        state.lastStatus = payload.status || null;
        updateCard(state.lastStatus);
        return payload;
    }

    async function refreshStatus() {
        const response = await fetch(config.subscriptionUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Unable to read browser push status.');
        }

        state.lastStatus = payload.status || null;
        return state.lastStatus;
    }

    function markRecentPushDelivery() {
        try {
            sessionStorage.setItem('press_erp_recent_push_at', String(Date.now()));
        } catch (error) {
        }
    }

    function wasRecentPushDelivery(maxAgeMs) {
        try {
            const timestamp = parseInt(sessionStorage.getItem('press_erp_recent_push_at') || '0', 10);
            return timestamp > 0 && (Date.now() - timestamp) < (maxAgeMs || 5000);
        } catch (error) {
            return false;
        }
    }

    async function backgroundSyncIfNeeded() {
        if (!isSupported() || !config.enabled || !isSecureEnough()) {
            return;
        }

        if (Notification.permission !== 'granted') {
            return;
        }

        const storageKey = 'press_erp_push_sync_at';
        let lastSync = 0;

        try {
            lastSync = parseInt(sessionStorage.getItem(storageKey) || '0', 10);
        } catch (error) {
            lastSync = 0;
        }

        if (Date.now() - lastSync < 30 * 60 * 1000) {
            return;
        }

        try {
            await syncSubscription(false);
            sessionStorage.setItem(storageKey, String(Date.now()));
        } catch (error) {
        }
    }

    function showIncomingPushToast(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        markRecentPushDelivery();

        const message = payload.toastMessage || payload.body || payload.title || 'New notification';
        const toastType = payload.toastType || 'info';
        const soundType = payload.sound || null;

        if (soundType && typeof window.playAppSound === 'function') {
            window.playAppSound(soundType);
        }

        if (typeof window.showToast === 'function') {
            window.showToast(message, toastType, {
                duration: payload.requireInteraction ? 8000 : 5000,
                sound: false
            });
        }
    }

    function showSystemNotificationInPage(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }

        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return null;
        }

        try {
            const notification = new Notification(payload.title || 'Notification', {
                body: payload.body || '',
                icon: payload.icon || undefined,
                badge: payload.badge || undefined,
                image: payload.image || undefined,
                tag: payload.tag || undefined,
                requireInteraction: !!payload.requireInteraction,
                renotify: !!payload.renotify,
                timestamp: payload.timestamp || Date.now(),
                data: payload
            });

            notification.onclick = function (event) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }

                try {
                    window.focus();
                } catch (error) {
                }

                if (payload.url) {
                    window.location.assign(payload.url);
                }

                notification.close();
            };

            return notification;
        } catch (error) {
            return null;
        }
    }

    function bindUi() {
        const elements = getCardElements();

        if (elements.enableButton) {
            elements.enableButton.addEventListener('click', async function () {
                try {
                    await syncSubscription(true);
                    if (typeof window.showToast === 'function') {
                        window.showToast('Browser push notifications are now active.', 'success', {
                            sound: 'success'
                        });
                    }
                } catch (error) {
                    if (typeof window.showToast === 'function') {
                        window.showToast(error.message || 'Unable to enable browser push notifications.', 'error', {
                            sound: 'error'
                        });
                    }
                }
            });
        }

        if (elements.disableButton) {
            elements.disableButton.addEventListener('click', async function () {
                try {
                    await disableSubscription();
                    if (typeof window.showToast === 'function') {
                        window.showToast('Browser push notifications were disabled for this browser.', 'success', {
                            sound: 'success'
                        });
                    }
                } catch (error) {
                    if (typeof window.showToast === 'function') {
                        window.showToast(error.message || 'Unable to disable browser push notifications.', 'error', {
                            sound: 'error'
                        });
                    }
                }
            });
        }
    }

    async function bootstrap() {
        bindUi();
        updateCard(state.lastStatus);

        if (!isSupported()) {
            return;
        }

        navigator.serviceWorker.addEventListener('message', function (event) {
            if (!event.data || event.data.type !== 'press-erp-push-event') {
                return;
            }

            const payload = event.data.payload || {};
            showIncomingPushToast(payload);

            if (event.data.showSystemNotification) {
                showSystemNotificationInPage(payload);
            }
        });

        if (!hasPushManagementUi()) {
            backgroundSyncIfNeeded().catch(function () {});
            return;
        }

        try {
            const status = await refreshStatus();
            updateCard(status);

            if (config.enabled && Notification.permission === 'granted') {
                await syncSubscription(false);
            }
        } catch (error) {
            updateCard(state.lastStatus);
        }
    }

    window.PressErpPush = {
        enable: function () {
            return syncSubscription(true);
        },
        disable: function () {
            return disableSubscription();
        },
        refreshStatus: function () {
            return refreshStatus();
        },
        wasRecentDelivery: function () {
            return wasRecentPushDelivery(5000);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
