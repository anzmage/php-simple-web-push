<?php
declare(strict_types=1);

final class SimpleWebPushSender
{
    private const P256_CURVE_OID = "\x2A\x86\x48\xCE\x3D\x03\x01\x07";
    private const EC_PUBLIC_KEY_OID = "\x2A\x86\x48\xCE\x3D\x02\x01";

    public function __construct(
        private readonly string $subject,
        private readonly string $publicKey,
        private readonly string $privateKey
    ) {
    }

    public function send(array $subscription, array $payload): int
    {
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $userPublicKey = $this->base64UrlDecode((string)($subscription['p256dh_key'] ?? ''));
        $authSecret = $this->base64UrlDecode((string)($subscription['auth_key'] ?? ''));

        if ($endpoint === '' || strlen($userPublicKey) !== 65 || $authSecret === '') {
            throw new RuntimeException('Invalid browser push subscription.');
        }

        $encrypted = $this->encryptPayload(json_encode($payload, JSON_UNESCAPED_SLASHES), $userPublicKey, $authSecret);
        $headers = [
            'TTL: 2419200',
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Authorization: vapid t=' . $this->createVapidToken($endpoint) . ', k=' . $this->publicKey,
        ];

        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize curl.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $encrypted,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($status === 0 && $error !== '') {
            throw new RuntimeException($error);
        }

        return $status;
    }

    private function createVapidToken(string $endpoint): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = $this->base64UrlEncode(json_encode([
            'aud' => $this->getAudience($endpoint),
            'exp' => time() + 43200,
            'sub' => $this->subject,
        ], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;

        $pem = $this->createEcPrivateKeyPem(
            $this->base64UrlDecode($this->privateKey),
            $this->base64UrlDecode($this->publicKey)
        );

        if (!openssl_sign($input, $signature, $pem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign VAPID token.');
        }

        return $input . '.' . $this->base64UrlEncode($this->ecdsaDerToRaw($signature, 64));
    }

    private function encryptPayload(string|false $payload, string $userPublicKey, string $authSecret): string
    {
        if ($payload === false) {
            $payload = '{}';
        }

        $salt = random_bytes(16);
        $localKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($localKey === false) {
            throw new RuntimeException('Unable to create local EC key.');
        }

        $details = openssl_pkey_get_details($localKey);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Unable to read local EC key details.');
        }

        $localPublicKey = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        $peerKey = openssl_pkey_get_public($this->createEcPublicKeyPem($userPublicKey));
        if ($peerKey === false) {
            throw new RuntimeException('Invalid subscription public key.');
        }

        $sharedSecret = openssl_pkey_derive($peerKey, $localKey, 32);
        if ($sharedSecret === false) {
            throw new RuntimeException('Unable to derive push shared secret.');
        }

        $keyPrk = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $ikm = $this->hkdfExpand($keyPrk, "WebPush: info\x00" . $userPublicKey . $localPublicKey, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = $this->hkdfExpand($prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $this->hkdfExpand($prk, "Content-Encoding: nonce\x00", 12);
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt push payload.');
        }

        return $salt . pack('N', 4096) . chr(strlen($localPublicKey)) . $localPublicKey . $ciphertext . $tag;
    }

    private function hkdfExpand(string $prk, string $info, int $length): string
    {
        $output = '';
        $previous = '';
        $counter = 1;

        while (strlen($output) < $length) {
            $previous = hash_hmac('sha256', $previous . $info . chr($counter), $prk, true);
            $output .= $previous;
            $counter++;
        }

        return substr($output, 0, $length);
    }

    private function getAudience(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Invalid push endpoint.');
        }

        $audience = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $audience .= ':' . (int)$parts['port'];
        }

        return $audience;
    }

    private function createEcPublicKeyPem(string $publicKey): string
    {
        $algorithm = $this->asn1Sequence(
            $this->asn1Oid(self::EC_PUBLIC_KEY_OID) . $this->asn1Oid(self::P256_CURVE_OID)
        );

        return $this->pem('PUBLIC KEY', $this->asn1Sequence($algorithm . $this->asn1BitString($publicKey)));
    }

    private function createEcPrivateKeyPem(string $privateKey, string $publicKey): string
    {
        if (strlen($privateKey) !== 32 || strlen($publicKey) !== 65) {
            throw new InvalidArgumentException('Invalid VAPID key length.');
        }

        return $this->pem('EC PRIVATE KEY', $this->asn1Sequence(
            $this->asn1Integer("\x01")
            . $this->asn1OctetString($privateKey)
            . $this->asn1Explicit(0, $this->asn1Oid(self::P256_CURVE_OID))
            . $this->asn1Explicit(1, $this->asn1BitString($publicKey))
        ));
    }

    private function ecdsaDerToRaw(string $der, int $rawLength): string
    {
        if ($der === '' || ord($der[0]) !== 0x30) {
            throw new RuntimeException('Invalid ECDSA signature.');
        }

        $offset = 2;
        if (ord($der[1]) > 0x80) {
            $offset += ord($der[1]) - 0x80;
        }

        if (ord($der[$offset]) !== 0x02) {
            throw new RuntimeException('Invalid ECDSA signature integer.');
        }

        $rLength = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $offset += 2 + $rLength;

        if (ord($der[$offset]) !== 0x02) {
            throw new RuntimeException('Invalid ECDSA signature integer.');
        }

        $sLength = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLength);

        return $this->normalizeSignatureInteger($r, $rawLength / 2)
            . $this->normalizeSignatureInteger($s, $rawLength / 2);
    }

    private function normalizeSignatureInteger(string $value, int|float $length): string
    {
        $length = (int)$length;
        $value = ltrim($value, "\x00");

        return str_pad(substr($value, -$length), $length, "\x00", STR_PAD_LEFT);
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Integer(string $value): string
    {
        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1OctetString(string $value): string
    {
        return "\x04" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1BitString(string $value): string
    {
        return "\x03" . $this->asn1Length(strlen($value) + 1) . "\x00" . $value;
    }

    private function asn1Oid(string $value): string
    {
        return "\x06" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Explicit(int $tag, string $value): string
    {
        return chr(0xA0 + $tag) . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function pem(string $label, string $der): string
    {
        return '-----BEGIN ' . $label . "-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . '-----END ' . $label . "-----\n";
    }

    private function base64UrlEncode(string|false $value): string
    {
        return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
    }
}
