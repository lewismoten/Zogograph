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

$configFile = __DIR__ . '/lib/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not configured. Copy lib/config.sample.php to lib/config.php.']);
    exit;
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'UTC');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mask.php';
require_once __DIR__ . '/lib/alerts.php';
require_once __DIR__ . '/lib/collect.php';
require_once __DIR__ . '/lib/actions.php';

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
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    // The message can name paths and SQL. It goes to the log, not the browser.
    error_log('[diskmon] ' . $e->getMessage());
    echo json_encode(['error' => 'Request failed. See the server error log.']);
}

function route(array $config, PDO $pdo, string $action, bool $post): array
{
    $signedIn = isSignedIn();

    return match ($action) {
        'overview' => overview($config, $pdo, $signedIn),
        'vitals' => vitalsSeries($pdo),
        'sample' => liveSample($config, $pdo),
        'scans' => scanSeries($pdo),
        'offenders' => offenders($config, $pdo, $signedIn),
        'history' => pathHistory($config, $pdo, $signedIn),
        'alerts' => alertList($config, $pdo, $signedIn),
        'actions' => actionLog($config, $pdo, $signedIn),
        'session' => sessionState($config),
        'signin' => doSignIn($config, $post),
        'signout' => doSignOut(),
        'delete-file' => doDeleteFile($config, $pdo, $post),
        'empty' => doEmpty($config, $pdo, $post),
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
        'totals' => $totals,
        'open_alerts' => (int)$pdo->query(
            'SELECT COUNT(*) FROM alerts WHERE raised_at >= ' . (time() - 86400)
        )->fetchColumn(),
        'deletable' => $config['deletable_categories'] ?? [],
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

    return ['from' => $from, 'to' => $to, 'total' => $total, 'step' => $step, 'rows' => $stmt->fetchAll()];
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

function scanSeries(PDO $pdo): array
{
    [$from, $to] = window();
    $stmt = $pdo->prepare(
        'SELECT s.id, s.started_at, s.finished_at, s.duration_ms, s.files_seen, s.dirs_seen, s.bytes_seen,
                MAX(CASE WHEN t.category = \'trash\' THEN t.bytes END) AS trash_bytes,
                MAX(CASE WHEN t.category = \'logs\'  THEN t.bytes END) AS logs_bytes,
                MAX(CASE WHEN t.category = \'temp\'  THEN t.bytes END) AS temp_bytes
         FROM scans s LEFT JOIN scan_totals t ON t.scan_id = s.id
         WHERE s.finished_at IS NOT NULL AND s.started_at BETWEEN ? AND ?
         GROUP BY s.id ORDER BY s.started_at'
    );
    $stmt->execute([$from, $to]);
    return ['rows' => $stmt->fetchAll()];
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
