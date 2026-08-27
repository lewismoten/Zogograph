<?php
declare(strict_types=1);

/** Opens the relay's private metadata store. Ciphertext itself stays in files. */
function replicaRelayOpen(array $config): PDO
{
    $database = trim((string)($config['database'] ?? ''));
    if ($database === '') throw new RuntimeException('relay database is not configured');
    $directory = dirname($database);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('could not create relay data directory');
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tenants (id TEXT PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL, created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS access_tokens (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, scope TEXT NOT NULL, token_hash TEXT NOT NULL, public_key BLOB, created_at INTEGER NOT NULL, revoked_at INTEGER)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS access_tokens_tenant_scope ON access_tokens (tenant_id, scope, revoked_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS client_nonces (token_id TEXT NOT NULL REFERENCES access_tokens(id) ON DELETE CASCADE, nonce TEXT NOT NULL, expires_at INTEGER NOT NULL, PRIMARY KEY (token_id, nonce))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS artifacts (tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, artifact_id TEXT NOT NULL, sha256 TEXT NOT NULL, bytes INTEGER NOT NULL, created_at INTEGER NOT NULL, verified_at INTEGER NOT NULL, PRIMARY KEY (tenant_id, artifact_id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, created_at INTEGER NOT NULL, action TEXT NOT NULL, subject TEXT NOT NULL, detail TEXT NOT NULL)');
    $pdo->prepare('DELETE FROM client_nonces WHERE expires_at < ?')->execute([time()]);
    return $pdo;
}

/** @return array{tenant_id:string,scope:string,token_id:string} */
function replicaRelayAuthenticate(PDO $pdo, string $bearer, string $scope): array
{
    [$id, $secret] = array_pad(explode('.', $bearer, 2), 2, '');
    if (!preg_match('/^tok_[a-f0-9]{20}$/', $id) || $secret === '') throw new InvalidArgumentException('invalid relay credential');
    $stmt = $pdo->prepare('SELECT tokens.tenant_id, tokens.scope, tokens.token_hash, tenants.active FROM access_tokens tokens JOIN tenants ON tenants.id = tokens.tenant_id WHERE tokens.id = ? AND tokens.revoked_at IS NULL');
    $stmt->execute([$id]); $row = $stmt->fetch();
    if (!is_array($row) || $row['scope'] !== $scope || !(int)$row['active'] || !password_verify($secret, $row['token_hash'])) throw new InvalidArgumentException('relay credential is unavailable');
    return ['tenant_id' => $row['tenant_id'], 'scope' => $row['scope'], 'token_id' => $id];
}

/** @return array{tenant_id:string,scope:string,token_id:string} */
function replicaRelayAuthenticateClientRequest(PDO $pdo, string $bearer, string $method, string $path, string $timestamp, string $nonce, string $signature, string $body): array
{
    $auth = replicaRelayAuthenticate($pdo, $bearer, 'client');
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 || !preg_match('/^[A-Za-z0-9_-]{16,200}$/', $nonce)) throw new InvalidArgumentException('relay request timestamp or nonce is invalid');
    $key = $pdo->prepare('SELECT public_key FROM access_tokens WHERE id = ? AND tenant_id = ? AND scope = ? AND revoked_at IS NULL');
    $key->execute([$auth['token_id'], $auth['tenant_id'], 'client']); $public = $key->fetchColumn();
    $decoded = base64_decode($signature, true);
    if (!is_string($public) || !is_string($decoded) || !function_exists('sodium_crypto_sign_verify_detached') || !sodium_crypto_sign_verify_detached($decoded, replicaRelayClientPayload($method, $path, $timestamp, $nonce, $body), $public)) throw new InvalidArgumentException('relay request signature is invalid');
    try { $pdo->prepare('INSERT INTO client_nonces (token_id, nonce, expires_at) VALUES (?, ?, ?)')->execute([$auth['token_id'], $nonce, time() + 600]); }
    catch (PDOException) { throw new InvalidArgumentException('relay request nonce was already used'); }
    return $auth;
}

function replicaRelayClientPayload(string $method, string $path, string $timestamp, string $nonce, string $body): string
{
    return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
}

/** @return array{tenant_id:string,management_token:string,client_token:string,client_signing_private_key:string} */
function replicaRelayCreateTenant(PDO $pdo, string $name): array
{
    if (!function_exists('sodium_crypto_sign_keypair')) throw new RuntimeException('libsodium is required for relay enrollment');
    $name = trim($name); if ($name === '' || strlen($name) > 120) throw new InvalidArgumentException('tenant name must contain 1 to 120 characters');
    $tenant = 'ten_' . bin2hex(random_bytes(12)); $now = time();
    $pdo->prepare('INSERT INTO tenants (id, name, active, created_at) VALUES (?, ?, 1, ?)')->execute([$tenant, $name, $now]);
    $management = replicaRelayCreateToken($pdo, $tenant, 'management', null);
    $client = replicaRelayCreateClientCredential($pdo, $tenant);
    replicaRelayAudit($pdo, $tenant, 'tenant_create', $tenant, 'initial relay enrollment created');
    return ['tenant_id' => $tenant, 'management_token' => $management, 'client_token' => $client['token'], 'client_signing_private_key' => $client['signing_private_key']];
}

function replicaRelayCreateToken(PDO $pdo, string $tenant, string $scope, ?string $public): string
{
    $id = 'tok_' . bin2hex(random_bytes(10)); $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare('INSERT INTO access_tokens (id, tenant_id, scope, token_hash, public_key, created_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, NULL)')->execute([$id, $tenant, $scope, password_hash($secret, PASSWORD_DEFAULT), $public, time()]);
    return $id . '.' . $secret;
}

/** @return array{token:string,signing_private_key:string} */
function replicaRelayCreateClientCredential(PDO $pdo, string $tenant): array
{
    $pair = sodium_crypto_sign_keypair(); $public = sodium_crypto_sign_publickey($pair); $secret = sodium_crypto_sign_secretkey($pair);
    return ['token' => replicaRelayCreateToken($pdo, $tenant, 'client', $public), 'signing_private_key' => base64_encode($secret)];
}

function replicaRelayArtifactPath(array $config, string $tenant, string $artifact): string
{
    $root = rtrim((string)($config['artifact_directory'] ?? ''), '/');
    if ($root === '' || !preg_match('/^ten_[a-f0-9]{24}$/', $tenant) || !preg_match('/^[A-Za-z0-9._:-]{1,200}$/', $artifact)) throw new InvalidArgumentException('relay artifact identity is invalid');
    return $root . '/' . $tenant . '/' . hash('sha256', $artifact) . '.sealed';
}

/** @return array{artifact_id:string,sha256:string,bytes:int,created_at:int} */
function replicaRelayStoreArtifact(PDO $pdo, array $config, string $tenant, string $artifact, string $ciphertext, string $providedHash): array
{
    $maximum = (int)($config['maximum_artifact_bytes'] ?? 0);
    if ($maximum < 1 || strlen($ciphertext) < 1 || strlen($ciphertext) > $maximum || !preg_match('/^[a-f0-9]{64}$/', $providedHash) || !hash_equals($providedHash, hash('sha256', $ciphertext))) throw new InvalidArgumentException('relay artifact content or digest is invalid');
    $path = replicaRelayArtifactPath($config, $tenant, $artifact); $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('could not create private relay artifact directory');
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $ciphertext, LOCK_EX) !== strlen($ciphertext) || !rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('could not store relay artifact'); }
    @chmod($path, 0600); $now = time();
    $pdo->prepare('INSERT INTO artifacts (tenant_id, artifact_id, sha256, bytes, created_at, verified_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(tenant_id, artifact_id) DO UPDATE SET sha256=excluded.sha256, bytes=excluded.bytes, created_at=excluded.created_at, verified_at=excluded.verified_at')->execute([$tenant, $artifact, $providedHash, strlen($ciphertext), $now, $now]);
    replicaRelayAudit($pdo, $tenant, 'artifact_store', $artifact, 'ciphertext hash verified');
    return ['artifact_id' => $artifact, 'sha256' => $providedHash, 'bytes' => strlen($ciphertext), 'created_at' => $now];
}

/** @return list<array{artifact_id:string,sha256:string,bytes:int,created_at:int,verified_at:int}> */
function replicaRelayListArtifacts(PDO $pdo, string $tenant): array
{
    $stmt = $pdo->prepare('SELECT artifact_id, sha256, bytes, created_at, verified_at FROM artifacts WHERE tenant_id = ? ORDER BY created_at DESC, artifact_id'); $stmt->execute([$tenant]);
    return array_map(fn(array $row): array => ['artifact_id' => $row['artifact_id'], 'sha256' => $row['sha256'], 'bytes' => (int)$row['bytes'], 'created_at' => (int)$row['created_at'], 'verified_at' => (int)$row['verified_at']], $stmt->fetchAll());
}

function replicaRelayDeleteArtifact(PDO $pdo, array $config, string $tenant, string $artifact): void
{
    $stmt = $pdo->prepare('SELECT sha256 FROM artifacts WHERE tenant_id = ? AND artifact_id = ?'); $stmt->execute([$tenant, $artifact]);
    if ($stmt->fetchColumn() === false) throw new InvalidArgumentException('relay artifact is unavailable');
    $path = replicaRelayArtifactPath($config, $tenant, $artifact); if (is_link($path) || !is_file($path) || !unlink($path)) throw new RuntimeException('could not delete relay ciphertext');
    $pdo->prepare('DELETE FROM artifacts WHERE tenant_id = ? AND artifact_id = ?')->execute([$tenant, $artifact]); replicaRelayAudit($pdo, $tenant, 'artifact_delete', $artifact, 'management deletion');
}

function replicaRelayAudit(PDO $pdo, string $tenant, string $action, string $subject, string $detail): void
{
    $pdo->prepare('INSERT INTO audit_events (tenant_id, created_at, action, subject, detail) VALUES (?, ?, ?, ?, ?)')->execute([$tenant, time(), $action, $subject, $detail]);
}
