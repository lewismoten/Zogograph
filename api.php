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
        'tail' => tailLog($config, $pdo, $signedIn),
        'schedule' => scheduleRead($signedIn),
        'processes' => processList($config, $pdo, $signedIn),
        'symlinks' => symlinkCheck($config, $signedIn),
        'host' => hostView($config, $signedIn),
        'host-series' => hostSeries($config, $signedIn),
        'uptime' => uptimeView($config, $pdo, $signedIn),
        'health' => healthCheck($config, $pdo, $signedIn),
        'problems' => problemLog($config, $signedIn),
        'session' => sessionState($config),
        'signin' => doSignIn($config, $post),
        'signout' => doSignOut(),
        'delete-file' => doDeleteFile($config, $pdo, $post),
        'empty' => doEmpty($config, $pdo, $post),
        'schedule-save' => doScheduleSave($post),
        'scan-now' => doScanNow($pdo, $post),
        'test-email' => doTestEmail($config, $pdo, $post),
        'clear-problems' => doClearProblems($config, $pdo, $post),
        'match-processes' => doMatchProcesses($config, $post),
        'stop-process' => doStopProcess($config, $pdo, $post),
        'stop-group' => doStopGroup($config, $pdo, $post),
        'stop-matching' => doStopMatching($config, $pdo, $post),
        default => throw new ActionRefused('unknown action'),
    };
}

/* ------------------------------------------------------------------ read -- */

function overview(array $config, PDO $pdo, bool $signedIn): array
{
    $vitals = $pdo->query('SELECT * FROM vitals ORDER BY taken_at DESC, id DESC LIMIT 1')->fetch() ?: null;

    $scan = $pdo->query(
        'SELECT * FROM scans WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1'
    )->fetch() ?: null;

    $running = $pdo->query(
        'SELECT id, started_at FROM scans WHERE finished_at IS NULL ORDER BY id DESC LIMIT 1'
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
        'scan_running' => $running && (time() - (int)$running['started_at']) < 3600
            ? ['started_at' => (int)$running['started_at']]
            : null,
        'totals' => $totals,
        'open_alerts' => (int)$pdo->query(
            'SELECT COUNT(*) FROM alerts WHERE raised_at >= ' . (time() - 86400)
        )->fetchColumn(),
        'deletable' => $config['deletable_categories'] ?? [],
        // So the dashboard builds its filters and its chart from the rules that
        // are actually in force, rather than a copy of them that goes stale.
        'categories' => categoryNames($config['scan']),
        'viewable' => $config['viewable_categories'] ?? [],
        'server_time' => time(),
    ];
}

/** The vitals series for a window, thinned so a long range is not a huge body. */
function vitalsSeries(PDO $pdo): array
{
    [$from, $to] = window();
    $limit = clampInt($_GET['limit'] ?? 720, 10, 5000);

    $count = $pdo->prepare('SELECT COUNT(*) FROM vitals WHERE taken_at BETWEEN ? AND ?');
    $count->execute([$from, $to]);
    $total = (int)$count->fetchColumn();

    /*
     * Every nth row rather than an average.
     *
     * A dashboard chart is read for shape and for spikes, and averaging is
     * exactly the operation that removes spikes - the ten seconds when memory
     * went through the roof is the thing you came to look at.
     */
    $step = max(1, (int)ceil($total / $limit));

    $stmt = $pdo->prepare(
        'SELECT * FROM (
             SELECT *, ROW_NUMBER() OVER (ORDER BY taken_at) AS rn
             FROM vitals WHERE taken_at BETWEEN :from AND :to
         ) WHERE rn % :step = 0 ORDER BY taken_at'
    );
    // Integers, for the reason set out in lib/alerts.php: a bound string
    // compared against a computed expression never matches in SQLite.
    $stmt->bindValue(':from', $from, PDO::PARAM_INT);
    $stmt->bindValue(':to', $to, PDO::PARAM_INT);
    $stmt->bindValue(':step', $step, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    /*
     * The trend is computed here, over exactly the rows being returned.
     *
     * Not separately in the browser: the card and the chart have to be talking
     * about the same window, or changing the range moves the line and leaves
     * the projection saying something else.
     */
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
        'rows' => $rows,
        'uptime' => $uptime,
        'trend' => [
            'disk' => seriesTrend($rows, 'disk_free_bytes'),
            'inodes' => seriesTrend($rows, 'inodes_free'),
        ],
    ];
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

    return ['rows' => $stmt->fetchAll(), 'categories' => $categories];
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
    $category = $_GET['category'] ?? null;
    $kind = $_GET['kind'] ?? null;
    $sort = ($_GET['sort'] ?? 'bytes') === 'growth' ? 'growth' : 'bytes';
    $limit = clampInt($_GET['limit'] ?? 25, 1, 200);

    $scans = $pdo->query('SELECT id FROM scans WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 2')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$scans) {
        return ['rows' => [], 'scan_id' => null];
    }

    $where = ['now.scan_id = :now'];
    $params = [':now' => $scans[0], ':before' => $scans[1] ?? -1, ':limit' => $limit];
    if ($category !== null && $category !== '') {
        $where[] = 'p.category = :category';
        $params[':category'] = $category;
    }
    if ($kind === 'file' || $kind === 'dir') {
        $where[] = 'p.kind = :kind';
        $params[':kind'] = $kind;
    }

    $order = $sort === 'growth' ? 'growth DESC, now.bytes DESC' : 'now.bytes DESC';

    $stmt = $pdo->prepare(
        'SELECT p.id AS path_id, p.path, p.kind, p.category,
                now.bytes, now.files, now.links,
                COALESCE(now.bytes - before.bytes, 0) AS growth,
                COALESCE(now.files - before.files, 0) AS growth_files
         FROM path_sizes now
         JOIN paths p ON p.id = now.path_id
         LEFT JOIN path_sizes before ON before.path_id = now.path_id AND before.scan_id = :before
         WHERE ' . implode(' AND ', $where) . "
         ORDER BY $order LIMIT :limit"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = array_map(
        fn(array $row): array => presentPath($config, $row, $signedIn),
        $stmt->fetchAll()
    );

    return ['rows' => $rows, 'scan_id' => (int)$scans[0], 'sort' => $sort];
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
                a.emailed_at, a.path_id, p.path, p.kind
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
function actionLog(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the action log');
    }
    return ['rows' => $pdo->query('SELECT * FROM actions ORDER BY at DESC LIMIT 100')->fetchAll()];
}

/**
 * The end of a log file.
 *
 * Signed in only, and only for the categories the config marks readable. The
 * reader never loads the file - see lib/logs.php - which is the difference
 * between this working on a ten gigabyte error log and taking the site down.
 */
function tailLog(array $config, PDO $pdo, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to read log files');
    }

    $pathId = clampInt($_GET['path_id'] ?? 0, 1, PHP_INT_MAX);
    $stmt = $pdo->prepare('SELECT id, path, kind, category FROM paths WHERE id = ?');
    $stmt->execute([$pathId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new ActionRefused('unknown path');
    }

    $real = resolveViewable($config, (string)$row['path']);
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
        'recorded' => recordedProcesses($pdo, 8),
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
function hostView(array $config, bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see host readings');
    }

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
function scheduleRead(bool $signedIn): array
{
    if (!$signedIn) {
        throw new ActionRefused('sign in to see the schedule');
    }
    return scheduleState(__DIR__);
}

function doScheduleSave(bool $post): array
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
        throw new ActionRefused($result['reason'] ?? 'Could not start the scan.');
    }

    return ['ok' => true, 'started' => true];
}

function doTestEmail(array $config, PDO $pdo, bool $post): array
{
    requireWrite($post);
    return sendTestEmail($config, $pdo);
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

function doSignIn(array $config, bool $post): array
{
    if (!$post) {
        throw new ActionRefused('sign in must be a POST');
    }
    if (!signIn($config, (string)($_POST['password'] ?? ''))) {
        // One message for every failure, so it cannot be used to learn whether
        // a password has been configured at all.
        http_response_code(401);
        return ['error' => 'Incorrect password.'];
    }
    return ['signed_in' => true, 'csrf' => csrfToken()];
}

function doSignOut(): array
{
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
