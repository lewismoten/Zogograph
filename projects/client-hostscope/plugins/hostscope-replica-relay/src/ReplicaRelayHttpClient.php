<?php
declare(strict_types=1);

namespace HostScope\Plugin\ReplicaRelay;

/** Signed ciphertext-only transport. It cannot send a key or plaintext store. */
final class ReplicaRelayHttpClient
{
    private readonly string $signingKey;

    public function __construct(private readonly string $baseUrl, private readonly string $clientToken, string $clientSigningKey)
    {
        $url = parse_url($baseUrl);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !is_array($url) || strtolower((string)($url['scheme'] ?? '')) !== 'https' || !is_string($url['host'] ?? null)
            || $url['host'] === '' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || $clientToken === '') {
            throw new \InvalidArgumentException('replica relay endpoint or client credential is invalid');
        }
        if (!function_exists('curl_init') || !function_exists('sodium_crypto_sign_detached')) throw new \RuntimeException('cURL and libsodium are required for replica relay');
        $this->signingKey = base64_decode($clientSigningKey, true);
        if (!is_string($this->signingKey) || strlen($this->signingKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new \InvalidArgumentException('replica relay signing credential is invalid');
    }

    /** Streams one already-encrypted artifact without retaining it in memory. */
    public function storeArtifact(string $artifactId, string $ciphertextPath): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,200}$/', $artifactId) || is_link($ciphertextPath) || !is_file($ciphertextPath) || !is_readable($ciphertextPath)) throw new \InvalidArgumentException('replica artifact path or identity is invalid');
        $bytes = filesize($ciphertextPath); $digest = hash_file('sha256', $ciphertextPath);
        if (!is_int($bytes) || $bytes < 1 || !is_string($digest)) throw new \RuntimeException('could not inspect replica ciphertext');
        $stream = fopen($ciphertextPath, 'rb'); if ($stream === false) throw new \RuntimeException('could not read replica ciphertext');
        try { return $this->request('PUT', '/v1/artifacts/' . rawurlencode($artifactId), $stream, $bytes, $digest); }
        finally { fclose($stream); }
    }

    private function request(string $method, string $path, $stream, int $bytes, string $digest): array
    {
        $timestamp = (string)time(); $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $digestBytes = hex2bin($digest); if (!is_string($digestBytes)) throw new \InvalidArgumentException('replica ciphertext digest is invalid');
        $payload = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . base64_encode($digestBytes);
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $this->signingKey));
        $handle = curl_init(rtrim($this->baseUrl, '/') . $path); if ($handle === false) throw new \RuntimeException('could not initialize replica relay request');
        try {
            curl_setopt_array($handle, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $stream, CURLOPT_INFILESIZE => $bytes, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 300, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->clientToken, 'Content-Type: application/octet-stream', 'X-HostScope-Ciphertext-SHA256: ' . $digest,
                    'X-HostScope-Timestamp: ' . $timestamp, 'X-HostScope-Nonce: ' . $nonce, 'X-HostScope-Signature: ' . $signature]]);
            $raw = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_string($raw)) throw new \RuntimeException('replica relay request failed: ' . curl_error($handle));
            $response = json_decode($raw, true); if (!is_array($response) || $status < 200 || $status >= 300) throw new \RuntimeException('replica relay request was refused');
            return $response;
        } finally { curl_close($handle); }
    }
}
