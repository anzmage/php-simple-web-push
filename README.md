# PHP Web Push CLI Demo

This is a standalone Web Push demo. It does not use Firebase, OneSignal, or any third-party push SDK.

The browser registers a service worker and stores a Push API subscription. PHP can then send a real push notification to that browser subscription from either HTTP or CLI.

## Files

```text
.
├── bin/
│   └── push
├── config/
│   └── vapid.example.php
├── public/
│   ├── app.js
│   ├── config.php
│   ├── index.html
│   ├── push.php
│   ├── save-subscription.php
│   └── sw.js
├── src/
│   └── SimpleWebPushSender.php
└── storage/
    └── .gitkeep
```

- `public/index.html` - Demo page used in the browser.
- `public/app.js` - Registers the service worker, subscribes the browser, and saves the subscription.
- `public/sw.js` - Service worker that receives push events and displays notifications.
- `public/save-subscription.php` - Saves the browser subscription into `storage/subscription.json`.
- `public/push.php` - Sends a push notification from an HTTP POST request.
- `bin/push` - Sends a push notification from PHP CLI.
- `src/SimpleWebPushSender.php` - Minimal standalone Web Push sender implementation.
- `config/vapid.example.php` - Example VAPID config.
- `config/vapid.php` - Local VAPID config. This file is ignored by Git.
- `storage/subscription.json` - Local browser subscription. This file is ignored by Git.

## Requirements

- PHP with `curl` and `openssl` extensions enabled.
- Chrome, Edge, or Firefox.
- HTTPS, or `localhost` / `127.0.0.1` for local development.

Service workers and Push API require a secure browser context. For a simple local demo, `http://127.0.0.1` is allowed by browsers.

## Setup

Copy the example VAPID config:

```bash
cp config/vapid.example.php config/vapid.php
```

Generate VAPID keys:

```bash
php -r '$k=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_EC,"curve_name"=>"prime256v1"]);$d=openssl_pkey_get_details($k);$b=function($v){return rtrim(strtr(base64_encode($v),"+/","-_"),"=");};echo "public=".$b("\x04".$d["ec"]["x"].$d["ec"]["y"]).PHP_EOL."private=".$b($d["ec"]["d"]).PHP_EOL;'
```

Paste the generated values into `config/vapid.php`:

```php
return [
    'subject' => 'mailto:admin@example.com',
    'public_key' => 'your-public-key',
    'private_key' => 'your-private-key',
];
```

Do not commit real VAPID private keys.

## Run The Demo Server

From this demo directory:

```bash
php -S 127.0.0.1:8788 -t public
```

Open:

```text
http://127.0.0.1:8788/index.html
```

Click **Register and Subscribe**.

The browser will:

1. Register `sw.js`.
2. Request notification permission.
3. Create a Push API subscription.
4. Save the subscription to `storage/subscription.json`.

## Send Push From PHP CLI

After the browser subscription has been saved:

```bash
php bin/push
```

With custom notification text:

```bash
php bin/push "Hello from CLI" "This message was sent by PHP CLI."
```

Expected successful output:

```text
Push status: 201
```

## Send Push From Browser Button

The demo page also has a **Send Push from PHP** button.

That button sends an HTTP POST request to:

```text
push.php
```

`push.php` sends the same kind of real Web Push notification, but through an HTTP endpoint instead of CLI.

## How It Works

The browser creates a subscription with:

```js
registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: vapidPublicKey
});
```

That subscription contains:

- `endpoint` - The push service URL owned by the browser vendor.
- `keys.p256dh` - Browser public encryption key.
- `keys.auth` - Browser auth secret.

PHP reads those values, encrypts the payload, signs the request with the VAPID private key, and posts it to the subscription endpoint.

When the push arrives, the browser wakes up `sw.js` and runs:

```js
self.addEventListener('push', function (event) {
    event.waitUntil(self.registration.showNotification(...));
});
```

## Troubleshooting

If the browser shows `Expected JSON, but server returned <!doctype ...`, the PHP file is not being executed by the web server. Use the PHP built-in server command above for this standalone demo.

If CLI returns `Subscription file not found`, open the browser demo and click **Register and Subscribe** first.

If CLI returns a status other than `201`, common causes are invalid VAPID keys, expired browser subscription, blocked notifications, or server network access to the browser push endpoint.
