<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/encryption.php';

function encryptionExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

if (!encryptionAvailable()) {
    echo "encryption checks skipped: OpenSSL AES-256-GCM is unavailable\n";
    exit(0);
}

$root = sys_get_temp_dir() . '/hostscope-encryption-' . bin2hex(random_bytes(4));
$web = $root . '/web';
$keys = $root . '/keys';
mkdir($web, 0700, true);
mkdir($keys, 0700, true);
$keyPath = $keys . '/primary.key';
$source = $root . '/source.db';
$sealed = $root . '/source.sealed';
$opened = $root . '/opened.db';

try {
    encryptionCreateLocalKey($keyPath, $web);
    $key = encryptionReadLocalKey($keyPath, $web);
    encryptionExpect(strlen($key) === 32, 'local key did not contain 256 bits');
    encryptionExpect((fileperms($keyPath) & 0077) === 0, 'local key permissions are too broad');
    encryptionValidateSettings(['encryption' => ['mode' => 'sealed_copies_local',
        'local_key_file' => $keyPath, 'stores' => ['account']]], $web);
    try {
        encryptionValidateSettings(['encryption' => ['mode' => 'sealed_copies_local',
            'local_key_file' => '', 'stores' => []]], $web);
        throw new RuntimeException('invalid encryption setup policy was accepted');
    } catch (InvalidArgumentException|RuntimeException $error) {
        encryptionExpect($error->getMessage() !== 'invalid encryption setup policy was accepted', $error->getMessage());
    }
    file_put_contents($source, str_repeat("private sqlite record\n", 100000));
    $sealedInfo = encryptionSealFile($source, $sealed, $key, 'stats:1');
    encryptionExpect($sealedInfo['plaintext_bytes'] === filesize($source), 'sealed byte count is wrong');
    encryptionExpect(!str_contains((string)file_get_contents($sealed), 'private sqlite record'), 'sealed copy contains plaintext');
    encryptionOpenFile($sealed, $opened, $key);
    encryptionExpect(hash_file('sha256', $source) === hash_file('sha256', $opened), 'opened copy differs from source');
    $replacementKeyPath = $keys . '/replacement.key';
    encryptionCreateLocalKey($replacementKeyPath, $web);
    $replacement = encryptionReadLocalKey($replacementKeyPath, $web);
    $rotated = $root . '/rotated.sealed';
    encryptionRotateSealedFile($sealed, $rotated, $key, $replacement, 'stats:2');
    encryptionOpenFile($rotated, $root . '/rotated.db', $replacement);
    encryptionExpect(hash_file('sha256', $source) === hash_file('sha256', $root . '/rotated.db'),
        'rotated sealed copy differs from source');

    if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $live = new PDO('sqlite:' . $root . '/live.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $live->exec('CREATE TABLE evidence (value TEXT NOT NULL)');
        $live->exec("INSERT INTO evidence VALUES ('committed WAL value')");
        $snapshot = $root . '/live.sealed';
        encryptionSealSqliteSnapshot($live, $snapshot, $key, 'stats:sqlite-snapshot');
        encryptionOpenFile($snapshot, $root . '/live-opened.db', $key);
        $restored = new PDO('sqlite:' . $root . '/live-opened.db');
        encryptionExpect($restored->query('SELECT value FROM evidence')->fetchColumn() === 'committed WAL value',
            'sealed SQLite snapshot missed committed data');

        $config = ['database' => $root . '/live.db', 'encryption' => [
            'mode' => 'sealed_copies_local', 'local_key_file' => $keyPath,
            'artifact_directory' => $root . '/sealed', 'stores' => ['account'],
        ]];
        $copies = encryptionSealConfiguredCopies($config, $live, $web);
        encryptionExpect(count($copies) === 1 && $copies[0]['store'] === 'account',
            'configured sealed copy was not made');
        $live->exec('CREATE TABLE encryption_artifacts (artifact_id TEXT PRIMARY KEY, store TEXT, created_at INTEGER, local_path TEXT, ciphertext_bytes INTEGER, ciphertext_sha256 TEXT, state TEXT)');
        encryptionRecordArtifacts($live, $copies);
        encryptionExpect((int)$live->query('SELECT COUNT(*) FROM encryption_artifacts')->fetchColumn() === 1,
            'sealed artifact was not recorded for replication');
    }

    $tampered = $root . '/tampered.sealed';
    copy($sealed, $tampered);
    $handle = fopen($tampered, 'r+b');
    fseek($handle, -20, SEEK_END);
    fwrite($handle, "x");
    fclose($handle);
    try {
        encryptionOpenFile($tampered, $root . '/tampered.db', $key);
        throw new RuntimeException('tampered artifact was accepted');
    } catch (RuntimeException $error) {
        encryptionExpect($error->getMessage() !== 'tampered artifact was accepted', $error->getMessage());
    }

    try {
        encryptionReadLocalKey($keyPath, $keys);
        throw new RuntimeException('web-root key was accepted');
    } catch (RuntimeException $error) {
        encryptionExpect($error->getMessage() !== 'web-root key was accepted', $error->getMessage());
    }
    echo "encryption checks passed\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $path) {
        if (is_dir($path)) foreach (glob($path . '/*') ?: [] as $child) @unlink($child);
        @is_dir($path) ? @rmdir($path) : @unlink($path);
    }
    @rmdir($root);
}
