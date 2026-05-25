(function () {
    'use strict';

    var registerButton = document.getElementById('register');
    var pushButton = document.getElementById('push');
    var titleInput = document.getElementById('title');
    var bodyInput = document.getElementById('body');
    var log = document.getElementById('log');
    var activeSubscription = null;

    function write(message) {
        log.textContent += message + "\n";
    }

    function base64UrlToUint8Array(value) {
        var padding = '='.repeat((4 - value.length % 4) % 4);
        var base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var output = new Uint8Array(rawData.length);

        for (var i = 0; i < rawData.length; i++) {
            output[i] = rawData.charCodeAt(i);
        }

        return output;
    }

    function assertSupported() {
        if (!('serviceWorker' in navigator)) {
            throw new Error('Service Worker is not supported by this browser.');
        }

        if (!('PushManager' in window)) {
            throw new Error('Push API is not supported by this browser.');
        }

        if (!('Notification' in window)) {
            throw new Error('Notification API is not supported by this browser.');
        }
    }

    function readJsonResponse(response) {
        return response.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (error) {
                throw new Error(
                    'Expected JSON, but server returned: '
                    + text.substring(0, 80).replace(/\s+/g, ' ')
                );
            }
        });
    }

    registerButton.addEventListener('click', function () {
        registerButton.disabled = true;
        write('Loading VAPID public key from PHP...');

        Promise.resolve()
            .then(assertSupported)
            .then(function () {
                return fetch('config.php', {
                    credentials: 'same-origin'
                });
            })
            .then(readJsonResponse)
            .then(function (config) {
                if (!config.success) {
                    throw new Error(config.message || 'Unable to load push config.');
                }

                write('Registering sw.js ...');

                return navigator.serviceWorker.register('sw.js').then(function (registration) {
                    write('Service worker registered with scope: ' + registration.scope);
                    write('Requesting notification permission...');

                    return Notification.requestPermission().then(function (permission) {
                        if (permission !== 'granted') {
                            throw new Error('Notification permission: ' + permission);
                        }

                        write('Subscribing browser to Push API...');

                        return registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: base64UrlToUint8Array(config.publicKey)
                        });
                    });
                });
            })
            .then(function (subscription) {
                activeSubscription = subscription;
                write('Browser subscribed.');
                write('Endpoint: ' + subscription.endpoint);
                write('Saving subscription for PHP CLI...');

                return fetch('save-subscription.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        subscription: subscription.toJSON()
                    })
                });
            })
            .then(readJsonResponse)
            .then(function (result) {
                if (!result.success) {
                    throw new Error(result.message || 'Unable to save subscription.');
                }

                pushButton.disabled = false;
                write('Subscription saved for CLI: ' + result.file);
            })
            .catch(function (error) {
                registerButton.disabled = false;
                write('Error: ' + error.message);
            });
    });

    pushButton.addEventListener('click', function () {
        if (!activeSubscription) {
            write('Subscribe first.');
            return;
        }

        pushButton.disabled = true;
        write('Asking PHP to send Web Push...');

        fetch('push.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                subscription: activeSubscription.toJSON(),
                title: titleInput.value,
                body: bodyInput.value,
                url: 'index.html'
            })
        })
            .then(readJsonResponse)
            .then(function (result) {
                if (!result.success) {
                    throw new Error(result.message || 'PHP failed to send push.');
                }

                write('PHP push response status: ' + result.status);
            })
            .catch(function (error) {
                write('Error: ' + error.message);
            })
            .finally(function () {
                pushButton.disabled = false;
            });
    });
})();
