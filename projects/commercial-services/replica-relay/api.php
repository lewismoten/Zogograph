<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/store.php';

header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile) || !is_array($config = require $configFile)) { http_response_code(503); echo json_encode(['error' => 'replica relay is not configured']); exit; }
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!empty($config['require_https']) && !$https) { http_response_code(400); echo json_encode(['error' => 'HTTPS is required']); exit; }
$path = '/' . trim((string)($_SERVER['PATH_INFO'] ?? $_GET['path'] ?? ''), '/'); $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$bearer = preg_match('/^Bearer\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $match) ? $match[1] : '';
$timestamp = (string)($_SERVER['HTTP_X_HOSTSCOPE_TIMESTAMP'] ?? ''); $nonce = (string)($_SERVER['HTTP_X_HOSTSCOPE_NONCE'] ?? ''); $signature = (string)($_SERVER['HTTP_X_HOSTSCOPE_SIGNATURE'] ?? '');
$body = '';
function relayReply(int $status, array $body): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body, JSON_UNESCAPED_SLASHES); exit; }
try {
    $pdo = replicaRelayOpen($config, __DIR__);
    if ($path === '/v1/health' && $method === 'GET') relayReply(200, ['status' => 'ok', 'service' => 'replica-relay', 'api_version' => 'v1']);
    if ($path === '/v1/artifacts' && $method === 'GET') { $auth = replicaRelayAuthenticate($pdo, $bearer, 'management'); relayReply(200, ['artifacts' => replicaRelayListArtifacts($pdo, $auth['tenant_id'])]); }
    if ($path === '/v1/client/entitlement' && $method === 'GET') {
        $auth = replicaRelayAuthenticateClientRequest($pdo, $bearer, $method, $path, $timestamp, $nonce, $signature, $body);
        relayReply(200, ['entitlement' => replicaRelayClientEntitlement($pdo, $config, $auth['tenant_id'])]);
    }
    if (preg_match('#^/v1/artifacts/([^/]{1,600})$#', $path, $match) && $method === 'PUT') {
        $artifactId = rawurldecode($match[1]);
        $digest = strtolower(trim((string)($_SERVER['HTTP_X_HOSTSCOPE_CIPHERTEXT_SHA256'] ?? '')));
        $digestBytes = ctype_xdigit($digest) ? hex2bin($digest) : false;
        if (!is_string($digestBytes) || strlen($digestBytes) !== 32) throw new InvalidArgumentException('relay ciphertext digest is invalid');
        $length = $_SERVER['CONTENT_LENGTH'] ?? null; $maximum = (int)($config['maximum_artifact_bytes'] ?? 0);
        if ($maximum < 1 || ($length !== null && (!ctype_digit((string)$length) || (int)$length < 1 || (int)$length > $maximum))) throw new InvalidArgumentException('relay artifact exceeds its configured size limit');
        $auth = replicaRelayAuthenticateClientDigestRequest($pdo, $bearer, $method, $path, $timestamp, $nonce, $signature, $digestBytes);
        $stream = fopen('php://input', 'rb'); if ($stream === false) throw new RuntimeException('could not receive relay ciphertext');
        try { $stored = replicaRelayStoreArtifactStream($pdo, $config, $auth['tenant_id'], $artifactId, $stream, $digest); }
        finally { fclose($stream); }
        relayReply(201, ['artifact' => $stored]);
    }
    if (preg_match('#^/v1/artifacts/([^/]{1,600})$#', $path, $match) && $method === 'GET') {
        $artifactId = rawurldecode($match[1]);
        $auth = replicaRelayAuthenticateClientRequest($pdo, $bearer, $method, $path, $timestamp, $nonce, $signature, $body);
        $records = array_filter(replicaRelayListArtifacts($pdo, $auth['tenant_id']), fn(array $record): bool => $record['artifact_id'] === $artifactId);
        $record = array_shift($records); $file = replicaRelayArtifactPath($config, $auth['tenant_id'], $artifactId);
        if (!is_array($record) || is_link($file) || !is_file($file) || hash_file('sha256', $file) !== $record['sha256']) throw new InvalidArgumentException('relay artifact is unavailable or failed verification');
        header('Content-Type: application/octet-stream'); header('Content-Length: ' . $record['bytes']); header('X-HostScope-Ciphertext-SHA256: ' . $record['sha256']); readfile($file); exit;
    }
    if (preg_match('#^/v1/artifacts/([^/]{1,600})$#', $path, $match) && $method === 'DELETE') {
        $artifactId = rawurldecode($match[1]);
        $auth = replicaRelayAuthenticate($pdo, $bearer, 'management'); replicaRelayDeleteArtifact($pdo, $config, $auth['tenant_id'], $artifactId);
        relayReply(200, ['artifact_id' => $artifactId, 'state' => 'deleted', 'warning' => 'Deletion is irreversible at this relay. Verify another recovery copy before removing ciphertext.']);
    }
    relayReply(404, ['error' => 'unknown replica relay API operation']);
} catch (InvalidArgumentException $error) { relayReply(403, ['error' => $error->getMessage()]); }
catch (Throwable $error) { error_log('replica relay service failure: ' . $error->getMessage()); relayReply(500, ['error' => 'service failure']); }
