<?php
declare(strict_types=1);

/*
 * Running one tool, safely.
 *
 * Every function here is a fixed statement with bound parameters. The model
 * supplies values that have already been checked against an enum or a numeric
 * range by aiCheckArguments; nothing it says ever becomes SQL, a column name, a
 * table name, or a fragment of either.
 *
 * That is not caution about a hostile model. It is that a language model
 * producing SQL will eventually produce SQL that is subtly not what anybody
 * meant - a join that multiplies rows, a missing bound, a comparison against
 * the wrong column - and the answer will look completely plausible. On a
 * database that holds a machine's history, being confidently wrong is the
 * failure worth designing against.
 *
 * What comes back is shaped for a model to read rather than for a page to
 * draw: small, flat, with units named and times as both a timestamp and a
 * phrase. And every reply that could be stale says when it was measured,
 * because a model given a number with no age will present it as current.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/spans.php';
require_once __DIR__ . '/aitools.php';
require_once __DIR__ . '/logstream.php';
require_once __DIR__ . '/logwatch.php';
require_once __DIR__ . '/aicharts.php';
require_once __DIR__ . '/aimemory.php';
require_once __DIR__ . '/terminal.php';
require_once __DIR__ . '/hostdb.php';

/** How much text one tool may return. Beyond this the reply is trimmed and says so. */
const AI_RESULT_MAX = 12000;

/**
 * Runs one server-side tool.
 *
 * Returns a structure to be handed back to the model as the tool's result.
 * Errors are returned rather than thrown: a tool that failed is information the
 * model should have and reason about, not something that should end the turn.
 *
 * @return array<string, mixed>
 */
function aiRunTool(
    string $name,
    array $values,
    array $config,
    PDO $pdo,
    ?PDO $ai = null,
    int $session = 0
): array {
    try {
        $result = match ($name) {
            'get_current_status' => aiCurrentStatus($config, $pdo),
            'get_measurement_history' => aiMeasurementHistory($pdo, $values),
            'get_recent_alerts' => aiRecentAlerts($pdo, $values),
            'get_recent_log_lines' => aiRecentLogLines($config, $pdo, $values),
            'get_repeated_log_lines' => aiRepeatedLogLines($config, $pdo, $values),
            'find_largest_paths' => aiLargestPaths($config, $pdo, $values),
            'find_crowded_directories' => aiCrowdedDirectories($config, $pdo, $values),
            'get_busiest_processes' => aiBusiestProcesses($pdo, $values),
            'get_site_check_results' => aiSiteChecks($pdo, $values),
            'get_network_summary' => aiNetworkSummary($config, $pdo),
            'get_collector_health' => aiCollectorHealth($config, $pdo),
            'get_recent_changes' => aiRecentChanges($pdo, $values),
            'get_action_history' => aiActionHistory($pdo, $values),
            'get_chart_data' => aiChartData($pdo, (string)$values['chart'],
                (int)$values['hours']),
            /*
             * The three that need the assistant's own database rather than the
             * account's - what it remembers, and what it has asked somebody to
             * type. They are given it explicitly rather than opening it here,
             * so this file still has exactly one connection to reason about.
             */
            'what_the_terminal_can_run' => aiTerminalTools($config),
            'what_the_host_can_be_asked' => aiHostJobs($config, $pdo),
            'get_pending_host_requests' => aiPendingHostRequests($config, $pdo),
            'get_collection_schedule' => aiCronRhythm($config, $pdo),
            'remember' => aiToolRemember($ai, $session, $values),
            'recall' => aiToolRecall($ai, $values),
            'recommend_command' => aiToolRecommend($values),
            default => ['error' => 'That tool does not run on the server.'],
        };
    } catch (Throwable $e) {
        /*
         * The message, not the trace.
         *
         * A stack trace names paths and function names that are of no use to
         * the model and would be sent to somebody else's service.
         */
        return ['error' => 'That tool failed: ' . $e->getMessage()];
    }

    return $result;
}

/** A moment, said both ways, because a model needs the number and the reader needs the phrase. */
function aiWhen(?int $at): ?array
{
    if ($at === null || $at <= 0) {
        return null;
    }

    return [
        'at' => $at,
        'said' => date('Y-m-d H:i:s T', $at),
        'ago' => plainSpan(time() - $at) . ' ago',
    ];
}

function aiCurrentStatus(array $config, PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM vitals ORDER BY taken_at DESC, id DESC LIMIT 1')->fetch();

    if ($row === false) {
        return ['error' => 'No readings have been collected yet, so there is nothing to '
            . 'report. That itself may be the problem - check get_collector_health.'];
    }

    $disk = $row['disk_total_bytes']
        ? round(($row['disk_used_bytes'] / $row['disk_total_bytes']) * 100, 1)
        : null;
    $inodes = $row['inodes_total']
        ? round(($row['inodes_used'] / $row['inodes_total']) * 100, 1)
        : null;
    $cores = (int)($row['cpu_cores'] ?? 0);

    return [
        'measured' => aiWhen((int)$row['taken_at']),
        'disk' => [
            'used_percent' => $disk,
            'free' => $row['disk_free_bytes'] === null
                ? null : humanBytes((int)$row['disk_free_bytes']),
            'total' => $row['disk_total_bytes'] === null
                ? null : humanBytes((int)$row['disk_total_bytes']),
            'free_bytes' => $row['disk_free_bytes'] === null
                ? null : (int)$row['disk_free_bytes'],
        ],
        'inodes' => [
            'used_percent' => $inodes,
            'free' => $row['inodes_free'] === null ? null : (int)$row['inodes_free'],
        ],
        'cpu' => [
            'percent_of_account_allowance' => $row['cpu_percent'] === null
                ? null : round((float)$row['cpu_percent'], 1),
            'cores_visible' => $cores ?: null,
            'note' => 'This is a percentage of what this ACCOUNT may use, not of the '
                . 'machine. Inside CageFS it can be at 100 while the machine is idle.',
        ],
        'memory' => [
            'used_percent' => $row['mem_used_percent'] === null
                ? null : round((float)$row['mem_used_percent'], 1),
            'available' => $row['mem_available_bytes'] === null
                ? null : humanBytes((int)$row['mem_available_bytes']),
        ],
        'load' => [
            'one_minute' => $row['load1'] === null ? null : (float)$row['load1'],
            'per_core_percent' => ($row['load1'] !== null && $cores > 0)
                ? round(((float)$row['load1'] / $cores) * 100, 1)
                : null,
        ],
        'uptime' => $row['boot_at']
            ? ['booted' => aiWhen((int)$row['boot_at']),
               'up_for' => plainSpan(time() - (int)$row['boot_at'], true)]
            : null,
    ];
}

/**
 * One measurement over a period, summarised into buckets.
 *
 * Buckets rather than raw rows: a month of readings is eight thousand numbers,
 * which would fill the context window and tell the model less than twenty
 * would. Each bucket carries its worst value as well as its average, because
 * the average of an hour containing a two-minute spike hides the spike, and the
 * spike is what somebody is looking for.
 */
function aiMeasurementHistory(PDO $pdo, array $values): array
{
    $columns = [
        'disk_used_percent' => ['disk_used_bytes', 'disk_total_bytes'],
        'inodes_used_percent' => ['inodes_used', 'inodes_total'],
        'cpu_percent' => ['cpu_percent', null],
        'memory_percent' => ['mem_used_percent', null],
        'load_per_core' => ['load1', 'cpu_cores'],
        'disk_free_bytes' => ['disk_free_bytes', null],
    ];

    $which = $values['measurement'];
    if (!isset($columns[$which])) {
        return ['error' => 'Unknown measurement.'];
    }

    [$top, $bottom] = $columns[$which];
    $since = time() - ((int)$values['hours'] * SPAN_HOUR);

    /*
     * The column names come from the table above, never from the model.
     *
     * $which has already been checked against the enum, so this can only ever
     * be one of six literal strings written in this file.
     */
    $expression = $bottom === null
        ? sprintf('CAST("%s" AS REAL)', $top)
        : sprintf('CASE WHEN "%s" > 0 THEN (CAST("%s" AS REAL) / "%s") * 100 END',
            $bottom, $top, $bottom);

    if ($which === 'disk_free_bytes') {
        $expression = 'CAST("disk_free_bytes" AS REAL)';
    }

    $stmt = $pdo->prepare(sprintf(
        'SELECT MIN(taken_at) AS from_at, MAX(taken_at) AS to_at, COUNT(*) AS readings,
                MIN(%1$s) AS low, MAX(%1$s) AS high, AVG(%1$s) AS mean,
                (taken_at / :bucket) AS slot
           FROM vitals
          WHERE taken_at >= :since AND %1$s IS NOT NULL
          GROUP BY slot ORDER BY slot',
        $expression
    ));

    // Twenty-odd buckets whatever the span, which is as many as a model can
    // usefully reason about and few enough to read.
    $bucket = max(60, (int)floor(((int)$values['hours'] * SPAN_HOUR) / 24));
    $stmt->execute([':since' => $since, ':bucket' => $bucket]);
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        return [
            'measurement' => $which,
            'points' => [],
            'note' => 'No readings in that period. Either the collector was not running - '
                . 'check get_collector_health - or the period is older than the retention '
                . 'settings keep at this resolution.',
        ];
    }

    $points = array_map(fn(array $row): array => [
        'from' => date('Y-m-d H:i', (int)$row['from_at']),
        'to' => date('Y-m-d H:i', (int)$row['to_at']),
        'low' => round((float)$row['low'], 2),
        'high' => round((float)$row['high'], 2),
        'average' => round((float)$row['mean'], 2),
        'readings' => (int)$row['readings'],
    ], $rows);

    $first = $points[0];
    $last = $points[count($points) - 1];

    return [
        'measurement' => $which,
        'unit' => $which === 'disk_free_bytes' ? 'bytes' : 'percent',
        'covering' => sprintf('%s to %s', $first['from'], $last['to']),
        'bucket' => plainSpan($bucket),
        'points' => $points,
        'moved_by' => round($last['average'] - $first['average'], 2),
        'note' => 'Each point is a bucket. "high" is the worst moment in it, which is what '
            . 'a spike looks like - an average would hide one.',
    ];
}

function aiRecentAlerts(PDO $pdo, array $values): array
{
    $since = time() - ((int)($values['hours'] ?? 168) * SPAN_HOUR);
    $severity = (string)($values['severity'] ?? 'any');
    $limit = (int)($values['limit'] ?? 20);

    if ($severity === 'any') {
        $stmt = $pdo->prepare(
            'SELECT raised_at, rule, severity, subject, detail, acknowledged_at
               FROM alerts WHERE raised_at >= ? ORDER BY raised_at DESC LIMIT ' . $limit
        );
        $stmt->execute([$since]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT raised_at, rule, severity, subject, detail, acknowledged_at
               FROM alerts WHERE raised_at >= ? AND severity = ?
              ORDER BY raised_at DESC LIMIT ' . $limit
        );
        $stmt->execute([$since, $severity]);
    }

    $rows = array_map(fn(array $row): array => [
        'when' => aiWhen((int)$row['raised_at']),
        'severity' => (string)$row['severity'],
        'rule' => (string)$row['rule'],
        'subject' => (string)$row['subject'],
        'detail' => mb_substr((string)$row['detail'], 0, 400),
        'read' => $row['acknowledged_at'] !== null,
    ], $stmt->fetchAll());

    return [
        'alerts' => $rows,
        'count' => count($rows),
        'note' => $rows === []
            ? 'No alerts in that period. That means the monitor has not noticed anything, '
              . 'which is not the same as nothing being wrong - it only watches what it '
              . 'has rules for.'
            : 'These are the monitor\'s own conclusions, not log lines from the server.',
    ];
}

function aiRecentLogLines(array $config, PDO $pdo, array $values): array
{
    $files = aiReadableLogs($config, $pdo);

    if ($files === []) {
        return ['error' => 'No log files are readable by this account.'];
    }

    $wanted = (int)($values['limit'] ?? 25);
    $level = (string)($values['level'] ?? 'any');
    $contains = (string)($values['contains'] ?? '');

    // Over-fetched, because filtering happens after parsing.
    $stream = logStream($files, min(200, max($wanted * 6, 60)));

    $rank = ['critical' => 4, 'error' => 3, 'warning' => 2, 'notice' => 1, 'info' => 1];
    $floor = ['any' => 0, 'warning' => 2, 'error' => 3, 'critical' => 4][$level] ?? 0;

    $out = [];
    foreach ($stream['rows'] as $row) {
        $parsed = $row['parsed'] ?? [];
        $kind = (string)($parsed['kind'] ?? '');

        if ($floor > 0 && ($rank[$kind] ?? 0) < $floor) {
            continue;
        }
        if ($contains !== '' && !str_contains($row['text'], $contains)) {
            continue;
        }

        $entry = [
            'when' => $row['at'] ? aiWhen((int)$row['at']) : null,
            'file' => (string)$row['label'],
            'level' => $kind ?: 'none stated',
            'written_by' => (string)($parsed['where'] ?? ''),
            'message' => (string)($parsed['message'] ?? $row['text']),
        ];

        if (isset($parsed['file'])) {
            $entry['in'] = $parsed['file'] . ':' . ($parsed['line'] ?? '?');
        }
        if (!empty($row['trace'])) {
            // The first few frames only: the tail of a trace is framework
            // plumbing and costs tokens to say nothing.
            $entry['stack_trace'] = array_slice($row['trace'], 0, 8);
        }

        $out[] = $entry;
        if (count($out) >= $wanted) {
            break;
        }
    }

    return [
        'lines' => $out,
        'count' => count($out),
        'searched' => count($stream['rows']) . ' recent lines across '
            . count(array_filter($stream['files'], fn(array $f): bool => (bool)$f['ok']))
            . ' files',
        'note' => $out === []
            ? 'Nothing matched. Either the logs are quiet or the filter is too narrow - '
              . 'try again with level "any" and no "contains".'
            : 'Stack traces are attached to the error they belong to and trimmed to the '
              . 'first few frames.',
    ];
}

function aiRepeatedLogLines(array $config, PDO $pdo, array $values): array
{
    $files = aiReadableLogs($config, $pdo);
    if ($files === []) {
        return ['error' => 'No log files are readable by this account.'];
    }

    $stream = logStream($files, 200);
    $groups = repeatedLines($stream['rows'], (int)($values['limit'] ?? 10));

    return [
        'groups' => array_map(fn(array $group): array => [
            'times' => (int)$group['count'],
            'shape' => mb_substr((string)$group['shape'], 0, 200),
            'example' => mb_substr((string)$group['example'], 0, 300),
            'files' => $group['files'] ?? [],
        ], $groups['groups'] ?? []),
        'note' => 'Numbers, addresses and paths are replaced with placeholders before '
            . 'grouping, so lines differing only in a request id count as one event.',
    ];
}

/**
 * Every log this account may read, as logStream wants them.
 *
 * logCandidates returns what the scanner found and classified as a log, which
 * is not the same as what can be opened - a scan records paths the account can
 * stat but not necessarily read. Checked here rather than trusted, because an
 * unreadable file in the list produces a confusing "skipped" entry in every
 * reply the model gets.
 */
function aiReadableLogs(array $config, PDO $pdo): array
{
    $files = [];

    foreach (logCandidates($pdo, $config) as $entry) {
        $path = (string)($entry['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            continue;
        }
        $files[] = ['path' => $path, 'label' => basename($path)];
    }

    return $files;
}

function aiLargestPaths(array $config, PDO $pdo, array $values): array
{
    $scans = $pdo->query(
        'SELECT id, finished_at FROM scans WHERE finished_at IS NOT NULL
          ORDER BY id DESC LIMIT 2'
    )->fetchAll();

    if ($scans === []) {
        return ['error' => 'No completed disk scan yet, so there is nothing to report. A '
            . 'scan can be started from the Storage page.'];
    }

    $now = (int)$scans[0]['id'];
    $before = isset($scans[1]) ? (int)$scans[1]['id'] : null;

    $limit = (int)($values['limit'] ?? 15);
    $category = (string)($values['category'] ?? 'any');
    $byGrowth = ($values['sort_by'] ?? 'size') === 'growth';

    if ($byGrowth && $before === null) {
        return ['error' => 'Only one scan has completed, so there is nothing to compare '
            . 'against and no growth to report. Ask again sorted by size.'];
    }

    $bind = [':now' => $now];
    $where = 'now.scan_id = :now';

    if ($category !== 'any') {
        $where .= ' AND p.category = :category';
        $bind[':category'] = $category;
    }

    /*
     * Growth is the difference between two scans, not a stored column.
     *
     * Two fixed statements chosen by a boolean rather than an ORDER BY built
     * from input - the sort direction is the one place an "obviously safe"
     * interpolation creeps in, and there are only two, so both are written out.
     */
    if ($byGrowth) {
        $bind[':before'] = $before;
        $sql =
            "SELECT p.path, p.kind, p.category, now.bytes, now.files,
                    now.bytes - COALESCE(was.bytes, 0) AS grew_by
               FROM path_sizes now
               JOIN paths p ON p.id = now.path_id
               LEFT JOIN path_sizes was
                      ON was.path_id = now.path_id AND was.scan_id = :before
              WHERE {$where}
              ORDER BY grew_by DESC LIMIT {$limit}";
    } else {
        $sql =
            "SELECT p.path, p.kind, p.category, now.bytes, now.files,
                    NULL AS grew_by
               FROM path_sizes now
               JOIN paths p ON p.id = now.path_id
              WHERE {$where}
              ORDER BY now.bytes DESC LIMIT {$limit}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);

    return [
        'scanned' => aiWhen((int)$scans[0]['finished_at']),
        'compared_against' => $byGrowth && isset($scans[1])
            ? aiWhen((int)$scans[1]['finished_at'])
            : null,
        'sorted_by' => $byGrowth ? 'growth since the previous scan' : 'total size',
        'paths' => array_map(fn(array $row): array => [
            'path' => (string)$row['path'],
            'kind' => (string)$row['kind'],
            'category' => $row['category'],
            'size' => humanBytes((int)$row['bytes']),
            'size_bytes' => (int)$row['bytes'],
            'grew_by' => $row['grew_by'] === null
                ? null
                : ((int)$row['grew_by'] >= 0 ? '+' : '-') . humanBytes(abs((int)$row['grew_by'])),
            'files' => $row['files'] === null ? null : (int)$row['files'],
        ], $stmt->fetchAll()),
        'note' => 'These sizes are from the last completed scan, not live. Say when it ran '
            . 'rather than presenting them as current.',
    ];
}

function aiCrowdedDirectories(array $config, PDO $pdo, array $values): array
{
    $scan = $pdo->query(
        'SELECT id, finished_at FROM scans WHERE finished_at IS NOT NULL
          ORDER BY id DESC LIMIT 1'
    )->fetch();

    if ($scan === false) {
        return ['error' => 'No completed disk scan yet.'];
    }

    $limit = (int)($values['limit'] ?? 15);

    $stmt = $pdo->prepare(
        "SELECT p.path, ps.files, ps.bytes
           FROM path_sizes ps JOIN paths p ON p.id = ps.path_id
          WHERE ps.scan_id = ? AND p.kind = 'dir' AND ps.files IS NOT NULL
          ORDER BY ps.files DESC LIMIT {$limit}"
    );
    $stmt->execute([(int)$scan['id']]);

    return [
        'scanned' => aiWhen((int)$scan['finished_at']),
        'directories' => array_map(fn(array $row): array => [
            'path' => (string)$row['path'],
            'files' => (int)$row['files'],
            'size' => humanBytes((int)$row['bytes']),
        ], $stmt->fetchAll()),
        'note' => 'Sorted by number of files, not size. A directory near the top of this '
            . 'list with a tiny size is exactly what exhausts inodes.',
    ];
}

function aiBusiestProcesses(PDO $pdo, array $values): array
{
    $at = $pdo->query('SELECT MAX(taken_at) FROM process_samples')->fetchColumn();

    if ($at === false || $at === null) {
        return ['error' => 'No process readings have been collected. These are only taken '
            . 'when CPU or memory crosses a threshold, so an absence can simply mean '
            . 'nothing has been busy enough to trigger one.'];
    }

    $limit = (int)($values['limit'] ?? 12);

    /*
     * Grouped by program, which is how the collector records them.
     *
     * Twenty PHP workers are one program with twenty processes, and that is the
     * shape worth reporting - a list of twenty near-identical rows says less
     * and costs twenty times the tokens.
     */
    $order = match ($values['sort_by'] ?? 'cpu') {
        'memory' => 'rss_bytes DESC',
        'age' => 'oldest_seconds DESC',
        default => 'cpu_percent DESC',
    };

    $stmt = $pdo->prepare(
        "SELECT name, cpu_percent, mem_percent, rss_bytes, procs,
                oldest_seconds, newest_seconds, reason, trigger_percent
           FROM process_samples WHERE taken_at = ? ORDER BY {$order} LIMIT {$limit}"
    );
    $stmt->execute([(int)$at]);
    $rows = $stmt->fetchAll();

    return [
        'measured' => aiWhen((int)$at),
        'sorted_by' => (string)($values['sort_by'] ?? 'cpu'),
        'why_taken' => $rows === [] ? null : sprintf(
            'This sample was taken because %s crossed %s%%.',
            (string)($rows[0]['reason'] ?? 'something'),
            (string)($rows[0]['trigger_percent'] ?? '?')
        ),
        'programs' => array_map(fn(array $row): array => [
            'program' => (string)$row['name'],
            'processes' => (int)$row['procs'],
            'cpu_percent' => $row['cpu_percent'] === null
                ? null : round((float)$row['cpu_percent'], 1),
            'memory_percent' => $row['mem_percent'] === null
                ? null : round((float)$row['mem_percent'], 1),
            'memory' => $row['rss_bytes'] === null
                ? null : humanBytes((int)$row['rss_bytes']),
            'oldest_running_for' => $row['oldest_seconds'] === null
                ? null : plainSpan((int)$row['oldest_seconds'], true),
            'newest_running_for' => $row['newest_seconds'] === null
                ? null : plainSpan((int)$row['newest_seconds'], true),
        ], $rows),
        'note' => 'A snapshot from the last collector run, not live. A program whose '
            . 'oldest process has been running far longer than its work should take is '
            . 'stuck, whatever its CPU says.',
    ];
}

function aiSiteChecks(PDO $pdo, array $values): array
{
    $hours = (int)($values['hours'] ?? 24);
    $since = time() - ($hours * SPAN_HOUR);

    try {
        $checks = $pdo->query(
            'SELECT id, label, url, enabled, last_at, last_ok, last_status, last_error,
                    interval_seconds
               FROM url_checks ORDER BY label'
        )->fetchAll();
    } catch (Throwable) {
        return ['error' => 'No URL checks are configured.'];
    }

    if ($checks === []) {
        return [
            'checks' => [],
            'note' => 'No URL checks are configured at all. That means nothing here is '
                . 'watching the site from outside - it does NOT mean the site is fine. '
                . 'Suggest adding one.',
        ];
    }

    $summary = $pdo->prepare(
        'SELECT COUNT(*) AS runs, SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS failures,
                AVG(ms) AS mean_ms, MAX(ms) AS worst_ms
           FROM url_results WHERE check_id = ? AND at >= ?'
    );

    $out = [];
    foreach ($checks as $check) {
        $summary->execute([(int)$check['id'], $since]);
        $stats = $summary->fetch() ?: [];

        $out[] = [
            'name' => (string)$check['label'],
            'url' => (string)$check['url'],
            'enabled' => (bool)$check['enabled'],
            'last_checked' => aiWhen($check['last_at'] === null ? null : (int)$check['last_at']),
            'last_result' => $check['last_ok'] === null
                ? 'never checked'
                : ((int)$check['last_ok'] === 1
                    ? 'answered ' . (int)$check['last_status']
                    : ($check['last_error'] ?: 'failed with ' . (int)$check['last_status'])),
            'checks_in_period' => (int)($stats['runs'] ?? 0),
            'failures_in_period' => (int)($stats['failures'] ?? 0),
            'typical_ms' => $stats['mean_ms'] === null ? null : (int)round((float)$stats['mean_ms']),
            'slowest_ms' => $stats['worst_ms'] === null ? null : (int)$stats['worst_ms'],
        ];
    }

    return [
        'period' => 'the last ' . plainSpan($hours * SPAN_HOUR, true),
        'checks' => $out,
        'note' => 'These are made from this machine, so they cannot tell you the site is '
            . 'unreachable from somewhere else.',
    ];
}

function aiNetworkSummary(array $config, PDO $pdo): array
{
    $out = ['note' => 'One address holding hundreds of connections is worth reporting. A '
        . 'long list with one each is ordinary web traffic.'];

    try {
        $host = hostDb($config, false);
    } catch (Throwable) {
        $host = null;
    }

    if ($host === null) {
        return ['error' => 'The host collector\'s database cannot be read from this '
            . 'account, and it is where the network readings live.'];
    }

    $latest = $host->query('SELECT MAX(sample_id) FROM host_ports')->fetchColumn();

    if ($latest === false || $latest === null) {
        /*
         * No ports recorded is not the same as nothing to say.
         *
         * The port and connection readings need `ss`, which is often missing
         * inside a cage, while captures come from the host collector and may
         * well exist. Reporting "no network readings" when there is a capture
         * sitting there would be wrong.
         */
        $out['listening'] = null;
        $out['busiest_peers'] = null;
        $out['why_missing'] = 'No listening-port or connection readings have been '
            . 'collected. That usually means `ss` is not available to the collector, '
            . 'not that nothing is listening.';
    } else {
        $ports = $host->prepare(
            'SELECT port, protocol, process, addresses, public FROM host_ports
              WHERE sample_id = ? ORDER BY port LIMIT 40'
        );
        $ports->execute([(int)$latest]);

        $peers = $host->prepare(
            'SELECT address, connections, private FROM host_peers
              WHERE sample_id = ? ORDER BY connections DESC LIMIT 15'
        );
        $peers->execute([(int)$latest]);

        $out['listening'] = array_map(fn(array $row): array => [
            'port' => (int)$row['port'],
            'protocol' => (string)$row['protocol'],
            'process' => (string)($row['process'] ?? ''),
            'on' => (string)($row['addresses'] ?? ''),
            'reachable_from_outside' => (bool)$row['public'],
        ], $ports->fetchAll());

        $out['busiest_peers'] = array_map(fn(array $row): array => [
            'address' => (string)$row['address'],
            'connections' => (int)$row['connections'],
            'private_address' => (bool)$row['private'],
        ], $peers->fetchAll());
    }

    try {
        $capture = $host->query(
            'SELECT taken_at, interface, filter, seconds, packets, bytes, distinct_peers,
                    in_packets, out_packets, in_bytes, out_bytes, hit_limit
               FROM host_captures WHERE ok = 1 ORDER BY taken_at DESC LIMIT 1'
        )->fetch();

        if ($capture !== false) {
            $seconds = max(1, (int)$capture['seconds']);
            $out['last_capture'] = [
                'taken' => aiWhen((int)$capture['taken_at']),
                'interface' => (string)$capture['interface'],
                'filter' => (string)$capture['filter'],
                'over' => plainSpan($seconds),
                'packets' => (int)$capture['packets'],
                'packets_per_second' => round((int)$capture['packets'] / $seconds, 1),
                'arriving' => humanBytes((int)$capture['in_bytes']),
                'leaving' => humanBytes((int)$capture['out_bytes']),
                'distinct_addresses' => (int)$capture['distinct_peers'],
                'hit_the_ceiling' => (bool)$capture['hit_limit'],
            ];

            if ($capture['hit_limit']) {
                $out['last_capture']['warning'] = 'This capture stopped at its packet '
                    . 'ceiling, so the figures are a floor rather than a total.';
            }
        }
    } catch (Throwable) {
        // An older host database may have no captures table.
    }

    return $out;
}

function aiCollectorHealth(array $config, PDO $pdo): array
{
    $out = ['collectors' => []];

    $account = $pdo->query(
        'SELECT taken_at FROM vitals ORDER BY taken_at DESC LIMIT 40'
    )->fetchAll(PDO::FETCH_COLUMN, 0);

    $out['collectors'][] = aiCollectorRow(
        "this account's collector",
        'cron.php, in this account\'s own crontab',
        array_map('intval', $account)
    );

    if ($config['host']['enabled'] ?? false) {
        try {
            $host = hostDb($config, false);
            $times = $host === null ? [] : $host->query(
                'SELECT taken_at FROM host_samples ORDER BY taken_at DESC LIMIT 40'
            )->fetchAll(PDO::FETCH_COLUMN, 0);

            $out['collectors'][] = aiCollectorRow(
                "the host's collector",
                'host-cron.php, run by root',
                array_map('intval', $times)
            );
        } catch (Throwable) {
            $out['collectors'][] = [
                'name' => "the host's collector",
                'state' => 'its database cannot be read from this account',
            ];
        }
    }

    $out['note'] = 'Every chart on this dashboard goes quiet when a collector stops, and '
        . 'quiet looks exactly like healthy. If anything here is late, say so before '
        . 'drawing conclusions from the readings.';

    return $out;
}

/** One collector's state, from the gaps between its readings. */
function aiCollectorRow(string $name, string $what, array $times): array
{
    if (count($times) < 2) {
        return ['name' => $name, 'what' => $what,
                'state' => $times === [] ? 'has never run' : 'has run once'];
    }

    $gaps = [];
    for ($i = 1, $n = count($times); $i < $n; $i++) {
        $gap = $times[$i - 1] - $times[$i];
        if ($gap > 0) {
            $gaps[] = $gap;
        }
    }
    sort($gaps);

    $interval = $gaps === [] ? null : $gaps[intdiv(count($gaps), 2)];
    $age = time() - $times[0];

    return [
        'name' => $name,
        'what' => $what,
        'last_ran' => aiWhen($times[0]),
        'usually_every' => $interval === null ? null : plainSpan($interval, true),
        'state' => $interval !== null && $age > $interval * 3
            ? 'LATE - it has not run when it should have'
            : 'running normally',
    ];
}

function aiRecentChanges(PDO $pdo, array $values): array
{
    $since = time() - ((int)($values['hours'] ?? 336) * SPAN_HOUR);
    $out = [];

    try {
        $stmt = $pdo->prepare(
            'SELECT at, label, old_value, new_value FROM software_changes
              WHERE at >= ? ORDER BY at DESC LIMIT 40'
        );
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'noticed' => aiWhen((int)$row['at']),
                'what' => (string)$row['label'],
                'was' => $row['old_value'],
                'became' => (string)$row['new_value'],
            ];
        }
    } catch (Throwable) {
        // The table arrives with a later migration on an older install.
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT at, name, old_version, new_version, old_state, new_state
               FROM capability_changes WHERE at >= ? ORDER BY at DESC LIMIT 40'
        );
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'noticed' => aiWhen((int)$row['at']),
                'what' => (string)$row['name'],
                'was' => $row['old_version'] ?: $row['old_state'],
                'became' => $row['new_version'] ?: $row['new_state'],
            ];
        }
    } catch (Throwable) {
        // Likewise.
    }

    usort($out, fn(array $a, array $b): int => ($b['noticed']['at'] ?? 0) <=> ($a['noticed']['at'] ?? 0));

    return [
        'changes' => array_slice($out, 0, 40),
        'note' => $out === []
            ? 'Nothing recorded as changing in that period. Note that this is only checked '
              . 'when capabilities are probed, so a long gap between probes can hide a '
              . 'change entirely.'
            : 'These times are when a change was NOTICED, not when it happened.',
    ];
}

function aiActionHistory(PDO $pdo, array $values): array
{
    $since = time() - ((int)($values['hours'] ?? 168) * SPAN_HOUR);
    $limit = (int)($values['limit'] ?? 20);

    $stmt = $pdo->prepare(
        "SELECT at, action, target, outcome, detail, bytes_freed, files_removed
           FROM actions WHERE at >= ? ORDER BY at DESC LIMIT {$limit}"
    );
    $stmt->execute([$since]);

    return [
        'actions' => array_map(fn(array $row): array => [
            'when' => aiWhen((int)$row['at']),
            'what' => (string)$row['action'],
            'on' => $row['target'],
            'outcome' => (string)$row['outcome'],
            'detail' => $row['detail'],
            'freed' => $row['bytes_freed'] ? humanBytes((int)$row['bytes_freed']) : null,
            'files_removed' => $row['files_removed'] ? (int)$row['files_removed'] : null,
        ], $stmt->fetchAll()),
        'note' => 'This records deliberate actions taken through the dashboard, not '
            . 'failures. Failures are in the logs.',
    ];
}

/**
 * A tool result, encoded and trimmed to something a context window can hold.
 *
 * A trimmed result says so in the text the model receives, because a model
 * given a truncated list with no marker will reason about it as though it were
 * complete.
 */
function aiEncodeResult(array $result): string
{
    $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PARTIAL_OUTPUT_ON_ERROR);

    if ($json === false) {
        return '{"error":"the result could not be encoded"}';
    }

    if (strlen($json) <= AI_RESULT_MAX) {
        return $json;
    }

    return substr($json, 0, AI_RESULT_MAX)
        . sprintf(
            '... [TRUNCATED: this result was %d characters and was cut to %d. '
            . 'Ask again with a smaller limit or a narrower filter if you need the rest.]',
            strlen($json),
            AI_RESULT_MAX
        );
}

/* --------------------------------------------------- what the host offers -- */

/**
 * The programs worth knowing about before suggesting a command.
 *
 * A fixed list, and it has to be: there is no way to enumerate "every program
 * on this machine" that is not a walk of the whole PATH, and the answer would
 * be thousands of entries the model has to read. These are the ones a
 * diagnostic conversation actually reaches for.
 *
 * Grouped by what they are for rather than by package, because the question is
 * always "how do I find out about X" and never "is coreutils installed".
 */
function terminalToolbox(): array
{
    return [
        'disk' => ['df', 'du', 'find', 'ls', 'stat', 'lsof', 'ncdu'],
        'processes' => ['ps', 'top', 'pgrep', 'pidof', 'strace', 'kill'],
        'network' => ['ss', 'netstat', 'curl', 'wget', 'dig', 'host', 'nslookup',
                      'ping', 'traceroute', 'openssl'],
        'text' => ['grep', 'awk', 'sed', 'sort', 'uniq', 'head', 'tail', 'wc', 'tr'],
        'files' => ['cat', 'file', 'readlink', 'realpath', 'tar', 'gzip', 'zcat'],
        'system' => ['uname', 'uptime', 'free', 'vmstat', 'iostat', 'systemctl',
                     'journalctl', 'crontab'],
        'php' => ['php', 'composer'],
    ];
}

/**
 * Which of those are actually there.
 *
 * `command -v` in one shell rather than one per program: on a shared host each
 * shell is tens of milliseconds and fifty of them is a visible pause.
 *
 * @return array<string, mixed>
 */
function aiTerminalTools(array $config): array
{
    $settings = terminalSettings($config);

    if (!$settings['can']) {
        return [
            'terminal_available' => false,
            'why' => $settings['why'],
            'note' => 'There is no terminal on this install and there cannot be. Do not '
                . 'suggest commands; suggest what the dashboard can do instead.',
        ];
    }

    if (!$settings['enabled']) {
        return [
            'terminal_available' => false,
            'why' => 'The terminal is switched off.',
            'note' => 'The operator can turn it on from the System > Terminal page, where '
                . 'it is enabled for a chosen length of time and then locks itself again. '
                . 'Say that, rather than suggesting commands they cannot run.',
        ];
    }

    $wanted = [];
    foreach (terminalToolbox() as $programs) {
        foreach ($programs as $program) {
            $wanted[$program] = true;
        }
    }

    /*
     * One shell, printing a line per program it finds.
     *
     * command -v is a shell builtin, so this costs one process no matter how
     * many names are checked.
     */
    $script = '';
    foreach (array_keys($wanted) as $program) {
        $script .= sprintf('command -v %s >/dev/null 2>&1 && echo %s; ',
            escapeshellarg($program), escapeshellarg($program));
    }

    $found = [];
    $raw = shellRead($settings['shell'] . ' -c ' . escapeshellarg($script) . ' 2>/dev/null');
    foreach (explode("\n", (string)$raw) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $found[$line] = true;
        }
    }

    $out = ['terminal_available' => true, 'shell' => $settings['shell'], 'groups' => []];
    $missing = [];

    foreach (terminalToolbox() as $what => $programs) {
        $here = array_values(array_filter($programs, fn(string $p): bool => isset($found[$p])));
        $gone = array_values(array_filter($programs, fn(string $p): bool => !isset($found[$p])));

        $out['groups'][$what] = ['available' => $here, 'not_found' => $gone];
        $missing = array_merge($missing, $gone);
    }

    $out['note'] = sprintf(
        'Commands run as the web account, not as root, and inside CageFS if this host uses '
        . 'it - so most of /usr/sbin is invisible even where it exists on the machine. %s '
        . 'A program not on this list was not looked for rather than known to be absent.',
        $missing === []
            ? 'Everything looked for is present.'
            : count($missing) . ' of the programs looked for are not on the PATH: '
              . implode(', ', array_slice($missing, 0, 20)) . '.'
    );

    /*
     * What root has that the account does not.
     *
     * Only when the operator has allowed it. Without this the model has no way
     * to distinguish "this machine cannot do that" from "this account cannot do
     * that", which are completely different conversations - the second one ends
     * with a support ticket that gets answered.
     */
    if (!empty($config['ai']['host_commands'])) {
        $out['what_root_has'] = aiRootCommands($config);
    }

    return $out;
}

/**
 * Programs the host has that this account cannot reach.
 *
 * Read from the capability probe the root collector already runs, so this costs
 * nothing and is as fresh as that probe. The point is that the operator can ask
 * their host to run something - a support ticket saying "please run `X` and send
 * me the output" is a normal, answerable request, and knowing which programs
 * exist is what makes it a specific one.
 */
function aiRootCommands(array $config): array
{
    $host = hostDb($config, false);

    if ($host === null) {
        return [
            'known' => false,
            'note' => 'The host collector has no database here yet.',
        ];
    }

    /*
     * `account_seen` is the whole point of the table.
     *
     * The root collector records both what it can see and what the account can
     * see, and the difference between the two columns is the answer to "is this
     * missing, or is it just hidden from me" - which are completely different
     * conversations. The second one ends with a support ticket that gets
     * answered.
     */
    try {
        $rows = $host->query(
            "SELECT name, group_name, version, path FROM host_capabilities
              WHERE executable = 1 AND (account_seen IS NULL OR account_seen = 0)
              ORDER BY group_name, name"
        )->fetchAll();
    } catch (Throwable) {
        return [
            'known' => false,
            'note' => 'The host collector has not reported what programs exist yet. The '
                . 'operator can ask for that from the "What the host allows" page.',
        ];
    }

    if ($rows === []) {
        return [
            'known' => true,
            'programs' => [],
            'note' => 'Nothing the host has is hidden from this account, so there is '
                . 'nothing worth asking the host to run that the terminal cannot.',
        ];
    }

    $onlyRoot = array_map(fn(array $row): array => [
        'program' => (string)$row['name'],
        'for' => (string)$row['group_name'],
        'version' => $row['version'] === null ? null : (string)$row['version'],
        'where' => (string)($row['path'] ?? ''),
    ], $rows);

    return [
        'known' => true,
        'programs' => $onlyRoot,
        'note' => 'These exist on the machine and this account cannot run them. You may '
            . 'suggest that the operator ASK THEIR HOST to run one and send back the '
            . 'output - that is an ordinary support request and hosts answer it. Write the '
            . 'exact command, say what it will show, and say why it needs root. Do not '
            . 'suggest anything that changes the machine; a support ticket asking a '
            . 'stranger to modify a shared server is a different and much larger thing to '
            . 'ask for.',
    ];
}

/**
 * The jobs root's collector can be asked to do, and how long the answer takes.
 *
 * The delay is the whole point of this tool. A request is a row in a table that
 * root's cron reads on its next run - so the honest answer to "can you check
 * the whole machine's disk" is "yes, in about four minutes", and a model that
 * does not know that says "yes" and then has nothing to show.
 */
function aiHostJobs(array $config, PDO $pdo): array
{
    if (empty($config['host']['enabled'])) {
        return [
            'host_collector' => false,
            'note' => 'There is no root collector on this install, so none of this is '
                . 'available. Everything you can see is what the account itself can reach.',
        ];
    }

    $rhythm = aiCronRhythm($config, $pdo);

    return [
        'host_collector' => true,
        'jobs' => [
            [
                'what' => 'Walk the whole machine\'s disk',
                'answers' => 'Where space has gone across every account, /var and /backup, '
                    . 'including space held by deleted files that are still open.',
                'ask_from' => 'the Storage page, the "Scan now" button under "The whole '
                    . 'machine"',
                'results_on' => 'Filesystems, Biggest directories, and Deleted still '
                    . 'holding',
                'takes' => 'tens of seconds once it starts',
            ],
            [
                'what' => 'Probe what the host allows',
                'answers' => 'Which programs exist, which this account may run, and what '
                    . 'the kernel and the panel are.',
                'ask_from' => 'the "What the host allows" page',
                'results_on' => 'the same page',
                'takes' => 'a few seconds once it starts',
            ],
            [
                'what' => 'Look a domain up in whois',
                'answers' => 'Registrar, expiry and status, from a lookup this account '
                    . 'usually cannot make.',
                'ask_from' => 'the Domain names page',
                'results_on' => 'the same page',
                'takes' => 'a few seconds once it starts',
            ],
            [
                'what' => 'Capture network traffic',
                'answers' => 'What is actually on the wire, which the account cannot see.',
                'ask_from' => 'the "Watch the wire" page',
                'results_on' => 'the same page',
                'takes' => 'as long as the capture was asked to run for',
            ],
            [
                'what' => 'Rebuild the shared library cache',
                'answers' => 'Nothing - it changes something. Only useful after a library '
                    . 'link has been repaired.',
                'ask_from' => 'the Symlinks page',
                'results_on' => 'nothing to look at; programs simply start working',
                'takes' => 'a second once it starts',
            ],
        ],
        'the_delay' => $rhythm['host'],
        'note' => 'NONE of these happen when the button is pressed. The press leaves a '
            . 'request; root\'s cron picks it up on its next run. Tell the operator which '
            . 'page to press it on and roughly how long the wait is - the numbers for that '
            . 'are in `the_delay`. You may draw a progress bar with show_progress if the '
            . 'wait is long enough to be worth watching. You cannot press it yourself.',
    ];
}

/**
 * What is already queued, and when it will be picked up.
 *
 * @return array<string, mixed>
 */
function aiPendingHostRequests(array $config, PDO $pdo): array
{
    if (empty($config['host']['enabled'])) {
        return ['host_collector' => false, 'pending' => [],
                'note' => 'There is no root collector on this install.'];
    }

    try {
        $rows = $pdo->query(
            'SELECT task, requested_at, requested_by FROM host_tasks ORDER BY requested_at'
        )->fetchAll();
    } catch (Throwable) {
        $rows = [];
    }

    $rhythm = aiCronRhythm($config, $pdo);
    $host = $rhythm['host'];

    $pending = array_map(fn(array $row): array => [
        'job' => (string)$row['task'],
        'asked_for' => aiWhen((int)$row['requested_at']),
        'waiting_for' => plainSpan(time() - (int)$row['requested_at'], true),
        'asked_by' => (string)($row['requested_by'] ?? 'somebody'),
    ], $rows);

    return [
        'host_collector' => true,
        'pending' => $pending,
        'next_run' => $host,
        'note' => $pending === []
            ? 'Nothing is queued. A request would be picked up on the next collector run.'
            : ($host['running'] ?? true
                ? 'These are waiting for the next collector run. A job already queued does '
                  . 'not need asking for again - saying it is already on its way is more '
                  . 'use than sending somebody to press the button twice.'
                : 'These are queued and the collector has STOPPED, so nothing is going to '
                  . 'pick them up. That is the finding, not the queue.'),
    ];
}

/**
 * How often each collector really runs, measured rather than configured.
 *
 * From the gaps between actual runs, because a crontab says what was asked for
 * and this says what is happening - and on a busy shared host those differ.
 *
 * @return array{account: array, host: array}
 */
function aiCronRhythm(array $config, PDO $pdo): array
{
    $describe = function (?int $last, ?int $interval, string $what): array {
        if ($last === null) {
            return [
                'what' => $what,
                'ever_run' => false,
                'note' => 'This has never run.',
            ];
        }

        $age = time() - $last;
        $running = $interval !== null && $age <= $interval * 2;

        return [
            'what' => $what,
            'ever_run' => true,
            'last_ran' => aiWhen($last),
            'last_ran_seconds_ago' => $age,
            'runs_every' => $interval === null ? null : plainSpan($interval),
            'runs_every_seconds' => $interval,
            'running' => $running,
            'next_due_in_seconds' => $interval === null ? null : max(0, $interval - $age),
            'next_due' => $interval === null
                ? 'not enough runs yet to say'
                : ($age >= $interval ? 'about now' : plainSpan($interval - $age, true)),
            'note' => $running
                ? ''
                : sprintf(
                    'Nothing for %s on something that usually runs every %s. This '
                    . 'collector has stopped, which makes every reading from it stale and '
                    . 'makes its charts look calm rather than broken.',
                    plainSpan($age, true),
                    $interval === null ? 'few minutes' : plainSpan($interval)
                ),
        ];
    };

    $accountLast = $pdo->query('SELECT MAX(taken_at) FROM vitals')->fetchColumn();
    $account = $describe(
        $accountLast === null || $accountLast === false ? null : (int)$accountLast,
        collectorInterval($pdo),
        "the account's own cron, which takes the readings behind most of this dashboard"
    );

    $hostLast = null;
    $hostInterval = null;

    if (!empty($config['host']['enabled'])) {
        try {
            $hostDb = hostDb($config, false);
            if ($hostDb !== null) {
                $found = $hostDb->query('SELECT MAX(taken_at) FROM host_samples')->fetchColumn();
                $hostLast = $found === null || $found === false ? null : (int)$found;

                $times = $hostDb->query(
                    'SELECT taken_at FROM host_samples ORDER BY taken_at DESC LIMIT 40'
                )->fetchAll(PDO::FETCH_COLUMN, 0);
                $hostInterval = medianGap(array_map('intval', $times));
            }
        } catch (Throwable) {
            // No host database is the same answer as no host collector.
        }
    }

    return [
        'account' => $account,
        'host' => $describe(
            $hostLast,
            $hostInterval,
            "root's collector, which does everything the account cannot"
        ),
    ];
}

/** The middle gap between a list of timestamps, or null if there are too few. */
function medianGap(array $times): ?int
{
    if (count($times) < 3) {
        return null;
    }

    sort($times);
    $gaps = [];
    for ($i = 1; $i < count($times); $i++) {
        $gap = $times[$i] - $times[$i - 1];
        if ($gap > 0) {
            $gaps[] = $gap;
        }
    }

    if ($gaps === []) {
        return null;
    }

    sort($gaps);

    /*
     * The median, not the mean.
     *
     * One four-hour gap where the machine was rebooted would drag an average
     * into uselessness, and the question here is "how often does this normally
     * happen" rather than "what was the average over the last week".
     */
    return (int)$gaps[intdiv(count($gaps), 2)];
}

/* --------------------------------------------------------------- memory -- */

/**
 * Writes one memory.
 *
 * The refusals from aiMemoryWrite are returned to the model as ordinary tool
 * results rather than thrown, because a model told "not remembering that, it
 * looks like a credential" understands and moves on, while one whose call
 * vanished tries again.
 */
function aiToolRemember(?PDO $ai, int $session, array $values): array
{
    if ($ai === null) {
        return ['error' => 'There is nowhere to remember things right now.'];
    }

    try {
        $result = aiMemoryWrite(
            $ai,
            $session > 0 ? $session : null,
            (string)$values['kind'],
            (string)$values['subject'],
            (string)$values['body']
        );
    } catch (ActionRefused $e) {
        return ['error' => $e->getMessage()];
    }

    return [
        'remembered' => true,
        'subject' => (string)$values['subject'],
        'replaced_an_earlier_one' => $result['replaced'],
        'note' => $result['replaced']
            ? 'This replaced what was previously remembered under that subject.'
            : 'Written down. It will be there in future conversations.',
    ];
}

/** Looks memories up. */
function aiToolRecall(?PDO $ai, array $values): array
{
    if ($ai === null) {
        return ['error' => 'There is nothing to recall from right now.'];
    }

    $about = trim((string)($values['about'] ?? ''));
    $found = aiMemoryFind($ai, $about, (int)($values['limit'] ?? 20));

    if ($found === []) {
        return [
            'memories' => [],
            'note' => $about === ''
                ? 'Nothing has been remembered yet.'
                : sprintf(
                    'Nothing remembered mentions "%s". That means nothing was written down '
                    . 'about it - not that it did not happen.',
                    $about
                ),
        ];
    }

    return [
        'memories' => array_map(fn(array $memory): array => [
            'kind' => $memory['kind'],
            'subject' => $memory['subject'],
            'body' => $memory['body'],
            'written' => aiWhen($memory['at']),
            'last_changed' => aiWhen($memory['updated_at']),
            'written_by' => $memory['written_by'] === 'you'
                ? 'the operator, by hand'
                : 'you, in an earlier conversation',
        ], $found),
        'note' => 'These were written in earlier conversations. Check anything that could '
            . 'have changed before relying on it.',
    ];
}

/**
 * Puts a command on the terminal page.
 *
 * Returns success as soon as it is written down, which is the honest answer:
 * the tool's job is to make the suggestion visible, and whether anybody runs it
 * is not this tool's business and must not be implied.
 */
function aiToolRecommend(array $values): array
{
    return [
        'shown_to_the_operator' => true,
        'command' => (string)$values['command'],
        'where' => 'It is on the System > Terminal page, waiting for them to read it.',
        'note' => 'It has NOT been run and will not be unless they choose to run it. Do '
            . 'not assume any output. When they send it back, you will see it as a message '
            . 'from them. If they do not, ask - do not guess what it said.',
    ];
}
