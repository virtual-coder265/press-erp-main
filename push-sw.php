<?php

require_once __DIR__ . '/config/app.php';

$scopePath = parse_url(BASE_URL, PHP_URL_PATH);
if (!is_string($scopePath) || $scopePath === '') {
    $scopePath = '/';
}
$scopePath = rtrim($scopePath, '/') . '/';

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Service-Worker-Allowed: ' . $scopePath);
?>
const APP_SCOPE = <?php echo json_encode($scopePath, JSON_UNESCAPED_SLASHES); ?>;
const FALLBACK_URL = new URL(APP_SCOPE, self.location.origin).href;

self.addEventListener('install', function (event) {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    let payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch (error) {
            payload = {
                title: 'Notification',
                body: event.data.text(),
                url: FALLBACK_URL
            };
        }
    }

    event.waitUntil(handlePushPayload(payload));
});

self.addEventListener('notificationclick', function (event) {
    const data = event.notification.data || {};
    event.notification.close();
    event.waitUntil(openTargetWindow(data));
});

async function handlePushPayload(payload) {
    const clientsList = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true
    });
    const visibleClient = clientsList.find(function (client) {
        return client.visibilityState === 'visible';
    });

    if (visibleClient) {
        visibleClient.postMessage({
            type: 'press-erp-push-event',
            payload: payload,
            showSystemNotification: true
        });
        return;
    }

    const title = payload.title || 'Notification';
    const options = {
        body: payload.body || '',
        icon: payload.icon || undefined,
        badge: payload.badge || undefined,
        image: payload.image || undefined,
        tag: payload.tag || undefined,
        requireInteraction: !!payload.requireInteraction,
        renotify: !!payload.renotify,
        timestamp: payload.timestamp || Date.now(),
        data: payload
    };

    return self.registration.showNotification(title, options);
}

async function openTargetWindow(data) {
    const targetUrl = new URL(data.url || FALLBACK_URL, self.location.origin).href;
    const clientsList = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true
    });

    for (const client of clientsList) {
        if ('navigate' in client) {
            await client.navigate(targetUrl);
        }

        if ('focus' in client) {
            await client.focus();
        }

        client.postMessage({
            type: 'press-erp-push-event',
            payload: data,
            fromNotificationClick: true
        });
        return;
    }

    if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
    }

    return null;
}
