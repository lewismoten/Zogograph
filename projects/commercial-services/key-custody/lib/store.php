<?php
declare(strict_types=1);

const KEY_CUSTODY_TOKEN_BYTES = 32;

function keyCustodyOpen(array $config): PDO
{
    $path = (string)($config['database'] ?? '');
    if ($path === '' || !str_starts_with($path, '/')) throw new InvalidArgumentException('custody database path must be absolute');
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('could not create custody data directory');
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA busy_timeout = 5000'); $pdo->exec('PRAGMA foreign_keys = ON'); @chmod($path, 0600);
    $pdo->exec('CREATE TABLE IF NOT EXISTS tenants (id TEXT PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS access_tokens (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, scope TEXT NOT NULL, token_hash TEXT NOT NULL, public_key BLOB, created_at INTEGER NOT NULL, revoked_at INTEGER)');
    if (!keyCustodyTableHasColumn($pdo, 'access_tokens', 'public_key')) $pdo->exec('ALTER TABLE access_tokens ADD COLUMN public_key BLOB');
    if (!keyCustodyTableHasColumn($pdo, 'access_tokens', 'label')) $pdo->exec('ALTER TABLE access_tokens ADD COLUMN label TEXT');
    $pdo->exec('CREATE INDEX IF NOT EXISTS access_tokens_tenant_scope ON access_tokens (tenant_id, scope, revoked_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS client_nonces (token_id TEXT NOT NULL REFERENCES access_tokens(id) ON DELETE CASCADE, nonce TEXT NOT NULL, expires_at INTEGER NOT NULL, PRIMARY KEY (token_id, nonce))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS client_nonces_expiry ON client_nonces (expires_at)');
    $pdo->prepare('DELETE FROM client_nonces WHERE expires_at < ?')->execute([time()]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS subscriptions (tenant_id TEXT PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE, plan TEXT NOT NULL, state TEXT NOT NULL, key_quota INTEGER NOT NULL, envelope_quota INTEGER NOT NULL DEFAULT 1000, renews_at INTEGER, changed_at INTEGER NOT NULL)');
    if (!keyCustodyTableHasColumn($pdo, 'subscriptions', 'envelope_quota')) $pdo->exec('ALTER TABLE subscriptions ADD COLUMN envelope_quota INTEGER NOT NULL DEFAULT 1000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS subscription_requests (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, action TEXT NOT NULL, requested_plan TEXT, state TEXT NOT NULL, created_at INTEGER NOT NULL, resolved_at INTEGER)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS subscription_requests_tenant_state ON subscription_requests (tenant_id, state, created_at DESC)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS custody_keys (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, name TEXT NOT NULL, wrapped_key BLOB NOT NULL, wrap_nonce BLOB NOT NULL, wrap_tag BLOB NOT NULL, authorization_ttl INTEGER NOT NULL, state TEXT NOT NULL, created_at INTEGER NOT NULL, rotated_at INTEGER)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custody_keys_tenant_state ON custody_keys (tenant_id, state, created_at DESC)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS key_envelopes (id TEXT PRIMARY KEY, key_id TEXT NOT NULL REFERENCES custody_keys(id) ON DELETE CASCADE, artifact_id TEXT NOT NULL, envelope BLOB NOT NULL, created_at INTEGER NOT NULL, UNIQUE(key_id, artifact_id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS custody_audit (id INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL, at INTEGER NOT NULL, action TEXT NOT NULL, target TEXT NOT NULL, detail TEXT NOT NULL)');
    return $pdo;
}

function keyCustodyTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $field) if (($field['name'] ?? null) === $column) return true;
    return false;
}

/** @return array{tenant_id:string,management_token:string,client_token:string,client_signing_private_key:string} */
function keyCustodyCreateTenant(PDO $pdo, string $name): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('tenant name is invalid');
    $tenant = 'ten_' . bin2hex(random_bytes(12)); $now = time();
    $pdo->beginTransaction();
    try {
    $pdo->prepare('INSERT INTO tenants (id, name, active, created_at) VALUES (?, ?, 1, ?)')->execute([$tenant, $name, $now]);
    $management = keyCustodyCreateToken($pdo, $tenant, 'management');
    $client = keyCustodyCreateClientCredential($pdo, $tenant, 'initial enrollment');
    $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan, state, key_quota, envelope_quota, renews_at, changed_at) VALUES (?, ?, ?, ?, ?, NULL, ?)')->execute([$tenant, 'free', 'active', 2, 1000, $now]);
    keyCustodyAudit($pdo, $tenant, 'tenant_create', $tenant, 'initial free plan');
    $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
    return ['tenant_id' => $tenant, 'management_token' => $management, 'client_token' => $client['token'], 'client_signing_private_key' => $client['signing_private_key']];
}

function keyCustodyCreateToken(PDO $pdo, string $tenant, string $scope, ?string $publicKey = null, ?string $label = null): string
{
    if (!in_array($scope, ['management', 'client'], true)) throw new InvalidArgumentException('token scope is invalid');
    if ($scope === 'client' && (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)) throw new InvalidArgumentException('client signing public key is invalid');
    if ($scope === 'management' && $publicKey !== null) throw new InvalidArgumentException('management tokens cannot have a signing key');
    if ($label !== null) {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120 || preg_match('/[\x00-\x1F\x7F]/', $label)) throw new InvalidArgumentException('credential label is invalid');
    }
    $id = 'tok_' . bin2hex(random_bytes(10)); $secret = base64_encode(random_bytes(KEY_CUSTODY_TOKEN_BYTES));
    $pdo->prepare('INSERT INTO access_tokens (id, tenant_id, scope, token_hash, public_key, label, created_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)')
        ->execute([$id, $tenant, $scope, password_hash($secret, PASSWORD_DEFAULT), $publicKey, $label, time()]);
    return $id . '.' . $secret;
}

/** @return array{id:string,token:string,signing_private_key:string} */
function keyCustodyCreateClientCredential(PDO $pdo, string $tenant, ?string $label = null): array
{
    if (!function_exists('sodium_crypto_sign_keypair')) throw new RuntimeException('libsodium is required for enrolled-client credentials');
    $pair = sodium_crypto_sign_keypair();
    $token = keyCustodyCreateToken($pdo, $tenant, 'client', sodium_crypto_sign_publickey($pair), $label);
    [$id] = explode('.', $token, 2);
    return [
        'id' => $id, 'token' => $token,
        // This is emitted once for enrollment; only the public half is retained.
        'signing_private_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
    ];
}

/** @return array{id:string,token:string,signing_private_key:string} */
function keyCustodyCreateAdditionalClientCredential(PDO $pdo, string $tenant, string $label): array
{
    $tenantCheck = $pdo->prepare('SELECT active FROM tenants WHERE id = ?'); $tenantCheck->execute([$tenant]);
    if ($tenantCheck->fetchColumn() !== 1) throw new InvalidArgumentException('tenant is unavailable');
    $label = trim($label);
    $credential = keyCustodyCreateClientCredential($pdo, $tenant, $label);
    keyCustodyAudit($pdo, $tenant, 'client_credential_create', $credential['id'], 'additional enrolled-client credential; label=' . $label);
    return $credential;
}

/** @return list<array{id:string,label:?string,state:string,created_at:int}> */
function keyCustodyListClientCredentials(PDO $pdo, string $tenant): array
{
    $stmt = $pdo->prepare("SELECT id, label, created_at, revoked_at FROM access_tokens WHERE tenant_id = ? AND scope = 'client' ORDER BY created_at DESC");
    $stmt->execute([$tenant]);
    return array_map(fn(array $row): array => ['id' => $row['id'], 'label' => $row['label'], 'state' => $row['revoked_at'] === null ? 'active' : 'revoked', 'created_at' => (int)$row['created_at']], $stmt->fetchAll());
}

function keyCustodyRevokeClientCredential(PDO $pdo, string $tenant, string $id): void
{
    if (!preg_match('/^tok_[a-f0-9]{20}$/', $id)) throw new InvalidArgumentException('enrolled-client credential id is invalid');
    $stmt = $pdo->prepare("UPDATE access_tokens SET revoked_at = ? WHERE id = ? AND tenant_id = ? AND scope = 'client' AND revoked_at IS NULL");
    $stmt->execute([time(), $id, $tenant]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('active enrolled-client credential is unavailable');
    keyCustodyAudit($pdo, $tenant, 'client_credential_revoke', $id, 'management request');
}

/** Revokes prior enrolled-client credentials and emits a replacement once.
 * @return array{id:string,token:string,signing_private_key:string} */
function keyCustodyRotateClientCredential(PDO $pdo, string $tenant): array
{
    $tenantCheck = $pdo->prepare('SELECT active FROM tenants WHERE id = ?'); $tenantCheck->execute([$tenant]);
    if ($tenantCheck->fetchColumn() !== 1) throw new InvalidArgumentException('tenant is unavailable');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE access_tokens SET revoked_at = ? WHERE tenant_id = ? AND scope = 'client' AND revoked_at IS NULL")->execute([time(), $tenant]);
        $credential = keyCustodyCreateClientCredential($pdo, $tenant, 'emergency replacement');
        keyCustodyAudit($pdo, $tenant, 'client_credential_rotate', $tenant, 'prior enrolled-client credentials revoked');
        $pdo->commit();
        return $credential;
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}

/** Revokes every prior management token and emits the replacement once. */
function keyCustodyRotateManagementCredential(PDO $pdo, string $tenant): string
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE access_tokens SET revoked_at = ? WHERE tenant_id = ? AND scope = 'management' AND revoked_at IS NULL")
            ->execute([time(), $tenant]);
        $token = keyCustodyCreateToken($pdo, $tenant, 'management');
        keyCustodyAudit($pdo, $tenant, 'management_credential_rotate', $tenant, 'prior management credentials revoked');
        $pdo->commit();
        return $token;
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}

/** @return array{tenant_id:string,scope:string} */
function keyCustodyAuthenticate(PDO $pdo, string $token, string $requiredScope): array
{
    [$id, $secret] = array_pad(explode('.', $token, 2), 2, '');
    if (!preg_match('/^tok_[a-f0-9]{20}$/', $id) || $secret === '') throw new InvalidArgumentException('access token is invalid');
    $stmt = $pdo->prepare('SELECT tokens.tenant_id, tokens.scope, tokens.token_hash, tenants.active FROM access_tokens tokens JOIN tenants ON tenants.id = tokens.tenant_id WHERE tokens.id = ? AND tokens.revoked_at IS NULL');
    $stmt->execute([$id]); $row = $stmt->fetch();
    if (!is_array($row) || !(bool)$row['active'] || $row['scope'] !== $requiredScope || !password_verify($secret, $row['token_hash'])) throw new InvalidArgumentException('access token is refused');
    return ['tenant_id' => $row['tenant_id'], 'scope' => $row['scope']];
}

/** @return array{tenant_id:string,scope:string} */
function keyCustodyClientRequestPayload(string $method, string $path, string $timestamp, string $nonce, string $body): string
{
    return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . base64_encode(hash('sha256', $body, true));
}

/** @return array{tenant_id:string,scope:string} */
function keyCustodyAuthenticateClientRequest(PDO $pdo, string $token, string $method, string $path, string $timestamp, string $nonce, string $signature, string $body): array
{
    $auth = keyCustodyAuthenticate($pdo, $token, 'client');
    [$tokenId] = explode('.', $token, 2);
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 || !preg_match('/^[A-Za-z0-9_-]{16,200}$/', $nonce)) {
        throw new InvalidArgumentException('client request timestamp or nonce is invalid');
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('libsodium is required for enrolled-client requests');
    $stmt = $pdo->prepare('SELECT public_key FROM access_tokens WHERE id = ? AND tenant_id = ? AND scope = ? AND revoked_at IS NULL');
    $stmt->execute([$tokenId, $auth['tenant_id'], 'client']); $publicKey = $stmt->fetchColumn();
    $signatureBytes = base64_decode($signature, true);
    if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !is_string($signatureBytes) || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached($signatureBytes, keyCustodyClientRequestPayload($method, $path, $timestamp, $nonce, $body), $publicKey)) {
        throw new InvalidArgumentException('client request signature is invalid');
    }
    $pdo->prepare('DELETE FROM client_nonces WHERE expires_at < ?')->execute([time()]);
    try {
        $pdo->prepare('INSERT INTO client_nonces (token_id, nonce, expires_at) VALUES (?, ?, ?)')->execute([$tokenId, $nonce, time() + 600]);
    } catch (PDOException) { throw new InvalidArgumentException('client request nonce was already used'); }
    return $auth;
}

/** @return array{id:string,name:string,authorization_ttl:int,state:string} */
function keyCustodyCreateKey(PDO $pdo, string $tenant, string $name, int $ttl, string $master): array
{
    keyCustodySubscriptionAllows($pdo, $tenant);
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120 || $ttl < 60 || $ttl > 900 || strlen($master) !== 32) throw new InvalidArgumentException('key creation request is invalid');
    $used = $pdo->prepare("SELECT COUNT(*) FROM custody_keys WHERE tenant_id = ? AND state = 'active'"); $used->execute([$tenant]);
    $quota = $pdo->prepare('SELECT key_quota FROM subscriptions WHERE tenant_id = ?'); $quota->execute([$tenant]);
    if ((int)$used->fetchColumn() >= (int)$quota->fetchColumn()) throw new InvalidArgumentException('subscription key quota is reached');
    $id = 'key_' . bin2hex(random_bytes(16)); $plain = random_bytes(32); $nonce = random_bytes(12);
    $wrapped = openssl_encrypt($plain, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $nonce, $tag, $id, 16);
    if (!is_string($wrapped) || !is_string($tag)) throw new RuntimeException('could not wrap custody key');
    $pdo->prepare('INSERT INTO custody_keys (id, tenant_id, name, wrapped_key, wrap_nonce, wrap_tag, authorization_ttl, state, created_at, rotated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)')
        ->execute([$id, $tenant, $name, $wrapped, $nonce, $tag, $ttl, 'active', time()]);
    keyCustodyAudit($pdo, $tenant, 'key_create', $id, 'ttl=' . $ttl);
    return ['id' => $id, 'name' => $name, 'authorization_ttl' => $ttl, 'state' => 'active'];
}

function keyCustodyUnwrapKey(PDO $pdo, string $tenant, string $id, string $artifactId, string $master): string
{
    if (strlen($master) !== 32 || $artifactId === '' || strlen($artifactId) > 200) throw new InvalidArgumentException('custody key authorization is invalid');
    $envelope = $pdo->prepare('SELECT COUNT(*) FROM key_envelopes envelopes JOIN custody_keys keys ON keys.id = envelopes.key_id WHERE envelopes.key_id = ? AND keys.tenant_id = ? AND envelopes.artifact_id = ?');
    $envelope->execute([$id, $tenant, $artifactId]);
    if ((int)$envelope->fetchColumn() !== 1) throw new InvalidArgumentException('the requested artifact envelope is unavailable');
    $stmt = $pdo->prepare("SELECT wrapped_key, wrap_nonce, wrap_tag FROM custody_keys WHERE id = ? AND tenant_id = ? AND state IN ('active', 'rotating')"); $stmt->execute([$id, $tenant]); $row = $stmt->fetch();
    if (!is_array($row)) throw new InvalidArgumentException('custody key is unavailable');
    $plain = openssl_decrypt($row['wrapped_key'], 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $row['wrap_nonce'], $row['wrap_tag'], $id);
    if (!is_string($plain) || strlen($plain) !== 32) throw new RuntimeException('custody key could not be unwrapped');
    keyCustodyAudit($pdo, $tenant, 'key_unwrap_authorized', $id, 'artifact=' . $artifactId);
    return $plain;
}

/** Returns a bounded cache recommendation; it does not turn exported key
 * material into a revocable capability. Zero remains a valid safer choice. */
function keyCustodyCacheRecommendation(PDO $pdo, string $tenant, string $id, int $requested): int
{
    if ($requested < 0 || $requested > 900) throw new InvalidArgumentException('requested key cache duration is invalid');
    $stmt = $pdo->prepare("SELECT authorization_ttl FROM custody_keys WHERE id = ? AND tenant_id = ? AND state IN ('active', 'rotating')");
    $stmt->execute([$id, $tenant]); $limit = $stmt->fetchColumn();
    if ($limit === false) throw new InvalidArgumentException('custody key is unavailable');
    return min($requested, (int)$limit);
}

/** @return array{id:string,artifact_id:string,created_at:int} */
function keyCustodyStoreEnvelope(PDO $pdo, string $tenant, string $keyId, string $artifactId, string $envelope): array
{
    keyCustodySubscriptionAllows($pdo, $tenant);
    if (!preg_match('/^key_[a-f0-9]{32}$/', $keyId) || $artifactId === '' || strlen($artifactId) > 200 || $envelope === '' || strlen($envelope) > 1048576) {
        throw new InvalidArgumentException('key envelope request is invalid');
    }
    $key = $pdo->prepare("SELECT COUNT(*) FROM custody_keys WHERE id = ? AND tenant_id = ? AND state = 'active'"); $key->execute([$keyId, $tenant]);
    if ((int)$key->fetchColumn() !== 1) throw new InvalidArgumentException('custody key is unavailable');
    $exists = $pdo->prepare('SELECT COUNT(*) FROM key_envelopes WHERE key_id = ? AND artifact_id = ?'); $exists->execute([$keyId, $artifactId]);
    if ((int)$exists->fetchColumn() === 0) {
        $usage = $pdo->prepare('SELECT COUNT(*) FROM key_envelopes envelopes JOIN custody_keys keys ON keys.id = envelopes.key_id WHERE keys.tenant_id = ?'); $usage->execute([$tenant]);
        $quota = $pdo->prepare('SELECT envelope_quota FROM subscriptions WHERE tenant_id = ?'); $quota->execute([$tenant]);
        if ((int)$usage->fetchColumn() >= (int)$quota->fetchColumn()) throw new InvalidArgumentException('subscription envelope quota is reached');
    }
    $id = 'env_' . bin2hex(random_bytes(16)); $now = time();
    $pdo->prepare('INSERT INTO key_envelopes (id, key_id, artifact_id, envelope, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(key_id, artifact_id) DO UPDATE SET id = excluded.id, envelope = excluded.envelope, created_at = excluded.created_at')
        ->execute([$id, $keyId, $artifactId, $envelope, $now]);
    keyCustodyAudit($pdo, $tenant, 'envelope_store', $keyId, 'artifact=' . $artifactId);
    return ['id' => $id, 'artifact_id' => $artifactId, 'created_at' => $now];
}

function keyCustodyDisableKey(PDO $pdo, string $tenant, string $id): void
{
    $envelopes = $pdo->prepare('SELECT COUNT(*) FROM key_envelopes envelopes JOIN custody_keys keys ON keys.id = envelopes.key_id WHERE keys.id = ? AND keys.tenant_id = ?');
    $envelopes->execute([$id, $tenant]);
    if ((int)$envelopes->fetchColumn() !== 0) throw new InvalidArgumentException('move or delete every registered envelope before disabling this custody key');
    $stmt = $pdo->prepare("UPDATE custody_keys SET state = 'disabled' WHERE id = ? AND tenant_id = ? AND state IN ('active', 'rotating')");
    $stmt->execute([$id, $tenant]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('active custody key is unavailable');
    keyCustodyAudit($pdo, $tenant, 'key_disable', $id, 'management request');
}

/** @return array{id:string,name:string,authorization_ttl:int,state:string} */
function keyCustodyUpdateKeyPolicy(PDO $pdo, string $tenant, string $id, int $ttl, ?string $name = null): array
{
    $name = $name === null ? null : trim($name);
    if ($ttl < 60 || $ttl > 900 || ($name !== null && ($name === '' || mb_strlen($name) > 120))) {
        throw new InvalidArgumentException('custody key policy is invalid');
    }
    $stmt = $pdo->prepare("UPDATE custody_keys SET authorization_ttl = ?, name = COALESCE(?, name) WHERE id = ? AND tenant_id = ? AND state IN ('active', 'rotating')");
    $stmt->execute([$ttl, $name, $id, $tenant]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('active custody key is unavailable');
    $key = $pdo->prepare('SELECT id, name, authorization_ttl, state FROM custody_keys WHERE id = ? AND tenant_id = ?');
    $key->execute([$id, $tenant]); $row = $key->fetch();
    if (!is_array($row)) throw new RuntimeException('custody key policy could not be read');
    keyCustodyAudit($pdo, $tenant, 'key_policy_update', $id, 'ttl=' . $ttl . ($name === null ? '' : '; name updated'));
    return ['id' => $row['id'], 'name' => $row['name'], 'authorization_ttl' => (int)$row['authorization_ttl'], 'state' => $row['state']];
}

/** @return array{id:string,name:string,authorization_ttl:int,state:string} */
function keyCustodyRotateKey(PDO $pdo, string $tenant, string $id, string $master): array
{
    $old = $pdo->prepare("SELECT name, authorization_ttl FROM custody_keys WHERE id = ? AND tenant_id = ? AND state = 'active'");
    $old->execute([$id, $tenant]); $row = $old->fetch();
    if (!is_array($row)) throw new InvalidArgumentException('active custody key is unavailable');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE custody_keys SET state = 'rotating', rotated_at = ? WHERE id = ?")->execute([time(), $id]);
        $replacement = keyCustodyCreateKey($pdo, $tenant, $row['name'], (int)$row['authorization_ttl'], $master);
        keyCustodyAudit($pdo, $tenant, 'key_rotate', $id, 'replacement=' . $replacement['id'] . '; old key remains available until envelopes are moved and it is retired');
        $pdo->commit();
        return $replacement;
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}

/** Retiring is the final, recovery-preserving rotation step. */
function keyCustodyRetireKey(PDO $pdo, string $tenant, string $id): void
{
    $envelopes = $pdo->prepare('SELECT COUNT(*) FROM key_envelopes envelopes JOIN custody_keys keys ON keys.id = envelopes.key_id WHERE keys.id = ? AND keys.tenant_id = ?');
    $envelopes->execute([$id, $tenant]);
    if ((int)$envelopes->fetchColumn() !== 0) throw new InvalidArgumentException('move or delete every registered envelope before retiring this custody key');
    $stmt = $pdo->prepare("UPDATE custody_keys SET state = 'retired' WHERE id = ? AND tenant_id = ? AND state = 'rotating'");
    $stmt->execute([$id, $tenant]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('rotating custody key is unavailable');
    keyCustodyAudit($pdo, $tenant, 'key_retire', $id, 'completed staged rotation');
}

/** @return list<array{id:string,artifact_id:string,created_at:int}> */
function keyCustodyListEnvelopes(PDO $pdo, string $tenant, string $keyId): array
{
    $stmt = $pdo->prepare('SELECT envelopes.id, envelopes.artifact_id, envelopes.created_at FROM key_envelopes envelopes JOIN custody_keys keys ON keys.id = envelopes.key_id WHERE envelopes.key_id = ? AND keys.tenant_id = ? ORDER BY envelopes.created_at DESC');
    $stmt->execute([$keyId, $tenant]);
    return array_map(fn(array $row): array => ['id' => $row['id'], 'artifact_id' => $row['artifact_id'], 'created_at' => (int)$row['created_at']], $stmt->fetchAll());
}

function keyCustodyDeleteEnvelope(PDO $pdo, string $tenant, string $keyId, string $artifactId): void
{
    if (!preg_match('/^key_[a-f0-9]{32}$/', $keyId) || $artifactId === '' || strlen($artifactId) > 200) {
        throw new InvalidArgumentException('key envelope deletion request is invalid');
    }
    $stmt = $pdo->prepare('DELETE FROM key_envelopes WHERE key_id = ? AND artifact_id = ? AND EXISTS (SELECT 1 FROM custody_keys WHERE id = ? AND tenant_id = ?)');
    $stmt->execute([$keyId, $artifactId, $keyId, $tenant]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('key envelope is unavailable');
    keyCustodyAudit($pdo, $tenant, 'envelope_delete', $keyId, 'artifact=' . $artifactId);
}

function keyCustodySetSubscription(PDO $pdo, string $tenant, string $plan, string $state, int $quota, ?int $renewsAt, int $envelopeQuota = 1000): void
{
    if (!preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $plan) || !in_array($state, ['active', 'past_due', 'cancelled'], true) || $quota < 0 || $quota > 10000 || $envelopeQuota < 0 || $envelopeQuota > 1000000) throw new InvalidArgumentException('subscription policy is invalid');
    $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan, state, key_quota, envelope_quota, renews_at, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(tenant_id) DO UPDATE SET plan = excluded.plan, state = excluded.state, key_quota = excluded.key_quota, envelope_quota = excluded.envelope_quota, renews_at = excluded.renews_at, changed_at = excluded.changed_at')
        ->execute([$tenant, $plan, $state, $quota, $envelopeQuota, $renewsAt, time()]);
    keyCustodyAudit($pdo, $tenant, 'subscription_change', $tenant, $plan . '; ' . $state . '; key_quota=' . $quota . '; envelope_quota=' . $envelopeQuota);
}

/** Applies an entitlement from the separately authenticated commercial billing
 * control plane. It has no authority to read or unwrap custody keys. */
function keyCustodyApplySubscription(PDO $pdo, string $tenant, string $plan, string $state, int $quota, ?int $renewsAt, int $envelopeQuota = 1000): void
{
    $pdo->beginTransaction();
    try {
        keyCustodySetSubscription($pdo, $tenant, $plan, $state, $quota, $renewsAt, $envelopeQuota);
        $pdo->prepare("UPDATE subscription_requests SET state = 'applied', resolved_at = ? WHERE tenant_id = ? AND state = 'pending'")
            ->execute([time(), $tenant]);
        keyCustodyAudit($pdo, $tenant, 'subscription_apply', $tenant, 'commercial billing control plane');
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}

/** @return list<array{id:string,name:string,authorization_ttl:int,state:string,created_at:int,envelope_count:int}> */
function keyCustodyListKeys(PDO $pdo, string $tenant): array
{
    $stmt = $pdo->prepare('SELECT keys.id, keys.name, keys.authorization_ttl, keys.state, keys.created_at,
        (SELECT COUNT(*) FROM key_envelopes envelopes WHERE envelopes.key_id = keys.id) AS envelope_count
        FROM custody_keys keys WHERE keys.tenant_id = ? ORDER BY keys.created_at DESC');
    $stmt->execute([$tenant]);
    return array_map(fn(array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'authorization_ttl' => (int)$row['authorization_ttl'],
        'state' => $row['state'], 'created_at' => (int)$row['created_at'], 'envelope_count' => (int)$row['envelope_count']], $stmt->fetchAll());
}

/** @return array{plan:string,state:string,key_quota:int,envelope_quota:int,envelope_used:int,renews_at:?int} */
function keyCustodySubscription(PDO $pdo, string $tenant): array
{
    $stmt = $pdo->prepare('SELECT plan, state, key_quota, envelope_quota, renews_at FROM subscriptions WHERE tenant_id = ?'); $stmt->execute([$tenant]); $row = $stmt->fetch();
    if (!is_array($row)) throw new InvalidArgumentException('subscription is unavailable');
    $used = $pdo->prepare('SELECT COUNT(*) FROM key_envelopes envelopes JOIN custody_keys keys ON keys.id = envelopes.key_id WHERE keys.tenant_id = ?'); $used->execute([$tenant]);
    return ['plan' => $row['plan'], 'state' => $row['state'], 'key_quota' => (int)$row['key_quota'], 'envelope_quota' => (int)$row['envelope_quota'],
        'envelope_used' => (int)$used->fetchColumn(), 'renews_at' => $row['renews_at'] === null ? null : (int)$row['renews_at']];
}

/** @return array{id:string,action:string,requested_plan:?string,state:string,created_at:int} */
function keyCustodyRequestSubscriptionChange(PDO $pdo, string $tenant, string $action, ?string $plan): array
{
    if (!in_array($action, ['change_plan', 'cancel'], true)) throw new InvalidArgumentException('subscription action is invalid');
    $plan = $plan === null ? null : trim($plan);
    if (($action === 'change_plan' && ($plan === '' || !preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $plan))) || ($action === 'cancel' && $plan !== null && $plan !== '')) {
        throw new InvalidArgumentException('requested subscription plan is invalid');
    }
    $active = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE tenant_id = ? AND state IN ('active', 'past_due')"); $active->execute([$tenant]);
    if ((int)$active->fetchColumn() !== 1) throw new InvalidArgumentException('subscription is unavailable');
    $pending = $pdo->prepare("SELECT COUNT(*) FROM subscription_requests WHERE tenant_id = ? AND state = 'pending'"); $pending->execute([$tenant]);
    if ((int)$pending->fetchColumn() !== 0) throw new InvalidArgumentException('a subscription request is already pending');
    $id = 'subreq_' . bin2hex(random_bytes(12)); $now = time();
    $pdo->prepare('INSERT INTO subscription_requests (id, tenant_id, action, requested_plan, state, created_at, resolved_at) VALUES (?, ?, ?, ?, ?, ?, NULL)')
        ->execute([$id, $tenant, $action, $plan === '' ? null : $plan, 'pending', $now]);
    keyCustodyAudit($pdo, $tenant, 'subscription_request', $id, $action . ($plan ? '; plan=' . $plan : ''));
    return ['id' => $id, 'action' => $action, 'requested_plan' => $plan === '' ? null : $plan, 'state' => 'pending', 'created_at' => $now];
}

/** @return list<array{id:string,action:string,requested_plan:?string,state:string,created_at:int,resolved_at:?int}> */
function keyCustodySubscriptionRequests(PDO $pdo, string $tenant): array
{
    $stmt = $pdo->prepare('SELECT id, action, requested_plan, state, created_at, resolved_at FROM subscription_requests WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$tenant]);
    return array_map(fn(array $row): array => ['id' => $row['id'], 'action' => $row['action'], 'requested_plan' => $row['requested_plan'],
        'state' => $row['state'], 'created_at' => (int)$row['created_at'], 'resolved_at' => $row['resolved_at'] === null ? null : (int)$row['resolved_at']], $stmt->fetchAll());
}

function keyCustodyWithdrawSubscriptionRequest(PDO $pdo, string $tenant, string $id): void
{
    if (!preg_match('/^subreq_[a-f0-9]{24}$/', $id)) throw new InvalidArgumentException('subscription request id is invalid');
    $stmt = $pdo->prepare("UPDATE subscription_requests SET state = 'withdrawn', resolved_at = ? WHERE id = ? AND tenant_id = ? AND state = 'pending'");
    $stmt->execute([time(), $id, $tenant]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('pending subscription request is unavailable');
    keyCustodyAudit($pdo, $tenant, 'subscription_request_withdraw', $id, 'management request');
}

function keyCustodySubscriptionAllows(PDO $pdo, string $tenant): void
{
    $stmt = $pdo->prepare('SELECT state FROM subscriptions WHERE tenant_id = ?'); $stmt->execute([$tenant]);
    if ($stmt->fetchColumn() !== 'active') throw new InvalidArgumentException('subscription does not permit custody operations');
}

function keyCustodyAudit(PDO $pdo, string $tenant, string $action, string $target, string $detail): void
{
    $pdo->prepare('INSERT INTO custody_audit (tenant_id, at, action, target, detail) VALUES (?, ?, ?, ?, ?)')->execute([$tenant, time(), $action, $target, $detail]);
}

/** @return list<array{id:int,at:int,action:string,target:string,detail:string}> */
function keyCustodyAuditEvents(PDO $pdo, string $tenant, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare('SELECT id, at, action, target, detail FROM custody_audit WHERE tenant_id = ? ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $tenant, PDO::PARAM_STR); $stmt->bindValue(2, $limit, PDO::PARAM_INT); $stmt->execute();
    return array_map(fn(array $row): array => ['id' => (int)$row['id'], 'at' => (int)$row['at'], 'action' => $row['action'],
        'target' => $row['target'], 'detail' => $row['detail']], $stmt->fetchAll());
}

function keyCustodyReadMasterKey(array $config, string $webRoot): string
{
    $path = (string)($config['master_key_file'] ?? '');
    if ($path === '' || !str_starts_with($path, '/') || is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0 || str_starts_with(realpath($path) ?: '', rtrim(realpath($webRoot) ?: $webRoot, '/') . '/')) throw new RuntimeException('custody master key file is unavailable or unsafe');
    $key = base64_decode(trim((string)file_get_contents($path)), true);
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('custody master key must contain Base64 256-bit material');
    return $key;
}

/** Reads a distinct billing-control token from a private 0600 file. */
function keyCustodyReadBillingServiceToken(array $config, string $webRoot): string
{
    $path = (string)($config['billing_service_token_file'] ?? '');
    if ($path === '') throw new RuntimeException('commercial billing integration is not configured');
    if (!str_starts_with($path, '/') || is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0
        || str_starts_with(realpath($path) ?: '', rtrim(realpath($webRoot) ?: $webRoot, '/') . '/')) {
        throw new RuntimeException('commercial billing token file is unavailable or unsafe');
    }
    $token = trim((string)file_get_contents($path));
    if (strlen($token) < 32 || strlen($token) > 512 || preg_match('/\s/', $token)) {
        throw new RuntimeException('commercial billing token is invalid');
    }
    return $token;
}

function keyCustodyAuthenticateBillingService(array $config, string $bearer, string $webRoot): void
{
    if ($bearer === '' || !hash_equals(keyCustodyReadBillingServiceToken($config, $webRoot), $bearer)) {
        throw new InvalidArgumentException('commercial billing authorization is invalid');
    }
}
