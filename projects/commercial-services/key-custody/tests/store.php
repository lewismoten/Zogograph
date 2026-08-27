<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/store.php';
function custodyExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$root = sys_get_temp_dir() . '/hostscope-custody-' . bin2hex(random_bytes(4)); mkdir($root, 0700, true);
try {
    $pdo = keyCustodyOpen(['database' => $root . '/service.db']);
    $tenant = keyCustodyCreateTenant($pdo, 'Example');
    $management = keyCustodyAuthenticate($pdo, $tenant['management_token'], 'management');
    $client = keyCustodyAuthenticate($pdo, $tenant['client_token'], 'client');
    $private = base64_decode($tenant['client_signing_private_key'], true);
    custodyExpect(is_string($private), 'tenant client signing credential is invalid');
    $signedRequest = function (string $nonce, string $body) use ($pdo, $tenant, $private): array {
        $timestamp = (string)time(); $path = '/v1/keys/key_' . str_repeat('a', 32) . '/envelopes';
        return [$timestamp, base64_encode(sodium_crypto_sign_detached(keyCustodyClientRequestPayload('POST', $path, $timestamp, $nonce, $body), $private)), $path];
    };
    $body = '{"artifact_id":"account:20260827"}'; $nonce = 'nonce_' . bin2hex(random_bytes(12));
    [$timestamp, $signature, $path] = $signedRequest($nonce, $body);
    $request = keyCustodyAuthenticateClientRequest($pdo, $tenant['client_token'], 'POST', $path, $timestamp, $nonce, $signature, $body);
    custodyExpect($request['tenant_id'] === $client['tenant_id'], 'replay-protected client request did not authenticate');
    try { keyCustodyAuthenticateClientRequest($pdo, $tenant['client_token'], 'POST', $path, $timestamp, $nonce, $signature, $body); throw new RuntimeException('reused nonce was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'reused nonce was accepted', $error->getMessage()); }
    $tamperedNonce = 'nonce_' . bin2hex(random_bytes(12)); [$tamperedTime, $tamperedSignature, $tamperedPath] = $signedRequest($tamperedNonce, $body);
    try { keyCustodyAuthenticateClientRequest($pdo, $tenant['client_token'], 'POST', $tamperedPath, $tamperedTime, $tamperedNonce, $tamperedSignature, '{"artifact_id":"changed"}'); throw new RuntimeException('body changed after signing was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'body changed after signing was accepted', $error->getMessage()); }
    custodyExpect($management['tenant_id'] === $client['tenant_id'], 'tenant tokens do not authenticate to the same tenant');
    $replacementCredential = keyCustodyRotateClientCredential($pdo, $management['tenant_id']);
    try { keyCustodyAuthenticate($pdo, $tenant['client_token'], 'client'); throw new RuntimeException('rotated client credential remained active'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'rotated client credential remained active', $error->getMessage()); }
    $client = keyCustodyAuthenticate($pdo, $replacementCredential['token'], 'client');
    custodyExpect($client['tenant_id'] === $management['tenant_id'], 'replacement client credential did not authenticate');
    $master = random_bytes(32); $key = keyCustodyCreateKey($pdo, $management['tenant_id'], 'primary', 300, $master);
    custodyExpect(keyCustodyListKeys($pdo, $management['tenant_id'])[0]['id'] === $key['id'], 'management key list omitted created key');
    custodyExpect(keyCustodySubscription($pdo, $management['tenant_id'])['state'] === 'active', 'initial subscription state is unavailable');
    $change = keyCustodyRequestSubscriptionChange($pdo, $management['tenant_id'], 'change_plan', 'pro');
    custodyExpect($change['state'] === 'pending' && $change['requested_plan'] === 'pro', 'subscription change request was not retained');
    $envelope = keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], 'account:20260827', random_bytes(80));
    custodyExpect(keyCustodyListEnvelopes($pdo, $management['tenant_id'], $key['id'])[0]['id'] === $envelope['id'], 'envelope metadata was not retained');
    custodyExpect(strlen(keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], $master)) === 32, 'client unwrap did not recover an active custody key');
    $replacement = keyCustodyRotateKey($pdo, $management['tenant_id'], $key['id'], $master);
    custodyExpect($replacement['id'] !== $key['id'] && keyCustodyListKeys($pdo, $management['tenant_id'])[0]['id'] === $replacement['id'], 'custody key rotation did not create a replacement');
    keyCustodyDisableKey($pdo, $management['tenant_id'], $replacement['id']);
    try { keyCustodyUnwrapKey($pdo, $client['tenant_id'], $replacement['id'], $master); throw new RuntimeException('disabled custody key was unwrapped'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'disabled custody key was unwrapped', $error->getMessage()); }
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'cancelled', 0, null);
    try { keyCustodyCreateKey($pdo, $management['tenant_id'], 'second', 300, $master); throw new RuntimeException('cancelled subscription created a key'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'cancelled subscription created a key', $error->getMessage()); }
    echo "key custody store checks passed\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $path) @unlink($path);
    @rmdir($root);
}
