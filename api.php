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
    return [
        'from' => $from,
        'to' => $to,
        'total' => $total,
        'step' => $step,
        'rows' => $rows,
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
                now.bytes, now.files,
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
        'problem_log_entries' => count(recentProblems(problemLogPath($config), 200)),
    ];

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

    return [
        'path' => problemLogPath($config),
        'rows' => recentProblems(problemLogPath($config), 80),
    ];
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
