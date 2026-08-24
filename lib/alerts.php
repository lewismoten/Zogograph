<?php
declare(strict_types=1);

/*
 * Noticing, and saying so.
 *
 * The reason this project exists is running out of disk without knowing, so the
 * alerting is not an extra: a monitor you have to remember to look at is a
 * monitor that tells you after the fact.
 *
 * Two kinds of rule. Thresholds ("less than five gigabytes left") catch the
 * state you must not be in. Jumps ("this directory gained eight gigabytes since
 * the last scan") catch the thing that is going to put you there, which is the
 * one worth having - a threshold tells you when it is nearly too late, and a
 * jump tells you which log file to go and look at.
 *
 * Every rule is rate limited per rule, and per path where a path is involved.
 * An alert that arrives every minute becomes a filter rule within a day, and
 * then it is not an alert.
 */

require_once __DIR__ . '/db.php';

/**
 * How far back a projection looks.
 *
 * Shared between the alert rules and the dashboard card on purpose: two
 * projections of the same disk that disagree because they measured different
 * windows is worse than either being absent, and there is no reason for them to
 * differ.
 */
const TREND_WINDOW_SECONDS = 86400;

function evaluateAlerts(array $config, PDO $pdo): array
{
    $rules = $config['alerts'] ?? [];
    if (!($rules['enabled'] ?? false)) {
        return [];
    }

    $raised = [];
    foreach (
        [
            ...diskAlerts($pdo, $rules),
            ...trendAlerts($pdo, $rules),
            ...categoryAlerts($pdo, $rules),
            ...pathAlerts($pdo, $rules),
        ] as $alert
    ) {
        $id = recordAlert($pdo, $rules, $alert);
        if ($id !== null) {
            $raised[] = $alert + ['id' => $id];
        }
    }

    if ($raised) {
        sendAlertEmail($config, $pdo, $raised);
    }

    return $raised;
}

/** The latest vitals row, or null if nothing has been collected yet. */
function latestVitals(PDO $pdo): ?array
{
    $row = $pdo->query('SELECT * FROM vitals ORDER BY taken_at DESC, id DESC LIMIT 1')->fetch();
    return $row ?: null;
}

function diskAlerts(PDO $pdo, array $rules): array
{
    $now = latestVitals($pdo);
    if (!$now) {
        return [];
    }

    $out = [];

    $free = $now['disk_free_bytes'];
    $total = $now['disk_total_bytes'];
    if ($free !== null && $total) {
        $freePercent = ($free / $total) * 100;
        $floorBytes = (int)($rules['disk_free_bytes'] ?? 0);
        $floorPercent = (float)($rules['disk_free_percent'] ?? 0);

        if ($floorBytes > 0 && $free < $floorBytes) {
            $out[] = [
                'rule' => 'disk_free_bytes',
                'severity' => 'critical',
                'subject' => 'Disk nearly full: ' . humanBytes((int)$free) . ' left',
                'detail' => sprintf(
                    'Free space is %s, below the %s floor. The filesystem is %.1f%% used.',
                    humanBytes((int)$free),
                    humanBytes($floorBytes),
                    (float)$now['disk_used_percent']
                ),
            ];
        } elseif ($floorPercent > 0 && $freePercent < $floorPercent) {
            $out[] = [
                'rule' => 'disk_free_percent',
                'severity' => 'critical',
                'subject' => sprintf('Disk nearly full: %.1f%% free', $freePercent),
                'detail' => sprintf(
                    'Only %.1f%% of the filesystem is free (%s of %s).',
                    $freePercent,
                    humanBytes((int)$free),
                    humanBytes((int)$total)
                ),
            ];
        }
    }

    $inodesFree = $now['inodes_free'];
    $inodesTotal = $now['inodes_total'];
    if ($inodesFree !== null && $inodesTotal) {
        $percent = ($inodesFree / $inodesTotal) * 100;
        if ($percent < (float)($rules['inodes_free_percent'] ?? 0)) {
            $out[] = [
                'rule' => 'inodes_free_percent',
                'severity' => 'critical',
                'subject' => sprintf('Inodes nearly exhausted: %.1f%% free', $percent),
                'detail' => sprintf(
                    'Only %s inodes free of %s. The disk can have room and still refuse to create a file.',
                    number_format((float)$inodesFree),
                    number_format((float)$inodesTotal)
                ),
            ];
        }
    }

    return $out;
}

/**
 * Where the trend is heading.
 *
 * The most useful alert of the lot, because it fires while there is still time
 * to do something. Fitted over the last day of samples rather than the last
 * two, so a single tar file being written does not predict catastrophe.
 */
function trendAlerts(PDO $pdo, array $rules): array
{
    $hours = (float)($rules['projected_full_hours'] ?? 0);
    if ($hours <= 0) {
        return [];
    }

    $rows = $pdo->prepare(
        'SELECT taken_at, disk_free_bytes, inodes_free FROM vitals
         WHERE taken_at >= ? ORDER BY taken_at'
    );
    $rows->execute([time() - TREND_WINDOW_SECONDS]);
    $readings = $rows->fetchAll();

    $out = [];

    foreach ([
        ['disk_free_bytes', 'Disk', fn(float $v): string => humanBytes((int)round($v))],
        ['inodes_free', 'Inodes', fn(float $v): string => number_format(round($v))],
    ] as [$field, $label, $format]) {
        $trend = seriesTrend($readings, $field);

        /*
         * Only a sustained fall is worth an email.
         *
         * Without the gate this fired every time somebody uploaded something
         * large, predicted catastrophe from a one-off, and taught the reader to
         * ignore it - which costs more than the alert was ever worth.
         */
        if ($trend === null || !$trend['sustained'] || $trend['seconds_left'] === null) {
            continue;
        }
        if ($trend['seconds_left'] / 3600 > $hours) {
            continue;
        }

        $out[] = [
            'rule' => 'projected_full:' . $field,
            'severity' => 'warning',
            'subject' => sprintf(
                '%s will run out in about %s',
                $label,
                humanDuration($trend['seconds_left'])
            ),
            'detail' => sprintf(
                "%s has been falling steadily for %s, not in one drop:\n"
                . "  losing about %s an hour\n"
                . "  %s left, which runs out in %s\n"
                . "  falling in both halves of the window (%s an hour, then %s an hour)",
                $label,
                humanDuration((float)$trend['span_seconds']),
                $format(-$trend['rate'] * 3600),
                $format($trend['latest']),
                humanDuration($trend['seconds_left']),
                $format(-(float)$trend['first_half'] * 3600),
                $format(-(float)$trend['second_half'] * 3600)
            ),
        ];
    }

    return $out;
}

/**
 * How a series is really moving, told apart from a one-off event.
 *
 * A plain least-squares fit was the old answer and it is the wrong one: a
 * single twenty-gigabyte upload drags the line down hard enough to predict a
 * full disk by Thursday, and after a week of those nobody reads the warnings.
 *
 * The distinction that matters is between a *step* and a *slope*. Space that
 * dropped once and stayed down is consumption that already happened; space
 * that keeps dropping is a leak. So the window is cut in half and each half is
 * measured on its own - a spike lands in one of them, and a leak is in both.
 *
 * Three rates come out, and they answer different questions:
 *
 *   overall  where the level actually went, spikes included
 *   robust   the median step between readings, which a spike cannot move
 *   halves   whether it is still going
 *
 * @return array|null null when there is not enough history to say anything.
 */
function seriesTrend(array $rows, string $field): ?array
{
    $points = [];
    foreach ($rows as $row) {
        if (($row[$field] ?? null) === null) {
            continue;
        }
        $points[] = [(int)$row['taken_at'], (float)$row[$field]];
    }

    $count = count($points);
    $span = $count > 1 ? $points[$count - 1][0] - $points[0][0] : 0;

    // Too few readings, or too short a window, and any answer is noise wearing
    // a confident face.
    if ($count < 8 || $span < 1800) {
        return null;
    }

    $overall = ($points[$count - 1][1] - $points[0][1]) / $span;

    $steps = [];
    foreach (range(1, $count - 1) as $i) {
        $dt = $points[$i][0] - $points[$i - 1][0];
        if ($dt > 0) {
            $steps[] = ($points[$i][1] - $points[$i - 1][1]) / $dt;
        }
    }
    if (!$steps) {
        return null;
    }

    $middle = (int)floor($count / 2);
    $first = halfRate(array_slice($points, 0, $middle + 1));
    $second = halfRate(array_slice($points, $middle));

    /*
     * Falling in both halves, or it is not a trend yet.
     *
     * This is the whole spike filter. An upload makes one half plummet and
     * leaves the other flat; a log file filling up makes both of them fall.
     */
    $sustained = $first !== null && $second !== null && $first < 0 && $second < 0 && $overall < 0;

    $median = median($steps);
    // The median is the estimate a spike cannot inflate, so it is preferred for
    // projecting; `overall` covers the case of a slow drift too fine to show up
    // as a consistent step between one reading and the next.
    $rate = $median < 0 ? $median : ($overall < 0 ? $overall : 0.0);

    $latest = $points[$count - 1][1];
    $secondsLeft = $rate < 0 ? $latest / -$rate : null;

    return [
        'rate' => $rate,
        'overall' => $overall,
        'median' => $median,
        'first_half' => $first,
        'second_half' => $second,
        'sustained' => $sustained,
        'seconds_left' => $secondsLeft,
        'latest' => $latest,
        'samples' => $count,
        'span_seconds' => $span,
        // Losing space overall but not in both halves: something happened, and
        // it is not happening now.
        'spike' => !$sustained && $overall < 0,
    ];
}

/** The average rate across a slice, or null if it does not span any time. */
function halfRate(array $points): ?float
{
    $n = count($points);
    if ($n < 2) {
        return null;
    }
    $seconds = $points[$n - 1][0] - $points[0][0];
    return $seconds > 0 ? ($points[$n - 1][1] - $points[0][1]) / $seconds : null;
}

function median(array $values): float
{
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return 0.0;
    }
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

/** Bytes per second, by least squares. Null if the points are all at once. */
function leastSquaresSlope(array $rows, string $xKey, string $yKey): ?float
{
    $n = count($rows);
    if ($n < 2) {
        return null;
    }

    $sumX = $sumY = $sumXY = $sumXX = 0.0;
    $x0 = (float)$rows[0][$xKey];
    foreach ($rows as $row) {
        $x = (float)$row[$xKey] - $x0;
        $y = (float)$row[$yKey];
        $sumX += $x;
        $sumY += $y;
        $sumXY += $x * $y;
        $sumXX += $x * $x;
    }

    $denominator = $n * $sumXX - $sumX * $sumX;
    if (abs($denominator) < 1e-9) {
        return null;
    }

    return ($n * $sumXY - $sumX * $sumY) / $denominator;
}

function categoryAlerts(PDO $pdo, array $rules): array
{
    $jump = (int)($rules['category_jump_bytes'] ?? 0);
    if ($jump <= 0) {
        return [];
    }

    $scans = $pdo->query('SELECT id FROM scans WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 2')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (count($scans) < 2) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT category, bytes FROM scan_totals WHERE scan_id = ?');
    $stmt->execute([$scans[0]]);
    $now = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt->execute([$scans[1]]);
    $before = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $out = [];
    foreach ($now as $category => $bytes) {
        $grew = (int)$bytes - (int)($before[$category] ?? 0);
        if ($grew < $jump) {
            continue;
        }
        $out[] = [
            'rule' => 'category_jump:' . $category,
            'severity' => 'warning',
            'subject' => sprintf('%s grew by %s', ucfirst((string)$category), humanBytes($grew)),
            'detail' => sprintf(
                '%s is now %s, up %s since the previous scan.',
                ucfirst((string)$category),
                humanBytes((int)$bytes),
                humanBytes($grew)
            ),
        ];
    }

    return $out;
}

/**
 * Individual paths that jumped.
 *
 * This is the one that names the culprit. "Logs grew by four gigabytes" sends
 * you looking; "/home/x/logs/error.log grew by four gigabytes" is the answer.
 */
function pathAlerts(PDO $pdo, array $rules): array
{
    $jumpBytes = (int)($rules['path_jump_bytes'] ?? 0);
    $jumpFiles = (int)($rules['path_jump_files'] ?? 0);
    if ($jumpBytes <= 0 && $jumpFiles <= 0) {
        return [];
    }

    $scans = $pdo->query('SELECT id FROM scans WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 2')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (count($scans) < 2) {
        return [];
    }

    $rows = $pdo->prepare(
        'SELECT p.id, p.path, p.kind, now.bytes AS bytes, now.files AS files,
                before.bytes AS was_bytes, before.files AS was_files
         FROM path_sizes now
         JOIN path_sizes before ON before.path_id = now.path_id AND before.scan_id = :before
         JOIN paths p ON p.id = now.path_id
         WHERE now.scan_id = :now
           AND (now.bytes - before.bytes >= :jumpBytes OR now.files - before.files >= :jumpFiles)
         ORDER BY (now.bytes - before.bytes) DESC
         LIMIT 20'
    );
    /*
     * Bound as integers, deliberately and not for tidiness.
     *
     * PDO's execute([...]) binds every value as text. SQLite applies a column's
     * affinity when comparing against that column, so `scan_id = '2'` is fine -
     * but `now.bytes - before.bytes` is a computed expression with no affinity
     * at all, and there an integer is never greater than a string. The whole
     * comparison was silently false, so the one alert that names the actual
     * culprit could not fire, and nothing anywhere reported a problem.
     */
    $rows->bindValue(':now', (int)$scans[0], PDO::PARAM_INT);
    $rows->bindValue(':before', (int)$scans[1], PDO::PARAM_INT);
    // A disabled rule must never match, so it is set beyond any real value
    // rather than to zero, which every unchanged path would satisfy.
    $rows->bindValue(':jumpBytes', $jumpBytes > 0 ? $jumpBytes : PHP_INT_MAX, PDO::PARAM_INT);
    $rows->bindValue(':jumpFiles', $jumpFiles > 0 ? $jumpFiles : PHP_INT_MAX, PDO::PARAM_INT);
    $rows->execute();

    /*
     * Don't blame a directory for its child's growth.
     *
     * A file gaining two gigabytes makes every directory above it gain two
     * gigabytes as well, so one runaway log raised an alert for itself, for its
     * directory, and for the home directory - three messages about one event,
     * of which only the most specific is any use.
     *
     * Decided over the whole candidate set rather than as the rows arrive: they
     * come biggest-growth first, which means a parent is always seen before the
     * child that explains it, and a running check can only ever look backwards.
     */
    $candidates = $rows->fetchAll();
    $growth = [];
    foreach ($candidates as $row) {
        $growth[(string)$row['path']] = (int)$row['bytes'] - (int)$row['was_bytes'];
    }

    $out = [];
    foreach ($candidates as $row) {
        $grewBytes = (int)$row['bytes'] - (int)$row['was_bytes'];
        $grewFiles = (int)$row['files'] - (int)$row['was_files'];

        if (explainedByChild((string)$row['path'], $grewBytes, $growth)) {
            continue;
        }

        $how = [];
        if ($grewBytes > 0) {
            $how[] = 'up ' . humanBytes($grewBytes);
        }
        if ($grewFiles > 0) {
            $how[] = 'up ' . number_format($grewFiles) . ' files';
        }

        $out[] = [
            'rule' => 'path_jump',
            'severity' => 'warning',
            'path_id' => (int)$row['id'],
            'subject' => sprintf('%s %s', basename((string)$row['path']), implode(', ', $how)),
            'detail' => sprintf(
                "%s\n  now %s in %s files, %s since the previous scan.",
                (string)$row['path'],
                humanBytes((int)$row['bytes']),
                number_format((float)$row['files']),
                implode(' and ', $how)
            ),
        ];
    }

    return $out;
}

/**
 * Whether a path's growth is already accounted for by something inside it.
 *
 * "Essentially all" rather than "all": a directory that gained a gigabyte from
 * one log and another hundred megabytes spread over fifty small files is still,
 * for the purpose of telling somebody what happened, the story of the log.
 */
function explainedByChild(string $path, int $grew, array $growth): bool
{
    if ($grew <= 0) {
        return false;
    }

    $prefix = rtrim($path, '/') . '/';
    foreach ($growth as $other => $otherGrew) {
        if ($other === $path || !str_starts_with($other, $prefix)) {
            continue;
        }
        if ($otherGrew >= $grew * 0.9) {
            return true;
        }
    }

    return false;
}

/**
 * Writes an alert, unless one like it went out recently.
 *
 * Returns the new row id, or null if it was suppressed. The cooldown is keyed
 * on the rule and the path together, so a hundred log files filling up at once
 * still names all hundred, while one of them filling up for a week names it
 * once a cooldown.
 */
function recordAlert(PDO $pdo, array $rules, array $alert): ?int
{
    $cooldown = (int)($rules['cooldown_seconds'] ?? 21600);
    $since = time() - $cooldown;

    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM alerts
         WHERE rule = ? AND raised_at >= ?
           AND (path_id IS ? OR path_id = ?)'
    );
    $pathId = $alert['path_id'] ?? null;
    $check->execute([$alert['rule'], $since, $pathId, $pathId]);
    if ((int)$check->fetchColumn() > 0) {
        return null;
    }

    $pdo->prepare(
        'INSERT INTO alerts (raised_at, rule, severity, subject, detail, path_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        time(),
        $alert['rule'],
        $alert['severity'],
        $alert['subject'],
        $alert['detail'],
        $pathId,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * One message for everything raised in this run.
 *
 * One rather than one each: a disk filling up trips several rules at once, and
 * five separate emails about the same event is how people learn to ignore them.
 */
function sendAlertEmail(array $config, PDO $pdo, array $raised): void
{
    $rules = $config['alerts'];
    $to = trim((string)($rules['to'] ?? ''));
    if ($to === '' || !function_exists('mail')) {
        return;
    }

    $worst = 'warning';
    foreach ($raised as $alert) {
        if ($alert['severity'] === 'critical') {
            $worst = 'critical';
        }
    }

    $host = php_uname('n');
    $subject = sprintf(
        '[%s] %s: %s',
        $worst === 'critical' ? 'CRITICAL' : 'warning',
        $host,
        count($raised) === 1 ? $raised[0]['subject'] : count($raised) . ' disk alerts'
    );

    $lines = ['Disk Usage Monitor on ' . $host, ''];
    foreach ($raised as $alert) {
        $lines[] = strtoupper($alert['severity']) . ' — ' . $alert['subject'];
        $lines[] = '  ' . str_replace("\n", "\n  ", $alert['detail']);
        $lines[] = '';
    }

    foreach (statusReport($pdo) as $line) {
        $lines[] = $line;
    }

    $lines[] = '';
    $lines[] = 'This message will not repeat for '
        . humanDuration((float)($rules['cooldown_seconds'] ?? 21600)) . '.';

    $headers = ['Auto-Submitted: auto-generated', 'X-Priority: ' . ($worst === 'critical' ? '1' : '3')];
    $from = trim((string)($rules['from'] ?? ''));
    if ($from !== '') {
        $headers[] = 'From: ' . $from;
    }

    $sent = @mail($to, $subject, implode("\n", $lines), implode("\r\n", $headers));

    $update = $pdo->prepare('UPDATE alerts SET emailed_at = ?, email_error = ? WHERE id = ?');
    foreach ($raised as $alert) {
        $update->execute([$sent ? time() : null, $sent ? null : 'mail() returned false', $alert['id']]);
    }
}

/**
 * Where things stand, as lines of plain text.
 *
 * Shared by the alert emails and the test email, so what a test sends is
 * literally the report a real alert would carry - a test that exercises a
 * different code path tells you the wrong thing about the one that matters.
 *
 * @return list<string>
 */
function statusReport(PDO $pdo): array
{
    $lines = [];
    $vitals = latestVitals($pdo);

    if (!$vitals) {
        return ['Nothing has been collected yet. Run: php cron.php --vitals'];
    }

    $lines[] = 'At the last reading (' . date('Y-m-d H:i', (int)$vitals['taken_at']) . '):';
    $lines[] = sprintf(
        '  disk     %s free of %s (%.1f%% used)',
        humanBytes((int)$vitals['disk_free_bytes']),
        humanBytes((int)$vitals['disk_total_bytes']),
        (float)$vitals['disk_used_percent']
    );

    if ($vitals['inodes_free'] !== null) {
        $lines[] = sprintf(
            '  inodes   %s free of %s (%.1f%% used)',
            number_format((float)$vitals['inodes_free']),
            number_format((float)$vitals['inodes_total']),
            (float)$vitals['inodes_used_percent']
        );
    }
    if ($vitals['load1'] !== null) {
        $cores = $vitals['cpu_cores'] ? ' over ' . (int)$vitals['cpu_cores'] . ' cores' : '';
        $lines[] = sprintf('  load     %.2f %.2f %.2f%s',
            (float)$vitals['load1'], (float)$vitals['load5'], (float)$vitals['load15'], $cores);
    }
    if ($vitals['cpu_percent'] !== null) {
        $lines[] = sprintf('  cpu      %.0f%%', (float)$vitals['cpu_percent']);
    }
    if ($vitals['mem_used_percent'] !== null) {
        $lines[] = sprintf('  memory   %.0f%% used, %s available',
            (float)$vitals['mem_used_percent'], humanBytes((int)$vitals['mem_available_bytes']));
    }
    if ($vitals['net_rx_rate'] !== null) {
        $lines[] = sprintf('  network  %s/s in, %s/s out',
            humanBytes((int)$vitals['net_rx_rate']), humanBytes((int)$vitals['net_tx_rate']));
    }

    $scan = $pdo->query(
        'SELECT id, started_at FROM scans WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1'
    )->fetch();

    if (!$scan) {
        $lines[] = '';
        $lines[] = 'No scan has finished yet. Run: php cron.php --scan';
        return $lines;
    }

    $lines[] = '';
    $lines[] = 'Last scan (' . date('Y-m-d H:i', (int)$scan['started_at']) . '):';

    $totals = $pdo->prepare('SELECT category, bytes, files FROM scan_totals WHERE scan_id = ? ORDER BY bytes DESC');
    $totals->execute([$scan['id']]);
    foreach ($totals->fetchAll() as $row) {
        $lines[] = sprintf('  %-8s %10s in %s files',
            $row['category'], humanBytes((int)$row['bytes']), number_format((float)$row['files']));
    }

    /*
     * The five biggest things, by name.
     *
     * Unmasked, because this goes to the address in the config and nowhere
     * else - the masking exists for people looking at the dashboard without a
     * password, and an email you asked to be sent to yourself is not that.
     */
    $top = $pdo->prepare(
        'SELECT p.path, ps.bytes FROM path_sizes ps JOIN paths p ON p.id = ps.path_id
         WHERE ps.scan_id = ? AND p.kind = \'file\' ORDER BY ps.bytes DESC LIMIT 5'
    );
    $top->execute([$scan['id']]);
    $biggest = $top->fetchAll();

    if ($biggest) {
        $lines[] = '';
        $lines[] = 'Largest files:';
        foreach ($biggest as $row) {
            $lines[] = sprintf('  %10s  %s', humanBytes((int)$row['bytes']), $row['path']);
        }
    }

    return $lines;
}

/**
 * Sends the status report on demand, from the dashboard.
 *
 * Deliberately goes to the configured recipient rather than to an address in
 * the request: a monitor that will email arbitrary people its file listing on
 * request is an open relay with a disk inventory attached.
 *
 * @return array{sent: bool, to: string, error: ?string}
 */
function sendTestEmail(array $config, PDO $pdo): array
{
    $rules = $config['alerts'] ?? [];
    $to = trim((string)($rules['to'] ?? ''));

    if ($to === '') {
        return ['sent' => false, 'to' => '', 'error' => 'No alert address is configured.'];
    }
    if (!function_exists('mail')) {
        return ['sent' => false, 'to' => $to, 'error' => 'mail() is not available on this host.'];
    }

    $host = php_uname('n');
    $body = array_merge(
        ['Disk Usage Monitor on ' . $host, '', 'This is a test. Nothing has tripped.', ''],
        statusReport($pdo),
        ['', 'Alerts are ' . (($rules['enabled'] ?? false) ? 'enabled' : 'DISABLED in the config') . '.']
    );

    $headers = ['Auto-Submitted: auto-generated'];
    $from = trim((string)($rules['from'] ?? ''));
    if ($from !== '') {
        $headers[] = 'From: ' . $from;
    }

    $sent = @mail($to, "[test] $host disk monitor", implode("\n", $body), implode("\r\n", $headers));

    return [
        'sent' => (bool)$sent,
        'to' => $to,
        'error' => $sent ? null : 'mail() returned false. The host may not accept mail from PHP.',
    ];
}

function humanBytes(?int $bytes): string
{
    if ($bytes === null) {
        return 'unknown';
    }
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $value = (float)$bytes;
    $i = 0;
    while (abs($value) >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return sprintf($i === 0 ? '%d %s' : '%.2f %s', $value, $units[$i]);
}

/*
 * A span of time, in the coarsest unit that still says something.
 *
 * Seconds are a real branch rather than being rounded up into "1 minutes".
 * This started life writing projections - "full in 3.5 days" - where nothing
 * under an hour was worth a word. It is now also used to say how long a process
 * has been running, and there the small end is the whole message: a pid that
 * has been alive four seconds when the screen claims eleven days is the
 * evidence that the number was recycled, and rounding it to a minute throws
 * away exactly the fact being reported.
 */
function humanDuration(float $seconds): string
{
    if ($seconds < 60) {
        $whole = max(0, (int)round($seconds));
        return $whole . ($whole === 1 ? ' second' : ' seconds');
    }
    if ($seconds < 3600) {
        $whole = (int)round($seconds / 60);
        return $whole . ($whole === 1 ? ' minute' : ' minutes');
    }
    if ($seconds < 172800) {
        return sprintf('%.1f hours', $seconds / 3600);
    }
    return sprintf('%.1f days', $seconds / 86400);
}
