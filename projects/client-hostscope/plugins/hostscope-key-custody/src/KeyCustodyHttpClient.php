<?php
declare(strict_types=1);

namespace HostScope\Plugin\KeyCustody;

/** HTTP adapter for an ephemeral client credential supplied by a secret
 * provider. It intentionally retains no response/key cache. */
final class KeyCustodyHttpClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $clientToken)
    {
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !str_starts_with($baseUrl, 'https://') || $clientToken === '') {
            throw new \InvalidArgumentException('key custody endpoint or client credential is invalid');
        }
        if (!function_exists('curl_init')) throw new \RuntimeException('cURL is required for remote key custody');
    }

    /** @return array{timestamp:string,nonce:string} */
    public static function requestMetadata(): array
    {
        return ['timestamp' => (string)time(), 'nonce' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=')];
    }

    public function storeEnvelope(string $keyId, string $artifactId, string $envelope): array
    {
        return $this->request('POST', '/v1/keys/' . rawurlencode($keyId) . '/envelopes', [
            'artifact_id' => $artifactId, 'envelope_base64' => base64_encode($envelope),
        ]);
    }

    /** Returns key material only for immediate in-memory use by the caller. */
    public function unwrap(string $keyId, string $artifactId, int $requestedTtl = 60): string
    {
        $response = $this->request('POST', '/v1/keys/' . rawurlencode($keyId) . '/unwrap-authorizations', [
            'artifact_id' => $artifactId, 'nonce' => bin2hex(random_bytes(16)), 'requested_ttl_seconds' => max(60, min(900, $requestedTtl)),
        ]);
        $key = base64_decode((string)($response['key_material'] ?? ''), true);
        if (!is_string($key) || strlen($key) !== 32) throw new \RuntimeException('key custody service returned invalid key material');
        return $key;
    }

    private function request(string $method, string $path, array $body): array
    {
        $metadata = self::requestMetadata();
        $handle = curl_init(rtrim($this->baseUrl, '/') . $path);
        if ($handle === false) throw new \RuntimeException('could not initialize custody request');
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->clientToken, 'Content-Type: application/json',
                    'X-HostScope-Timestamp: ' . $metadata['timestamp'], 'X-HostScope-Nonce: ' . $metadata['nonce']],
            ]);
            $raw = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_string($raw)) throw new \RuntimeException('key custody request failed: ' . curl_error($handle));
            $response = json_decode($raw, true);
            if (!is_array($response) || $status < 200 || $status >= 300) throw new \RuntimeException('key custody request was refused');
            return $response;
        } finally { curl_close($handle); }
    }
}
