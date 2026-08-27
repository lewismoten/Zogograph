<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/store.php';
function custodyExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$contract = json_decode((string)file_get_contents(__DIR__ . '/../openapi.json'), true);
custodyExpect(($contract['paths']['/health']['get']['security'] ?? null) === [], 'published custody health endpoint is not safely unauthenticated');
custodyExpect(($contract['paths']['/keys/{keyId}/envelopes/{artifactId}']['delete']['requestBody']['required'] ?? false) === true
    && (($contract['components']['schemas']['EnvelopeDeleteConfirmation']['properties']['confirm_recovery']['const'] ?? false) === true),
    'published custody API does not require explicit recovery confirmation before deleting an envelope');
custodyExpect(is_array($contract) && (($contract['components']['schemas']['UnwrapRequest']['properties']['requested_ttl_seconds']['default'] ?? null) === 0),
    'published custody API contract does not use zero key caching by default');
custodyExpect(($contract['components']['schemas']['EnvelopeStore']['required'] ?? []) === ['artifact_id', 'envelope_base64'],
    'published custody API contract omits required envelope fields');
custodyExpect(($contract['components']['parameters']['KeyId']['schema']['pattern'] ?? null) === '^key_[a-f0-9]{32}$',
    'published custody API contract does not match the accepted key identifier format');
custodyExpect(($contract['paths']['/subscription']['patch']['requestBody']['content']['application/json']['schema']['$ref'] ?? null) === '#/components/schemas/SubscriptionRequest'
    && ($contract['components']['schemas']['SubscriptionRequest']['properties']['action']['enum'] ?? null) === ['change_plan', 'cancel'],
    'published custody API contract omits the subscription-change request body');
custodyExpect(isset($contract['paths']['/internal/subscriptions/{tenantId}']) && isset($contract['components']['securitySchemes']['billingService']),
    'published custody API contract omits the isolated billing control plane');
custodyExpect(($contract['components']['schemas']['SubscriptionApply']['properties']['unwrap_quota_per_hour']['default'] ?? null) === 60,
    'published custody API contract omits the bounded recovery-release default');
foreach (['/keys', '/subscription', '/billing/checkout', '/billing/portal'] as $path) {
    foreach ($contract['paths'][$path] as $operation) custodyExpect(($operation['security'][0]['accountSession'] ?? null) === [],
        'published custody API contract omits management authentication for ' . $path);
}
custodyExpect(($contract['paths']['/keys/{keyId}/wrapping-key']['get']['security'][0]['enrolledClient'] ?? null) === [],
    'published custody API contract omits enrolled-client authentication for public wrapping-key access');
$api = file_get_contents(__DIR__ . '/../api.php');
$requestHelper = file_get_contents(__DIR__ . '/../lib/request.php');
custodyExpect($api !== false && str_contains($api, 'CUSTODY_MAX_REQUEST_BYTES') && str_contains($api, 'request body is too large'),
    'custody API does not bound request-body intake before parsing');
custodyExpect(str_contains((string)$api, "X-Frame-Options: DENY") && str_contains((string)$api, "Referrer-Policy: no-referrer")
    && str_contains((string)$api, 'Strict-Transport-Security'), 'custody API lacks its HTTPS response-header baseline');
custodyExpect(str_contains((string)$api, "catch (JsonException) { custodyReply(400") && str_contains((string)$api, 'JSON object body is invalid'),
    'custody API does not distinguish malformed JSON from an internal failure');
custodyExpect(str_contains((string)$api, "'/v1/health'"), 'custody API does not provide the documented health endpoint');
custodyExpect(str_contains((string)$api, "confirm_recovery must be true") && str_contains((string)$api, 'keyCustodyRequestFields($rawBody, [\'confirm_recovery\']')
    && str_contains((string)$requestHelper, 'request contains unsupported fields'),
    'custody API does not enforce explicit recovery confirmation before deleting an envelope');
$root = sys_get_temp_dir() . '/hostscope-custody-' . bin2hex(random_bytes(4)); mkdir($root, 0700, true);
try {
    $pdo = keyCustodyOpen(['database' => $root . '/service.db']);
    foreach ([$root . '/service.db', $root . '/service.db-wal', $root . '/service.db-shm'] as $privateFile) if (is_file($privateFile)) {
        custodyExpect((fileperms($privateFile) & 0077) === 0, 'custody SQLite metadata companion has broad permissions');
    }
    try { keyCustodyOpen(['database' => 'relative.db']); throw new RuntimeException('relative custody database path was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'relative custody database path was accepted', $error->getMessage()); }
    try { keyCustodyOpen(['database' => $root . '/web/service.db'], $root . '/web'); throw new RuntimeException('web-root custody database path was accepted'); }
    catch (RuntimeException $error) { custodyExpect($error->getMessage() !== 'web-root custody database path was accepted', $error->getMessage()); }
    $tenant = keyCustodyCreateTenant($pdo, 'Example');
    try { keyCustodyCreateTenant($pdo, "forged\ntenant"); throw new RuntimeException('multiline custody tenant name was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'multiline custody tenant name was accepted', $error->getMessage()); }
    [$initialClientTokenId] = explode('.', $tenant['client_token'], 2);
    $pdo->prepare('INSERT INTO custody_unwrap_usage (tenant_id, window_started, count) VALUES (?, ?, 1)')->execute([$tenant['tenant_id'], intdiv(time(), 3600) * 3600 - 172801]);
    $pdo->prepare('INSERT INTO client_nonces (token_id, nonce, expires_at) VALUES (?, ?, ?)')->execute([$initialClientTokenId, 'expired_nonce_abcdefghijklmnop', time() - 1]);
    keyCustodyOpen(['database' => $root . '/service.db']);
    custodyExpect((int)$pdo->query('SELECT COUNT(*) FROM client_nonces')->fetchColumn() === 0, 'expired client replay nonce was not pruned during service open');
    custodyExpect((int)$pdo->query('SELECT COUNT(*) FROM custody_unwrap_usage')->fetchColumn() === 0, 'expired custody recovery-release usage was not pruned during service open');
    $management = keyCustodyAuthenticate($pdo, $tenant['management_token'], 'management');
    $metadataBackup = keyCustodyBackupMetadata($pdo, $root . '/metadata-backup.db', $root . '/web');
    $backupPdo = new PDO('sqlite:' . $metadataBackup['path']);
    custodyExpect($metadataBackup['bytes'] > 0 && preg_match('/^[a-f0-9]{64}$/', $metadataBackup['sha256']) === 1
        && $backupPdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn() === 1 && (fileperms($metadataBackup['path']) & 0077) === 0,
        'custody metadata backup was not a private consistent SQLite copy');
    try { keyCustodyBackupMetadata($pdo, $root . '/web/metadata-backup.db', $root . '/web'); throw new RuntimeException('web-root custody metadata backup was accepted'); }
    catch (RuntimeException $error) { custodyExpect($error->getMessage() !== 'web-root custody metadata backup was accepted', $error->getMessage()); }
    $client = keyCustodyAuthenticate($pdo, $tenant['client_token'], 'client');
    $initialEntitlement = keyCustodyClientSubscription($pdo, $client['tenant_id']);
    custodyExpect($initialEntitlement['state'] === 'active' && $initialEntitlement['renews_at'] === null && $initialEntitlement['accepting_new_custody_operations']
        && $initialEntitlement['envelope_used'] === 0 && $initialEntitlement['can_store_envelopes'],
        'enrolled client could not read its minimal initial envelope entitlement');
    $additionalClient = keyCustodyCreateAdditionalClientCredential($pdo, $client['tenant_id'], 'second server');
    custodyExpect(keyCustodyAuthenticate($pdo, $additionalClient['token'], 'client')['tenant_id'] === $client['tenant_id'], 'additional enrolled-client credential did not authenticate');
    $credentialList = keyCustodyListClientCredentials($pdo, $client['tenant_id']);
    custodyExpect(count($credentialList) === 2 && in_array('second server', array_column($credentialList, 'label'), true),
        'enrolled-client metadata did not list both labeled deployments');
    keyCustodyRevokeClientCredential($pdo, $client['tenant_id'], $additionalClient['id']);
    try { keyCustodyAuthenticate($pdo, $additionalClient['token'], 'client'); throw new RuntimeException('revoked individual enrolled-client credential remained active'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'revoked individual enrolled-client credential remained active', $error->getMessage()); }
    custodyExpect(keyCustodyAuthenticate($pdo, $tenant['client_token'], 'client')['tenant_id'] === $client['tenant_id'], 'individual credential revocation affected another deployment');
    try { keyCustodyCreateAdditionalClientCredential($pdo, $client['tenant_id'], "bad\nlabel"); throw new RuntimeException('multiline deployment label was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'multiline deployment label was accepted', $error->getMessage()); }
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
    $replacementManagement = keyCustodyRotateManagementCredential($pdo, $management['tenant_id']);
    try { keyCustodyAuthenticate($pdo, $tenant['management_token'], 'management'); throw new RuntimeException('rotated management credential remained active'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'rotated management credential remained active', $error->getMessage()); }
    $management = keyCustodyAuthenticate($pdo, $replacementManagement, 'management');
    custodyExpect($management['tenant_id'] === $client['tenant_id'], 'replacement management credential did not authenticate');
    $replacementCredential = keyCustodyRotateClientCredential($pdo, $management['tenant_id']);
    try { keyCustodyAuthenticate($pdo, $tenant['client_token'], 'client'); throw new RuntimeException('rotated client credential remained active'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'rotated client credential remained active', $error->getMessage()); }
    $client = keyCustodyAuthenticate($pdo, $replacementCredential['token'], 'client');
    custodyExpect($client['tenant_id'] === $management['tenant_id'], 'replacement client credential did not authenticate');
    $master = random_bytes(32); $key = keyCustodyCreateKey($pdo, $management['tenant_id'], 'primary', 300, $master);
    try { keyCustodyCreateKey($pdo, $management['tenant_id'], "forged\nkey", 300, $master); throw new RuntimeException('multiline custody key name was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'multiline custody key name was accepted', $error->getMessage()); }
    $wrappingPublic = base64_decode($key['wrapping_public_key'], true);
    custodyExpect(is_string($wrappingPublic) && keyCustodyWrappingPublicKey($pdo, $client['tenant_id'], $key['id']) === $key['wrapping_public_key'],
        'enrolled client could not obtain the custody public wrapping key');
    $artifactDataKey = random_bytes(32); $sealedDataKey = sodium_crypto_box_seal($artifactDataKey, $wrappingPublic);
    custodyExpect(keyCustodySubscription($pdo, $management['tenant_id'])['key_used'] === 1
        && keyCustodySubscription($pdo, $management['tenant_id'])['can_create_keys'],
        'subscription did not report current active custody-key usage');
    custodyExpect(keyCustodyListKeys($pdo, $management['tenant_id'])[0]['id'] === $key['id'] && keyCustodyListKeys($pdo, $management['tenant_id'])[0]['envelope_count'] === 0,
        'management key list omitted created key or its envelope count');
    custodyExpect(keyCustodyListKeys($pdo, $management['tenant_id'])[0]['wrapping_mode'] === 'asymmetric_artifact_envelope',
        'new custody key was not identified as asymmetric artifact-envelope wrapping');
    $policy = keyCustodyUpdateKeyPolicy($pdo, $management['tenant_id'], $key['id'], 120, 'primary policy');
    custodyExpect($policy['name'] === 'primary policy' && $policy['authorization_ttl'] === 120, 'custody key policy was not updated');
    try { keyCustodyUpdateKeyPolicy($pdo, $management['tenant_id'], $key['id'], 1); throw new RuntimeException('invalid custody key policy was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'invalid custody key policy was accepted', $error->getMessage()); }
    try { keyCustodyUpdateKeyPolicy($pdo, $management['tenant_id'], $key['id'], 120, "forged\npolicy"); throw new RuntimeException('multiline custody key policy name was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'multiline custody key policy name was accepted', $error->getMessage()); }
    custodyExpect(in_array('key_create', array_column(keyCustodyAuditEvents($pdo, $management['tenant_id'], 10), 'action'), true), 'tenant audit history omitted the key creation');
    custodyExpect(in_array('key_policy_update', array_column(keyCustodyAuditEvents($pdo, $management['tenant_id'], 10), 'action'), true), 'tenant audit history omitted the policy update');
    custodyExpect(keyCustodySubscription($pdo, $management['tenant_id'])['state'] === 'active' && keyCustodySubscription($pdo, $management['tenant_id'])['envelope_quota'] === 1000
        && keyCustodySubscription($pdo, $management['tenant_id'])['envelope_used'] === 0 && keyCustodySubscription($pdo, $management['tenant_id'])['key_used'] === 1,
        'initial subscription envelope entitlement is unavailable');
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
    if (function_exists('symlink') && @symlink($root, $root . '/linked-private')) {
        try { keyCustodyReadBillingServiceToken(['billing_service_token_file' => $root . '/linked-private/billing.token'], $root . '/web'); throw new RuntimeException('symlinked billing-token directory was accepted'); }
        catch (RuntimeException $error) { custodyExpect($error->getMessage() !== 'symlinked billing-token directory was accepted', $error->getMessage()); }
        $masterPath = $root . '/master.key'; file_put_contents($masterPath, base64_encode(random_bytes(32)) . "\n"); chmod($masterPath, 0600);
        try { keyCustodyReadMasterKey(['master_key_file' => $root . '/linked-private/master.key'], $root . '/web'); throw new RuntimeException('symlinked master-key directory was accepted'); }
        catch (RuntimeException $error) { custodyExpect($error->getMessage() !== 'symlinked master-key directory was accepted', $error->getMessage()); }
        @unlink($masterPath); @unlink($root . '/linked-private');
    }
    $pending = keyCustodyRequestSubscriptionChange($pdo, $management['tenant_id'], 'change_plan', 'starter');
    keyCustodyApplySubscription($pdo, $management['tenant_id'], 'starter', 'active', 3, 1767225600);
    $applied = keyCustodySubscription($pdo, $management['tenant_id']);
    custodyExpect($applied['plan'] === 'starter' && $applied['key_quota'] === 3 && $applied['renews_at'] === 1767225600,
        'billing control plane did not apply subscription entitlement');
    custodyExpect(!$applied['accepting_new_custody_operations'] && !$applied['can_create_keys'],
        'overdue subscription still allowed new custody operations');
    custodyExpect(!keyCustodyClientSubscription($pdo, $client['tenant_id'])['accepting_new_custody_operations'],
        'enrolled client entitlement did not report an overdue renewal pause');
    try { keyCustodyCreateKey($pdo, $management['tenant_id'], 'overdue renewal', 300, $master); throw new RuntimeException('overdue subscription created a custody key'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'overdue subscription created a custody key', $error->getMessage()); }
    custodyExpect(keyCustodySubscriptionRequests($pdo, $management['tenant_id'])[0]['id'] === $pending['id']
        && keyCustodySubscriptionRequests($pdo, $management['tenant_id'])[0]['state'] === 'applied', 'billing control plane did not resolve pending request');
    try { keyCustodyApplySubscription($pdo, $management['tenant_id'], 'starter', 'active', -1, null); throw new RuntimeException('invalid billing quota was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'invalid billing quota was accepted', $error->getMessage()); }
    try { keyCustodyApplySubscription($pdo, 'ten_' . str_repeat('0', 24), 'starter', 'active', 2, null); throw new RuntimeException('unknown tenant billing entitlement was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'unknown tenant billing entitlement was accepted', $error->getMessage()); }
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'past_due', 2, null);
    try { keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], 'account:blocked', random_bytes(80), $master); throw new RuntimeException('past-due subscription stored an envelope'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'past-due subscription stored an envelope', $error->getMessage()); }
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'active', 2, null);
    try { keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], 'account:forged', random_bytes(SODIUM_CRYPTO_BOX_SEALBYTES + 32), $master); throw new RuntimeException('unopenable envelope was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'unopenable envelope was accepted', $error->getMessage()); }
    $envelope = keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $sealedDataKey, $master);
    try { keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], "account:\nforged", $sealedDataKey, $master); throw new RuntimeException('multiline artifact identifier was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'multiline artifact identifier was accepted', $error->getMessage()); }
    custodyExpect(keyCustodyListEnvelopes($pdo, $management['tenant_id'], $key['id'])[0]['id'] === $envelope['id'], 'envelope metadata was not retained');
    custodyExpect(keyCustodyListKeys($pdo, $management['tenant_id'])[0]['envelope_count'] === 1, 'management key list omitted registered envelope count');
    custodyExpect(keyCustodySubscription($pdo, $management['tenant_id'])['envelope_used'] === 1, 'subscription metadata omitted current envelope use');
    custodyExpect(keyCustodyClientSubscription($pdo, $client['tenant_id'])['envelope_used'] === 1,
        'enrolled client entitlement did not report current envelope usage');
    $replacementMaster = random_bytes(32);
    $rewrapped = keyCustodyRewrapMasterKey($pdo, $master, $replacementMaster);
    custodyExpect($rewrapped['keys'] === 1 && $rewrapped['tenants'] === 1
        && hash_equals($artifactDataKey, keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $replacementMaster)),
        'service master-key rotation did not preserve artifact recovery');
    try { keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $master); throw new RuntimeException('old service master key still unwrapped custody material'); }
    catch (RuntimeException $error) { custodyExpect($error->getMessage() !== 'old service master key still unwrapped custody material', $error->getMessage()); }
    $master = $replacementMaster;
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'active', 2, null, 1);
    custodyExpect(!keyCustodyClientSubscription($pdo, $client['tenant_id'])['can_store_envelopes'],
        'enrolled client entitlement allowed an envelope after its quota was filled');
    try { keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], 'account:quota', random_bytes(80), $master); throw new RuntimeException('subscription envelope quota was exceeded'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'subscription envelope quota was exceeded', $error->getMessage()); }
    custodyExpect(hash_equals($artifactDataKey, keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $master)), 'client unwrap did not recover the sealed artifact data key');
    $pdo->exec('DELETE FROM custody_unwrap_usage');
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'active', 2, null, 1, 1);
    custodyExpect(keyCustodySubscription($pdo, $management['tenant_id'])['unwrap_quota_per_hour'] === 1
        && hash_equals($artifactDataKey, keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $master)),
        'configured custody recovery-release allowance did not permit its first recovery');
    try { keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $master); throw new RuntimeException('custody recovery-release limit was exceeded'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'custody recovery-release limit was exceeded', $error->getMessage()); }
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'active', 2, null, 1, 60);
    custodyExpect(keyCustodyCacheRecommendation($pdo, $client['tenant_id'], $key['id'], 900) === 120,
        'custody key authorization limit did not cap a requested cache duration');
    custodyExpect(keyCustodyCacheRecommendation($pdo, $client['tenant_id'], $key['id'], 0) === 0,
        'custody cache recommendation did not preserve the zero-cache default');
    try { keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:missing', $master); throw new RuntimeException('unknown artifact envelope was authorized'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'unknown artifact envelope was authorized', $error->getMessage()); }
    custodyExpect((int)$pdo->query('SELECT count FROM custody_unwrap_usage WHERE tenant_id = ' . $pdo->quote($client['tenant_id']) . ' ORDER BY window_started DESC LIMIT 1')->fetchColumn() === 1,
        'a failed custody unwrap consumed the successful-recovery release allowance');
    $legacyId = 'key_' . bin2hex(random_bytes(16)); $legacyKey = random_bytes(32); $legacyNonce = random_bytes(12);
    $legacyWrapped = openssl_encrypt($legacyKey, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $legacyNonce, $legacyTag, $legacyId, 16);
    $pdo->prepare('INSERT INTO custody_keys (id, tenant_id, name, wrapped_key, wrap_nonce, wrap_tag, wrapping_public_key, authorization_ttl, state, created_at, rotated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NULL)')
        ->execute([$legacyId, $management['tenant_id'], 'pre-public-wrapping', $legacyWrapped, $legacyNonce, $legacyTag, 300, 'rotating', time()]);
    $pdo->prepare('INSERT INTO key_envelopes (id, key_id, artifact_id, envelope, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['env_' . bin2hex(random_bytes(16)), $legacyId, 'legacy:artifact', random_bytes(80), time()]);
    custodyExpect(hash_equals($legacyKey, keyCustodyUnwrapKey($pdo, $client['tenant_id'], $legacyId, 'legacy:artifact', $master))
        && in_array('legacy_symmetric', array_column(keyCustodyListKeys($pdo, $management['tenant_id']), 'wrapping_mode'), true),
        'service upgrade did not preserve recoverability or disclose legacy symmetric custody records');
    try { keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $legacyId, 'legacy:new', random_bytes(SODIUM_CRYPTO_BOX_SEALBYTES + 32), $master); throw new RuntimeException('legacy custody key accepted a new envelope'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'legacy custody key accepted a new envelope', $error->getMessage()); }
    $replacement = keyCustodyRotateKey($pdo, $management['tenant_id'], $key['id'], $master);
    custodyExpect($replacement['id'] !== $key['id'] && keyCustodyListKeys($pdo, $management['tenant_id'])[0]['id'] === $replacement['id'], 'custody key rotation did not create a replacement');
    custodyExpect(hash_equals($artifactDataKey, keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], 'account:20260827', $master)),
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
