<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/setupstore.php';
require_once __DIR__ . '/../lib/setup-preflight.php';

function setupExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/hostscope-preflight-' . bin2hex(random_bytes(6));
mkdir($root . '/data', 0700, true);
mkdir($root . '/lib', 0700, true);
$config = $root . '/lib/config.php';
file_put_contents($config, "<?php return ['database' => " . var_export($root . '/data/stats.db', true) . "];\n");

try {
    $checks = setupPreflight($root, $config, [
        'php_version' => '8.0.30', 'sqlite' => false, 'commands' => [], 'https' => false, 'cli' => false, 'encryption' => false,
    ]);
    $byLabel = array_column($checks, null, 'label');
    setupExpect($byLabel['PHP']['state'] === 'error', 'unsupported PHP was not a blocking installer error');
    setupExpect($byLabel['SQLite']['state'] === 'error', 'missing SQLite was not a blocking installer error');
    setupExpect($byLabel['Command capability']['state'] === 'warning'
        && str_contains($byLabel['Command capability']['detail'], 'PHP-only collection still works'),
        'disabled command execution did not preserve the PHP-only install path');
    setupExpect($byLabel['Cron']['state'] === 'warning'
        && str_contains($byLabel['Cron']['detail'], 'host panel'),
        'shared-host cron guidance was not shown without command execution');
    setupExpect($byLabel['HTTPS']['state'] === 'warning', 'plain HTTP was not called out before credential entry');
    setupExpect($byLabel['Encrypted copies']['state'] === 'warning', 'missing encryption capability was not reported');

    $live = setupPreflight($root, $config, [
        'php_version' => '8.1.0', 'sqlite' => true, 'commands' => ['proc_open'], 'https' => true, 'cli' => true, 'encryption' => true,
    ]);
    $liveByLabel = array_column($live, null, 'label');
    setupExpect($liveByLabel['PHP']['state'] === 'ok' && $liveByLabel['SQLite']['state'] === 'ok',
        'minimum supported PHP/SQLite installation was rejected');
    setupExpect($liveByLabel['Command capability']['state'] === 'ok' && $liveByLabel['Cron']['state'] === 'ok',
        'available CLI capabilities were not reported as usable');
    setupExpect($liveByLabel['Encrypted copies']['state'] === 'ok', 'available encryption capability was not reported');
} finally {
    @unlink($config);
    @rmdir($root . '/lib');
    @rmdir($root . '/data');
    @rmdir($root);
}

echo "setup preflight checks passed\n";
