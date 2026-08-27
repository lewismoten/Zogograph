<?php
declare(strict_types=1);

/* Verifies the database primitive shared by account, host, and assistant DBs. */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/storekeep.php';

function migrationTestExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/hostscope-migration-' . bin2hex(random_bytes(8));
migrationTestExpect(mkdir($directory, 0700), 'could not create a temporary test directory');
$database = $directory . '/account.db';

try {
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE evidence (value TEXT NOT NULL)');
    $pdo->exec("INSERT INTO evidence (value) VALUES ('before migration')");

    $integrity = databaseFullIntegrity($pdo);
    migrationTestExpect($integrity['ok'], 'full SQLite integrity check did not pass on a valid database');
    $retention = retentionRules([]);
    migrationTestExpect($retention['remote_vital_days'] === 400 && $retention['remote_receipt_days'] === 2,
        'remote transport retention defaults are missing');

    $snapshot = migrationBackup($pdo, 12, 13);
    migrationTestExpect(is_file($snapshot), 'migration snapshot was not created');
    migrationTestExpect((fileperms($snapshot) & 0077) === 0, 'migration snapshot is not private');

    $copy = new PDO('sqlite:' . $snapshot, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    migrationTestExpect($copy->query('SELECT value FROM evidence')->fetchColumn() === 'before migration',
        'migration snapshot did not preserve source data');
    $copy = null;
    $pdo = null;

    unlink($snapshot);
    rmdir($directory . '/migration-backups');
    unlink($database);
    rmdir($directory);
    echo "migration snapshot checks passed\n";
} catch (Throwable $e) {
    foreach (glob($directory . '/migration-backups/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory . '/migration-backups');
    @unlink($database);
    @rmdir($directory);
    throw $e;
}
