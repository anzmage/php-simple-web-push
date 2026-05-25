self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};

    if (event.data) {
        try {
            data = event.data.json();
        } catch (error) {
            data = {
                title: 'PHP Web Push Demo',
                body: event.data.text()
            };
        }
    }

    event.waitUntil(self.registration.showNotification(data.title || 'PHP Web Push Demo', {
        body: data.body || 'A push notification was received.',
        icon: data.icon || '/favicon.ico',
        badge: data.badge || '/favicon.ico',
        data: {
            url: data.url || '/sw-demo/index.html'
        }
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var targetUrl = event.notification.data && event.notification.data.url
        ? event.notification.data.url
        : '/sw-demo/index.html';

    event.waitUntil(clients.openWindow(targetUrl));
});
