<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/host.php';

function monitoringImpactExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/hostscope-monitoring-' . bin2hex(random_bytes(6));
mkdir($root . '/201', 0700, true);
mkdir($root . '/202', 0700, true);
file_put_contents($root . '/201/smaps_rollup', "Pss:                64 kB\n");
file_put_contents($root . '/202/smaps_rollup', "Pss:                32 kB\n");

try {
    $impact = hostMonitoringImpact([
        ['pid' => 201, 'ppid' => 1, 'command' => '/usr/bin/php /srv/hostscope/host-cron.php --collect',
            'state' => 'R', 'cpu_percent' => 1.25, 'rss_bytes' => 200000],
        ['pid' => 202, 'ppid' => 201, 'command' => 'sqlite3 /srv/private/host.db',
            'state' => 'D', 'cpu_percent' => 2.5, 'rss_bytes' => 100000],
        ['pid' => 203, 'ppid' => 1, 'command' => 'mariadbd',
            'state' => 'R', 'cpu_percent' => 45, 'rss_bytes' => 9000000],
    ], '/srv/hostscope', $root);

    monitoringImpactExpect($impact['processes'] === 2, 'collector child was not attributed exactly');
    monitoringImpactExpect($impact['runnable'] === 2, 'runnable collector-tree tasks were not counted');
    monitoringImpactExpect($impact['cpu_percent'] === 3.75, 'collector CPU did not exclude unrelated work');
    monitoringImpactExpect($impact['rss_bytes'] === 300000, 'collector RSS did not include the child exactly');
    monitoringImpactExpect($impact['pss_bytes'] === 98304, 'collector PSS did not include the selected tree');
    echo "Monitoring impact tests passed\n";
} finally {
    @unlink($root . '/201/smaps_rollup');
    @unlink($root . '/202/smaps_rollup');
    @rmdir($root . '/201');
    @rmdir($root . '/202');
    @rmdir($root);
}
