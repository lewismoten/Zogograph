<?php
declare(strict_types=1);

/*
 * Sealed-copy primitives.
 *
 * This does not turn PDO SQLite into an encrypted live database. It creates a
 * separately verified encrypted artifact from a consistent source copy, which
 * is the portable building block for encrypted exports and fleet replicas.
 */

const ENCRYPTION_MAGIC = "HOSTSCOPE-SEAL-1\n";
const ENCRYPTION_CHUNK_BYTES = 1048576;

function encryptionAvailable(): bool
{
    return extension_loaded('openssl')
        && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)
        && openssl_cipher_iv_length('aes-256-gcm') === 12;
}

/** Creates a 256-bit local KEK outside the web root. */
function encryptionCreateLocalKey(string $path, string $webRoot): void
{
    encryptionKeyPath($path, $webRoot, false);
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('the local key directory is not writable');
    }
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException('refusing to replace an existing local key');
    }
    try {
        encryptionWriteAll($handle, base64_encode(random_bytes(32)) . "\n");
    } finally {
        fclose($handle);
    }
    @chmod($path, 0600);
    encryptionKeyPath($path, $webRoot, true);
}

/** Reads a strictly permissioned, non-web-readable 256-bit local KEK. */
function encryptionReadLocalKey(string $path, string $webRoot): string
{
    $real = encryptionKeyPath($path, $webRoot, true);
    $encoded = @file_get_contents($real);
    $key = is_string($encoded) ? base64_decode(trim($encoded), true) : false;
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('the local key is not a Base64-encoded 256-bit key');
    }
    return $key;
}

/** @return string canonical key path */
function encryptionKeyPath(string $path, string $webRoot, bool $mustExist): string
{
    if ($path === '' || $path[0] !== '/') {
        throw new InvalidArgumentException('a local key path must be absolute');
    }
    if (is_link($path)) {
        throw new RuntimeException('a local key file must not be a symbolic link');
    }
    if ($mustExist && (!is_file($path) || !is_readable($path))) {
        throw new RuntimeException('the local key file is unavailable');
    }
    $real = $mustExist ? realpath($path) : $path;
    if (!is_string($real)) {
        throw new RuntimeException('could not resolve the local key path');
    }
    $root = realpath($webRoot);
    if (is_string($root) && ($real === $root || str_starts_with($real, rtrim($root, '/') . '/'))) {
        throw new RuntimeException('a local key must be outside the web root');
    }
    if ($mustExist) {
        $stat = stat($real);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)) {
            throw new RuntimeException('the local key is not a regular file');
        }
        if (($stat['mode'] & 0077) !== 0) {
            throw new RuntimeException('the local key must not be readable by group or others');
        }
    }
    return $real;
}

/**
 * Seals a file with a fresh data key wrapped by `$kek`.
 *
 * @return array{artifact_id: string, plaintext_bytes: int, ciphertext_bytes: int, sha256: string}
 */
function encryptionSealFile(string $source, string $destination, string $kek, string $artifactId): array
{
    encryptionRequire($kek, $artifactId);
    if (!is_file($source) || !is_readable($source)) {
        throw new RuntimeException('the source file is unavailable');
    }
    if (file_exists($destination)) {
        throw new RuntimeException('refusing to replace an existing sealed artifact');
    }
    $in = fopen($source, 'rb');
    $out = @fopen($destination, 'xb');
    if ($in === false || $out === false) {
        if (is_resource($in)) fclose($in);
        if (is_resource($out)) fclose($out);
        throw new RuntimeException('could not open the sealed-artifact files');
    }
    $plain = 0;
    try {
        $dek = random_bytes(32);
        $wrapNonce = random_bytes(12);
        $wrapped = openssl_encrypt($dek, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $wrapNonce, $wrapTag, $artifactId, 16);
        if (!is_string($wrapped) || !is_string($wrapTag)) {
            throw new RuntimeException('could not wrap the data key');
        }
        $header = json_encode([
            'version' => 1, 'cipher' => 'aes-256-gcm', 'chunk_bytes' => ENCRYPTION_CHUNK_BYTES,
            'artifact_id' => $artifactId, 'nonce_prefix' => base64_encode(random_bytes(4)),
            'wrapped_key' => base64_encode($wrapped), 'wrap_nonce' => base64_encode($wrapNonce),
            'wrap_tag' => base64_encode($wrapTag),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($header) || strlen($header) > 65535) {
            throw new RuntimeException('could not create the sealed-artifact header');
        }
        $prefix = base64_decode((string)json_decode($header, true)['nonce_prefix'], true);
        if (!is_string($prefix) || strlen($prefix) !== 4) {
            throw new RuntimeException('could not write the sealed-artifact header');
        }
        encryptionWriteAll($out, ENCRYPTION_MAGIC . pack('N', strlen($header)) . $header);
        $aadPrefix = hash('sha256', $header, true);
        $index = 0;
        while (!feof($in)) {
            $chunk = fread($in, ENCRYPTION_CHUNK_BYTES);
            if ($chunk === false) throw new RuntimeException('could not read the source file');
            if ($chunk === '') continue;
            $ciphertext = openssl_encrypt($chunk, 'aes-256-gcm', $dek, OPENSSL_RAW_DATA,
                encryptionChunkNonce($prefix, $index), $tag, $aadPrefix . encryptionChunkIndex($index), 16);
            if (!is_string($ciphertext) || !is_string($tag)) {
                throw new RuntimeException('could not write encrypted data');
            }
            encryptionWriteAll($out, pack('N', strlen($ciphertext)) . $ciphertext . $tag);
            $plain += strlen($chunk);
            $index++;
        }
        encryptionWriteAll($out, pack('N', 0));
    } catch (Throwable $error) {
        fclose($in);
        fclose($out);
        @unlink($destination);
        throw $error;
    }
    fclose($in);
    fclose($out);
    @chmod($destination, 0600);
    return ['artifact_id' => $artifactId, 'plaintext_bytes' => $plain,
        'ciphertext_bytes' => (int)filesize($destination), 'sha256' => hash_file('sha256', $destination) ?: ''];
}

/**
 * Makes a WAL-safe SQLite snapshot, seals it, then removes the short-lived
 * plaintext snapshot. The caller must keep the destination directory private.
 *
 * @return array{artifact_id: string, plaintext_bytes: int, ciphertext_bytes: int, sha256: string}
 */
function encryptionSealSqliteSnapshot(PDO $pdo, string $destination, string $kek, string $artifactId): array
{
    $directory = dirname($destination);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('the sealed-artifact directory is not writable');
    }
    $snapshot = $destination . '.snapshot-' . bin2hex(random_bytes(8)) . '.db';
    try {
        /* VACUUM INTO includes committed WAL pages; copying the main file does not. */
        $pdo->exec('VACUUM INTO ' . $pdo->quote($snapshot));
        @chmod($snapshot, 0600);
        return encryptionSealFile($snapshot, $destination, $kek, $artifactId);
    } finally {
        @unlink($snapshot);
    }
}

/**
 * Produces one verified sealed snapshot for each explicitly selected local
 * SQLite store. This is intentionally separate from normal collection.
 *
 * @return list<array{store: string, artifact_id: string, plaintext_bytes: int, ciphertext_bytes: int, sha256: string, local_path: string}>
 */
function encryptionSealConfiguredCopies(array $config, PDO $account, string $webRoot): array
{
    $settings = (array)($config['encryption'] ?? []);
    if (($settings['mode'] ?? 'none') === 'none') return [];
    if (($settings['mode'] ?? '') === 'operator_managed_volume') return [];
    if (($settings['mode'] ?? '') !== 'sealed_copies_local') {
        throw new RuntimeException('the selected encryption mode is unavailable in this HostScope release');
    }
    $key = encryptionReadLocalKey((string)($settings['local_key_file'] ?? ''), $webRoot);
    $directory = (string)($settings['artifact_directory'] ?? '');
    if ($directory === '') $directory = dirname((string)$config['database']) . '/sealed';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('could not create the sealed-artifact directory');
    }
    @chmod($directory, 0700);
    $stores = encryptionSelectedStores($settings);
    if ($stores === []) throw new RuntimeException('select at least one database for sealed copies');

    $base = dirname((string)$config['database']);
    $paths = [
        'config' => $webRoot . '/lib/config.php',
        'setup' => $base . '/setup.db', 'account' => (string)$config['database'],
        'host' => (string)($config['host']['database'] ?? '') ?: $base . '/host.db',
        'ai' => (string)($config['ai']['database'] ?? '') ?: $base . '/ai.db',
    ];
    $out = [];
    foreach ($stores as $store) {
        if (!is_file($paths[$store])) continue;
        $stamp = gmdate('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(4));
        $artifact = $store . ':' . $stamp;
        $sealed = $directory . '/' . $store . '.' . $stamp . '.sealed';
        if ($store === 'config') {
            $out[] = ['store' => $store, 'local_path' => $sealed]
                + encryptionSealFile($paths[$store], $sealed, $key, $artifact);
            continue;
        }
        $pdo = $store === 'account' ? $account : new PDO('sqlite:' . $paths[$store], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $out[] = ['store' => $store, 'local_path' => $sealed]
            + encryptionSealSqliteSnapshot($pdo, $sealed, $key, $artifact);
    }
    return $out;
}

/** Rejects an unusable opt-in policy before setup records it. */
function encryptionValidateSettings(array $config, string $webRoot): void
{
    $settings = (array)($config['encryption'] ?? []);
    $mode = (string)($settings['mode'] ?? 'none');
    if ($mode === 'none') return;
    $stores = encryptionSelectedStores($settings);
    if ($stores === []) throw new InvalidArgumentException('select at least one database or configuration artifact to protect');
    if ($mode === 'operator_managed_volume') return;
    if ($mode !== 'sealed_copies_local') {
        throw new InvalidArgumentException('the selected encryption mode is unavailable in this HostScope release');
    }
    if (($settings['key_custody'] ?? 'local_private_file') !== 'local_private_file') {
        throw new InvalidArgumentException('the selected key-custody method is unavailable in this HostScope release');
    }
    encryptionReadLocalKey((string)($settings['local_key_file'] ?? ''), $webRoot);
}

/** @return list<string> */
function encryptionSelectedStores(array $settings): array
{
    return array_values(array_unique(array_filter(array_map('strval', (array)($settings['stores'] ?? [])),
        fn(string $store): bool => in_array($store, ['config', 'setup', 'account', 'host', 'ai'], true))));
}

/** Persists ciphertext-only evidence for local copies and later fleet replicas. */
function encryptionRecordArtifacts(PDO $pdo, array $artifacts): void
{
    $insert = $pdo->prepare('INSERT INTO encryption_artifacts
        (artifact_id, store, created_at, local_path, ciphertext_bytes, ciphertext_sha256, state)
        VALUES (?, ?, ?, ?, ?, ?, \'verified\')');
    foreach ($artifacts as $artifact) {
        $insert->execute([
            (string)$artifact['artifact_id'], (string)$artifact['store'], time(), (string)$artifact['local_path'],
            (int)$artifact['ciphertext_bytes'], (string)$artifact['sha256'],
        ]);
    }
}

/** Decrypts a sealed artifact into a new destination file after authenticating every chunk. */
function encryptionOpenFile(string $source, string $destination, string $kek): void
{
    if (!encryptionAvailable() || strlen($kek) !== 32) throw new RuntimeException('encryption support or key is unavailable');
    if (!is_file($source) || !is_readable($source) || file_exists($destination)) {
        throw new RuntimeException('the encrypted source or output destination is unavailable');
    }
    $in = fopen($source, 'rb');
    $out = @fopen($destination, 'xb');
    if ($in === false || $out === false) {
        if (is_resource($in)) fclose($in);
        if (is_resource($out)) fclose($out);
        throw new RuntimeException('could not open the sealed-artifact files');
    }
    try {
        if (encryptionReadExact($in, strlen(ENCRYPTION_MAGIC)) !== ENCRYPTION_MAGIC) {
            throw new RuntimeException('the artifact is not a HostScope sealed copy');
        }
        $headerBytes = unpack('Nsize', encryptionReadExact($in, 4))['size'];
        if ($headerBytes < 2 || $headerBytes > 65535) throw new RuntimeException('the artifact header is invalid');
        $headerText = encryptionReadExact($in, $headerBytes);
        $header = json_decode($headerText, true);
        if (!is_array($header) || ($header['version'] ?? null) !== 1 || ($header['cipher'] ?? null) !== 'aes-256-gcm'
            || ($header['chunk_bytes'] ?? null) !== ENCRYPTION_CHUNK_BYTES || !is_string($header['artifact_id'] ?? null)) {
            throw new RuntimeException('the artifact header is unsupported');
        }
        $prefix = base64_decode((string)($header['nonce_prefix'] ?? ''), true);
        $wrapped = base64_decode((string)($header['wrapped_key'] ?? ''), true);
        $wrapNonce = base64_decode((string)($header['wrap_nonce'] ?? ''), true);
        $wrapTag = base64_decode((string)($header['wrap_tag'] ?? ''), true);
        if (!is_string($prefix) || strlen($prefix) !== 4 || !is_string($wrapped) || !is_string($wrapNonce)
            || strlen($wrapNonce) !== 12 || !is_string($wrapTag) || strlen($wrapTag) !== 16) {
            throw new RuntimeException('the wrapped artifact key is invalid');
        }
        $dek = openssl_decrypt($wrapped, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $wrapNonce, $wrapTag,
            (string)$header['artifact_id']);
        if (!is_string($dek) || strlen($dek) !== 32) throw new RuntimeException('the artifact key cannot be opened');
        $aadPrefix = hash('sha256', $headerText, true);
        for ($index = 0;; $index++) {
            $bytes = unpack('Nsize', encryptionReadExact($in, 4))['size'];
            if ($bytes === 0) break;
            if ($bytes > ENCRYPTION_CHUNK_BYTES) throw new RuntimeException('an encrypted chunk is too large');
            $ciphertext = encryptionReadExact($in, $bytes);
            $tag = encryptionReadExact($in, 16);
            $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $dek, OPENSSL_RAW_DATA,
                encryptionChunkNonce($prefix, $index), $tag, $aadPrefix . encryptionChunkIndex($index));
            if (!is_string($plain)) {
                throw new RuntimeException('the artifact data failed authentication');
            }
            encryptionWriteAll($out, $plain);
        }
        if (fgetc($in) !== false) throw new RuntimeException('the artifact has trailing data');
    } catch (Throwable $error) {
        fclose($in);
        fclose($out);
        @unlink($destination);
        throw $error;
    }
    fclose($in);
    fclose($out);
    @chmod($destination, 0600);
}

/**
 * Re-encrypts a sealed artifact under a new local key without replacing either
 * input. The temporary plaintext exists only for the duration of this command.
 *
 * @return array{artifact_id: string, plaintext_bytes: int, ciphertext_bytes: int, sha256: string}
 */
function encryptionRotateSealedFile(
    string $source,
    string $destination,
    string $oldKey,
    string $newKey,
    string $artifactId
): array {
    $temporary = $destination . '.rotate-' . bin2hex(random_bytes(8));
    try {
        encryptionOpenFile($source, $temporary, $oldKey);
        return encryptionSealFile($temporary, $destination, $newKey, $artifactId);
    } finally {
        @unlink($temporary);
    }
}

function encryptionRequire(string $kek, string $artifactId): void
{
    if (!encryptionAvailable()) throw new RuntimeException('OpenSSL AES-256-GCM support is required for sealed copies');
    if (strlen($kek) !== 32) throw new InvalidArgumentException('a sealed copy needs a 256-bit key-encryption key');
    if ($artifactId === '' || strlen($artifactId) > 200) throw new InvalidArgumentException('an artifact needs a bounded identifier');
}

function encryptionChunkNonce(string $prefix, int $index): string
{
    if ($index < 0) throw new RuntimeException('too many encrypted chunks');
    return $prefix . encryptionChunkIndex($index);
}

function encryptionChunkIndex(int $index): string
{
    return pack('N2', intdiv($index, 4294967296), $index % 4294967296);
}

/** @param resource $stream */
function encryptionReadExact($stream, int $bytes): string
{
    $out = '';
    while (strlen($out) < $bytes && !feof($stream)) {
        $part = fread($stream, $bytes - strlen($out));
        if ($part === false) break;
        $out .= $part;
    }
    if (strlen($out) !== $bytes) throw new RuntimeException('the sealed artifact is truncated');
    return $out;
}

/** @param resource $stream */
function encryptionWriteAll($stream, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($stream, substr($data, $offset));
        if (!is_int($written) || $written <= 0) throw new RuntimeException('could not write the sealed artifact');
        $offset += $written;
    }
}
