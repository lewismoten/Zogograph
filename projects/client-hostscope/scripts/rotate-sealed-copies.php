#!/usr/bin/env php
<?php
declare(strict_types=1);

/* Rotates every currently verified local sealed artifact before changing the
 * configured key. Old ciphertext is retained as superseded recovery evidence. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__); $configFile = $root . '/lib/config.php';
require_once $root . '/lib/setupstore.php';
require_once $root . '/lib/db.php';
require_once $root . '/lib/encryption.php';

function rotationUsage(): never
{
    fwrite(STDERR, "Usage: php scripts/rotate-sealed-copies.php --new-key /absolute/private/replacement.key --confirm\n");
    exit(64);
}

$options = []; $arguments = array_slice($argv, 1);
for ($index = 0; $index < count($arguments); $index++) {
    $argument = $arguments[$index];
    if ($argument === '--confirm') { $options['confirm'] = true; continue; }
    if ($argument !== '--new-key' || isset($options['new-key']) || !isset($arguments[++$index])) rotationUsage();
    $options['new-key'] = $arguments[$index];
}
if (empty($options['confirm']) || !isset($options['new-key']) || !is_file($configFile)) rotationUsage();

try {
    $config = loadConfiguredSettings($configFile); $settings = (array)($config['encryption'] ?? []);
    if (($settings['mode'] ?? 'none') !== 'sealed_copies_local') throw new RuntimeException('local sealed copies are not enabled');
    $oldKey = encryptionReadLocalKey((string)($settings['local_key_file'] ?? ''), $root);
    $newPath = (string)$options['new-key']; $newKey = encryptionReadLocalKey($newPath, $root);
    if (hash_equals($oldKey, $newKey)) throw new RuntimeException('the replacement key differs from the configured key');
    $directory = (string)($settings['artifact_directory'] ?? '') ?: dirname((string)$config['database']) . '/sealed';
    $directory = realpath($directory);
    if ($directory === false || is_link($directory) || !is_dir($directory)) throw new RuntimeException('the sealed-artifact directory is unavailable');
    $pdo = db($config);
    $rows = $pdo->query("SELECT artifact_id, store, local_path FROM encryption_artifacts WHERE state = 'verified' ORDER BY created_at, artifact_id")->fetchAll();
    if ($rows === []) throw new RuntimeException('no verified sealed artifacts are available to rotate');
    $created = []; $superseded = [];
    try {
        foreach ($rows as $row) {
            $source = (string)$row['local_path']; $real = realpath($source);
            if ($real === false || is_link($source) || !is_file($real) || !str_starts_with($real, rtrim($directory, '/') . '/') || !str_ends_with($real, '.sealed')) {
                throw new RuntimeException('a verified artifact path is unsafe or unavailable: ' . (string)$row['artifact_id']);
            }
            $stamp = gmdate('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(4)); $store = (string)$row['store'];
            $artifact = $store . ':rotation-' . $stamp; $destination = $directory . '/' . $store . '.rotation-' . $stamp . '.sealed';
            $record = ['store' => $store, 'local_path' => $destination]
                + encryptionRotateSealedFile($real, $destination, $oldKey, $newKey, $artifact);
            encryptionVerifySealedArtifact($destination, $newKey, (int)$record['plaintext_bytes']);
            $created[] = $record; $superseded[] = (string)$row['artifact_id'];
        }
        $pdo->beginTransaction();
        try {
            encryptionRecordArtifacts($pdo, $created);
            $mark = $pdo->prepare("UPDATE encryption_artifacts SET state = 'superseded' WHERE artifact_id = ? AND state = 'verified'");
            foreach ($superseded as $artifact) $mark->execute([$artifact]);
            $config['encryption']['local_key_file'] = $newPath;
            saveConfiguredSettings($config, $configFile, $root . '/lib');
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    } catch (Throwable $error) {
        // Unrecorded replacements are safe to inspect and remove; the old
        // policy and its verified artifacts remain unchanged on this path.
        throw $error;
    }
    echo 'Rotated ' . count($created) . " sealed artifact(s) and switched the configured local key.\n";
    echo "Old artifacts remain as superseded recovery evidence. Keep the old key until you have run --verify-sealed and completed a restore drill with the replacement key.\n";
} catch (Throwable $error) { fwrite(STDERR, 'Rotation refused: ' . $error->getMessage() . "\n"); exit(1); }
