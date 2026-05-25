<?php
declare(strict_types=1);

header('Content-Type: application/json');

try {
    $config = require __DIR__ . '/vapid.php';
    $publicKey = trim((string)($config['public_key'] ?? ''));

    if ($publicKey === '') {
        throw new RuntimeException('VAPID public key is empty. Edit pub/sw-demo/vapid.php first.');
    }

    echo json_encode([
        'success' => true,
        'publicKey' => $publicKey,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
