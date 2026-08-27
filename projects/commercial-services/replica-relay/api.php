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
$body = (string)file_get_contents('php://input');
function relayReply(int $status, array $body): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body, JSON_UNESCAPED_SLASHES); exit; }
try {
    $pdo = replicaRelayOpen($config);
    if ($path === '/v1/artifacts' && $method === 'GET') { $auth = replicaRelayAuthenticate($pdo, $bearer, 'management'); relayReply(200, ['artifacts' => replicaRelayListArtifacts($pdo, $auth['tenant_id'])]); }
    if (preg_match('#^/v1/artifacts/([A-Za-z0-9._:-]{1,200})$#', $path, $match) && $method === 'PUT') {
        $auth = replicaRelayAuthenticateClientRequest($pdo, $bearer, $method, $path, $timestamp, $nonce, $signature, $body);
        $digest = strtolower(trim((string)($_SERVER['HTTP_X_HOSTSCOPE_CIPHERTEXT_SHA256'] ?? '')));
        relayReply(201, ['artifact' => replicaRelayStoreArtifact($pdo, $config, $auth['tenant_id'], $match[1], $body, $digest)]);
    }
    if (preg_match('#^/v1/artifacts/([A-Za-z0-9._:-]{1,200})$#', $path, $match) && $method === 'GET') {
        $auth = replicaRelayAuthenticateClientRequest($pdo, $bearer, $method, $path, $timestamp, $nonce, $signature, $body);
        $records = array_filter(replicaRelayListArtifacts($pdo, $auth['tenant_id']), fn(array $record): bool => $record['artifact_id'] === $match[1]);
        $record = array_shift($records); $file = replicaRelayArtifactPath($config, $auth['tenant_id'], $match[1]);
        if (!is_array($record) || is_link($file) || !is_file($file) || hash_file('sha256', $file) !== $record['sha256']) throw new InvalidArgumentException('relay artifact is unavailable or failed verification');
        header('Content-Type: application/octet-stream'); header('Content-Length: ' . $record['bytes']); header('X-HostScope-Ciphertext-SHA256: ' . $record['sha256']); readfile($file); exit;
    }
    if (preg_match('#^/v1/artifacts/([A-Za-z0-9._:-]{1,200})$#', $path, $match) && $method === 'DELETE') {
        $auth = replicaRelayAuthenticate($pdo, $bearer, 'management'); replicaRelayDeleteArtifact($pdo, $config, $auth['tenant_id'], $match[1]);
        relayReply(200, ['artifact_id' => $match[1], 'state' => 'deleted', 'warning' => 'Deletion is irreversible at this relay. Verify another recovery copy before removing ciphertext.']);
    }
    relayReply(404, ['error' => 'unknown replica relay API operation']);
} catch (InvalidArgumentException $error) { relayReply(403, ['error' => $error->getMessage()]); }
catch (Throwable $error) { error_log('replica relay service failure: ' . $error->getMessage()); relayReply(500, ['error' => 'service failure']); }
