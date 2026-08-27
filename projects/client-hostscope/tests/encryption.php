<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/encryption.php';
require_once __DIR__ . '/../lib/configure.php';

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
mkdir($web . '/lib', 0700, true);
mkdir($keys, 0700, true);
$keyPath = $keys . '/primary.key';
$source = $root . '/source.db';
$sealed = $root . '/source.sealed';
$opened = $root . '/opened.db';

try {
    $api = file_get_contents(__DIR__ . '/../api.php');
    $dashboard = file_get_contents(__DIR__ . '/../index.js');
    $page = file_get_contents(__DIR__ . '/../index.html');
    $rotation = file_get_contents(__DIR__ . '/../scripts/rotate-sealed-copies.php');
    encryptionExpect($api !== false && str_contains($api, "'encryption-status' => 'sys-encryption'"),
        'encryption status is not protected by its dashboard page access rule');
    encryptionExpect($dashboard !== false && str_contains($dashboard, 'function renderEncryptionStatus')
        && str_contains($dashboard, 'Verified encrypted copies protect backup artifacts')
        && str_contains($dashboard, 'Optional remote key custody and ciphertext replica storage are not selectable yet'),
        'dashboard does not explain encrypted-copy limitations');
    encryptionExpect($page !== false && str_contains($page, 'data-view="sys-encryption"')
        && str_contains($page, 'id="encryptionBody"'), 'dashboard protection view is unavailable');
    encryptionExpect($rotation !== false && str_contains($rotation, 'encryptionRotateConfiguredArtifacts')
        && str_contains($rotation, 'saveConfiguredSettings') && str_contains($rotation, '--confirm'),
        'configured sealed-copy rotation does not preserve recovery evidence before switching keys');

    $sample = require __DIR__ . '/../lib/config.sample.php';
    $fields = array_column(configFields($sample), null, 'key');
    encryptionExpect(isset($fields['encryption_mode']['options']['operator_managed_volume']),
        'operator-managed volume option is missing from setup');
    encryptionExpect(fieldApplies($fields['encryption_key_file'], ['encryption_mode' => 'sealed_copies_local'])
        && !fieldApplies($fields['encryption_key_file'], ['encryption_mode' => 'none']),
        'local key field does not follow the selected encryption mode');
    encryptionExpect(fieldApplies($fields['encryption_stores'], ['encryption_mode' => 'operator_managed_volume']),
        'store choice is missing for operator-managed volume protection');

    encryptionCreateLocalKey($keyPath, $web);
    $key = encryptionReadLocalKey($keyPath, $web);
    encryptionExpect(strlen($key) === 32, 'local key did not contain 256 bits');
    encryptionExpect((fileperms($keyPath) & 0077) === 0, 'local key permissions are too broad');
    encryptionValidateSettings(['encryption' => ['mode' => 'sealed_copies_local',
        'local_key_file' => $keyPath, 'stores' => ['account']]], $web);
    encryptionValidateSettings(['encryption' => ['mode' => 'operator_managed_volume',
        'stores' => ['setup']]], $web);
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
    $secondSealed = $root . '/source-second.sealed';
    encryptionSealFile($source, $secondSealed, $key, 'stats:1');
    $sealedHeader = function (string $path): array {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('could not read sealed artifact header');
        try {
            encryptionExpect(fread($handle, strlen(ENCRYPTION_MAGIC)) === ENCRYPTION_MAGIC, 'sealed artifact magic is invalid');
            $size = unpack('Nsize', (string)fread($handle, 4))['size'];
            return json_decode((string)fread($handle, $size), true, 64, JSON_THROW_ON_ERROR);
        } finally { fclose($handle); }
    };
    $firstHeader = $sealedHeader($sealed); $secondHeader = $sealedHeader($secondSealed);
    $firstDek = openssl_decrypt(base64_decode($firstHeader['wrapped_key'], true), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        base64_decode($firstHeader['wrap_nonce'], true), base64_decode($firstHeader['wrap_tag'], true), 'stats:1');
    $secondDek = openssl_decrypt(base64_decode($secondHeader['wrapped_key'], true), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        base64_decode($secondHeader['wrap_nonce'], true), base64_decode($secondHeader['wrap_tag'], true), 'stats:1');
    encryptionExpect(is_string($firstDek) && is_string($secondDek) && strlen($firstDek) === 32 && !hash_equals($firstDek, $secondDek),
        'sealed artifacts unexpectedly reused a data-encryption key');
    encryptionOpenFile($sealed, $opened, $key);
    encryptionExpect(hash_file('sha256', $source) === hash_file('sha256', $opened), 'opened copy differs from source');
    encryptionVerifySealedArtifact($sealed, $key, (int)filesize($source));
    $replacementKeyPath = $keys . '/replacement.key';
    encryptionCreateLocalKey($replacementKeyPath, $web);
    $replacement = encryptionReadLocalKey($replacementKeyPath, $web);
    $rotated = $root . '/rotated.sealed';
    encryptionRotateSealedFile($sealed, $rotated, $key, $replacement, 'stats:2');
    encryptionOpenFile($rotated, $root . '/rotated.db', $replacement);
    encryptionExpect(hash_file('sha256', $source) === hash_file('sha256', $root . '/rotated.db'),
        'rotated sealed copy differs from source');

    if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        file_put_contents($web . '/lib/config.php', "<?php return ['private' => true];\n");
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
            'artifact_directory' => $root . '/sealed', 'stores' => ['config', 'account'],
        ]];
        $copies = encryptionSealConfiguredCopies($config, $live, $web);
        encryptionExpect(count($copies) === 2 && $copies[0]['store'] === 'config'
            && $copies[1]['store'] === 'account', 'configured sealed copies were not made');
        encryptionOpenFile((string)$copies[0]['local_path'], $root . '/restored-config.php', $key);
        encryptionExpect(str_contains((string)file_get_contents($root . '/restored-config.php'), "'private' => true"),
            'sealed configuration copy differs from source');
        $live->exec('CREATE TABLE encryption_artifacts (artifact_id TEXT PRIMARY KEY, store TEXT, created_at INTEGER, last_verified_at INTEGER, local_path TEXT, ciphertext_bytes INTEGER, ciphertext_sha256 TEXT, state TEXT)');
        encryptionRecordArtifacts($live, $copies);
        encryptionExpect((int)$live->query('SELECT COUNT(*) FROM encryption_artifacts')->fetchColumn() === 2,
            'sealed artifacts were not recorded for replication');
        $reverified = encryptionVerifyConfiguredCopies($config, $live, $web);
        encryptionExpect($reverified['verified'] === 2 && $reverified['failed'] === 0,
            'retained encrypted artifacts were not re-verified');
        $status = encryptionStatus($live, $config, $web);
        encryptionExpect($status['ready'] && count($status['artifacts']) === 2, 'sealed-copy status did not report verified coverage');
        encryptionExpect(is_array($status['storage_estimate']) && $status['storage_estimate']['required_bytes'] > $status['storage_estimate']['source_bytes'],
            'sealed-copy status did not estimate next-run storage needs');
        $optional = $config; $optional['encryption']['stores'] = ['account', 'host', 'ai'];
        $optionalStatus = encryptionStatus($live, $optional, $web);
        encryptionExpect($optionalStatus['ready'] && $optionalStatus['waiting_stores'] === ['host', 'ai'],
            'missing optional databases were treated as failed encryption coverage');
        $stale = $config; $stale['encryption']['backup_schedule'] = 'daily';
        $live->prepare("UPDATE encryption_artifacts SET created_at = ? WHERE store = 'account'")->execute([time() - 3 * 86400]);
        $staleStatus = encryptionStatus($live, $stale, $web);
        encryptionExpect(!$staleStatus['ready'] && str_contains(implode(' ', $staleStatus['warnings']), 'older than the configured schedule'),
            'stale encrypted copy was reported as current recovery evidence');
        $live->prepare("UPDATE encryption_artifacts SET created_at = ? WHERE store = 'account'")->execute([time()]);
        $volume = encryptionStatus($live, ['encryption' => ['mode' => 'operator_managed_volume', 'stores' => ['account']]], $web);
        encryptionExpect($volume['ready'] && $volume['warnings'] !== [], 'volume-protection status did not explain its verification limit');
        $oldPath = $root . '/sealed/account.old.sealed';
        copy((string)$copies[1]['local_path'], $oldPath);
        $oldHash = hash_file('sha256', $oldPath);
        $live->prepare("INSERT INTO encryption_artifacts (artifact_id, store, created_at, last_verified_at, local_path, ciphertext_bytes, ciphertext_sha256, state)
            VALUES (?, 'account', ?, ?, ?, ?, ?, 'verified')")
            ->execute(['account:old', time() - 31 * 86400, time() - 31 * 86400, $oldPath, filesize($oldPath), $oldHash]);
        $retentionConfig = $config;
        $retentionConfig['encryption']['retention_days'] = 30;
        $pruned = encryptionPruneConfiguredCopies($retentionConfig, $live);
        encryptionExpect($pruned['removed'] === 1 && !is_file($oldPath), 'expired verified sealed artifact was not pruned');
        encryptionExpect(is_file((string)$copies[1]['local_path']), 'newest verified sealed artifact was pruned');
        $afterPrune = encryptionStatus($live, $retentionConfig, $web);
        $accountEvidence = array_values(array_filter($afterPrune['artifacts'], fn(array $artifact): bool => $artifact['store'] === 'account'));
        encryptionExpect(count($accountEvidence) === 1 && $accountEvidence[0]['copies'] === 1,
            'pruned encrypted artifacts were reported as current verified copies');
        file_put_contents((string)$copies[0]['local_path'], 'tampered ciphertext');
        $failedVerification = encryptionVerifyConfiguredCopies($retentionConfig, $live, $web);
        encryptionExpect($failedVerification['failed'] === 1 && (int)$live->query("SELECT COUNT(*) FROM encryption_artifacts WHERE state = 'verification_failed'")->fetchColumn() === 1,
            'tampered retained encrypted artifact was not marked as failed evidence');
        $failedStatus = encryptionStatus($live, $retentionConfig, $web);
        encryptionExpect(str_contains(implode(' ', $failedStatus['warnings']), 'failed their last verification'),
            'failed encrypted artifact was not visible in protection status');
        $rotationConfig = $config; $rotationSaved = false;
        $rotatedConfigured = encryptionRotateConfiguredArtifacts($rotationConfig, $live, $web, $replacementKeyPath,
            function (array $updated) use (&$rotationSaved): void { $rotationSaved = isset($updated['encryption']['local_key_file']); });
        encryptionExpect(count($rotatedConfigured) === 1 && $rotationSaved
            && $rotationConfig['encryption']['local_key_file'] === $replacementKeyPath,
            'configured sealed-copy rotation did not prepare recovery evidence before switching keys');
        encryptionExpect((int)$live->query("SELECT COUNT(*) FROM encryption_artifacts WHERE state = 'superseded'")->fetchColumn() >= 1
            && (int)$live->query("SELECT COUNT(*) FROM encryption_artifacts WHERE state = 'verified'")->fetchColumn() >= 1,
            'configured sealed-copy rotation did not retain old and new recovery evidence separately');
        encryptionOpenFile((string)$rotatedConfigured[0]['local_path'], $root . '/rotated-configured.db', $replacement);
        $rotationStatus = encryptionStatus($live, $rotationConfig, $web);
        encryptionExpect(count($rotationStatus['superseded_artifacts']) >= 1,
            'protection status did not disclose retained superseded recovery evidence');
        $retired = encryptionRetireSupersededArtifacts($rotationConfig, $live, $web, $keyPath);
        encryptionExpect($retired >= 1 && (int)$live->query("SELECT COUNT(*) FROM encryption_artifacts WHERE state = 'retired'")->fetchColumn() >= 1,
            'superseded sealed copies were not retired after current-key recovery verification');
        $retiredStatus = encryptionStatus($live, $rotationConfig, $web);
        encryptionExpect(count($retiredStatus['retired_artifacts']) >= 1,
            'protection status did not retain retired-copy audit evidence');
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
        if (is_dir($path)) foreach (glob($path . '/*') ?: [] as $child) {
            if (is_dir($child)) foreach (glob($child . '/*') ?: [] as $grandchild) @unlink($grandchild);
            @is_dir($child) ? @rmdir($child) : @unlink($child);
        }
        @is_dir($path) ? @rmdir($path) : @unlink($path);
    }
    @rmdir($root);
}
