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

$args = array_slice($argv, 1);
$quiet = in_array('--quiet', $args, true);
$want = fn(string $flag): bool => in_array($flag, $args, true);

// Nothing named means collect, which is what a crontab line usually wants.
$doCollect = $want('--collect') || (!$want('--prune') && !$want('--show'));

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
            'Recorded sample %d: %d processes, %d users, %d slab caches, %d files%s.',
            $result['sample_id'],
            $result['processes'],
            $result['users'],
            $result['slab'],
            $result['files'],
            $result['lve'] === null ? '' : ", {$result['lve']} LVE rows"
        ));
        if ($result['unaccounted'] !== null) {
            $say(sprintf(
                'Other kernel/system memory: %s (not in any process, cache or slab).',
                humanBytes((int)$result['unaccounted'])
            ));
        }
    }

    if ($want('--prune')) {
        $days = (int)($config['host']['keep_days'] ?? 14);
        $say(sprintf('Pruned %d readings older than %d days.', hostPrune($pdo, $days), $days));
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
