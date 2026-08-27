#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 2 || !str_starts_with($argv[1], '/')) {
    fwrite(STDERR, "Usage: php scripts/backup-metadata.php /absolute/private/key-custody-metadata.db\n"); exit(64);
}
require_once __DIR__ . '/../lib/store.php';
$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile) || !is_array($config = require $configFile)) { fwrite(STDERR, "Copy config.sample.php to config.php before creating a backup.\n"); exit(1); }
try {
    $webRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $backup = keyCustodyBackupMetadata(keyCustodyOpen($config, $webRoot), $argv[1], $webRoot);
    fwrite(STDOUT, "Created custody metadata backup {$backup['path']} ({$backup['bytes']} bytes, SHA-256 {$backup['sha256']}).\n"
        . "This backup deliberately excludes the custody master key. Keep that key's offline recovery copy separately.\n");
} catch (Throwable $error) { fwrite(STDERR, 'Custody metadata backup failed: ' . $error->getMessage() . "\n"); exit(1); }
