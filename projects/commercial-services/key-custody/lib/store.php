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
    $pdo->exec('CREATE TABLE IF NOT EXISTS access_tokens (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, scope TEXT NOT NULL, token_hash TEXT NOT NULL, created_at INTEGER NOT NULL, revoked_at INTEGER)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS access_tokens_tenant_scope ON access_tokens (tenant_id, scope, revoked_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS client_nonces (token_id TEXT NOT NULL REFERENCES access_tokens(id) ON DELETE CASCADE, nonce TEXT NOT NULL, expires_at INTEGER NOT NULL, PRIMARY KEY (token_id, nonce))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS client_nonces_expiry ON client_nonces (expires_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS subscriptions (tenant_id TEXT PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE, plan TEXT NOT NULL, state TEXT NOT NULL, key_quota INTEGER NOT NULL, renews_at INTEGER, changed_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS custody_keys (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, name TEXT NOT NULL, wrapped_key BLOB NOT NULL, wrap_nonce BLOB NOT NULL, wrap_tag BLOB NOT NULL, authorization_ttl INTEGER NOT NULL, state TEXT NOT NULL, created_at INTEGER NOT NULL, rotated_at INTEGER)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custody_keys_tenant_state ON custody_keys (tenant_id, state, created_at DESC)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS key_envelopes (id TEXT PRIMARY KEY, key_id TEXT NOT NULL REFERENCES custody_keys(id) ON DELETE CASCADE, artifact_id TEXT NOT NULL, envelope BLOB NOT NULL, created_at INTEGER NOT NULL, UNIQUE(key_id, artifact_id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS custody_audit (id INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL, at INTEGER NOT NULL, action TEXT NOT NULL, target TEXT NOT NULL, detail TEXT NOT NULL)');
    return $pdo;
}

/** @return array{tenant_id:string,management_token:string,client_token:string} */
function keyCustodyCreateTenant(PDO $pdo, string $name): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('tenant name is invalid');
    $tenant = 'ten_' . bin2hex(random_bytes(12)); $now = time();
    $pdo->beginTransaction();
    try {
    $pdo->prepare('INSERT INTO tenants (id, name, active, created_at) VALUES (?, ?, 1, ?)')->execute([$tenant, $name, $now]);
    $management = keyCustodyCreateToken($pdo, $tenant, 'management');
    $client = keyCustodyCreateToken($pdo, $tenant, 'client');
    $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan, state, key_quota, renews_at, changed_at) VALUES (?, ?, ?, ?, NULL, ?)')->execute([$tenant, 'free', 'active', 2, $now]);
    keyCustodyAudit($pdo, $tenant, 'tenant_create', $tenant, 'initial free plan');
    $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
    return ['tenant_id' => $tenant, 'management_token' => $management, 'client_token' => $client];
}

function keyCustodyCreateToken(PDO $pdo, string $tenant, string $scope): string
{
    if (!in_array($scope, ['management', 'client'], true)) throw new InvalidArgumentException('token scope is invalid');
    $id = 'tok_' . bin2hex(random_bytes(10)); $secret = base64_encode(random_bytes(KEY_CUSTODY_TOKEN_BYTES));
    $pdo->prepare('INSERT INTO access_tokens (id, tenant_id, scope, token_hash, created_at, revoked_at) VALUES (?, ?, ?, ?, ?, NULL)')
        ->execute([$id, $tenant, $scope, password_hash($secret, PASSWORD_DEFAULT), time()]);
    return $id . '.' . $secret;
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
function keyCustodyAuthenticateClientRequest(PDO $pdo, string $token, string $timestamp, string $nonce): array
{
    $auth = keyCustodyAuthenticate($pdo, $token, 'client');
    [$tokenId] = explode('.', $token, 2);
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 || !preg_match('/^[A-Za-z0-9_-]{16,200}$/', $nonce)) {
        throw new InvalidArgumentException('client request timestamp or nonce is invalid');
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

function keyCustodyUnwrapKey(PDO $pdo, string $tenant, string $id, string $master): string
{
    if (strlen($master) !== 32) throw new InvalidArgumentException('custody master key is unavailable');
    $stmt = $pdo->prepare("SELECT wrapped_key, wrap_nonce, wrap_tag FROM custody_keys WHERE id = ? AND tenant_id = ? AND state = 'active'"); $stmt->execute([$id, $tenant]); $row = $stmt->fetch();
    if (!is_array($row)) throw new InvalidArgumentException('custody key is unavailable');
    $plain = openssl_decrypt($row['wrapped_key'], 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $row['wrap_nonce'], $row['wrap_tag'], $id);
    if (!is_string($plain) || strlen($plain) !== 32) throw new RuntimeException('custody key could not be unwrapped');
    keyCustodyAudit($pdo, $tenant, 'key_unwrap_authorized', $id, 'client authorization');
    return $plain;
}

/** @return array{id:string,artifact_id:string,created_at:int} */
function keyCustodyStoreEnvelope(PDO $pdo, string $tenant, string $keyId, string $artifactId, string $envelope): array
{
    if (!preg_match('/^key_[a-f0-9]{32}$/', $keyId) || $artifactId === '' || strlen($artifactId) > 200 || $envelope === '' || strlen($envelope) > 1048576) {
        throw new InvalidArgumentException('key envelope request is invalid');
    }
    $key = $pdo->prepare("SELECT COUNT(*) FROM custody_keys WHERE id = ? AND tenant_id = ? AND state = 'active'"); $key->execute([$keyId, $tenant]);
    if ((int)$key->fetchColumn() !== 1) throw new InvalidArgumentException('custody key is unavailable');
    $id = 'env_' . bin2hex(random_bytes(16)); $now = time();
    $pdo->prepare('INSERT INTO key_envelopes (id, key_id, artifact_id, envelope, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(key_id, artifact_id) DO UPDATE SET id = excluded.id, envelope = excluded.envelope, created_at = excluded.created_at')
        ->execute([$id, $keyId, $artifactId, $envelope, $now]);
    keyCustodyAudit($pdo, $tenant, 'envelope_store', $keyId, 'artifact=' . $artifactId);
    return ['id' => $id, 'artifact_id' => $artifactId, 'created_at' => $now];
}

function keyCustodyDisableKey(PDO $pdo, string $tenant, string $id): void
{
    $stmt = $pdo->prepare("UPDATE custody_keys SET state = 'disabled' WHERE id = ? AND tenant_id = ? AND state = 'active'");
    $stmt->execute([$id, $tenant]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('active custody key is unavailable');
    keyCustodyAudit($pdo, $tenant, 'key_disable', $id, 'management request');
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
        $pdo->prepare("UPDATE custody_keys SET state = 'retired' WHERE id = ?")->execute([$id]);
        keyCustodyAudit($pdo, $tenant, 'key_rotate', $id, 'replacement=' . $replacement['id']);
        $pdo->commit();
        return $replacement;
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}

/** @return list<array{id:string,artifact_id:string,created_at:int}> */
function keyCustodyListEnvelopes(PDO $pdo, string $tenant, string $keyId): array
{
    $stmt = $pdo->prepare('SELECT envelopes.id, envelopes.artifact_id, envelopes.created_at FROM key_envelopes envelopes JOIN custody_keys keys ON keys.id = envelopes.key_id WHERE envelopes.key_id = ? AND keys.tenant_id = ? ORDER BY envelopes.created_at DESC');
    $stmt->execute([$keyId, $tenant]);
    return array_map(fn(array $row): array => ['id' => $row['id'], 'artifact_id' => $row['artifact_id'], 'created_at' => (int)$row['created_at']], $stmt->fetchAll());
}

function keyCustodySetSubscription(PDO $pdo, string $tenant, string $plan, string $state, int $quota, ?int $renewsAt): void
{
    if (!preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $plan) || !in_array($state, ['active', 'past_due', 'cancelled'], true) || $quota < 0 || $quota > 10000) throw new InvalidArgumentException('subscription policy is invalid');
    $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan, state, key_quota, renews_at, changed_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(tenant_id) DO UPDATE SET plan = excluded.plan, state = excluded.state, key_quota = excluded.key_quota, renews_at = excluded.renews_at, changed_at = excluded.changed_at')
        ->execute([$tenant, $plan, $state, $quota, $renewsAt, time()]);
    keyCustodyAudit($pdo, $tenant, 'subscription_change', $tenant, $plan . '; ' . $state . '; quota=' . $quota);
}

/** @return list<array{id:string,name:string,authorization_ttl:int,state:string,created_at:int}> */
function keyCustodyListKeys(PDO $pdo, string $tenant): array
{
    $stmt = $pdo->prepare('SELECT id, name, authorization_ttl, state, created_at FROM custody_keys WHERE tenant_id = ? ORDER BY created_at DESC');
    $stmt->execute([$tenant]);
    return array_map(fn(array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'authorization_ttl' => (int)$row['authorization_ttl'], 'state' => $row['state'], 'created_at' => (int)$row['created_at']], $stmt->fetchAll());
}

/** @return array{plan:string,state:string,key_quota:int,renews_at:?int} */
function keyCustodySubscription(PDO $pdo, string $tenant): array
{
    $stmt = $pdo->prepare('SELECT plan, state, key_quota, renews_at FROM subscriptions WHERE tenant_id = ?'); $stmt->execute([$tenant]); $row = $stmt->fetch();
    if (!is_array($row)) throw new InvalidArgumentException('subscription is unavailable');
    return ['plan' => $row['plan'], 'state' => $row['state'], 'key_quota' => (int)$row['key_quota'], 'renews_at' => $row['renews_at'] === null ? null : (int)$row['renews_at']];
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

function keyCustodyReadMasterKey(array $config, string $webRoot): string
{
    $path = (string)($config['master_key_file'] ?? '');
    if ($path === '' || !str_starts_with($path, '/') || is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0 || str_starts_with(realpath($path) ?: '', rtrim(realpath($webRoot) ?: $webRoot, '/') . '/')) throw new RuntimeException('custody master key file is unavailable or unsafe');
    $key = base64_decode(trim((string)file_get_contents($path)), true);
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('custody master key must contain Base64 256-bit material');
    return $key;
}
