<?php
declare(strict_types=1);

require __DIR__ . '/web-push.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST request is required.');
    }

    $request = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($request)) {
        throw new RuntimeException('Invalid JSON request.');
    }

    $subscription = $request['subscription'] ?? null;
    if (!is_array($subscription)) {
        throw new RuntimeException('Missing push subscription.');
    }

    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $pushSubscription = [
        'endpoint' => (string)($subscription['endpoint'] ?? ''),
        'p256dh_key' => (string)($keys['p256dh'] ?? ''),
        'auth_key' => (string)($keys['auth'] ?? ''),
    ];

    $payload = [
        'title' => trim((string)($request['title'] ?? 'PHP Web Push Demo')),
        'body' => trim((string)($request['body'] ?? 'This push notification was sent by PHP.')),
        'url' => trim((string)($request['url'] ?? '/sw-demo/index.html')),
    ];

    $config = require __DIR__ . '/vapid.php';

    if (trim((string)($config['public_key'] ?? '')) === ''
        || trim((string)($config['private_key'] ?? '')) === ''
        || trim((string)($config['subject'] ?? '')) === ''
    ) {
        throw new RuntimeException('VAPID config is incomplete. Edit pub/sw-demo/vapid.php first.');
    }

    $sender = new SimpleWebPushSender(
        (string)$config['subject'],
        (string)$config['public_key'],
        (string)$config['private_key']
    );
    $status = $sender->send($pushSubscription, $payload);

    echo json_encode([
        'success' => $status === 201,
        'status' => $status,
        'message' => $status === 201 ? 'Push sent.' : 'Push service returned status ' . $status . '.',
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
