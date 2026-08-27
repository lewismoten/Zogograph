<?php
declare(strict_types=1);

namespace HostScope\Plugin\KeyCustody;

/** HTTP adapter for an ephemeral client credential supplied by a secret
 * provider. It intentionally retains no response/key cache. */
final class KeyCustodyHttpClient
{
    private readonly string $clientSigningKey;

    /** The signing key is the Base64 Ed25519 private key printed at enrollment. */
    public function __construct(private readonly string $baseUrl, private readonly string $clientToken, string $clientSigningKey)
    {
        $url = parse_url($baseUrl);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !is_array($url) || strtolower((string)($url['scheme'] ?? '')) !== 'https' || !is_string($url['host'] ?? null)
            || $url['host'] === '' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || $clientToken === '') {
            throw new \InvalidArgumentException('key custody endpoint or client credential is invalid');
        }
        if (!preg_match('~^tok_[a-f0-9]{20}\.[A-Za-z0-9+/_-]+={0,2}$~', $clientToken)) {
            throw new \InvalidArgumentException('key custody client credential is invalid');
        }
        if (!function_exists('curl_init')) throw new \RuntimeException('cURL is required for remote key custody');
        if (!function_exists('sodium_crypto_sign_detached')) throw new \RuntimeException('libsodium is required for remote key custody');
        $this->clientSigningKey = base64_decode($clientSigningKey, true);
        if (!is_string($this->clientSigningKey) || strlen($this->clientSigningKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('key custody client signing credential is invalid');
        }
    }

    /** @return array{timestamp:string,nonce:string} */
    public static function requestMetadata(): array
    {
        return ['timestamp' => (string)time(), 'nonce' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=')];
    }

    public static function requestPayload(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . base64_encode(hash('sha256', $body, true));
    }

    public function storeEnvelope(string $keyId, string $artifactId, string $envelope): array
    {
        self::validateIdentity($keyId, $artifactId);
        if ($envelope === '' || !defined('SODIUM_CRYPTO_BOX_SEALBYTES') || strlen($envelope) !== SODIUM_CRYPTO_BOX_SEALBYTES + 32) throw new \InvalidArgumentException('key custody envelope is invalid');
        return $this->request('POST', '/v1/keys/' . rawurlencode($keyId) . '/envelopes', [
            'artifact_id' => $artifactId, 'envelope_base64' => base64_encode($envelope),
        ]);
    }

    /** Reads the public wrapping key used to seal one fresh artifact data key. */
    public function wrappingPublicKey(string $keyId): string
    {
        self::validateIdentity($keyId, 'public-key');
        $response = $this->request('GET', '/v1/keys/' . rawurlencode($keyId) . '/wrapping-key', null);
        $key = base64_decode((string)($response['wrapping_public_key'] ?? ''), true);
        if (!is_string($key) || !defined('SODIUM_CRYPTO_BOX_PUBLICKEYBYTES') || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \RuntimeException('key custody service returned an invalid wrapping public key');
        }
        return $key;
    }

    /** Seals a new 256-bit artifact data key locally; only custody can open it. */
    public static function sealDataKey(string $dataKey, string $wrappingPublicKey): string
    {
        if (!function_exists('sodium_crypto_box_seal') || strlen($dataKey) !== 32 || !defined('SODIUM_CRYPTO_BOX_PUBLICKEYBYTES')
            || strlen($wrappingPublicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('artifact data key or custody wrapping key is invalid');
        }
        return sodium_crypto_box_seal($dataKey, $wrappingPublicKey);
    }

    /** Returns an artifact data key only for immediate in-memory use by the caller. */
    public function unwrap(string $keyId, string $artifactId, int $requestedTtl = 0): string
    {
        self::validateIdentity($keyId, $artifactId);
        if ($requestedTtl < 0 || $requestedTtl > 900) throw new \InvalidArgumentException('requested key cache duration is invalid');
        $response = $this->request('POST', '/v1/keys/' . rawurlencode($keyId) . '/unwrap-authorizations', [
            'artifact_id' => $artifactId, 'nonce' => bin2hex(random_bytes(16)), 'requested_ttl_seconds' => $requestedTtl,
        ]);
        $key = base64_decode((string)($response['data_key_material'] ?? ''), true);
        if (!is_string($key) || strlen($key) !== 32) throw new \RuntimeException('key custody service returned invalid artifact data key');
        return $key;
    }

    /** Reads only whether this enrolled client can store another envelope. It
     * has no billing, management, or key-material authority. */
    public function envelopeEntitlement(): array
    {
        $response = $this->request('GET', '/v1/client/subscription', null);
        $entitlement = $response['entitlement'] ?? null;
        if (!is_array($entitlement) || !is_string($entitlement['state'] ?? null)
            || !is_int($entitlement['envelope_quota'] ?? null) || !is_int($entitlement['envelope_used'] ?? null)
            || !is_bool($entitlement['can_store_envelopes'] ?? null)) {
            throw new \RuntimeException('key custody service returned an invalid client entitlement');
        }
        return $entitlement;
    }

    private static function validateIdentity(string $keyId, string $artifactId): void
    {
        if (!preg_match('/^key_[a-f0-9]{32}$/', $keyId) || $artifactId === '' || strlen($artifactId) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $artifactId)) {
            throw new \InvalidArgumentException('key custody artifact identity is invalid');
        }
    }

    private function request(string $method, string $path, ?array $body): array
    {
        $metadata = self::requestMetadata();
        $encoded = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $signature = base64_encode(sodium_crypto_sign_detached(self::requestPayload($method, $path, $metadata['timestamp'], $metadata['nonce'], $encoded), $this->clientSigningKey));
        $handle = curl_init(rtrim($this->baseUrl, '/') . $path);
        if ($handle === false) throw new \RuntimeException('could not initialize custody request');
        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->clientToken,
                    'X-HostScope-Timestamp: ' . $metadata['timestamp'], 'X-HostScope-Nonce: ' . $metadata['nonce'],
                    'X-HostScope-Signature: ' . $signature],
            ];
            if ($body !== null) { $options[CURLOPT_POSTFIELDS] = $encoded; $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json'; }
            curl_setopt_array($handle, $options);
            $raw = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_string($raw)) throw new \RuntimeException('key custody request failed: ' . curl_error($handle));
            $response = json_decode($raw, true);
            if (!is_array($response) || $status < 200 || $status >= 300) throw new \RuntimeException('key custody request was refused');
            return $response;
        } finally { curl_close($handle); }
    }
}
