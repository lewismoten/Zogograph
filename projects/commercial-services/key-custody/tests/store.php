<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/store.php';
function custodyExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$contract = json_decode((string)file_get_contents(__DIR__ . '/../openapi.json'), true);
custodyExpect(is_array($contract) && (($contract['components']['schemas']['UnwrapRequest']['properties']['requested_ttl_seconds']['default'] ?? null) === 0),
    'published custody API contract does not use zero key caching by default');
custodyExpect(($contract['components']['schemas']['EnvelopeStore']['required'] ?? []) === ['artifact_id', 'envelope_base64'],
    'published custody API contract omits required envelope fields');
custodyExpect(isset($contract['paths']['/internal/subscriptions/{tenantId}']) && isset($contract['components']['securitySchemes']['billingService']),
    'published custody API contract omits the isolated billing control plane');
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
    custodyExpect(in_array('key_create', array_column(keyCustodyAuditEvents($pdo, $management['tenant_id'], 1), 'action'), true), 'tenant audit history omitted the key creation');
    custodyExpect(keyCustodySubscription($pdo, $management['tenant_id'])['state'] === 'active', 'initial subscription state is unavailable');
    $change = keyCustodyRequestSubscriptionChange($pdo, $management['tenant_id'], 'change_plan', 'pro');
    custodyExpect($change['state'] === 'pending' && $change['requested_plan'] === 'pro', 'subscription change request was not retained');
    custodyExpect(keyCustodySubscriptionRequests($pdo, $management['tenant_id'])[0]['id'] === $change['id'], 'subscription request is not visible to the tenant');
    try { keyCustodyRequestSubscriptionChange($pdo, $management['tenant_id'], 'cancel', null); throw new RuntimeException('a second pending subscription request was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'a second pending subscription request was accepted', $error->getMessage()); }
    keyCustodyWithdrawSubscriptionRequest($pdo, $management['tenant_id'], $change['id']);
    custodyExpect(keyCustodySubscriptionRequests($pdo, $management['tenant_id'])[0]['state'] === 'withdrawn', 'subscription request was not withdrawn');
    $billingTokenPath = $root . '/billing.token'; $billingToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    file_put_contents($billingTokenPath, $billingToken . "\n"); chmod($billingTokenPath, 0600);
    custodyExpect(keyCustodyReadBillingServiceToken(['billing_service_token_file' => $billingTokenPath], $root . '/web') === $billingToken,
        'private billing-control token was not read');
    keyCustodyAuthenticateBillingService(['billing_service_token_file' => $billingTokenPath], $billingToken, $root . '/web');
    try { keyCustodyAuthenticateBillingService(['billing_service_token_file' => $billingTokenPath], 'wrong-token', $root . '/web'); throw new RuntimeException('wrong billing-control token was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'wrong billing-control token was accepted', $error->getMessage()); }
    $pending = keyCustodyRequestSubscriptionChange($pdo, $management['tenant_id'], 'change_plan', 'starter');
    keyCustodyApplySubscription($pdo, $management['tenant_id'], 'starter', 'active', 3, 1767225600);
    $applied = keyCustodySubscription($pdo, $management['tenant_id']);
    custodyExpect($applied['plan'] === 'starter' && $applied['key_quota'] === 3 && $applied['renews_at'] === 1767225600,
        'billing control plane did not apply subscription entitlement');
    custodyExpect(keyCustodySubscriptionRequests($pdo, $management['tenant_id'])[0]['id'] === $pending['id']
        && keyCustodySubscriptionRequests($pdo, $management['tenant_id'])[0]['state'] === 'applied', 'billing control plane did not resolve pending request');
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'past_due', 2, null);
    try { keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], 'account:blocked', random_bytes(80)); throw new RuntimeException('past-due subscription stored an envelope'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'past-due subscription stored an envelope', $error->getMessage()); }
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'active', 2, null);
    $envelope = keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], 'account:20260827', random_bytes(80));
    custodyExpect(keyCustodyListEnvelopes($pdo, $management['tenant_id'], $key['id'])[0]['id'] === $envelope['id'], 'envelope metadata was not retained');
    custodyExpect(strlen(keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $master)) === 32, 'client unwrap did not recover an active custody key');
    custodyExpect(keyCustodyCacheRecommendation($pdo, $client['tenant_id'], $key['id'], 900) === 300,
        'custody key authorization limit did not cap a requested cache duration');
    custodyExpect(keyCustodyCacheRecommendation($pdo, $client['tenant_id'], $key['id'], 0) === 0,
        'custody cache recommendation did not preserve the zero-cache default');
    try { keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:missing', $master); throw new RuntimeException('unknown artifact envelope was authorized'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'unknown artifact envelope was authorized', $error->getMessage()); }
    $replacement = keyCustodyRotateKey($pdo, $management['tenant_id'], $key['id'], $master);
    custodyExpect($replacement['id'] !== $key['id'] && keyCustodyListKeys($pdo, $management['tenant_id'])[0]['id'] === $replacement['id'], 'custody key rotation did not create a replacement');
    custodyExpect(strlen(keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $master)) === 32,
        'rotating custody key did not preserve existing artifact recovery');
    try { keyCustodyRetireKey($pdo, $management['tenant_id'], $key['id']); throw new RuntimeException('custody key with an envelope was retired'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'custody key with an envelope was retired', $error->getMessage()); }
    try { keyCustodyDisableKey($pdo, $management['tenant_id'], $key['id']); throw new RuntimeException('custody key with an envelope was disabled'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'custody key with an envelope was disabled', $error->getMessage()); }
    keyCustodyDeleteEnvelope($pdo, $management['tenant_id'], $key['id'], 'account:20260827');
    custodyExpect(keyCustodyListEnvelopes($pdo, $management['tenant_id'], $key['id']) === [], 'artifact envelope was not deleted');
    keyCustodyRetireKey($pdo, $management['tenant_id'], $key['id']);
    try { keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $master); throw new RuntimeException('retired custody key was unwrapped'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'retired custody key was unwrapped', $error->getMessage()); }
    keyCustodyDisableKey($pdo, $management['tenant_id'], $replacement['id']);
    try { keyCustodyUnwrapKey($pdo, $client['tenant_id'], $replacement['id'], 'account:20260827', $master); throw new RuntimeException('disabled custody key was unwrapped'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'disabled custody key was unwrapped', $error->getMessage()); }
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'cancelled', 0, null);
    try { keyCustodyCreateKey($pdo, $management['tenant_id'], 'second', 300, $master); throw new RuntimeException('cancelled subscription created a key'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'cancelled subscription created a key', $error->getMessage()); }
    echo "key custody store checks passed\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $path) @unlink($path);
    @rmdir($root);
}
