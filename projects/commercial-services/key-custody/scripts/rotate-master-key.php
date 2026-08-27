#!/usr/bin/env php
<?php
declare(strict_types=1);

/* Offline-only rotation for the service master key, deliberately separate
 * from tenant/API key rotation. It never prints key paths or key material. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This rotation command is CLI-only.\n"); }
require_once __DIR__ . '/../lib/store.php';

$options = getopt('', ['config:', 'old-key:', 'new-key:', 'backup:', 'confirm']);
if (!isset($options['old-key'], $options['new-key'], $options['backup']) || !array_key_exists('confirm', $options)) {
    fwrite(STDERR, "Usage: php scripts/rotate-master-key.php --old-key /secure/old.key --new-key /secure/new.key --backup /private/custody-before-rotation.db --confirm [--config /absolute/config.php]\n");
    exit(64);
}
$root = dirname(__DIR__); $configFile = (string)($options['config'] ?? ($root . '/config.php'));
if ($configFile === '' || !str_starts_with($configFile, '/') || !is_file($configFile)) { fwrite(STDERR, "Service configuration is unavailable.\n"); exit(1); }
$config = require $configFile;
if (!is_array($config)) { fwrite(STDERR, "Service configuration is invalid.\n"); exit(1); }
try {
    /* The caller must stop all PHP-FPM/worker processes before this command;
     * the exclusive SQLite transaction prevents only database-level overlap. */
    $old = keyCustodyReadMasterKey(['master_key_file' => (string)$options['old-key']], $root);
    $new = keyCustodyReadMasterKey(['master_key_file' => (string)$options['new-key']], $root);
    $pdo = keyCustodyOpen($config, $root);
    $backup = keyCustodyBackupMetadata($pdo, (string)$options['backup'], $root);
    $result = keyCustodyRewrapMasterKey($pdo, $old, $new);
    fwrite(STDOUT, "Rewrapped {$result['keys']} custody key record(s) for {$result['tenants']} tenant(s). Metadata backup verified. Keep the old key until recovery is tested, then update master_key_file while the service remains stopped.\n");
} catch (Throwable $error) { fwrite(STDERR, 'Master-key rotation refused: ' . $error->getMessage() . "\n"); exit(1); }
