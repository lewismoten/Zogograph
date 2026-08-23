#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * The collector. Run from cron; it does nothing over HTTP and .htaccess denies
 * it there.
 *
 * Three jobs on three clocks:
 *
 *   --vitals   disk, inodes, load, memory, cpu. Costs milliseconds. Every
 *              minute is reasonable; every five is plenty.
 *   --scan     walk the tree and size everything. Costs minutes of IO on a big
 *              home directory. Hourly at most.
 *   --alerts   compare the latest readings against the rules and email if
 *              anything has tripped. Costs nothing, so it runs after both.
 *
 * With no flags it does all three, which is right for a daily job and wrong for
 * a per-minute one - see the README for the crontab lines.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cron.php is a command line program.\n");
}

$configFile = __DIR__ . '/lib/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "No lib/config.php. Copy lib/config.sample.php and edit it.\n");
    exit(1);
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'UTC');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/collect.php';
require_once __DIR__ . '/lib/alerts.php';

$flags = array_slice($argv, 1);
$quiet = in_array('--quiet', $flags, true);
$jobs = array_values(array_filter($flags, fn(string $f): bool => $f !== '--quiet'));
$wants = fn(string $flag): bool => $jobs === [] || in_array($flag, $jobs, true);

$say = function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line, "\n";
    }
};

if (in_array('--help', $jobs, true) || in_array('-h', $jobs, true)) {
    echo <<<TEXT
    Disk Usage Monitor collector

      php cron.php --vitals     take one cheap reading (disk, memory, cpu)
      php cron.php --scan       walk the tree and record what is where
      php cron.php --alerts     evaluate the rules, email if anything tripped
      php cron.php --prune      thin readings older than the retention window
      php cron.php              all of the above
      php cron.php --quiet      say nothing unless something goes wrong

    TEXT;
    exit(0);
}

try {
    $pdo = db($config);

    if ($wants('--vitals')) {
        $row = takeVitals($config, $pdo);
        $say(sprintf(
            'vitals  disk %s free of %s, load %s, cpu %s',
            humanBytes($row['disk_free_bytes']),
            humanBytes($row['disk_total_bytes']),
            $row['load1'] === null ? 'n/a' : (string)$row['load1'],
            $row['cpu_percent'] === null ? 'n/a' : $row['cpu_percent'] . '%'
        ));
    }

    if ($wants('--scan')) {
        $result = runScan($config, $pdo);
        $say(sprintf(
            'scan    %s files in %s dirs, %s, kept %d paths%s',
            number_format($result['files']),
            number_format($result['dirs']),
            humanBytes($result['bytes']),
            $result['kept'],
            $result['error'] ? ' (partial: ' . $result['error'] . ')' : ''
        ));
    }

    if ($wants('--alerts')) {
        $raised = evaluateAlerts($config, $pdo);
        foreach ($raised as $alert) {
            $say('ALERT   ' . strtoupper($alert['severity']) . ' ' . $alert['subject']);
        }
        if (!$raised) {
            $say('alerts  nothing tripped');
        }
    }

    if ($wants('--prune')) {
        $say('prune   ' . prune($pdo));
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Drops readings that are too old to be interesting.
 *
 * Vitals once a minute is half a million rows a year. SQLite does not care, but
 * a chart does, and so does the phone loading it. The recent past is kept at
 * full resolution; the older past is thinned to one reading an hour rather than
 * deleted, because "when did this start" is a question about months ago.
 */
function prune(PDO $pdo): string
{
    $fullResolutionDays = 14;
    $keepThinnedDays = 400;

    $cutoff = time() - $fullResolutionDays * 86400;
    $ancient = time() - $keepThinnedDays * 86400;

    $pdo->beginTransaction();

    // Past the window: keep the first reading of each hour.
    $thinned = $pdo->prepare(
        'DELETE FROM vitals WHERE taken_at < ? AND id NOT IN (
            SELECT MIN(id) FROM vitals WHERE taken_at < ? GROUP BY taken_at / 3600
         )'
    );
    $thinned->execute([$cutoff, $cutoff]);
    $thinnedCount = $thinned->rowCount();

    $dropped = $pdo->prepare('DELETE FROM vitals WHERE taken_at < ?');
    $dropped->execute([$ancient]);
    $droppedCount = $dropped->rowCount();

    // Scans cascade to their totals and their path sizes.
    $scans = $pdo->prepare('DELETE FROM scans WHERE started_at < ?');
    $scans->execute([$ancient]);
    $scanCount = $scans->rowCount();

    // A path nothing refers to any more is history nobody can ask about.
    $paths = $pdo->exec(
        'DELETE FROM paths
         WHERE id NOT IN (SELECT path_id FROM path_sizes)
           AND id NOT IN (SELECT path_id FROM alerts WHERE path_id IS NOT NULL)'
    );

    $pdo->commit();

    return sprintf(
        'thinned %d vitals, dropped %d vitals, %d scans, %d paths',
        $thinnedCount,
        $droppedCount,
        $scanCount,
        (int)$paths
    );
}
