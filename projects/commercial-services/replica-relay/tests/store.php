<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/store.php';
function relayExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$contract = json_decode((string)file_get_contents(__DIR__ . '/../openapi.json'), true);
relayExpect(($contract['paths']['/health']['get']['security'] ?? null) === [], 'published relay health endpoint is not safely unauthenticated');
relayExpect(($contract['paths']['/artifacts/{artifactId}']['delete']['requestBody']['required'] ?? false) === true
    && (($contract['components']['schemas']['RecoveryDeleteConfirmation']['properties']['confirm_recovery']['const'] ?? false) === true),
    'published relay API does not require explicit recovery confirmation before ciphertext deletion');
$api = file_get_contents(__DIR__ . '/../api.php');
relayExpect($api !== false && str_contains($api, "'/v1/health'"), 'relay API does not provide the documented health endpoint');
relayExpect(str_contains((string)$api, 'relayDeleteConfirmation($body)') && str_contains((string)$api, 'confirm_recovery must be true'),
    'relay API does not enforce explicit recovery confirmation before ciphertext deletion');
$root = sys_get_temp_dir() . '/hostscope-relay-' . bin2hex(random_bytes(4)); mkdir($root, 0700, true);
try {
    $config = ['database' => $root . '/service.db', 'artifact_directory' => $root . '/artifacts', 'maximum_artifact_bytes' => 1024, 'maximum_tenant_bytes' => 512, 'maximum_tenant_artifacts' => 1];
    $pdo = replicaRelayOpen($config); $tenant = replicaRelayCreateTenant($pdo, 'Example');
    relayExpect(strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal', 'relay service metadata database did not enable WAL');
    foreach ([$root . '/service.db', $root . '/service.db-wal', $root . '/service.db-shm'] as $privateFile) if (is_file($privateFile)) {
        relayExpect((fileperms($privateFile) & 0077) === 0, 'relay SQLite metadata companion has broad permissions');
    }
    try { replicaRelayCreateTenant($pdo, "forged\ntenant"); throw new RuntimeException('multiline relay tenant name was accepted'); }
    catch (InvalidArgumentException $error) { relayExpect($error->getMessage() !== 'multiline relay tenant name was accepted', $error->getMessage()); }
    try { replicaRelayOpen(['database' => 'relative.db', 'artifact_directory' => $root . '/bad']); throw new RuntimeException('relative relay database path was accepted'); }
    catch (RuntimeException $error) { relayExpect($error->getMessage() !== 'relative relay database path was accepted', $error->getMessage()); }
    try { replicaRelayOpen(['database' => $root . '/bad.db', 'artifact_directory' => 'relative-artifacts']); throw new RuntimeException('relative relay artifact directory was accepted'); }
    catch (RuntimeException $error) { relayExpect($error->getMessage() !== 'relative relay artifact directory was accepted', $error->getMessage()); }
    try { replicaRelayOpen(['database' => $root . '/web/service.db', 'artifact_directory' => $root . '/artifacts'], $root . '/web'); throw new RuntimeException('web-root relay database path was accepted'); }
    catch (RuntimeException $error) { relayExpect($error->getMessage() !== 'web-root relay database path was accepted', $error->getMessage()); }
    try { replicaRelayOpen(['database' => $root . '/service.db', 'artifact_directory' => $root . '/web/artifacts'], $root . '/web'); throw new RuntimeException('web-root relay artifact directory was accepted'); }
    catch (RuntimeException $error) { relayExpect($error->getMessage() !== 'web-root relay artifact directory was accepted', $error->getMessage()); }
    $initialEntitlement = replicaRelayClientEntitlement($pdo, $config, $tenant['tenant_id']);
    relayExpect($initialEntitlement['artifact_used'] === 0 && $initialEntitlement['storage_used_bytes'] === 0 && $initialEntitlement['can_store_artifact'],
        'relay client did not receive its minimal initial capacity entitlement');
    $management = replicaRelayAuthenticate($pdo, $tenant['management_token'], 'management');
    $private = base64_decode($tenant['client_signing_private_key'], true); relayExpect(is_string($private), 'relay enrollment signing key is invalid');
    $artifact = 'account:20260827'; $ciphertext = random_bytes(256); $path = '/v1/artifacts/' . $artifact; $timestamp = (string)time(); $nonce = 'nonce_' . bin2hex(random_bytes(12));
    relayExpect(rawurldecode(rawurlencode($artifact)) === $artifact, 'relay artifact identity is not safe for its signed URL path');
    $signature = base64_encode(sodium_crypto_sign_detached(replicaRelayClientPayload('PUT', $path, $timestamp, $nonce, $ciphertext), $private));
    $client = replicaRelayAuthenticateClientRequest($pdo, $tenant['client_token'], 'PUT', $path, $timestamp, $nonce, $signature, $ciphertext);
    $stored = replicaRelayStoreArtifact($pdo, $config, $client['tenant_id'], $artifact, $ciphertext, hash('sha256', $ciphertext));
    relayExpect($stored['bytes'] === 256 && is_file(replicaRelayArtifactPath($config, $client['tenant_id'], $artifact)), 'verified relay ciphertext was not stored privately');
    relayExpect(replicaRelayClientEntitlement($pdo, $config, $client['tenant_id'])['storage_used_bytes'] === 256,
        'relay client entitlement did not report used ciphertext storage');
    relayExpect(replicaRelayListArtifacts($pdo, $management['tenant_id'])[0]['sha256'] === hash('sha256', $ciphertext), 'management relay metadata omitted ciphertext digest');
    try { replicaRelayAuthenticateClientRequest($pdo, $tenant['client_token'], 'PUT', $path, $timestamp, $nonce, $signature, $ciphertext); throw new RuntimeException('replayed relay request was accepted'); }
    catch (InvalidArgumentException $error) { relayExpect($error->getMessage() !== 'replayed relay request was accepted', $error->getMessage()); }
    try { replicaRelayStoreArtifact($pdo, $config, $client['tenant_id'], $artifact, $ciphertext, str_repeat('0', 64)); throw new RuntimeException('relay accepted mismatched ciphertext digest'); }
    catch (InvalidArgumentException $error) { relayExpect($error->getMessage() !== 'relay accepted mismatched ciphertext digest', $error->getMessage()); }
    $secondCiphertext = random_bytes(128);
    try { replicaRelayStoreArtifact($pdo, $config, $client['tenant_id'], 'account:second', $secondCiphertext, hash('sha256', $secondCiphertext)); throw new RuntimeException('relay tenant artifact quota was exceeded'); }
    catch (InvalidArgumentException $error) { relayExpect($error->getMessage() !== 'relay tenant artifact quota was exceeded', $error->getMessage()); }
    relayExpect(!replicaRelayClientEntitlement($pdo, $config, $client['tenant_id'])['can_store_artifact'],
        'relay client entitlement allowed another artifact after the quota was reached');
    $largeContent = random_bytes(1025); $large = fopen('php://temp', 'w+b'); fwrite($large, $largeContent); rewind($large);
    try { replicaRelayStoreArtifactStream($pdo, $config, $client['tenant_id'], 'account:oversize', $large, hash('sha256', $largeContent)); throw new RuntimeException('relay accepted oversized ciphertext'); }
    catch (InvalidArgumentException $error) { relayExpect($error->getMessage() !== 'relay accepted oversized ciphertext', $error->getMessage()); }
    finally { fclose($large); }
    replicaRelayDeleteArtifact($pdo, $config, $management['tenant_id'], $artifact);
    relayExpect(replicaRelayListArtifacts($pdo, $management['tenant_id']) === [], 'relay ciphertext metadata remained after deletion');
    echo "replica relay store checks passed\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $path) { if (is_dir($path)) { foreach (glob($path . '/*') ?: [] as $child) { if (is_dir($child)) { foreach (glob($child . '/*') ?: [] as $file) @unlink($file); @rmdir($child); } else @unlink($child); } @rmdir($path); } else @unlink($path); }
    @rmdir($root);
}
