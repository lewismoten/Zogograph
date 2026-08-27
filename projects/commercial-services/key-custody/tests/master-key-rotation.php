<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/store.php';

function masterRotationExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
if (!function_exists('proc_open') || !function_exists('sodium_crypto_box_seal')) { echo "custody master-key rotation checks skipped: required capability is unavailable\n"; exit(0); }
$root = sys_get_temp_dir() . '/hostscope-custody-master-rotation-' . bin2hex(random_bytes(4));
mkdir($root . '/private', 0700, true); mkdir($root . '/backups', 0700, true); mkdir($root . '/web', 0700, true);
try {
    $oldPath = $root . '/private/old.key'; $newPath = $root . '/private/new.key';
    $old = random_bytes(32); $new = random_bytes(32);
    file_put_contents($oldPath, base64_encode($old) . "\n"); file_put_contents($newPath, base64_encode($new) . "\n"); chmod($oldPath, 0600); chmod($newPath, 0600);
    $config = ['database' => $root . '/private/custody.db', 'master_key_file' => $oldPath];
    $configPath = $root . '/private/config.php'; file_put_contents($configPath, "<?php return " . var_export($config, true) . ";\n"); chmod($configPath, 0600);
    $pdo = keyCustodyOpen($config, $root . '/web'); $tenant = keyCustodyCreateTenant($pdo, 'rotation test');
    $key = keyCustodyCreateKey($pdo, $tenant['tenant_id'], 'rotation', 300, $old);
    $public = base64_decode($key['wrapping_public_key'], true); masterRotationExpect(is_string($public), 'test custody wrapping public key is invalid');
    $artifact = random_bytes(32); keyCustodyStoreEnvelope($pdo, $tenant['tenant_id'], $key['id'], 'setup:rotation', sodium_crypto_box_seal($artifact, $public), $old);
    $backup = $root . '/backups/before.db';
    $command = [PHP_BINARY, __DIR__ . '/../scripts/rotate-master-key.php', '--config', $configPath, '--old-key', $oldPath, '--new-key', $newPath, '--backup', $backup, '--confirm'];
    $pipes = []; $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    masterRotationExpect(is_resource($process), 'could not start custody master-key rotation command');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    masterRotationExpect(proc_close($process) === 0 && is_file($backup) && str_contains($output, 'Rewrapped 1 custody key record'),
        'custody master-key rotation command did not create a verified rewrap');
    masterRotationExpect(hash_equals($artifact, keyCustodyUnwrapKey($pdo, $tenant['tenant_id'], $key['id'], 'setup:rotation', $new)),
        'custody master-key rotation command did not preserve artifact recovery');
    try { keyCustodyUnwrapKey($pdo, $tenant['tenant_id'], $key['id'], 'setup:rotation', $old); throw new RuntimeException('old master key remained usable after command rotation'); }
    catch (RuntimeException $error) { masterRotationExpect($error->getMessage() !== 'old master key remained usable after command rotation', $error->getMessage()); }
    echo "custody master-key rotation checks passed\n";
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($root);
}
