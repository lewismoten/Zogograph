<?php
declare(strict_types=1);

/*
 * The only thing the dashboard talks to.
 *
 * Read endpoints are open, because the numbers are the useful part and a
 * dashboard you have to log into to glance at is a dashboard you stop glancing
 * at. Everything that names a path goes through presentPath/presentAlert, which
 * removes the real string for a signed-out caller rather than hiding it in the
 * markup. Everything that changes the disk is POST, signed in, and CSRF checked.
 */

/*
 * The error boundary goes on before anything else can fail.
 *
 * Every require below this line, the config file, the database - any of them
 * can blow up, and until this is installed a failure produces a blank 500 with
 * nothing in the body to read.
 */
require_once __DIR__ . '/lib/boot.php';
installErrorBoundary('json');

$configFile = __DIR__ . '/lib/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not configured. Copy lib/config.sample.php to lib/config.php.']);
    exit;
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'UTC');

// Now that the config is known, problems go beside the database rather than to
// the fallback path guessed before it was read.
define('DISKMON_LOG', dirname((string)$config['database']) . '/error.log');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mask.php';
require_once __DIR__ . '/lib/alerts.php';
require_once __DIR__ . '/lib/incident.php';
require_once __DIR__ . '/lib/daemons.php';
require_once __DIR__ . '/lib/growth.php';
require_once __DIR__ . '/lib/retention.php';
require_once __DIR__ . '/lib/treemap.php';
require_once __DIR__ . '/lib/hostdisk.php';
require_once __DIR__ . '/lib/backups.php';
require_once __DIR__ . '/lib/mountinfo.php';
require_once __DIR__ . '/lib/capabilities.php';
require_once __DIR__ . '/lib/collect.php';
require_once __DIR__ . '/lib/actions.php';
require_once __DIR__ . '/lib/logs.php';
require_once __DIR__ . '/lib/schedule.php';
require_once __DIR__ . '/lib/processes.php';
require_once __DIR__ . '/lib/symlinks.php';
require_once __DIR__ . '/lib/signals.php';
require_once __DIR__ . '/lib/hostdb.php';
// swapPressure and the host collectors live here, not in hostdb.php.
require_once __DIR__ . '/lib/host.php';
require_once __DIR__ . '/lib/uptime.php';
require_once __DIR__ . '/lib/limits.php';
require_once __DIR__ . '/lib/inspect.php';
// renderConfig, so the email page can save through the same writer setup uses.
require_once __DIR__ . '/lib/configure.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

startSession($config);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'overview');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

try {
    $pdo = db($config);
    echo json_encode(route($config, $pdo, $action, $post), JSON_UNESCAPED_SLASHES);
} catch (ActionRefused $e) {
    // An expected refusal, not a fault: the message is written for the person
    // reading it and is safe to show.
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
}
// Anything else falls through to the boundary installed above, which logs it
// with a reference and returns that reference as JSON.

function route(array $config, PDO $pdo, string $action, bool $post): array
{
    $signedIn = isSignedIn();

    return match ($action) {
        'overview' => overview($config, $pdo, $signedIn),
        'vitals' => vitalsSeries($pdo),
        'sample' => liveSample($config, $pdo),
        'scans' => scanSeries($config, $pdo),
        'offenders' => offenders($config, $pdo, $signedIn),
        'history' => pathHistory($config, $pdo, $signedIn),
        'alerts' => alertList($config, $pdo, $signedIn),
        'actions' => actionLog($config, $pdo, $signedIn),
        'incident' => incidentView($config, $pdo, $signedIn),
        'treemap' => treemapView($config, $signedIn),
        'inspect' => inspectView($config, $pdo, $signedIn),
        'host-logs' => hostLogList($config, $signedIn),
        'host-disk' => hostDiskView($config, $signedIn),
        'backups' => backupsView($config, $signedIn),
        'capabilities' => capabilitiesView($config, $pdo, $signedIn),
        'mounts' => mountsView($config, $signedIn),
        'capability-changes' => capabilityChangesView($config, $pdo, $signedIn),
        'tail' => tailLog($config, $pdo, $signedIn),
        'last-lines' => lastLines($config, $pdo, $signedIn),
        'schedule' => scheduleRead($signedIn),
        'host-setup' => hostSetup($config, $signedIn),
        'email' => emailSettings($config, $pdo, $signedIn),
        'processes' => processList($config, $pdo, $signedIn),
        'busy' => busyMoments($config, $pdo, $signedIn),
        'symlinks' => symlinkCheck($config, $signedIn),
        'host' => hostView($config, $signedIn, $pdo),
        'host-series' => hostSeries($config, $signedIn),
        'host-io' => hostIoSeries($config, $signedIn),
        'uptime' => uptimeView($config, $pdo, $signedIn),
        'health' => healthCheck($config, $pdo, $signedIn),
        'problems' => problemLog($config, $signedIn),
        'session' => sessionState($config),
        'signin' => doSignIn($config, $post, $pdo),
        'signout' => doSignOut($pdo),
        'delete-file' => doDeleteFile($config, $pdo, $post),
        'delete-dir' => doDeleteDir($config, $pdo, $post),
        'empty' => doEmpty($config, $pdo, $post),
        'schedule-save' => doScheduleSave($post, $pdo),
        'scan-now' => doScanNow($pdo, $post),
        'test-email' => doTestEmail($config, $pdo, $post),
        'email-save' => doEmailSave($config, $pdo, $post),
        'clear-problems' => doClearProblems($config, $pdo, $post),
        'alert-action' => doAlertAction($config, $pdo, $post),
        'daemon-watch' => doDaemonWatch($config, $pdo, $post),
        'request-host-walk' => doRequestHostWalk($config, $pdo, $post),
        'check-capabilities' => doCheckCapabilities($config, $pdo, $post),
        'request-host-task' => doRequestHostTask($config, $pdo, $post),
        'match-processes' => doMatchProcesses($config, $post),
        'stop-process' => doStopProcess($config, $pdo, $post),
        'stop-group' => doStopGroup($config, $pdo, $post),
        'stop-matching' => doStopMatching($config, $pdo, $post),
        default => throw new ActionRefused('unknown action'),
    };
}

/* ------------------------------------------------------------------ read -- */

/**
 * The newest host reading, or null when there is not one to be had.
 *
 * Every reason for there not being one - collection turned off, the cron never
 * run, the file unreadable by this account - is the same answer here: no
 * comparison. The host panel is where those are told apart and explained; this
 * one only needs to know whether it can compare.
 */
function latestHostSample(array $config): ?array
{
    if (!($config['host']['enabled'] ?? true)) {
        return null;
    }

    try {
        $pdo = hostDb($config, false);
        if ($pdo === null) {
            return null;
        }
        return $pdo->query('SELECT * FROM host_samples ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    } catch (Throwable) {
        return null;
    }
}

function overview(array $config, PDO $pdo, bool $signedIn): array
{
    $vitals = $pdo->query('SELECT * FROM vitals ORDER BY taken_at DESC, id DESC LIMIT 1')->fetch() ?: null;

    $scan = $pdo->query(
        'SELECT * FROM scans WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1'
    )->fetch() ?: null;



    $totals = [];
    if ($scan) {
        $stmt = $pdo->prepare('SELECT category, bytes, files FROM scan_totals WHERE scan_id = ?');
        $stmt->execute([$scan['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $totals[$row['category']] = ['bytes' => (int)$row['bytes'], 'files' => (int)$row['files']];
        }
    }

    return [
        'signed_in' => $signedIn,
        'csrf' => $signedIn ? csrfToken() : null,
        'vitals' => $vitals,
        'scan' => $scan,
        'scan_running' => scanRunning($pdo),
        'totals' => $totals,
        /*
         * Categories emptied since the scan that measured them.
         *
         * The totals come from the last scan, so after emptying one the figure
         * on its card is stale until the next scan runs - and offering to empty
         * it again, under a size that is no longer there, is the same trap the
         * per-row Delete button had.
         *
         * A partial empty counts here, unlike for individual rows. There the
         * question is "was this exact file removed", and a partial run may have
         * skipped it; here it is "is this total still true", and after any
         * successful removal it is not.
         */
        'emptied' => $scan ? emptiedSince($pdo, (int)$scan['started_at']) : [],
        'open_alerts' => (int)$pdo->query(
            'SELECT COUNT(*) FROM alerts WHERE raised_at >= ' . (time() - 86400)
        )->fetchColumn(),
        /*
         * What the host allows, when there is a host reading to say.
         *
         * Folded into the overview rather than given an endpoint of its own
         * because it changes nothing and is needed on the page that opens: a
         * dashboard that reports 94% CPU without saying that is 94% of two
         * cores out of thirty-two has told you the wrong thing first.
         */
        'limits' => accountLimits($vitals, latestHostSample($config)),
        'deletable' => $config['deletable_categories'] ?? [],
        // What the delete buttons are allowed to reach - see resolveDeletable.
        'deletable_scope' => (string)($config['deletable_scope'] ?? 'account'),
        // So the dashboard builds its filters and its chart from the rules that
        // are actually in force, rather than a copy of them that goes stale.
        'categories' => categoryNames($config['scan']),
        'viewable' => $config['viewable_categories'] ?? [],
        'server_time' => time(),
    ];
}

/**
 * When each category was last emptied, if since the given moment.
 *
 * @return array<string, int>
 */
function emptiedSince(PDO $pdo, int $since): array
{
    $stmt = $pdo->prepare(
        "SELECT target, MAX(at) AS at FROM actions
         WHERE action = 'empty_category' AND outcome IN ('ok', 'partial') AND at >= ?
         GROUP BY target"
    );
    $stmt->execute([$since]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string)$row['target']] = (int)$row['at'];
    }

    return $out;
}

/** The vitals series for a window, thinned so a long range is not a huge body. */
/**
 * How wide one chart point should be, from a ladder of round durations.
 *
 * The smallest step that brings the range under the point budget. Round
 * numbers because the reader has to hold the bucket in their head while
 * looking at the chart - "each point is five minutes" is a thought, "each
 * point is 43.7 seconds" is an obstacle.
 */
function displayBucket(int $span, int $points): int
{
    if ($span <= 0 || $points <= 0) {
        return 0;
    }

    $ladder = [
        1, 5, 10, 15, 30,
        60, 120, 300, 600, 900, 1800,
        3600, 7200, 10800, 21600, 43200,
        86400, 172800, 604800,
    ];

    foreach ($ladder as $step) {
        if ($span / $step <= $points) {
            return $step;
        }
    }

    return end($ladder);
}

function vitalsSeries(PDO $pdo): array
{
    [$from, $to] = window();
    $limit = clampInt($_GET['limit'] ?? 720, 10, 5000);

    $count = $pdo->prepare('SELECT COUNT(*) FROM vitals WHERE taken_at BETWEEN ? AND ?');
    $count->execute([$from, $to]);
    $total = (int)$count->fetchColumn();

    /*
     * Grouped into time buckets chosen from the range, worst value per bucket.
     *
     * A year holds three million ten-second readings and a canvas has about a
     * thousand pixels, so something has to give. This used to take every nth
     * row, on the reasoning that averaging destroys spikes - which is true,
     * and every-nth destroys them just as thoroughly: it keeps an arbitrary
     * sample and the spike is only kept if it happens to be the arbitrary one.
     *
     * So the bucket carries its worst value, exactly as compaction does in
     * lib/retention.php and using the same classification - MAX for the
     * columns where high is bad, MIN for the three that measure what is left.
     * A point then means "the worst it got in this bucket", which is what a
     * monitoring chart is read for.
     *
     * Buckets come from a ladder of round durations rather than span/points,
     * so the x-axis lands on ten seconds or five minutes or an hour instead of
     * on 43.7 seconds.
     */
    $bucket = displayBucket($to - $from, $limit);

    if ($bucket <= 1) {
        $stmt = $pdo->prepare(
            'SELECT * FROM vitals WHERE taken_at BETWEEN :from AND :to ORDER BY taken_at'
        );
    } else {
        $min = retentionMinColumns();
        $select = [
            'MAX(taken_at) AS taken_at',
            'MAX(id) AS id',
            'SUM(COALESCE(samples, 1)) AS samples',
        ];
        foreach (vitalsColumns($pdo) as $column) {
            if (in_array($column, ['id', 'taken_at', 'samples'], true)) {
                continue;
            }
            $select[] = (in_array($column, $min, true) ? 'MIN' : 'MAX') . "($column) AS $column";
        }

        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $select) . '
             FROM vitals WHERE taken_at BETWEEN :from AND :to
             GROUP BY taken_at / :bucket
             ORDER BY taken_at'
        );
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
    }

    // Integers, for the reason set out in lib/alerts.php: a bound string
    // compared against a computed expression never matches in SQLite.
    $stmt->bindValue(':from', $from, PDO::PARAM_INT);
    $stmt->bindValue(':to', $to, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $step = $bucket;

    /*
     * Whether this range has been compacted, and how coarsely.
     *
     * Once readings are merged, a point stops meaning "the machine was like
     * this at 04:12" and starts meaning "this was the worst it got in the hour
     * around 04:12". Those are different claims and the second one is only
     * honest if it is stated - a chart that silently changes what its points
     * mean halfway along its own x-axis is a trap.
     */
    $coarse = $pdo->prepare(
        'SELECT MAX(COALESCE(samples, 1)) FROM vitals WHERE taken_at BETWEEN ? AND ?'
    );
    $coarse->execute([$from, $to]);
    $worst = (int)$coarse->fetchColumn();

    /*
     * The projection is computed over a fixed day, not over the rows returned.
     *
     * This used to follow the displayed range, on the reasoning that the card
     * and the chart should describe the same window. That was the wrong
     * trade, for two reasons found by using it.
     *
     * First, nothing on the chart draws the trend, so there was no line for the
     * card to agree with - the coupling bought nothing.
     *
     * Second, an hour cannot support a projection. Disk space does not fall
     * smoothly: it sits flat and then jumps when something flushes, so a
     * one-hour window catches whichever burst it happened to land in. Measured
     * on identical simulated behaviour, the same machine projected anywhere
     * between 80 and 207 days depending on the hour, and occasionally reported
     * a spike instead. Changing the range to look at something made the
     * projection jump, which reads as the machine changing.
     *
     * A day is also exactly the window the alert rules use, so the card and the
     * email that may arrive about it now agree by construction rather than by
     * coincidence.
     */
    $recent = $pdo->prepare(
        'SELECT taken_at, disk_free_bytes, inodes_free FROM vitals
         WHERE taken_at >= ? ORDER BY taken_at'
    );
    $recent->execute([time() - TREND_WINDOW_SECONDS]);
    $recentRows = $recent->fetchAll();

    /*
     * Worked out over exactly the rows being returned, like the trend above.
     *
     * The chart shades the gaps, so the shading and the line have to come from
     * the same set of readings - computed separately they would disagree
     * whenever the series was thinned.
     */
    $uptime = analyseUptime($rows, $from, $to);

    return [
        'from' => $from,
        'to' => $to,
        'total' => $total,
        'step' => $step,
        // How wide each point is. 0 means the readings are shown as collected.
        'bucket_seconds' => $bucket > 1 ? $bucket : 0,
        'rows' => $rows,
        'uptime' => $uptime,
        // 1 means every point is a single reading; more means the older part
        // of this range has been compacted and each point is a worst-of.
        'samples_per_point' => $worst,
        // The window goes inside each trend rather than beside them: the card is
        // handed one trend, and a field it cannot reach is a field it will not
        // print.
        'trend' => [
            'disk' => trendWithWindow($recentRows, 'disk_free_bytes'),
            'inodes' => trendWithWindow($recentRows, 'inodes_free'),
        ],
    ];
}

/** A trend that carries the window it was measured over. */
function trendWithWindow(array $rows, string $field): ?array
{
    $trend = seriesTrend($rows, $field);
    return $trend === null ? null : $trend + ['window_seconds' => TREND_WINDOW_SECONDS];
}

/**
 * Takes a reading right now and returns it.
 *
 * What "live update" polls. The collector's own clock is a minute at best, so
 * without this the ten-second mode would just re-read the same row - and the
 * moment you actually want ten-second resolution is the moment something is
 * going wrong, which is the worst time to be looking at minute-old numbers.
 */
function liveSample(array $config, PDO $pdo): array
{
    return ['vitals' => takeVitals($config, $pdo, 'live')];
}

function scanSeries(array $config, PDO $pdo): array
{
    [$from, $to] = window();

    /*
     * One column per category, built from the config rather than written out.
     *
     * The value is bound; the column *alias* cannot be, so it is filtered to
     * plain identifiers instead. These names come from a config file rather
     * than from a request, but an alias built by string concatenation is an
     * alias built by string concatenation - and a category someone names with a
     * space in it would otherwise produce a syntax error nobody could explain.
     */
    $categories = array_values(array_filter(
        categoryNames($config['scan']),
        fn(string $name): bool => (bool)preg_match('/^[a-z][a-z0-9_]{0,30}$/i', $name)
    ));

    $columns = [];
    $params = [':from' => $from, ':to' => $to];
    foreach ($categories as $i => $category) {
        $columns[] = "MAX(CASE WHEN t.category = :c$i THEN t.bytes END) AS {$category}_bytes";
        $params[":c$i"] = $category;
    }

    $stmt = $pdo->prepare(
        'SELECT s.id, s.started_at, s.finished_at, s.duration_ms, s.files_seen, s.dirs_seen,
                s.bytes_seen' . ($columns ? ', ' . implode(', ', $columns) : '') . '
         FROM scans s LEFT JOIN scan_totals t ON t.scan_id = s.id
         WHERE s.finished_at IS NOT NULL AND s.started_at BETWEEN :from AND :to
         GROUP BY s.id ORDER BY s.started_at'
    );
    $stmt->execute($params);

    /*
     * The type breakdown from the newest scan in the window, not a series.
     *
     * What a disk is made of changes over months, not between two scans an hour
     * apart, so a line per type would be nine flat lines. The interesting form
     * is the composition right now, and one row of it is far cheaper than
     * carrying every scan's copy of it to the browser to draw one pie.
     */
    $rows = $stmt->fetchAll();
    $types = [];
    $extensions = [];
    $typeScan = null;
    $typeHistory = [];

    if ($rows !== []) {
        $latest = (int)end($rows)['id'];

        $byType = $pdo->prepare(
            'SELECT type, bytes, files FROM scan_file_types WHERE scan_id = ? ORDER BY bytes DESC'
        );
        $byType->execute([$latest]);
        $types = $byType->fetchAll();
        $typeScan = $types === [] ? null : $latest;

        /*
         * What fell into `other`, for the newest scan only.
         *
         * A history of these would be a table with forty columns and no
         * question attached to it. The composition now is what turns an
         * unactionable slice into a list of things to go and look at.
         */
        $byExtension = $pdo->prepare(
            'SELECT extension, bytes, files FROM scan_extensions
             WHERE scan_id = ? ORDER BY bytes DESC'
        );
        $byExtension->execute([$latest]);
        $extensions = $byExtension->fetchAll();

        /*
         * One row per scan per type, for the growth chart.
         *
         * Returned long rather than pivoted into a column per type: the set of
         * types is fixed and small, but a scan that saw no `.mp4` has no row at
         * all, and a pivot in SQL would turn that absence into a zero. The
         * difference matters at the left-hand end of the chart, where a type
         * that did not exist yet should start where it started rather than
         * running along the axis from the beginning of time.
         */
        $ids = array_column($rows, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $history = $pdo->prepare(
            "SELECT f.scan_id, s.started_at, f.type, f.bytes
               FROM scan_file_types f JOIN scans s ON s.id = f.scan_id
              WHERE f.scan_id IN ($marks)
              ORDER BY s.started_at"
        );
        $history->execute($ids);
        $typeHistory = $history->fetchAll();
    }

    return [
        'rows' => $rows,
        'categories' => $categories,
        'file_types' => $types,
        'file_type_history' => $typeHistory,
        'other_extensions' => $extensions,
        // Null when the newest scan predates the tally, so the panel can say
        // "not measured yet" rather than drawing an empty pie as though the
        // account contained nothing.
        'file_types_scan_id' => $typeScan,
        /*
         * Enough for the Scan now button to work from this page.
         *
         * It used to live only beside the offender table and read its state
         * from the overview payload, which the storage view does not fetch -
         * so putting the button here without this would give a control that
         * never knew whether a scan was already running.
         */
        'scan_running' => scanRunning($pdo),
        'last_scan' => $pdo->query(
            'SELECT id, started_at, finished_at, duration_ms, files_seen, dirs_seen, bytes_seen, error
               FROM scans WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1'
        )->fetch() ?: null,
    ];
}

/**
 * A scan in flight, or null.
 *
 * An hour's grace, because a scan that died without writing a finish time would
 * otherwise leave the button disabled for ever, and "the button is stuck" is a
 * worse failure than "you can start a second scan an hour later".
 */
function scanRunning(PDO $pdo): ?array
{
    $row = $pdo->query(
        'SELECT id, started_at FROM scans WHERE finished_at IS NULL ORDER BY id DESC LIMIT 1'
    )->fetch();

    return $row && (time() - (int)$row['started_at']) < 3600
        ? ['started_at' => (int)$row['started_at']]
        : null;
}

/**
 * The top offenders, with how much each has grown.
 *
 * Growth is the column worth having. "This is the biggest file" is static
 * knowledge you learn once; "this is the biggest file and it doubled last
 * night" is the thing you act on.
 */
function offenders(array $config, PDO $pdo, bool $signedIn): array
{
    $view = (string)($_GET['view'] ?? $_GET['sort'] ?? 'size');
    if (!in_array($view, GROWTH_VIEWS, true)) {
        // 'bytes' was the old name for the default, and old bookmarks and any
        // half-updated index.js still send it.
        $view = $view === 'bytes' ? 'size' : 'size';
    }

    $filters = ['category' => $_GET['category'] ?? '', 'kind' => $_GET['kind'] ?? ''];
    $limit = clampInt($_GET['limit'] ?? 25, 1, 200);

    $window = growthWindow($pdo, $view);

    if ($window['now'] === null || ($window['before'] === null && $view !== 'size')) {
        return [
            'rows' => [], 'view' => $view, 'scan_id' => $window['now'],
            'note' => growthSpanNote($window, $view), 'window' => $window,
        ];
    }

    $rows = match ($view) {
        'new' => newlyAppeared($pdo, $window, $filters, $limit),
        'deleted' => deletedSince($pdo, $window, $filters, $limit),
        'size' => largestPaths($pdo, $window, $filters, $limit),
        default => grewMost($pdo, $window, $filters, $limit),
    };

    /*
     * Whether the path has been deleted since the scan that found it.
     *
     * The list is drawn from a scan, so a file stays in it until the next one
     * runs - which is right, it is a record of what the scan saw. What is not
     * right is still offering to delete it: the button came back on every
     * refresh and the second press answered "path does not exist", which reads
     * as a fault rather than as "already done".
     *
     * The deleted view has already worked this out for itself, and by asking
     * the filesystem rather than the audit log, so it is left alone.
     */
    if ($view !== 'deleted') {
        $rows = markDeleted($pdo, $rows, (int)$window['now']);
    }

    $viewableCategories = $config['viewable_categories'] ?? [];
    $rows = array_map(
        function (array $row) use ($config, $signedIn, $viewableCategories, $view): array {
            /*
             * Whether each row can actually be read, decided here rather than
             * guessed. The dashboard used to offer View for anything in a
             * viewable category, and a rotated log keeps the word "log" in its
             * name - so git.lewismoten.com-ssl_log-Aug-2026.gz got a button
             * that could only ever answer with binary.
             *
             * A row in the deleted view is never viewable: the file is the one
             * thing it is documented as not being there.
             */
            $row['viewable'] = $signedIn
                && $view !== 'deleted'
                && ($row['kind'] ?? '') === 'file'
                && ($row['deleted_at'] ?? null) === null
                && in_array($row['category'] ?? null, $viewableCategories, true)
                && compressedExtension((string)$row['path']) === null
                && looksLikeText((string)$row['path']);

            return presentPath($config, $row, $signedIn);
        },
        $rows
    );

    return [
        'rows' => $rows,
        'view' => $view,
        'scan_id' => (int)$window['now'],
        'note' => growthSpanNote($window, $view),
        'window' => $window,
        // Kept so an index.js that has not been uploaded yet still works.
        'sort' => $view === 'size' ? 'bytes' : 'growth',
    ];
}

/** The plain "what is biggest" list, which is still the one people open first. */
function largestPaths(PDO $pdo, array $window, array $filters, int $limit): array
{
    $where = ['now.scan_id = :now'];
    $params = [':now' => $window['now'], ':before' => $window['before'] ?? -1, ':limit' => $limit];
    applyFilters($filters, $where, $params);

    return runGrowthQuery(
        $pdo,
        'SELECT p.id AS path_id, p.path, p.kind, p.category,
                now.bytes, now.files, now.links,
                COALESCE(now.bytes - was.bytes, 0) AS growth,
                COALESCE(now.files - was.files, 0) AS growth_files
         FROM path_sizes now
         JOIN paths p ON p.id = now.path_id
         LEFT JOIN path_sizes was ON was.path_id = now.path_id AND was.scan_id = :before
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY now.bytes DESC LIMIT :limit',
        $params
    );
}

/**
 * Marks rows whose file has been deleted through this dashboard since the scan.
 *
 * Only deletes recorded at or after the scan started count. A file removed
 * before the scan cannot be the one the scan found, and a name that has since
 * been recreated is genuinely deletable again.
 *
 * Emptying a category writes one summary row rather than one per file, so that
 * case is matched on the category instead. Less precise, and the alternative is
 * a row per file in an audit log meant to be readable.
 */
function markDeleted(PDO $pdo, array $rows, int $scanId): array
{
    if ($rows === []) {
        return $rows;
    }

    $startedAt = (int)$pdo->query("SELECT started_at FROM scans WHERE id = $scanId")->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT MAX(a.at) FROM actions a
          WHERE a.outcome = \'ok\' AND a.at >= :started
            AND ((a.action = \'delete_file\' AND a.target = :path)
              OR (a.action = \'empty_category\' AND a.target = :category))'
    );

    foreach ($rows as $i => $row) {
        $stmt->execute([
            ':started' => $startedAt,
            ':path' => (string)$row['path'],
            ':category' => (string)($row['category'] ?? ''),
        ]);
        $at = $stmt->fetchColumn();
        $rows[$i]['deleted_at'] = $at === false || $at === null ? null : (int)$at;
    }

    return $rows;
}

/** One path's size over time, which is the chart that shows a leak. */
function pathHistory(array $config, PDO $pdo, bool $signedIn): array
{
    $pathId = clampInt($_GET['path_id'] ?? 0, 1, PHP_INT_MAX);
    [$from, $to] = window();

    $meta = $pdo->prepare('SELECT id, path, kind, category FROM paths WHERE id = ?');
    $meta->execute([$pathId]);
    $path = $meta->fetch();
    if (!$path) {
        throw new ActionRefused('unknown path');
    }

    $stmt = $pdo->prepare(
        'SELECT s.started_at, ps.bytes, ps.files
         FROM path_sizes ps JOIN scans s ON s.id = ps.scan_id
         WHERE ps.path_id = ? AND s.started_at BETWEEN ? AND ?
         ORDER BY s.started_at'
    );
    $stmt->execute([$pathId, $from, $to]);

    return [
        'path' => presentPath($config, $path, $signedIn),
        'rows' => $stmt->fetchAll(),
    ];
}

function alertList(array $config, PDO $pdo, bool $signedIn): array
{
    $limit = clampInt($_GET['limit'] ?? 50, 1, 500);
    $stmt = $pdo->prepare(
        'SELECT a.id, a.raised_at, a.rule, a.severity, a.subject, a.detail,
                a.emailed_at, a.path_id, a.incident_id, a.acknowledged_at, p.path, p.kind
         FROM alerts a LEFT JOIN paths p ON p.id = a.path_id
         ORDER BY a.raised_at DESC LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => array_map(
            fn(array $row): array => presentAlert($config, $row, $signedIn),
            $stmt->fetchAll()
        ),
    ];
}

/** The audit trail. Only for people who could have caused an entry in it. */
/**
 * One incident record, in full.
 *
 * Signed-in only, and refused rather than masked. Everywhere else a signed-out
 * visitor sees the shape of a problem with the names taken out, which works
 * because the sizes are the interesting part. It does not work here: this
 * record holds other accounts' command lines, and while redactSecrets() strips
 * the password arguments it recognises, "the ones it recognises" is not a
 * promise worth making to the open internet.
 */
/**
 * One level of the treemap, measured now rather than remembered.
 *
 * Signed-in only, and refused rather than masked. Everywhere else a signed-out
 * visitor sees shapes with the names removed, which works because the sizes are
 * the interesting part. It does not work here: this walks the filesystem on
 * demand at a path the caller chooses, and an anonymous visitor able to map a
 * private directory - or to ask for an expensive walk over and over - is not
 * something masking fixes.
 */
/**
 * The last root-side disk walk: filesystems, directories, and hidden space.
 *
 * Signed-in only. It names every large directory on the machine including other
 * accounts' homes, which is exactly the information the host collector exists
 * to gather and exactly the information not to hand to an anonymous visitor.
 */
/**
 * What this account and this host can actually run.
 *
 * Read-only and cheap: it reports the last probe rather than running one. The
 * probe shells out, and putting that behind a page refresh would spawn a shell
 * every ten seconds to answer a question whose answer changes when somebody
 * installs a package.
 */
function capabilitiesView(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see what this account can run');
    }

    $host = null;
    try {
        $host = hostDb($config, false);
    } catch (Throwable) {
        // No host database is a normal state, handled inside the report.
    }

    $report = capabilityReport($config, $pdo, $host);
    $report['tasks'] = pendingHostTasks($config, $pdo, $host);

    return $report;
}

/**
 * The mount table as the kernel describes it, and the CageFS view of it.
 */
/**
 * When programs appeared, vanished or changed version.
 *
 * Its own action rather than a field on `capabilities`: that one is a snapshot
 * the dashboard polls, while this answers to the range selector and would make
 * every poll re-read a year of history to redraw a table showing now.
 */
function capabilityChangesView(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see what has changed');
    }

    $host = null;
    try {
        $host = hostDb($config, false);
    } catch (Throwable) {
        // Absent host database is normal; the account history stands alone.
    }

    [$from, $to] = window();

    return capabilityChanges($config, $pdo, $host, $from, $to);
}

function mountsView(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the mount table');
    }

    $host = hostDb($config, false);
    $mounts = [];
    $at = 0;

    if ($host !== null) {
        try {
            $at = (int)$host->query('SELECT MAX(taken_at) FROM host_mountinfo')->fetchColumn();
            if ($at > 0) {
                $stmt = $host->prepare('SELECT * FROM host_mountinfo WHERE taken_at = ?');
                $stmt->execute([$at]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $mounts[] = [
                        'mount_id' => (int)$row['mount_id'],
                        'parent_id' => (int)$row['parent_id'],
                        'device' => (string)$row['device'],
                        'fs_root' => (string)$row['fs_root'],
                        'mount_point' => (string)$row['mount_point'],
                        'fs_type' => (string)$row['fs_type'],
                        'source' => (string)$row['source'],
                        'options' => (string)$row['options'],
                        'propagation' => (string)$row['propagation'],
                        'read_only' => (bool)$row['read_only'],
                        'bind' => (bool)$row['bind'],
                    ];
                }
            }
        } catch (Throwable) {
            $mounts = [];
        }
    }

    /*
     * The account's own mount table, when the host has not recorded one.
     *
     * Inside CageFS this is the *account's* view rather than the machine's,
     * which is a different and still useful answer - it is precisely the small
     * curated filesystem the account lives in.
     */
    $ownView = false;
    if ($mounts === []) {
        $mounts = readMountInfo();
        $ownView = $mounts !== [];
    }

    return [
        'available' => $mounts !== [],
        'own_view' => $ownView,
        'taken_at' => $at,
        'reason' => $mounts === []
            ? 'No mount table could be read. /proc/self/mountinfo is where this comes from.'
            : null,
        'mounts' => $mounts,
        'filesystems' => groupByFilesystem($mounts),
        // Told which view it is looking at, because the same absence of
        // skeleton mounts means opposite things from the two sides.
        'cagefs' => cagefsMounts($mounts, CAGEFS_SKELETON, !$ownView),
    ];
}

/**
 * Backup storage, as its own subject.
 *
 * Signed-in only: it names accounts, their live sizes and their backup sizes,
 * which together describe the shape of somebody's hosting.
 */
function backupsView(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see backup storage');
    }

    $host = hostDb($config, false);
    if ($host === null) {
        return [
            'available' => false,
            'reason' => 'Backups live outside this account, so this needs the host collector.',
        ];
    }

    try {
        $analysis = analyseBackups($host, $config);
    } catch (Throwable $e) {
        return ['available' => false, 'reason' => 'Could not be read: ' . $e->getMessage()];
    }

    if ($analysis['available']) {
        // The named account, not the unattributed remainder.
        $analysis += backupActions($analysis);
        foreach ($analysis['accounts'] as $i => $row) {
            $analysis['accounts'][$i]['verdict'] =
                backupRatioVerdict($row['ratio'], $row['copies']);
        }
    }

    return $analysis;
}

function hostDiskView(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the host disk report');
    }

    $host = hostDb($config, false);
    if ($host === null) {
        return [
            'available' => false,
            'reason' => 'The host collector is not installed, so nothing outside this '
                . 'account can be seen at all.',
            'mounts' => [], 'dirs' => [], 'deleted' => [],
        ];
    }

    $report = hostDiskReport($host);
    $report['unaccounted'] = hostUnaccounted($report);

    $requested = requestedHostWalk($config);
    $report['requested_at'] = $requested;
    $report['pending'] = hostWalkPending($host, $requested);

    return $report;
}

/**
 * Everything knowable about one path.
 *
 * Signed in only. It reports owners, permissions and the contents of
 * directories, which is more than a masked path is willing to say.
 */
function inspectView(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to inspect a path');
    }
    return inspectPath($config, $pdo, (string)($_GET['path'] ?? ''));
}

/**
 * The host logs this install offers, and whether each can actually be read.
 *
 * Probed rather than listed, because the list in the config is a statement of
 * intent and the filesystem is the authority. A log that is named but
 * unreadable is worth showing as exactly that - it tells you the feature is
 * configured and the host is what is in the way.
 */
function hostLogList(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to read log files');
    }

    $rows = [];
    foreach ($config['host_log_paths'] ?? [] as $path) {
        $real = realpath((string)$path);
        $exists = $real !== false && is_file($real);

        $rows[] = [
            'path' => (string)$path,
            'exists' => $exists,
            'readable' => $exists && is_readable($real),
            'bytes' => $exists ? (int)(@filesize($real) ?: 0) : null,
            'modified_at' => $exists ? (int)(@filemtime($real) ?: 0) : null,
        ];
    }

    return ['rows' => $rows];
}

function treemapView(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to explore the disk');
    }

    $path = (string)($_GET['path'] ?? '');

    if (($_GET['source'] ?? 'account') === 'host') {
        if (!($config['host']['enabled'] ?? true)) {
            throw new ActionRefused('host collection is turned off');
        }
        $host = hostDb($config, false);
        if ($host === null) {
            throw new ActionRefused('the host collector is not installed');
        }
        return hostTreemapLevel($host, $path) + ['source' => 'host'];
    }

    return treemapLevel($config, $path) + ['source' => 'account'];
}

function incidentView(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the evidence behind an alert');
    }

    $id = clampInt($_GET['id'] ?? 0, 1, PHP_INT_MAX);
    $incident = incidentRead($pdo, $id);
    if ($incident === null) {
        throw new ActionRefused('there is no such incident');
    }

    return $incident;
}

function actionLog(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the action log');
    }

    $rows = $pdo->query('SELECT * FROM actions ORDER BY at DESC LIMIT 500')->fetchAll();

    /*
     * Who did it, as far as anything here can honestly say.
     *
     * There is one password, so there is one identity, and the only thing that
     * distinguishes two uses of it is the address they came from. Calling that
     * "who" would be a lie - an address is a place, not a person - so it is
     * labelled as what it is and the interface says so. When accounts exist
     * this becomes a real name and the column stops needing the caveat.
     */
    $who = [];
    foreach ($rows as $row) {
        $address = (string)($row['remote_addr'] ?? '');
        if ($address !== '') {
            $who[$address] = true;
        }
    }

    $days = (int)(retentionRules($config)['action_days'] ?? 0);

    return [
        'rows' => $rows,
        'kinds' => array_values(array_unique(array_column($rows, 'action'))),
        'who' => array_keys($who),
        'identity' => 'address',
        // So the page can answer "does this ever get cleared" without anybody
        // having to read the config to find out.
        'kept_days' => $days,
        'total' => (int)$pdo->query('SELECT COUNT(*) FROM actions')->fetchColumn(),
        'oldest_at' => (int)$pdo->query('SELECT MIN(at) FROM actions')->fetchColumn(),
    ];
}

/**
 * The end of a log file.
 *
 * Signed in only, and only for the categories the config marks readable. The
 * reader never loads the file - see lib/logs.php - which is the difference
 * between this working on a ten gigabyte error log and taking the site down.
 */
/**
 * The last line of each of several log files, in one request.
 *
 * The single most useful thing about a log at a glance is its most recent line -
 * "is it still throwing the same error" is answered by one line and does not
 * need the viewer opened. Batched because the alternative is one request per
 * row of a table, and a table of forty logs would be forty requests.
 *
 * Each file goes through resolveViewable, so this grants nothing the viewer
 * does not, and a refusal is reported per file rather than failing the batch -
 * one unreadable log should not blank the other nine.
 */
function lastLines(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to read log files');
    }

    $ids = array_slice(array_filter(array_map(
        'intval',
        explode(',', (string)($_GET['path_ids'] ?? ''))
    )), 0, 60);

    $paths = array_slice(array_filter(
        explode("\n", (string)($_GET['paths'] ?? '')),
        fn(string $p): bool => trim($p) !== ''
    ), 0, 60);

    $wanted = [];

    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, path FROM paths WHERE id IN ($marks)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $wanted[] = ['id' => (int)$row['id'], 'path' => (string)$row['path']];
        }
    }
    foreach ($paths as $path) {
        $wanted[] = ['id' => null, 'path' => trim($path)];
    }

    $out = [];
    foreach ($wanted as $entry) {
        try {
            $real = resolveViewable($config, $entry['path']);
            $tail = tailFile($real, 1, 0, '');
            $line = $tail['lines'][0] ?? null;

            $out[] = [
                'path_id' => $entry['id'],
                'path' => $entry['path'],
                'line' => $line['text'] ?? null,
                'line_number' => $line['n'] ?? null,
                'bytes' => $tail['size'],
                'modified_at' => (int)(@filemtime($real) ?: 0),
                'error' => null,
            ];
        } catch (ActionRefused $e) {
            // Reported against the row it belongs to. A log that cannot be read
            // is a fact about that log, not a failure of the request.
            $out[] = [
                'path_id' => $entry['id'],
                'path' => $entry['path'],
                'line' => null,
                'line_number' => null,
                'bytes' => null,
                'modified_at' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    return ['rows' => $out];
}

function tailLog(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to read log files');
    }

    /*
     * Named either by id or by path.
     *
     * The id is how the offender table refers to a file it already knows, and
     * carries the category with it. A raw path is how everything else does -
     * the treemap, the host log list - and those files may never have been
     * recorded at all. resolveViewable decides in both cases, so the extra way
     * in grants nothing the other one did not already allow.
     */
    $pathId = clampInt($_GET['path_id'] ?? 0, 0, PHP_INT_MAX);
    $wanted = (string)($_GET['path'] ?? '');

    if ($pathId > 0) {
        $stmt = $pdo->prepare('SELECT id, path, kind, category FROM paths WHERE id = ?');
        $stmt->execute([$pathId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ActionRefused('unknown path');
        }
    } elseif ($wanted !== '') {
        $row = ['id' => null, 'path' => $wanted, 'kind' => 'file', 'category' => null];
    } else {
        throw new ActionRefused('no file named');
    }

    try {
        $real = resolveViewable($config, (string)$row['path']);
    } catch (ActionRefused $e) {
        /*
         * "Already deleted" beats "no such file" when we did the deleting.
         *
         * A stale tab, or an open log panel refreshed after the file went: the
         * path is genuinely missing and the reason is on record, so there is no
         * need to make it sound like a fault.
         */
        if (!file_exists((string)$row['path']) && alreadyDeleted($pdo, (string)$row['path'])) {
            throw new ActionRefused(
                'that file was deleted. It is still listed because the last scan found '
                . 'it; the next scan will drop it.'
            );
        }
        throw $e;
    }
    $result = tailFile(
        $real,
        clampInt($_GET['lines'] ?? 200, 1, 2000),
        clampInt($_GET['page'] ?? 0, 0, 100000),
        substr((string)($_GET['q'] ?? ''), 0, 200)
    );

    return ['path' => $row['path'], 'category' => $row['category']] + $result;
}

/**
 * What is running now, and what was running when the machine last struggled.
 *
 * Signed in only. A command line is not a size: it carries paths, arguments and
 * sometimes a password somebody put where they should not have, which is also
 * why the arguments are redacted before they get this far.
 */
/**
 * The moments the machine was busy, and what fired at the same time.
 *
 * Its own endpoint rather than a corner of the process list, because that one
 * shells out to `ps` on every call and this needs none of it - opening the page
 * about last night should not start a process listing today.
 *
 * The alerts are matched by clock, and that is all the claim being made. A rule
 * that fired within a couple of minutes of a recorded spike is worth putting a
 * link to; saying it fired *because* of the spike would be asserting a cause
 * nothing here measured.
 */
function busyMoments(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see what the machine was doing');
    }

    $moments = recordedProcesses($pdo, clampInt($_GET['limit'] ?? 25, 1, 100));
    if ($moments === []) {
        return ['moments' => [], 'window_seconds' => BUSY_ALERT_WINDOW];
    }

    $times = array_column($moments, 'taken_at');
    $stmt = $pdo->prepare(
        'SELECT id, raised_at, severity, subject, rule, incident_id
           FROM alerts
          WHERE raised_at BETWEEN ? AND ?
          ORDER BY raised_at'
    );
    $stmt->execute([min($times) - BUSY_ALERT_WINDOW, max($times) + BUSY_ALERT_WINDOW]);
    $alerts = $stmt->fetchAll();

    foreach ($moments as &$moment) {
        $moment['alerts'] = array_values(array_filter(
            $alerts,
            fn(array $a): bool =>
                abs((int)$a['raised_at'] - (int)$moment['taken_at']) <= BUSY_ALERT_WINDOW
        ));
    }
    unset($moment);

    return ['moments' => $moments, 'window_seconds' => BUSY_ALERT_WINDOW];
}

function processList(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the process list');
    }

    $by = ($_GET['by'] ?? 'cpu') === 'memory' ? 'memory' : 'cpu';
    // Ungrouped is how you reach one process of a pool to stop it on its own;
    // grouped is how you read the list. Both, rather than a choice.
    $group = ($_GET['group'] ?? '1') !== '0';
    $live = listProcesses($by, clampInt($_GET['limit'] ?? 15, 1, 100), $group);
    $stopping = signalsAvailable($config);

    return [
        'by' => $by,
        'grouped' => $group,
        'available' => $live['available'],
        'reason' => $live['reason'],
        'rows' => $stopping['available']
            ? annotateEligibility($config, $live['rows'])
            : $live['rows'],
        // So the Stop buttons are only drawn where they could actually work.
        'can_stop' => $stopping['available'],
        'stop_reason' => $stopping['reason'],
    ];
}

/**
 * Looks for symlinks that will send something into a loop.
 *
 * Run on request rather than on a schedule: it is a whole extra walk of the
 * tree, the answer changes only when somebody creates a link, and nothing about
 * it is urgent enough to pay for every hour.
 *
 * Signed in only, because it reports paths, and bounded by a clock it reports
 * back - a check that quietly gave up and said "nothing wrong" would be worse
 * than not having one.
 */
function symlinkCheck(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to check symlinks');
    }

    return findSymlinkProblems($config, (float)clampInt($_GET['budget'] ?? 10, 1, 60));
}

/**
 * What the host collector saw, which is everything the account cannot.
 *
 * Signed in only, and not negotiable: this lists every process on the machine
 * with its command line, every user's memory, and the paths of root-owned log
 * files. The account dashboard masks its own paths for a signed-out visitor;
 * there is no masked version of this, so there is no signed-out version.
 *
 * Read-only. The root cron is the single writer, and the dashboard never
 * migrates this database - a schema half-created by the web user in a file root
 * expects to own is a mess with no clean way out.
 */
/**
 * @param PDO $account the account database, which holds the monitoring choices -
 *                     the host database is root's and cannot be written here.
 */
function hostView(array $config, bool $signedIn, PDO $account): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see host readings');
    }

    $watch = daemonWatch($account);

    if (!($config['host']['enabled'] ?? true)) {
        return [
            'available' => false,
            'reason' => 'Host collection is turned off in the config.',
            'off' => true,
            'sample' => null,
        ];
    }

    $path = hostDatabasePath($config);
    $pdo = hostDb($config, false);

    if ($pdo === null) {
        return [
            'available' => false,
            // Told apart, because they need opposite fixes: one means the cron
            // has not run, the other means it ran as root and the dashboard
            // cannot read what it wrote.
            'reason' => is_file($path)
                ? 'The host database exists but this account cannot read it. Set '
                    . 'host.owner in the config to the account the dashboard runs as, '
                    . 'then run host-cron.php once as root.'
                : 'No host readings yet. This needs a second collector, run by root.',
            // The exact line to paste, with this install's real paths in it.
            'cron' => hostCronLine(__DIR__),
            'command' => hostCronCommand(__DIR__),
            'sample' => null,
        ];
    }

    try {
        $sample = $pdo->query('SELECT * FROM host_samples ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    } catch (Throwable) {
        return [
            'available' => false,
            'reason' => 'The host database is unreadable or not yet initialised.',
            'sample' => null,
        ];
    }

    if (!$sample) {
        return [
            'available' => false,
            'reason' => 'The host database is set up, but no readings have been recorded yet.',
            'cron' => hostCronLine(__DIR__),
            'command' => hostCronCommand(__DIR__),
            'sample' => null,
        ];
    }

    $id = (int)$sample['id'];
    $of = function (string $sql) use ($pdo, $id): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    };

    // Once: this walks a query per service, and the array below wants it twice.
    $services = hostServiceGrowth($pdo, time());

    // Validated against the known list inside hostServiceSeries, so an unknown
    // name falls back to the default rather than reaching the query.
    $metric = (string)($_GET['metric'] ?? 'pss');

    // Throughput per service, folded onto the growth rows so the table can show
    // memory and I/O for the same service side by side.
    $io = hostServiceIoRates($pdo);
    foreach ($services as $i => $service) {
        $services[$i]['read_rate'] = $io[$service['name']]['read'] ?? null;
        $services[$i]['write_rate'] = $io[$service['name']]['write'] ?? null;
    }

    [$from, $to] = window();
    $series = $pdo->prepare(
        'SELECT taken_at, mem_total, mem_available, used_bytes, cache_bytes, unaccounted,
                slab, shmem, swap_total, swap_free, pss_sum_bytes, proc_count
         FROM host_samples WHERE taken_at BETWEEN ? AND ? ORDER BY taken_at'
    );
    $series->execute([$from, $to]);
    $rows = $series->fetchAll();

    return [
        'available' => true,
        'reason' => null,
        'sample' => $sample,
        // Worked out here, over exactly the rows being returned, so the card and
        // the chart cannot end up describing different windows.
        'swap' => swapPressure($rows),
        'users' => $of('SELECT * FROM host_users WHERE sample_id = ? ORDER BY rss_bytes DESC'),
        'processes' => $of('SELECT * FROM host_processes WHERE sample_id = ? ORDER BY rss_bytes DESC'),
        'slab' => $of('SELECT * FROM host_slab WHERE sample_id = ? ORDER BY cache_bytes DESC'),
        'files' => $of('SELECT * FROM host_files WHERE sample_id = ? ORDER BY bytes DESC'),
        // Now, a day ago, a week ago, and the change - the figures that tell a
        // leak from a large but steady working set.
        'services' => $services,
        'disks' => hostDiskRates($pdo),
        /*
         * Which processes are doing the I/O, as against which devices are
         * carrying it. The device totals say the machine is busy; this says
         * what to go and look at.
         */
        'io_processes' => hostProcessIoRates($pdo),
        /*
         * Whether the services are alive, which no other figure on this page
         * can answer: a process that is not running uses no memory and no CPU
         * and appears in no ranking.
         */
        'daemons' => daemonStatus($pdo, daemonDefinitions($config), $watch),
        // So an empty disk table can tell "not enough readings yet" apart from
        // "read them and found nothing".
        'sample_count' => (int)$pdo->query('SELECT COUNT(*) FROM host_samples')->fetchColumn(),
        // Charted for the biggest few only: twenty lines on one canvas is a
        // legend, not a chart.
        /*
         * The metric the caller asked for, not a fixed one.
         *
         * This used to return PSS regardless while the dashboard chose its axis
         * from the selector, so picking "Writing" drew memory sizes against a
         * bytes-per-second scale - and every background refresh quietly put the
         * memory data back under a label that still said Writing. Echoing the
         * metric back lets the chart take its axis from what the data actually
         * is rather than from what was requested.
         */
        'service_series' => hostServiceSeries(
            $pdo,
            array_column(array_slice($services, 0, 5), 'name'),
            $from,
            $to,
            $metric
        ),
        'service_metric' => $metric,
        'service_charted' => array_column(array_slice($services, 0, 5), 'name'),
        'service_metrics' => array_map(
            fn(array $m): string => $m['label'],
            hostServiceMetrics()
        ),
        // Summed over unique (device, inode), not over paths.
        'file_totals' => hostFileTotals(
            $of('SELECT * FROM host_files WHERE sample_id = ? ORDER BY bytes DESC')
        ),
        'lve' => $of('SELECT * FROM host_lve WHERE sample_id = ? ORDER BY faults_mem DESC, user'),
        'rows' => $rows,
    ];
}

/**
 * One metric of the service series, on its own.
 *
 * A separate action so changing which figure is charted does not refetch the
 * process list, the slab table, the file list and everything else in the host
 * payload to redraw one canvas.
 */
/**
 * Host disk throughput over the selected range.
 *
 * Its own action rather than a field on `host`, because it answers to the range
 * selector while the rest of that panel is a snapshot of the latest reading -
 * bundling them would make every poll re-read a week of device counters to
 * refresh a table that only ever shows now.
 */
function hostIoSeries(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see host readings');
    }

    $pdo = hostDb($config, false);
    if ($pdo === null) {
        return ['rows' => [], 'available' => false,
                'reason' => 'The host collector is not installed.'];
    }

    [$from, $to] = window();

    return [
        'available' => true,
        'reason' => null,
        'rows' => hostDiskSeries($pdo, $from, $to),
        // So an empty chart can say which kind of empty it is.
        'sample_count' => (int)$pdo->query('SELECT COUNT(*) FROM host_samples')->fetchColumn(),
    ];
}

function hostSeries(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see host readings');
    }

    $pdo = hostDb($config, false);
    if ($pdo === null) {
        return ['rows' => [], 'names' => [], 'metric' => 'pss'];
    }

    $metric = (string)($_GET['metric'] ?? 'pss');
    $names = array_values(array_filter(
        array_map('strval', (array)($_GET['names'] ?? [])),
        fn(string $name): bool => $name !== ''
    ));

    [$from, $to] = window();

    return [
        'metric' => $metric,
        'names' => $names,
        'rows' => hostServiceSeries($pdo, array_slice($names, 0, 8), $from, $to, $metric),
    ];
}

/**
 * Uptime: how long it has been up, what it missed, and why it restarted.
 *
 * Open, like the other read endpoints - a boot time and an uptime percentage
 * describe the machine rather than naming anything on it. The boot *history*
 * is the exception: it comes from the host collector, names kernel versions and
 * is only there for somebody signed in.
 */
function uptimeView(array $config, PDO $pdo, bool $signedIn): array
{
    [$from, $to] = window();

    $rows = $pdo->prepare(
        'SELECT taken_at, boot_at FROM vitals WHERE taken_at BETWEEN ? AND ? ORDER BY taken_at'
    );
    $rows->execute([$from, $to]);
    $readings = $rows->fetchAll();

    $latest = $pdo->query(
        'SELECT taken_at, boot_at FROM vitals WHERE boot_at IS NOT NULL
         ORDER BY taken_at DESC, id DESC LIMIT 1'
    )->fetch() ?: null;

    $analysis = analyseUptime($readings, $from, $to);

    /*
     * Uptime measured from the boot clock, not from the readings.
     *
     * The last reading may be a minute old, or an hour if cron is unwell, and
     * "up for 3 days" should not quietly stop advancing because the collector
     * did. now - boot_at is the machine's own answer.
     */
    $bootAt = $latest ? (int)$latest['boot_at'] : null;
    $upFor = $bootAt !== null ? max(0, time() - $bootAt) : null;

    $out = [
        'boot_at' => $bootAt,
        'up_for' => $upFor,
        // A reading older than a few minutes means this figure is being derived
        // from a stale boot time, which is worth flagging rather than hiding.
        'reading_age' => $latest ? max(0, time() - (int)$latest['taken_at']) : null,
        'analysis' => $analysis,
    ];

    if (!$signedIn) {
        return $out;
    }

    // From the host database, where the collector stored what `last` said.
    $host = hostDb($config, false);
    if ($host === null) {
        return $out + ['history' => ['available' => false,
            'reason' => 'No host readings yet — the boot history comes from the root collector.',
            'boots' => []]];
    }

    try {
        $stmt = $host->query(
            'SELECT booted_at, kernel, ended, shutdown_at, still_running
             FROM host_boots ORDER BY booted_at DESC LIMIT 30'
        );
        $boots = array_map(
            fn(array $row): array => $row + ['reason' => bootReason((string)$row['ended'])],
            $stmt->fetchAll()
        );
        return $out + ['history' => ['available' => true, 'reason' => null, 'boots' => $boots]];
    } catch (Throwable) {
        return $out + ['history' => ['available' => false,
            'reason' => 'The host collector has not recorded a boot history yet.',
            'boots' => []]];
    }
}

/** What is in the crontab. Signed in only: it is a list of what runs as you. */
/**
 * Everything the host side needs, as instructions rather than as a diagnosis.
 *
 * This is the one part of the install the dashboard genuinely cannot do: it
 * runs as the account, and the collector has to run as somebody who can read
 * every process's /proc entry. So the useful output is not "not configured" but
 * the exact lines to paste, with this install's real paths already in them, and
 * an honest account of what each of them grants.
 *
 * Two ways to run it, because two different people install this. A server
 * administrator adds it to root's crontab. Somebody on shared hosting with no
 * root at all can still add it to their own crontab - it collects much less,
 * and saying which parts go missing is more useful than implying it is useless.
 */
function hostSetup(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the setup instructions');
    }

    $root = __DIR__;
    $dataDir = dirname((string)$config['database']);
    $hostDb = hostDatabasePath($config);
    $owner = trim((string)($config['host']['owner'] ?? ''));
    $me = currentAccountName();

    $exists = is_file($hostDb);

    return [
        'cron_line' => hostCronLine($root),
        'command' => hostCronCommand($root),
        'disk_command' => hostCronCommand($root) . ' --disk',
        'database' => $hostDb,
        'database_exists' => $exists,
        'database_readable' => $exists && is_readable($hostDb),
        'owner' => $owner,
        'account' => $me,
        'data_dir' => $dataDir,
        /*
         * Ownership is the step everybody misses.
         *
         * Run as root, the collector creates a database root owns, and then the
         * dashboard - running as the account - cannot open it. The symptom is
         * "the host database exists but this account cannot read it", which
         * says nothing about what to do. host.owner is what fixes it, and it
         * has to be set before the first run or the file has to be chowned
         * afterwards.
         */
        'owner_set' => $owner !== '',
        'needs_owner' => $owner === '' && $exists && !is_readable($hostDb),
        'steps' => [
            [
                'title' => 'Set who the collector writes for',
                'body' => $owner !== ''
                    ? "host.owner is set to \"{$owner}\". The collector chowns what it "
                        . 'writes to that account, so the dashboard can read it.'
                    : 'Set host.owner in lib/config.php to ' . ($me !== '' ? "\"{$me}\"" : 'this account')
                        . ' — the user the dashboard runs as. Running the collector as root '
                        . 'without it creates a database only root can read, and every host '
                        . 'panel stays empty with no obvious reason.',
                'done' => $owner !== '',
            ],
            [
                'title' => 'Run it once by hand, and read what it says',
                'body' => 'As root: ' . hostCronCommand($root)
                    . ' — it prints what it could and could not see. Doing this before '
                    . 'adding the cron line means any permission problem shows up now, '
                    . 'attached to the command that caused it.',
                'done' => $exists,
            ],
            [
                'title' => 'Add it to a crontab',
                'body' => 'Every five minutes. A reading walks every process in /proc, '
                    . 'which is not free on a busy machine, and nothing it watches moves '
                    . 'faster than that.',
                'done' => $exists && (time() - (int)@filemtime($hostDb)) < 3600,
            ],
            [
                'title' => 'Schedule the disk walk separately',
                'body' => 'The walk of the whole filesystem takes tens of seconds, so it is '
                    . 'not part of the five-minute collection. Once a night is plenty: '
                    . '17 3 * * * ' . hostCronCommand($root) . ' --disk --quiet',
                'done' => false,
            ],
        ],
        /*
         * What root buys, stated so the decision can be made on facts.
         *
         * Somebody on shared hosting has no root and never will, and telling
         * them only "run this as root" leaves them with nothing. Running it as
         * the account works and collects a real subset - which is worth having,
         * and worth being honest about the limits of.
         */
        'as_root' => [
            'Memory for every process on the machine, and per-account totals — '
                . 'inside CageFS the account sees only its own.',
            'Device throughput and saturation from /proc/diskstats.',
            'Space held by deleted files still open, from /proc/<pid>/fd.',
            'Directories and files outside the account, including other homes and /backup.',
            'Whether the machine\'s daemons are running, from systemd.',
            'Why it last restarted, from `last`.',
        ],
        'as_account' => [
            'Its own processes and their memory.',
            'Load average and total memory, which are not virtualised.',
            'Anything under the account\'s own home directory.',
        ],
        'account_cron_line' => '*/5 * * * * ' . hostCronCommand($root) . ' --quiet',
    ];
}

function scheduleRead(bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the schedule');
    }
    return scheduleState(__DIR__);
}

function doScheduleSave(bool $post, PDO $pdo): array
{
    requireWrite($post);

    $crontab = readCrontab();
    if (!$crontab['available']) {
        throw new ActionRefused($crontab['reason'] ?? 'crontab is not available here');
    }

    // An empty pick for both jobs removes the block entirely, which is how the
    // dashboard deletes a schedule - there is no separate destructive path.
    $lines = buildCronLines(
        [
            'vitals' => (string)($_POST['vitals'] ?? 'off'),
            'scan' => (string)($_POST['scan'] ?? 'off'),
            'minute' => $_POST['minute'] ?? 7,
            'hour' => $_POST['hour'] ?? 2,
        ],
        (string)($_POST['php'] ?? ''),
        __DIR__
    );

    writeCrontab(replaceManagedBlock($crontab['raw'], $lines));

    logAction($pdo, 'schedule_save', 'crontab', 'ok', 0, 0,
        $lines === [] ? 'schedule removed' : count($lines) . ' line(s) written');

    return ['ok' => true, 'lines' => $lines];
}

/**
 * Kicks off a scan by hand.
 *
 * Refused while one is already running, which is not politeness: two walks of
 * the same tree at once double the IO on a machine somebody is already worried
 * about, and produce two scan rows a few seconds apart that make every growth
 * figure meaningless.
 */
function doScanNow(PDO $pdo, bool $post): array
{
    requireWrite($post);

    $running = $pdo->query(
        'SELECT id, started_at FROM scans WHERE finished_at IS NULL
         ORDER BY id DESC LIMIT 1'
    )->fetch();

    if ($running) {
        $age = time() - (int)$running['started_at'];
        // A row left unfinished by a killed process would otherwise block
        // scanning for ever, so an old one does not count as running.
        if ($age < 3600) {
            throw new ActionRefused(
                'A scan started ' . ($age < 60 ? "{$age} seconds" : round($age / 60) . ' minutes')
                . ' ago is still running.'
            );
        }
    }

    $binaries = phpBinaries();
    if (!$binaries) {
        throw new ActionRefused('No PHP binary could be found to run the scan with.');
    }

    $result = startScan(__DIR__, $binaries[0]);
    if (!$result['started']) {
        logAction($pdo, 'scan_now', null, 'failed', 0, 0, $result['reason'] ?? null);
        throw new ActionRefused($result['reason'] ?? 'Could not start the scan.');
    }

    logAction($pdo, 'scan_now', null, 'ok', 0, 0, 'started by hand');
    return ['ok' => true, 'started' => true];
}

/*
 * Which conditions can raise an email, and what each is set to.
 *
 * Built from the config rather than written out, so a rule that is turned off
 * by having its threshold set to zero shows as off rather than as absent - and
 * so this cannot drift from what the alerting code actually reads.
 */
function emailConditions(array $config): array
{
    $a = $config['alerts'] ?? [];
    $n = fn(string $key, float $fallback = 0.0): float => (float)($a[$key] ?? $fallback);

    return [
        [
            'key' => 'disk_percent',
            'name' => 'The disk is nearly full',
            'detail' => sprintf('At or above %s%% used.', trimNumber($n('disk_percent', 90))),
            'on' => $n('disk_percent', 90) > 0,
        ],
        [
            'key' => 'inode_percent',
            'name' => 'Inodes are nearly gone',
            'detail' => sprintf('At or above %s%% of inodes used. A disk with free '
                . 'gigabytes and no inodes still refuses to create a file.',
                trimNumber($n('inode_percent', 90))),
            'on' => $n('inode_percent', 90) > 0,
        ],
        [
            'key' => 'cpu_percent',
            'name' => 'CPU held at the ceiling',
            'detail' => sprintf(
                'At least %s%% of readings over %s minutes at or above %s%% — a spike '
                . 'cannot satisfy that however tall it is.',
                trimNumber($n('cpu_share', 0.9) * 100),
                trimNumber($n('cpu_minutes', 15)),
                trimNumber($n('cpu_percent', 95))
            ),
            'on' => $n('cpu_percent', 95) > 0,
        ],
        [
            'key' => 'growth_bytes',
            'name' => 'Something grew unusually fast',
            'detail' => 'A file or category growing far beyond its own recent baseline.',
            'on' => true,
        ],
        [
            'key' => 'anomalies',
            'name' => 'Anomalies against the baseline',
            'detail' => 'Memory, process counts, swap, load and process age compared with '
                . 'what is normal for this machine rather than against a fixed number.',
            'on' => (bool)($a['anomalies']['enabled'] ?? true),
        ],
    ];
}

function trimNumber(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function emailSettings(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the email settings');
    }

    $a = $config['alerts'] ?? [];

    $last = $pdo->query(
        "SELECT at, target, outcome, detail FROM actions
          WHERE action IN ('test_email', 'alert_email')
          ORDER BY at DESC LIMIT 10"
    )->fetchAll();

    return [
        'enabled' => (bool)($a['enabled'] ?? false),
        'to' => (string)($a['to'] ?? ''),
        'from' => (string)($a['from'] ?? ''),
        'cooldown_seconds' => (int)($a['cooldown_seconds'] ?? 21600),
        'conditions' => emailConditions($config),
        // Whether the host can send at all, which is the usual reason nothing
        // arrives and is not something the settings above can fix.
        'mail_available' => function_exists('mail'),
        'recent' => $last,
        'writable' => is_writable(__DIR__ . '/lib/config.php'),
    ];
}

/**
 * Changes only the email settings, through the same renderer setup.php uses.
 *
 * Deliberately narrow. It reads the config that is in force, replaces four
 * values inside `alerts`, and writes the whole file back through renderConfig -
 * so nothing else in the file can be lost by a form that did not know about it,
 * and the result is the same shape setup.php produces.
 */
function doEmailSave(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    $file = __DIR__ . '/lib/config.php';
    if (!is_writable($file)) {
        throw new ActionRefused(
            'lib/config.php is not writable by the web server, so this cannot save. '
            . 'Change it in setup.php, or edit the file directly.'
        );
    }

    $to = trim((string)($_POST['to'] ?? ''));
    if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new ActionRefused('that does not look like an email address');
    }

    $from = trim((string)($_POST['from'] ?? ''));
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new ActionRefused('the from address does not look like an email address');
    }

    $enabled = ($_POST['enabled'] ?? '0') === '1';
    if ($enabled && $to === '') {
        throw new ActionRefused('turning email on needs an address to send to');
    }

    $current = require $file;
    $current['alerts']['enabled'] = $enabled;
    $current['alerts']['to'] = $to;
    $current['alerts']['from'] = $from;

    $cooldown = (int)($_POST['cooldown_seconds'] ?? 0);
    if ($cooldown > 0) {
        $current['alerts']['cooldown_seconds'] = min(604800, max(60, $cooldown));
    }

    /*
     * A condition is turned off by zeroing its threshold, not by a new flag.
     *
     * That is already how the alerting code reads them - `cpu_percent => 0`
     * means the rule does not run - so the checkbox writes the thing the rules
     * already consult rather than adding a second switch that could disagree
     * with the first.
     */
    $wanted = array_filter(explode(',', (string)($_POST['conditions'] ?? '')));
    $defaults = ['disk_percent' => 90.0, 'inode_percent' => 90.0, 'cpu_percent' => 95.0];

    foreach ($defaults as $key => $fallback) {
        $on = in_array($key, $wanted, true);
        $existing = (float)($config['alerts'][$key] ?? $fallback);
        // Turning one back on restores what it was set to, not the default -
        // unless it was off before, in which case there is nothing to restore.
        $current['alerts'][$key] = $on ? ($existing > 0 ? $existing : $fallback) : 0;
    }
    $current['alerts']['anomalies']['enabled'] = in_array('anomalies', $wanted, true);

    $rendered = renderConfig($current, __DIR__ . '/lib');

    $temporary = $file . '.new';
    if (@file_put_contents($temporary, $rendered, LOCK_EX) === false) {
        throw new ActionRefused('could not write into lib/');
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $file)) {
        @unlink($temporary);
        throw new ActionRefused('could not replace lib/config.php');
    }

    logAction($pdo, 'email_settings', $to !== '' ? $to : null, 'ok', 0, 0,
        ($enabled ? 'email on' : 'email off') . ', ' . count($wanted) . ' condition(s)');

    /*
     * The new settings come back with the write, rather than being fetched.
     *
     * PHP caches the result of stat() for the length of a request and beyond,
     * so a read issued immediately after this returned the *old* file - which
     * looked exactly like the save having silently failed. Answering with what
     * was just written removes the race rather than papering over it.
     */
    clearstatcache(true, $file);
    return ['ok' => true, 'settings' => emailSettings($current, $pdo, true)];
}

function doTestEmail(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    $result = sendTestEmail($config, $pdo);
    logAction(
        $pdo,
        'test_email',
        $result['to'] ?? null,
        ($result['sent'] ?? false) ? 'ok' : 'failed',
        0,
        0,
        $result['error'] ?? null
    );

    return $result;
}

/**
 * What is wrong with this install, in the terms that actually break it.
 *
 * Written for the moment when the dashboard is returning 500s and there is no
 * error log to be found: it answers "can PHP write where it needs to", "is the
 * database reachable", "which PHP is this" without needing a shell. Open to
 * everybody because a broken install has to be diagnosable by whoever can see
 * it, and it deliberately reports states rather than paths.
 */
function healthCheck(array $config, PDO $pdo, bool $signedIn): array
{
    $dataDir = dirname((string)$config['database']);
    $scanRoot = (string)$config['scan_root'];

    $checks = [
        'php_version' => PHP_VERSION,
        'php_new_enough' => PHP_VERSION_ID >= 80100,
        'sqlite' => in_array('sqlite', PDO::getAvailableDrivers(), true),
        'data_dir_writable' => is_dir($dataDir) && is_writable($dataDir),
        'database_writable' => is_file($config['database'])
            ? is_writable($config['database'])
            : (is_dir($dataDir) && is_writable($dataDir)),
        'scan_root_readable' => is_dir($scanRoot) && is_readable($scanRoot),
        'schema_version' => (int)$pdo->query(
            'SELECT COALESCE(MAX(version), 0) FROM schema_version'
        )->fetchColumn(),
        'mail_available' => function_exists('mail'),
        'shell_available' => function_exists('shell_exec')
            && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true),
        'proc_readable' => @is_readable('/proc/stat'),
        'diskstats_readable' => @is_readable('/proc/diskstats'),
        'io_pressure_available' => @is_readable('/proc/pressure/io'),
        /*
         * The dashboard's own markup, checked against its own policy.
         *
         * .htaccess sends default-src 'self' with no style-src, so a single
         * style="" attribute in index.html is blocked outright - the rule does
         * not apply, the console fills with violations, and the only symptom is
         * something looking slightly wrong. Cheap to check, and it fails
         * silently otherwise.
         */
        'no_inline_styles' => !preg_match(
            '/\sstyle\s*=\s*["\']/i',
            (string)@file_get_contents(__DIR__ . '/index.html')
        ),
        // Always available, and the thing people actually want to see when a
        // kernel feature is missing.
        'kernel' => php_uname('r'),
        'problem_log_entries' => count(recentProblems(problemLogPath($config), 200)),
    ];

    // Why, not just whether. A row saying "needs kernel 4.20+ with CONFIG_PSI"
    // leaves the reader to go and find out which of those they are missing,
    // which is the work this page exists to save.
    $checks['io_pressure_reason'] = pressureDiagnosis();
    $checks['diskstats_reason'] = diskstatsDiagnosis();

    // Only somebody signed in gets told where any of it is.
    if ($signedIn) {
        $checks['data_dir'] = $dataDir;
        $checks['problem_log'] = problemLogPath($config);
        $checks['php_error_log'] = ini_get('error_log') ?: '(PHP is not configured with one)';
        $checks['php_binary_guess'] = PHP_BINDIR . '/php';
    }

    return ['checks' => $checks];
}

function problemLogPath(array $config): string
{
    return dirname((string)$config['database']) . '/error.log';
}

/**
 * The dashboard's own error log.
 *
 * Signed in only: the entries name file paths, class names and sometimes SQL,
 * which is the whole reason they are not sent to the browser in the first
 * place.
 */
function problemLog(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to read the problem log');
    }

    $grouped = groupedProblems(problemLogPath($config));

    return [
        // realpath, so it is not shown once as data/error.log and again as
        // lib/../data/error.log and read as two different files.
        'path' => realpath(problemLogPath($config)) ?: problemLogPath($config),
        'rows' => $grouped['rows'],
        'lines' => $grouped['lines'],
    ];
}

/**
 * Empties the problem log.
 *
 * Destructive, so it goes through the same door as everything else that is:
 * POST, signed in, CSRF checked. Recorded in the audit log, because "the errors
 * disappeared" should have an explanation somewhere that is not the errors.
 */
/**
 * Switches monitoring of one service on or off.
 *
 * Written to the account database, which is the only one the dashboard can
 * write - host.db belongs to root. The collector reads the choice back on its
 * next run, so the effect is not instant and the dashboard says so rather than
 * pretending the row changed.
 */
/**
 * Asks the root collector to walk the disk on its next run.
 *
 * It cannot run anything as root and does not pretend to. The request is left
 * in the account database, the collector reads it within a few minutes, and the
 * answer is that it says so plainly rather than spinning as if something is
 * happening now.
 */
/**
 * Probes what this account can run, now, and stores the answer.
 *
 * Runs on demand rather than on a schedule: the answer changes when somebody
 * installs something, which is not a thing to poll for. Versions are optional
 * because asking forty programs their version means running forty programs.
 */
function doCheckCapabilities(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    $withVersions = ($_POST['versions'] ?? '1') !== '0';
    $probe = probeCapabilities($config, null, $withVersions, true);
    $changes = storeCapabilities($pdo, $probe);

    logAction($pdo, 'check_capabilities', 'account', 'ok', 0, 0,
        sprintf('%d programs in %ss', count($probe['rows']), $probe['seconds']));

    return [
        'ok' => true,
        'seconds' => $probe['seconds'],
        'total' => count($probe['rows']),
        'changes' => $changes,
    ];
}

/**
 * Queues something for the root collector: a capability sweep, or exposing one
 * program to this account.
 */
function doRequestHostTask(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    $task = (string)($_POST['task'] ?? '');
    $program = trim((string)($_POST['program'] ?? ''));

    if ($task === 'capabilities') {
        $at = requestHostTask($pdo, 'capabilities');
        logAction($pdo, 'request_host_task', 'capabilities', 'ok', 0, 0, 'queued');
        return ['ok' => true, 'task' => 'capabilities', 'requested_at' => $at];
    }

    if ($task === 'expose') {
        /*
         * Only a program the catalogue knows about.
         *
         * This name ends up in a command the root collector runs, so it is
         * checked against a fixed list rather than merely pattern-matched -
         * the pattern is the second line of defence, not the first.
         */
        $known = array_column(capabilityList($config), 'name');
        if (!in_array($program, $known, true)) {
            throw new ActionRefused('not a program this monitor knows about');
        }

        $at = requestHostTask($pdo, 'expose:' . $program);
        logAction($pdo, 'request_host_task', 'expose:' . $program, 'ok', 0, 0, 'queued');
        return ['ok' => true, 'task' => 'expose', 'program' => $program, 'requested_at' => $at];
    }

    throw new ActionRefused('unknown task');
}

function doRequestHostWalk(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    if (!($config['host']['enabled'] ?? true)) {
        throw new ActionRefused('host collection is turned off in the config');
    }

    $at = requestHostWalk($pdo, 'dashboard');
    logAction($pdo, 'request_disk_walk', 'host', 'ok', 0, 0, 'queued for the next host run');

    return ['ok' => true, 'requested_at' => $at];
}

function doDaemonWatch(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    $name = (string)($_POST['name'] ?? '');
    $watched = (string)($_POST['watched'] ?? '') === '1';

    /*
     * Only a service that is actually defined.
     *
     * Otherwise this is a free write of arbitrary strings into the database by
     * anyone with a session, and the table would fill with names nothing will
     * ever read.
     */
    $known = array_column(daemonDefinitions($config), 'name');
    if (!in_array($name, $known, true)) {
        throw new ActionRefused('no such service');
    }

    setDaemonWatch($pdo, $name, $watched);
    logAction($pdo, 'daemon_watch', $name, 'ok', 0, 0, $watched ? 'monitored' : 'not monitored');

    return ['ok' => true, 'name' => $name, 'watched' => $watched];
}

/**
 * Marks alerts read, or removes them.
 *
 * Two different things and both are wanted. Acknowledging stops an alert
 * counting towards the badge while leaving it in the history - which is what
 * you want for something you have seen and dealt with. Deleting is for the
 * ones that were noise, and it is per-row rather than a "clear all" because
 * clearing everything is how a real alert gets swept away with the rest.
 */
function doAlertAction(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    $what = (string)($_POST['what'] ?? '');
    $ids = array_values(array_filter(
        array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))),
        fn(int $id): bool => $id > 0
    ));

    if ($ids === [] && $what !== 'ack-all') {
        throw new ActionRefused('no alerts named');
    }

    if ($what === 'ack-all') {
        $stmt = $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE acknowledged_at IS NULL');
        $stmt->execute([time()]);
        logAction($pdo, 'ack_alerts', 'all', 'ok', 0, 0, $stmt->rowCount() . ' marked read');
        return ['ok' => true, 'changed' => $stmt->rowCount()];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));

    if ($what === 'ack') {
        $stmt = $pdo->prepare("UPDATE alerts SET acknowledged_at = ? WHERE id IN ($marks)");
        $stmt->execute([time(), ...$ids]);
        logAction($pdo, 'ack_alerts', implode(',', $ids), 'ok', 0, 0, 'marked read');
        return ['ok' => true, 'changed' => $stmt->rowCount()];
    }

    if ($what === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM alerts WHERE id IN ($marks)");
        $stmt->execute($ids);
        logAction($pdo, 'delete_alerts', implode(',', $ids), 'ok', 0, 0,
            $stmt->rowCount() . ' removed');
        return ['ok' => true, 'changed' => $stmt->rowCount()];
    }

    throw new ActionRefused('unknown alert action');
}

function doClearProblems(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    $path = problemLogPath($config);
    $removed = clearProblems($path);
    logAction($pdo, 'clear_problems', $path, 'ok', 0, $removed, "$removed entries");

    return ['ok' => true, 'removed' => $removed];
}

function sessionState(array $config): array
{
    return [
        'signed_in' => isSignedIn(),
        'csrf' => isSignedIn() ? csrfToken() : null,
        'configured' => ($config['password_hash'] ?? '') !== '',
    ];
}

/* ----------------------------------------------------------------- write -- */

function doSignIn(array $config, bool $post, PDO $pdo): array
{
    if (!$post) {
        throw new ActionRefused('sign in must be a POST');
    }
    if (!signIn($config, (string)($_POST['password'] ?? ''))) {
        /*
         * A failed attempt is recorded; the password is not.
         *
         * Repeated failures from one address are the only sign this dashboard
         * can give that somebody is guessing, and they are worth having. What
         * is logged is the fact and the address - never the string tried, which
         * is very often somebody's password for something else.
         */
        logAction($pdo, 'sign_in', null, 'refused', 0, 0, 'wrong password');

        // One message for every failure, so it cannot be used to learn whether
        // a password has been configured at all.
        http_response_code(401);
        return ['error' => 'Incorrect password.'];
    }

    logAction($pdo, 'sign_in', null, 'ok', 0, 0, null);
    return ['signed_in' => true, 'csrf' => csrfToken()];
}

function doSignOut(PDO $pdo): array
{
    // Only worth a line if there was a session to end.
    if (isSignedIn()) {
        logAction($pdo, 'sign_out', null, 'ok', 0, 0, null);
    }
    signOut();
    return ['signed_in' => false];
}

/** Everything destructive shares these checks. */
function requireWrite(bool $post): void
{
    if (!$post) {
        throw new ActionRefused('that must be a POST');
    }
    if (!isSignedIn()) {
        throw new ActionRefused('sign in first');
    }
    if (!csrfValid($_POST['csrf'] ?? null)) {
        throw new ActionRefused('stale form token; reload and try again');
    }
}

/**
 * Removes a directory and everything under it.
 *
 * Its own action rather than a flag on delete-file, so that nothing can turn a
 * single-file delete into a recursive one by changing one parameter - and so
 * that the confirmation the interface shows is attached to the act it is
 * actually confirming.
 */
function doDeleteDir(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);
    return ['ok' => true] + deleteDirectory($config, $pdo, (string)($_POST['path'] ?? ''));
}

function doDeleteFile(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);
    $result = deleteFile($config, $pdo, (string)($_POST['path'] ?? ''));
    return ['ok' => true] + $result;
}

function doEmpty(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);
    $result = emptyCategory($config, $pdo, (string)($_POST['category'] ?? ''));
    return ['ok' => true] + $result;
}

/**
 * Which processes a command-line pattern would hit.
 *
 * Changes nothing, but goes through the same door as the things that do: a POST
 * so a regular expression does not end up in the access log of every proxy
 * between here and the browser, and signed in because it returns command lines.
 */
function doMatchProcesses(array $config, bool $post): array
{
    requireWrite($post);
    return matchProcesses($config, (string)($_POST['pattern'] ?? ''));
}

function doStopProcess(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);

    /*
     * The name and age are what the caller had on screen, and they are required.
     *
     * Without them this is "signal whatever holds pid 8214 right now", which is
     * a different and much worse thing - see the top of lib/signals.php.
     */
    $result = stopProcess(
        $config,
        $pdo,
        clampInt($_POST['pid'] ?? 0, 0, PHP_INT_MAX),
        (string)($_POST['name'] ?? ''),
        isset($_POST['elapsed']) && $_POST['elapsed'] !== ''
            ? clampInt($_POST['elapsed'], 0, PHP_INT_MAX)
            : null,
        ($_POST['force'] ?? '') === '1'
    );

    return ['ok' => true] + $result;
}

function doStopGroup(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);
    $result = stopGroup(
        $config,
        $pdo,
        (string)($_POST['name'] ?? ''),
        ($_POST['force'] ?? '') === '1'
    );
    return ['ok' => true] + $result;
}

function doStopMatching(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);
    $result = stopMatching(
        $config,
        $pdo,
        (string)($_POST['pattern'] ?? ''),
        ($_POST['force'] ?? '') === '1'
    );
    return ['ok' => true] + $result;
}

/* ----------------------------------------------------------------- utils -- */

/** The requested time window, defaulting to the last day. */
function window(): array
{
    $to = clampInt($_GET['to'] ?? time(), 0, PHP_INT_MAX);
    $from = clampInt($_GET['from'] ?? ($to - 86400), 0, PHP_INT_MAX);
    return $from <= $to ? [$from, $to] : [$to, $from];
}

function clampInt(mixed $value, int $min, int $max): int
{
    $n = is_numeric($value) ? (int)$value : $min;
    return max($min, min($max, $n));
}
