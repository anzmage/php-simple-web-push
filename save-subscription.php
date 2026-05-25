<?php
declare(strict_types=1);

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST request is required.');
    }

    $request = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($request) || !is_array($request['subscription'] ?? null)) {
        throw new RuntimeException('Missing push subscription.');
    }

    $subscription = $request['subscription'];
    if (trim((string)($subscription['endpoint'] ?? '')) === ''
        || trim((string)($subscription['keys']['p256dh'] ?? '')) === ''
        || trim((string)($subscription['keys']['auth'] ?? '')) === ''
    ) {
        throw new RuntimeException('Invalid push subscription.');
    }

    $file = __DIR__ . '/subscription.json';
    $saved = file_put_contents($file, json_encode($subscription, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($saved === false) {
        throw new RuntimeException('Unable to write subscription file.');
    }

    echo json_encode([
        'success' => true,
        'file' => 'pub/sw-demo/subscription.json',
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
