<?php
declare(strict_types=1);

/** Resolves a private absolute directory, rejecting aliases that could place
 * relay ciphertext or metadata beneath a web-served tree. */
function replicaRelayPrivateDirectory(string $path, ?string $webRoot = null): string
{
    if ($path === '' || !str_starts_with($path, '/') || is_link($path)) throw new RuntimeException('relay private path must be absolute and must not be a symbolic link');
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('could not create relay private directory');
    $real = realpath($path);
    if ($real === false || is_link($path) || !is_dir($real)) throw new RuntimeException('relay private directory is unavailable or unsafe');
    @chmod($real, 0700);
    $root = $webRoot === null ? false : realpath($webRoot);
    if (is_string($root) && ($real === $root || str_starts_with($real, rtrim($root, '/') . '/'))) {
        throw new RuntimeException('relay private directory must be outside the web root');
    }
    return $real;
}

/** Opens the relay's private metadata store. Ciphertext itself stays in files. */
function replicaRelayOpen(array $config, ?string $webRoot = null): PDO
{
    $database = trim((string)($config['database'] ?? ''));
    if ($database === '' || !str_starts_with($database, '/') || is_link($database)) throw new RuntimeException('relay database must be an absolute non-symlink path');
    $directory = replicaRelayPrivateDirectory(dirname($database), $webRoot);
    $database = $directory . '/' . basename($database);
    if (is_link($database)) throw new RuntimeException('relay database must not be a symbolic link');
    replicaRelayPrivateDirectory((string)($config['artifact_directory'] ?? ''), $webRoot);
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON'); $pdo->exec('PRAGMA busy_timeout = 5000'); @chmod($database, 0600);
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
    return replicaRelayAuthenticateClientDigestRequest($pdo, $bearer, $method, $path, $timestamp, $nonce, $signature, hash('sha256', $body, true));
}

/** Authenticates a signed request from a known SHA-256 digest without buffering
 * the ciphertext body. The digest is the exact final line of the signature
 * payload, Base64-encoded, matching the normal in-memory request form. */
function replicaRelayAuthenticateClientDigestRequest(PDO $pdo, string $bearer, string $method, string $path, string $timestamp, string $nonce, string $signature, string $digest): array
{
    $auth = replicaRelayAuthenticate($pdo, $bearer, 'client');
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 || !preg_match('/^[A-Za-z0-9_-]{16,200}$/', $nonce)) throw new InvalidArgumentException('relay request timestamp or nonce is invalid');
    $key = $pdo->prepare('SELECT public_key FROM access_tokens WHERE id = ? AND tenant_id = ? AND scope = ? AND revoked_at IS NULL');
    $key->execute([$auth['token_id'], $auth['tenant_id'], 'client']); $public = $key->fetchColumn();
    $decoded = base64_decode($signature, true);
    if (!is_string($public) || !is_string($decoded) || strlen($digest) !== 32 || !function_exists('sodium_crypto_sign_verify_detached') || !sodium_crypto_sign_verify_detached($decoded, replicaRelayClientDigestPayload($method, $path, $timestamp, $nonce, $digest), $public)) throw new InvalidArgumentException('relay request signature is invalid');
    try { $pdo->prepare('INSERT INTO client_nonces (token_id, nonce, expires_at) VALUES (?, ?, ?)')->execute([$auth['token_id'], $nonce, time() + 600]); }
    catch (PDOException) { throw new InvalidArgumentException('relay request nonce was already used'); }
    return $auth;
}

function replicaRelayClientPayload(string $method, string $path, string $timestamp, string $nonce, string $body): string
{
    return replicaRelayClientDigestPayload($method, $path, $timestamp, $nonce, hash('sha256', $body, true));
}

function replicaRelayClientDigestPayload(string $method, string $path, string $timestamp, string $nonce, string $digest): string
{
    if (strlen($digest) !== 32) throw new InvalidArgumentException('relay ciphertext digest is invalid');
    return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . base64_encode($digest);
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
    $root = replicaRelayPrivateDirectory((string)($config['artifact_directory'] ?? ''));
    if ($root === '' || !preg_match('/^ten_[a-f0-9]{24}$/', $tenant) || !preg_match('/^[A-Za-z0-9._:-]{1,200}$/', $artifact)) throw new InvalidArgumentException('relay artifact identity is invalid');
    return $root . '/' . $tenant . '/' . hash('sha256', $artifact) . '.sealed';
}

/** @return array{artifact_id:string,sha256:string,bytes:int,created_at:int} */
function replicaRelayStoreArtifact(PDO $pdo, array $config, string $tenant, string $artifact, string $ciphertext, string $providedHash): array
{
    $stream = fopen('php://temp', 'w+b'); if ($stream === false) throw new RuntimeException('could not prepare relay artifact');
    try { fwrite($stream, $ciphertext); rewind($stream); return replicaRelayStoreArtifactStream($pdo, $config, $tenant, $artifact, $stream, $providedHash); }
    finally { fclose($stream); }
}

/** Streams ciphertext to private storage, verifies its supplied digest, then
 * atomically makes the file visible with matching SQLite metadata.
 * @return array{artifact_id:string,sha256:string,bytes:int,created_at:int} */
function replicaRelayStoreArtifactStream(PDO $pdo, array $config, string $tenant, string $artifact, $stream, string $providedHash): array
{
    $maximum = (int)($config['maximum_artifact_bytes'] ?? 0);
    if ($maximum < 1 || !preg_match('/^[a-f0-9]{64}$/', $providedHash)) throw new InvalidArgumentException('relay artifact content or digest is invalid');
    $path = replicaRelayArtifactPath($config, $tenant, $artifact); $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('could not create private relay artifact directory');
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    $output = fopen($temporary, 'xb'); if ($output === false) throw new RuntimeException('could not prepare relay artifact storage');
    $hash = hash_init('sha256'); $bytes = 0;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1048576); if ($chunk === false) throw new RuntimeException('could not read relay ciphertext');
            if ($chunk === '') continue;
            $bytes += strlen($chunk); if ($bytes > $maximum) throw new InvalidArgumentException('relay artifact exceeds its configured size limit');
            hash_update($hash, $chunk); if (fwrite($output, $chunk) !== strlen($chunk)) throw new RuntimeException('could not write relay ciphertext');
        }
    } catch (Throwable $error) { @unlink($temporary); throw $error; }
    finally { fclose($output); }
    if ($bytes < 1 || !hash_equals($providedHash, hash_final($hash))) { @unlink($temporary); throw new InvalidArgumentException('relay artifact content or digest is invalid'); }
    if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('could not store relay artifact'); }
    @chmod($path, 0600); $now = time();
    $pdo->prepare('INSERT INTO artifacts (tenant_id, artifact_id, sha256, bytes, created_at, verified_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(tenant_id, artifact_id) DO UPDATE SET sha256=excluded.sha256, bytes=excluded.bytes, created_at=excluded.created_at, verified_at=excluded.verified_at')->execute([$tenant, $artifact, $providedHash, $bytes, $now, $now]);
    replicaRelayAudit($pdo, $tenant, 'artifact_store', $artifact, 'ciphertext hash verified');
    return ['artifact_id' => $artifact, 'sha256' => $providedHash, 'bytes' => $bytes, 'created_at' => $now];
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
