<?php
declare(strict_types=1);

/*
 * What is using the machine.
 *
 * Two halves, and the second is the one that matters. A live list is `top` with
 * extra steps - useful, but you have to be watching. The recorded half answers
 * the question this whole project exists for: something pinned the CPU at four
 * in the morning, what was it.
 *
 * Recording every process every minute would be a table growing faster than the
 * disk it is watching, so it only writes when the machine is actually busy.
 * When nothing is wrong there is nothing worth keeping.
 *
 * Everything here is signed-in only. A command line is not a size: it carries
 * paths, arguments, and often enough a password somebody put on a command line
 * despite being told not to. Those get redacted too, because they end up in a
 * database and on a screen.
 */

require_once __DIR__ . '/collect.php';

/** Processes with the same name are one row: forty php-fpm workers are one thing. */
const PROCESS_KEEP_DEFAULT = 8;

/**
 * A snapshot of what is running, biggest first.
 *
 * `ps -eo` rather than anything cleverer because it is the one invocation that
 * behaves the same on Linux and BSD - `--sort` is procps only, so the sorting
 * happens here instead.
 *
 * @return array{available: bool, reason: ?string, rows: list<array>}
 */
function listProcesses(string $by = 'cpu', int $limit = 20, bool $group = true): array
{
    // `etime` rather than `etimes`: the seconds form is procps only, and the
    // clock form is understood by both. It has no spaces, so it stays a field.
    $out = shellRead('ps -eo pid,user,pcpu,pmem,rss,etime,args 2>/dev/null');
    if ($out === null) {
        return [
            'available' => false,
            'reason' => 'This host does not allow running ps from PHP.',
            'rows' => [],
        ];
    }

    $lines = preg_split('/\R/', trim($out)) ?: [];
    array_shift($lines);

    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        // Seven fields, and the last one keeps its spaces because it is the
        // command line.
        $parts = preg_split('/\s+/', $line, 7);
        if (!$parts || count($parts) < 7) {
            continue;
        }

        [$pid, $user, $cpu, $mem, $rss, $etime, $args] = $parts;
        if (!ctype_digit($pid)) {
            continue;
        }

        $rows[] = [
            'pid' => (int)$pid,
            'user' => $user,
            'cpu_percent' => (float)$cpu,
            'mem_percent' => (float)$mem,
            // ps reports RSS in kilobytes on both platforms.
            'rss_bytes' => (int)$rss * 1024,
            'elapsed_seconds' => parseElapsed($etime),
            'name' => processName($args),
            'command' => redactSecrets($args),
        ];
    }

    /*
     * Grouped by name, because that is the answer to the question.
     *
     * Fifteen Chrome helpers using two gigabytes between them is one fact, and
     * shown a row apiece it is fifteen rows none of which look alarming. The
     * heaviest process keeps its pid and its command line so there is still
     * something to go and look at.
     */
    if ($group) {
        $rows = groupByName($rows);
    }

    $key = $by === 'memory' ? 'rss_bytes' : 'cpu_percent';
    usort($rows, fn(array $a, array $b): int => $b[$key] <=> $a[$key]);

    return ['available' => true, 'reason' => null, 'rows' => array_slice($rows, 0, $limit)];
}

/**
 * How long a process has been running, in seconds.
 *
 * `ps` writes elapsed time as `[[DD-]HH:]MM:SS`, which is readable and useless
 * for arithmetic. Null rather than zero when it cannot be read: zero would mean
 * "just started", which is the opposite of "not known" and exactly the sort of
 * thing somebody would act on.
 */
function parseElapsed(string $etime): ?int
{
    if (!preg_match('/^(?:(\d+)-)?(?:(\d+):)?(\d+):(\d+)$/', trim($etime), $m)) {
        return null;
    }

    return ((int)($m[1] ?: 0)) * 86400
        + ((int)($m[2] ?: 0)) * 3600
        + ((int)$m[3]) * 60
        + (int)$m[4];
}

/**
 * The short name a process should be grouped under.
 *
 * The first word of the command line, without its path - so every worker of the
 * same daemon lands in one row. Interpreters are the exception worth handling:
 * a hundred `php` processes tell you nothing, and `php cron.php` tells you
 * everything, so the script name comes along.
 *
 * Taken from `args` rather than from `comm`, which would be tidier on Linux and
 * unparseable on BSD: `comm` there is a full path that can contain spaces, and
 * a field with spaces in the middle of a space-separated line cannot be split
 * on reliably. The cost is that an executable whose *path* contains a space
 * shows only the part before it - vanishingly rare on a server, common in macOS
 * application bundles, so it looks worse on a laptop than where it will run.
 */
function processName(string $args): string
{
    $parts = preg_split('/\s+/', trim($args)) ?: [''];
    $name = basename($parts[0] ?? '');

    if (preg_match('/^(php|python[\d.]*|perl|ruby|node|bash|sh)$/i', $name)) {
        foreach (array_slice($parts, 1) as $part) {
            // The first thing that is not a flag is the script.
            if ($part !== '' && $part[0] !== '-') {
                return $name . ' ' . basename($part);
            }
        }
    }

    return $name !== '' ? $name : '(unknown)';
}

/**
 * Blanks out anything on a command line that looks like a secret.
 *
 * People put passwords in arguments. `ps` shows them to anyone on the box, and
 * without this they would be written into the database and rendered in a
 * browser as well - turning a monitoring tool into a second place the password
 * leaks from. It is a blunt filter and it does not need to be clever: erring
 * towards hiding an innocent argument costs nothing here.
 */
function redactSecrets(string $args): string
{
    $patterns = [
        // -pSECRET, as mysql and friends take it.
        '/(^|\s)(-p)(?!\s)\S+/i' => '$1$2[hidden]',
        // --password=SECRET, --token SECRET, api_key=SECRET, and the like.
        '/(--?(?:password|passwd|pwd|token|secret|api[-_]?key|auth)[=\s])\S+/i' => '$1[hidden]',
        '/((?:password|passwd|pwd|token|secret|auth|api[-_]?key|access[-_]?key)=)\S+/i' => '$1[hidden]',
    ];

    $clean = $args;
    foreach ($patterns as $pattern => $replacement) {
        $clean = (string)preg_replace($pattern, $replacement, $clean);
    }

    return mb_substr($clean, 0, 400);
}

/**
 * Writes down what was running, but only when something was worth writing down.
 *
 * Called after each vitals reading. The thresholds are the whole design: a
 * table of every process every minute grows faster than anything it could warn
 * you about, and a table of what was running during the three minutes the CPU
 * was pinned is small and answers the question.
 */
function recordProcesses(array $config, PDO $pdo, array $vitals): array
{
    $rules = $config['processes'] ?? [];
    $cpuAbove = (float)($rules['record_when_cpu_above'] ?? 70);
    $memoryAbove = (float)($rules['record_when_memory_above'] ?? 85);

    $cpu = $vitals['cpu_percent'];
    $memory = $vitals['mem_used_percent'];

    $reasons = [];
    if ($cpu !== null && $cpu >= $cpuAbove) {
        $reasons[] = 'cpu';
    }
    if ($memory !== null && $memory >= $memoryAbove) {
        $reasons[] = 'memory';
    }
    if (!$reasons) {
        return ['recorded' => 0, 'reasons' => []];
    }

    $snapshot = listProcesses('cpu', 500);
    if (!$snapshot['available']) {
        return ['recorded' => 0, 'reasons' => $reasons];
    }

    $grouped = $snapshot['rows'];
    $keep = max(1, (int)($rules['keep'] ?? PROCESS_KEEP_DEFAULT));

    $insert = $pdo->prepare(
        'INSERT INTO process_samples
            (taken_at, reason, name, cpu_percent, mem_percent, rss_bytes, procs,
             oldest_seconds, newest_seconds)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $written = 0;
    foreach ($reasons as $reason) {
        $ranked = $grouped;
        usort(
            $ranked,
            $reason === 'memory'
                ? fn(array $a, array $b): int => $b['rss_bytes'] <=> $a['rss_bytes']
                : fn(array $a, array $b): int => $b['cpu_percent'] <=> $a['cpu_percent']
        );

        foreach (array_slice($ranked, 0, $keep) as $row) {
            $insert->execute([
                (int)$vitals['taken_at'],
                $reason,
                $row['name'],
                round($row['cpu_percent'], 2),
                round($row['mem_percent'], 2),
                $row['rss_bytes'],
                $row['procs'],
                $row['oldest_seconds'],
                $row['newest_seconds'],
            ]);
            $written++;
        }
    }

    return ['recorded' => $written, 'reasons' => $reasons];
}

/**
 * Adds together every process sharing a name.
 *
 * The totals are sums, since that is what "how much is this thing costing me"
 * means. The pid and command kept are the heaviest single process of the group,
 * because those are only useful for going and looking at one of them, and the
 * biggest is the one worth looking at.
 */
function groupByName(array $rows): array
{
    $byName = [];

    foreach ($rows as $row) {
        $name = $row['name'];

        if (!isset($byName[$name])) {
            $byName[$name] = [
                'name' => $name,
                'cpu_percent' => 0.0,
                'mem_percent' => 0.0,
                'rss_bytes' => 0,
                'procs' => 0,
                'user' => $row['user'],
                'pid' => $row['pid'],
                'command' => $row['command'],
                /*
                 * The oldest and the youngest of the group.
                 *
                 * Both, because together they say something neither says
                 * alone: a pool up for forty days whose newest member is nine
                 * seconds old is churning, and that is a different fault from
                 * one that has simply been running a long time.
                 */
                'oldest_seconds' => null,
                'newest_seconds' => null,
                'heaviest' => -1,
            ];
        }

        $into = &$byName[$name];
        $into['cpu_percent'] += $row['cpu_percent'];
        $into['mem_percent'] += $row['mem_percent'];
        $into['rss_bytes'] += $row['rss_bytes'];
        $into['procs']++;

        $elapsed = $row['elapsed_seconds'];
        if ($elapsed !== null) {
            $into['oldest_seconds'] = $into['oldest_seconds'] === null
                ? $elapsed
                : max($into['oldest_seconds'], $elapsed);
            $into['newest_seconds'] = $into['newest_seconds'] === null
                ? $elapsed
                : min($into['newest_seconds'], $elapsed);
        }

        if ($row['rss_bytes'] > $into['heaviest']) {
            $into['heaviest'] = $row['rss_bytes'];
            $into['pid'] = $row['pid'];
            $into['command'] = $row['command'];
            $into['user'] = $row['user'];
        }
        unset($into);
    }

    foreach ($byName as &$row) {
        unset($row['heaviest']);
    }

    return array_values($byName);
}

/**
 * The busy moments that were recorded, newest first.
 *
 * Grouped by the instant they were taken, so the answer reads as "at 04:12 the
 * CPU was pinned and these five things were running" rather than as a flat list
 * of rows somebody has to reassemble.
 */
function recordedProcesses(PDO $pdo, int $limit = 12): array
{
    $moments = $pdo->prepare(
        'SELECT DISTINCT taken_at, reason FROM process_samples
         ORDER BY taken_at DESC LIMIT :limit'
    );
    $moments->bindValue(':limit', $limit, PDO::PARAM_INT);
    $moments->execute();

    $rows = $pdo->prepare(
        'SELECT name, cpu_percent, mem_percent, rss_bytes, procs,
                oldest_seconds, newest_seconds
         FROM process_samples WHERE taken_at = ? AND reason = ?
         ORDER BY ' . '(CASE WHEN ? = \'memory\' THEN rss_bytes ELSE cpu_percent END) DESC'
    );

    $out = [];
    foreach ($moments->fetchAll() as $moment) {
        $rows->execute([$moment['taken_at'], $moment['reason'], $moment['reason']]);
        $out[] = [
            'taken_at' => (int)$moment['taken_at'],
            'reason' => $moment['reason'],
            'rows' => $rows->fetchAll(),
        ];
    }

    return $out;
}
