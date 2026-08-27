<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/store.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(503); echo json_encode(['error' => 'key custody service is not configured']); exit; }
$config = require $configFile;
if (!is_array($config)) { http_response_code(500); echo json_encode(['error' => 'invalid service configuration']); exit; }
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!empty($config['require_https']) && !$https) { http_response_code(400); echo json_encode(['error' => 'HTTPS is required']); exit; }
$path = '/' . trim((string)($_SERVER['PATH_INFO'] ?? $_GET['path'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$bearer = preg_match('/^Bearer\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $match) ? $match[1] : '';
$clientTimestamp = (string)($_SERVER['HTTP_X_HOSTSCOPE_TIMESTAMP'] ?? '');
$clientNonce = (string)($_SERVER['HTTP_X_HOSTSCOPE_NONCE'] ?? '');
$clientSignature = (string)($_SERVER['HTTP_X_HOSTSCOPE_SIGNATURE'] ?? '');
$rawBody = (string)file_get_contents('php://input');
function custodyBody(string $raw): array { $value = json_decode($raw, true); if (!is_array($value)) throw new InvalidArgumentException('JSON object body is required'); return $value; }
function custodyReply(int $status, array $body): never { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_SLASHES); exit; }
try {
    $pdo = keyCustodyOpen($config);
    if ($path === '/v1/keys' && $method === 'GET') {
        $auth = keyCustodyAuthenticate($pdo, $bearer, 'management'); custodyReply(200, ['keys' => keyCustodyListKeys($pdo, $auth['tenant_id'])]);
    }
    if ($path === '/v1/keys' && $method === 'POST') {
        $auth = keyCustodyAuthenticate($pdo, $bearer, 'management'); $body = custodyBody($rawBody);
        $key = keyCustodyCreateKey($pdo, $auth['tenant_id'], (string)($body['name'] ?? ''), (int)($body['authorization_ttl_seconds'] ?? 0), keyCustodyReadMasterKey($config, __DIR__));
        custodyReply(201, ['key' => $key]);
    }
    if (preg_match('#^/v1/keys/(key_[a-f0-9]{32})$#', $path, $match) && $method === 'PATCH') {
        $auth = keyCustodyAuthenticate($pdo, $bearer, 'management'); $body = custodyBody($rawBody); $action = (string)($body['action'] ?? '');
        if ($action === 'disable') { keyCustodyDisableKey($pdo, $auth['tenant_id'], $match[1]); custodyReply(200, ['key_id' => $match[1], 'state' => 'disabled']); }
        if ($action === 'rotate') { custodyReply(200, ['replacement_key' => keyCustodyRotateKey($pdo, $auth['tenant_id'], $match[1], keyCustodyReadMasterKey($config, __DIR__))]); }
        throw new InvalidArgumentException('key action must be disable or rotate');
    }
    if (preg_match('#^/v1/keys/(key_[a-f0-9]{32})/envelopes$#', $path, $match) && $method === 'POST') {
        $auth = keyCustodyAuthenticateClientRequest($pdo, $bearer, $method, $path, $clientTimestamp, $clientNonce, $clientSignature, $rawBody); $body = custodyBody($rawBody);
        $envelope = base64_decode((string)($body['envelope_base64'] ?? ''), true);
        if (!is_string($envelope)) throw new InvalidArgumentException('envelope_base64 is invalid');
        custodyReply(201, ['envelope' => keyCustodyStoreEnvelope($pdo, $auth['tenant_id'], $match[1], trim((string)($body['artifact_id'] ?? '')), $envelope)]);
    }
    if (preg_match('#^/v1/keys/(key_[a-f0-9]{32})/envelopes$#', $path, $match) && $method === 'GET') {
        $auth = keyCustodyAuthenticate($pdo, $bearer, 'management'); custodyReply(200, ['envelopes' => keyCustodyListEnvelopes($pdo, $auth['tenant_id'], $match[1])]);
    }
    if (preg_match('#^/v1/keys/(key_[a-f0-9]{32})/unwrap-authorizations$#', $path, $match) && $method === 'POST') {
        $auth = keyCustodyAuthenticateClientRequest($pdo, $bearer, $method, $path, $clientTimestamp, $clientNonce, $clientSignature, $rawBody); $body = custodyBody($rawBody);
        if (trim((string)($body['artifact_id'] ?? '')) === '' || trim((string)($body['nonce'] ?? '')) === '') throw new InvalidArgumentException('artifact and nonce are required');
        $artifactId = trim((string)($body['artifact_id'] ?? ''));
        $key = keyCustodyUnwrapKey($pdo, $auth['tenant_id'], $match[1], $artifactId, keyCustodyReadMasterKey($config, __DIR__));
        custodyReply(201, ['key_id' => $match[1], 'key_material' => base64_encode($key),
            'cache_recommendation_seconds' => min(900, max(0, (int)($body['requested_ttl_seconds'] ?? 0))),
            'warning' => 'Key material is returned for immediate in-memory use. A cache recommendation cannot revoke material that a client already received.']);
    }
    if ($path === '/v1/client-credentials/rotate' && $method === 'POST') {
        $auth = keyCustodyAuthenticate($pdo, $bearer, 'management');
        $credential = keyCustodyRotateClientCredential($pdo, $auth['tenant_id']);
        custodyReply(201, ['client_token' => $credential['token'], 'client_signing_private_key' => $credential['signing_private_key'],
            'warning' => 'Store both values now. The service cannot show them again; the previous enrolled-client credential has been revoked.']);
    }
    if ($path === '/v1/subscription' && $method === 'GET') {
        $auth = keyCustodyAuthenticate($pdo, $bearer, 'management'); custodyReply(200, ['subscription' => keyCustodySubscription($pdo, $auth['tenant_id'])]);
    }
    if ($path === '/v1/subscription' && $method === 'PATCH') {
        $auth = keyCustodyAuthenticate($pdo, $bearer, 'management'); $body = custodyBody($rawBody);
        $request = keyCustodyRequestSubscriptionChange($pdo, $auth['tenant_id'], (string)($body['action'] ?? ''), isset($body['plan']) ? (string)$body['plan'] : null);
        custodyReply(202, ['request' => $request, 'detail' => 'The commercial billing service must approve and apply this request.']);
    }
    if (in_array($path, ['/v1/billing/checkout', '/v1/billing/portal'], true) && $method === 'POST') {
        keyCustodyAuthenticate($pdo, $bearer, 'management'); $portal = trim((string)($config['billing_portal_url'] ?? ''));
        if ($portal === '' || !filter_var($portal, FILTER_VALIDATE_URL) || !str_starts_with($portal, 'https://')) custodyReply(503, ['error' => 'billing portal is not configured']);
        custodyReply(201, ['billing_session_url' => $portal]);
    }
    custodyReply(404, ['error' => 'unknown custody API operation']);
} catch (InvalidArgumentException $error) { custodyReply(403, ['error' => $error->getMessage()]); }
catch (Throwable $error) { error_log('key custody service failure: ' . $error->getMessage()); custodyReply(500, ['error' => 'service failure']); }
