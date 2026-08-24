<?php
declare(strict_types=1);

/*
 * The host-side collector. Runs as root, out of WHM's crontab.
 *
 *   * * * * * /usr/local/bin/php /home/.../host-cron.php --collect --prune --quiet
 *
 * Command line only: it is refused over HTTP below, and .htaccess denies it as
 * well. A root-privileged endpoint reachable from a browser is not something to
 * leave to one layer of protection.
 *
 * It reads /proc, /etc/passwd, Apache's log directory and - if CloudLinux is
 * installed - lveinfo, then writes to its own database. It never touches the
 * account's database, and the account's collector never touches this one.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("host-cron.php runs from the command line only.\n");
}

require_once __DIR__ . '/lib/boot.php';

$configFile = __DIR__ . '/lib/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Not configured: lib/config.php is missing.\n");
    exit(1);
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'UTC');
define('DISKMON_LOG', dirname((string)$config['database']) . '/error.log');

require_once __DIR__ . '/lib/db.php';
// For humanBytes, the same way cron.php gets it.
require_once __DIR__ . '/lib/alerts.php';
require_once __DIR__ . '/lib/host.php';
require_once __DIR__ . '/lib/hostdisk.php';

$args = array_slice($argv, 1);
$quiet = in_array('--quiet', $args, true);
$want = fn(string $flag): bool => in_array($flag, $args, true);

// Nothing named means collect, which is what a crontab line usually wants.
$doCollect = $want('--collect') || (!$want('--prune') && !$want('--show') && !$want('--disk'));

/*
 * The disk walk is its own mode, and deliberately not part of --collect.
 *
 * Collection runs every minute and must stay cheap. Walking the root
 * filesystem takes tens of seconds and answers a question that does not change
 * minute to minute, so it belongs on its own much slower schedule - or on a
 * command somebody types when 72 GB has gone missing and they want to know
 * where. Both are served by giving it a flag of its own.
 */
$doDisk = $want('--disk');

$say = function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line, "\n";
    }
};

/*
 * A warning rather than a refusal.
 *
 * Run as an ordinary user this still works - it just sees what that user can
 * see, which is the situation it exists to escape. Saying so is more useful
 * than exiting, because "I ran it and it collected nothing interesting" is
 * otherwise very hard to explain.
 */
$root = function_exists('posix_geteuid') ? posix_geteuid() === 0 : (trim((string)shell_exec('id -u')) === '0');
if (!$root) {
    $say('Warning: not running as root. Other users\' processes, slab detail and');
    $say('         Apache\'s logs will be invisible - which is the whole point of this.');
}

if (!($config['host']['enabled'] ?? true)) {
    fwrite(STDERR, "Host collection is turned off: set host.enabled to true in lib/config.php.\n");
    exit(1);
}

try {
    $pdo = hostDb($config, true);
    if ($pdo === null) {
        throw new RuntimeException('the host database could not be opened for writing');
    }

    if ($doCollect) {
        $result = hostTakeReading($config, $pdo, 'cron');
        // Ownership is re-applied every run, not only at creation: WAL and
        // shared-memory files are recreated by SQLite and come back owned by
        // root, and the dashboard cannot read a WAL database without them.
        hostHandOver($config, hostDatabasePath($config));

        $say(sprintf(
            'Recorded sample %d: %d processes, %d users, %d services, %d slab caches, '
            . '%d files, %d disks%s.',
            $result['sample_id'],
            $result['processes'],
            $result['users'],
            $result['services'],
            $result['slab'],
            $result['files'],
            $result['disks'],
            $result['lve'] === null ? '' : ", {$result['lve']} LVE rows"
        ));

        /*
         * Said out loud when a source is missing, rather than left as a zero in
         * a count nobody reads. Running the collector and seeing nothing appear
         * on the dashboard is the situation this line exists to explain.
         */
        if ($result['disks'] === 0) {
            $say('  No disk devices: /proc/diskstats could not be read, even as root.');
        }
        $say(sprintf(
            '  Kernel %s. Device counters %s, pressure stall info %s.',
            php_uname('r'),
            $result['disks'] > 0 ? 'readable' : 'NOT readable',
            $result['psi'] ? 'available' : 'NOT available'
        ));
        if (!$result['psi'] && $result['psi_reason'] !== '') {
            $say('  ' . $result['psi_reason']);
        }
        if ($result['unaccounted'] !== null) {
            $say(sprintf(
                'Other kernel/system memory: %s (not in any process, cache or slab).',
                humanBytes((int)$result['unaccounted'])
            ));
        }
    }

    if ($doDisk) {
        $at = time();

        $mounts = hostMounts();
        $insert = $pdo->prepare(
            'INSERT INTO host_mounts (taken_at, device, mount, type, total_bytes,
                used_bytes, free_bytes, used_percent, also_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($mounts as $row) {
            $insert->execute([
                $at, $row['device'], $row['mount'], $row['type'], $row['total_bytes'],
                $row['used_bytes'], $row['free_bytes'], $row['used_percent'],
                $row['also_at'] ? implode(' ', $row['also_at']) : null,
            ]);
        }
        if ($mounts === []) {
            /*
             * No filesystems is not "the disk is fine", it is "the question
             * could not be asked" - and that is the first question of the
             * three, so saying which it is matters.
             */
            $say('No filesystems could be read. /proc/mounts is where this comes from, so '
                . 'either this is not Linux or /proc is not mounted.');
        } else {
            $say(sprintf('Recorded %d filesystem(s), fullest first:', count($mounts)));
        }
        foreach (array_slice($mounts, 0, 6) as $row) {
            $say(sprintf(
                '  %-28s %6s used of %-8s (%.0f%%) on %s',
                $row['mount'],
                humanBytes($row["used_bytes"]),
                humanBytes($row["total_bytes"]),
                $row['used_percent'],
                $row['device']
            ));
        }

        $root = (string)($config['host']['walk_root'] ?? '/');
        $skip = array_map('strval', $config['host']['walk_skip'] ?? []);
        $budget = (float)($config['host']['walk_seconds'] ?? HOSTDISK_WALK_SECONDS);

        $say(sprintf('Walking %s (up to %.0fs)...', $root, $budget));
        $walk = hostDirectoryUsage($root, $budget, $skip);

        $dirs = $pdo->prepare(
            'INSERT INTO host_dirs (taken_at, path, depth, bytes, files) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($walk['dirs'] as $row) {
            $dirs->execute([$at, $row['path'], $row['depth'], $row['bytes'], $row['files']]);
        }
        $say(sprintf(
            '  %d directories kept from %s scanned in %ss%s.',
            count($walk['dirs']),
            number_format($walk['scanned']),
            $walk['seconds'],
            $walk['complete'] ? '' : ' - STOPPED EARLY, the totals are lower bounds'
        ));
        foreach (array_slice($walk['dirs'], 0, 8) as $row) {
            $say(sprintf('  %10s  %s', humanBytes($row["bytes"]), $row['path']));
        }

        $deleted = hostDeletedOpen();
        $del = $pdo->prepare(
            'INSERT INTO host_deleted (taken_at, pid, process, path, bytes, holders)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($deleted['files'] as $row) {
            $del->execute([$at, $row['pid'], $row['process'], $row['path'], $row['bytes'], $row['holders']]);
        }

        if ($deleted['bytes'] > 0) {
            $say(sprintf(
                'Deleted but still open: %s held by %d file(s). This space is gone from df '
                . 'and invisible to du.',
                humanBytes($deleted["bytes"]),
                count($deleted['files'])
            ));
            foreach (array_slice($deleted['files'], 0, 8) as $row) {
                $say(sprintf('  %10s  %s (pid %d, %s)',
                    humanBytes($row["bytes"]), $row['path'], $row['pid'], $row['process']));
            }
        } else {
            $say('No deleted-but-open files holding space.');
        }
        if ($deleted['reason'] !== null) {
            $say('  ' . $deleted['reason']);
        }

        $pdo->prepare(
            'INSERT INTO host_walks (taken_at, root, complete, seconds, dirs_scanned,
                deleted_bytes, deleted_readable, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $at, $root, $walk['complete'] ? 1 : 0, $walk['seconds'], $walk['scanned'],
            $deleted['bytes'], $deleted['readable'] ? 1 : 0, $deleted['reason'],
        ]);
    }

    if ($want('--prune')) {
        $days = (int)($config['host']['keep_days'] ?? 14);
        $say(sprintf('Pruned %d readings older than %d days.', hostPrune($pdo, $days), $days));

        // The service series keeps its own, much longer clock: those rows are
        // tiny and their whole value is being older than the detail tables.
        $serviceDays = (int)($config['host']['keep_service_days'] ?? 90);
        $say(sprintf(
            'Pruned %d service readings older than %d days.',
            hostPruneServices($pdo, $serviceDays),
            $serviceDays
        ));
    }

    if ($want('--show')) {
        $row = $pdo->query('SELECT * FROM host_samples ORDER BY id DESC LIMIT 1')->fetch();
        if (!$row) {
            $say('Nothing recorded yet.');
        } else {
            foreach ($row as $key => $value) {
                printf("  %-16s %s\n", $key, (string)$value);
            }
        }
    }
} catch (Throwable $e) {
    // Same shape as cron.php's handler: the log wants a level and a string.
    logProblem('error', 'host-cron: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
