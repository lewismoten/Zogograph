<?php
declare(strict_types=1);

/*
 * The newest lines from every log at once, and what keeps repeating in them.
 *
 * Two problems this solves that the single-file viewer cannot.
 *
 * The first is that a machine misbehaving does not tell you which log to open.
 * There are a dozen candidates - the account's error logs, each domain's, the
 * host's if it can be read - and the useful view is all of them merged, newest
 * first, because the line that explains it is the most recent one *somewhere*.
 *
 * The second is repetition. A log filling a disk is almost never full of
 * interesting distinct events; it is one event ten thousand times. Reading the
 * last hundred lines shows the same line a hundred times and no more. What is
 * needed is the *shape* of the line with its variable parts removed, counted -
 * "this happened 41,000 times" is a finding, and the same information as forty
 * one thousand lines nobody can read.
 *
 * Ordering across files is done on the timestamps in the lines where they can
 * be parsed, and by file modification time where they cannot. Which of the two
 * was used is reported, because a merged view sorted by the wrong clock is
 * confidently wrong about the sequence of events, and sequence is the whole
 * point of merging.
 */

require_once __DIR__ . '/logs.php';

/** How many lines to read from the end of each file before merging. */
const STREAM_PER_FILE = 60;

/** How much of each line to keep. Log lines can be enormous. */
const STREAM_MAX_LINE = 400;

/**
 * The newest lines across several files, merged.
 *
 * @param list<array{path: string, label: string}> $files
 * @return array{rows: list<array>, files: list<array>, ordering: string, note: string}
 */
function logStream(array $files, int $want = 20): array
{
    $want = max(1, min(200, $want));

    $rows = [];
    $read = [];
    $timed = 0;
    $untimed = 0;

    foreach (array_slice($files, 0, 40) as $file) {
        $path = (string)$file['path'];
        $label = (string)($file['label'] ?? basename($path));

        if (!is_file($path) || !is_readable($path)) {
            $read[] = ['path' => $path, 'label' => $label, 'ok' => false,
                       'reason' => 'not readable'];
            continue;
        }

        if (compressedExtension($path) !== null) {
            $read[] = ['path' => $path, 'label' => $label, 'ok' => false,
                       'reason' => 'compressed'];
            continue;
        }

        try {
            $tail = tailFile($path, STREAM_PER_FILE, 0, '');
        } catch (Throwable $e) {
            $read[] = ['path' => $path, 'label' => $label, 'ok' => false,
                       'reason' => $e->getMessage()];
            continue;
        }

        $mtime = (int)(@filemtime($path) ?: 0);
        $read[] = [
            'path' => $path, 'label' => $label, 'ok' => true, 'reason' => '',
            'lines' => count($tail['lines']), 'bytes' => $tail['size'], 'modified_at' => $mtime,
        ];

        foreach ($tail['lines'] as $line) {
            $text = (string)$line['text'];
            $at = logLineTime($text);

            if ($at === null) {
                $untimed++;
            } else {
                $timed++;
            }

            $rows[] = [
                'path' => $path,
                'label' => $label,
                'n' => (int)$line['n'],
                'text' => mb_substr($text, 0, STREAM_MAX_LINE),
                'at' => $at,
                /*
                 * The fallback clock, and it is a poor one.
                 *
                 * A file's mtime is when it was last written, which is the time
                 * of its *newest* line - so using it for every line from that
                 * file bunches them all at one moment. Good enough to decide
                 * which file is currently active, not good enough to interleave
                 * lines with. Hence saying which was used.
                 */
                'at_guess' => $mtime,
                'timed' => $at !== null,
            ];
        }
    }

    /*
     * Sorted newest first, on whichever clock each line has.
     *
     * A line with a real timestamp sorts on it. A line without sorts on its
     * file's mtime, which puts it near the other lines from the same file - the
     * least wrong place for it.
     */
    usort($rows, fn(array $a, array $b): int =>
        (($b['at'] ?? $b['at_guess']) <=> ($a['at'] ?? $a['at_guess']))
        ?: ($b['n'] <=> $a['n']));

    $ordering = $untimed === 0 ? 'timestamps'
        : ($timed === 0 ? 'file times' : 'mixed');

    return [
        'rows' => array_slice($rows, 0, $want),
        'files' => $read,
        'ordering' => $ordering,
        'note' => match ($ordering) {
            'timestamps' => '',
            'file times' => 'None of these lines carry a timestamp this recognises, so they '
                . 'are ordered by when each file was last written. Lines from one file keep '
                . 'their order; lines from different files may be interleaved wrongly.',
            default => sprintf(
                '%d of these lines carry a timestamp and %d do not. The ones without are '
                . 'placed by when their file was last written, which is only approximately '
                . 'where they belong.',
                $timed,
                $untimed
            ),
        },
    ];
}

/**
 * The time a log line claims for itself, or null.
 *
 * Four formats, which between them cover almost everything a web host writes:
 * Apache's bracketed form, the ISO-ish one most modern software uses, syslog's
 * monthless-year form, and the Common Log Format found in access logs.
 *
 * Returns null rather than guessing. A wrong timestamp is worse than none in a
 * merged view: it silently moves a line to the wrong place in the sequence, and
 * the sequence is the only reason to merge.
 */
function logLineTime(string $line): ?int
{
    $head = substr($line, 0, 120);

    // [Mon Aug 24 09:20:00 2026] or [Mon Aug 24 09:20:00.123456 2026]
    if (preg_match('/^\[([A-Z][a-z]{2} [A-Z][a-z]{2} +\d+ \d{2}:\d{2}:\d{2})(?:\.\d+)? (\d{4})\]/', $head, $m)) {
        $at = strtotime($m[1] . ' ' . $m[2]);
        return $at === false ? null : $at;
    }

    // 2026-08-24 09:20:00 or 2026-08-24T09:20:00Z or with an offset
    if (preg_match('/(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?)/', $head, $m)) {
        $at = strtotime(str_replace(',', '.', $m[1]));
        return $at === false ? null : $at;
    }

    // Common Log Format: [24/Aug/2026:09:20:00 +0000]
    if (preg_match('#\[(\d{2}/[A-Z][a-z]{2}/\d{4}):(\d{2}:\d{2}:\d{2}) ([+-]\d{4})\]#', $head, $m)) {
        $at = strtotime(str_replace('/', '-', $m[1]) . ' ' . $m[2] . ' ' . $m[3]);
        return $at === false ? null : $at;
    }

    /*
     * syslog: "Aug 24 09:20:00", with no year at all.
     *
     * The year is inferred as the current one, and rolled back when that would
     * put the line in the future - which is what happens across New Year. A
     * line from last December read on the 2nd of January is otherwise stamped
     * eleven months ahead of everything around it.
     */
    if (preg_match('/^([A-Z][a-z]{2} +\d+ \d{2}:\d{2}:\d{2})/', $head, $m)) {
        $year = (int)date('Y');
        $at = strtotime($m[1] . ' ' . $year);
        if ($at === false) {
            return null;
        }
        if ($at > time() + 86400) {
            $at = strtotime($m[1] . ' ' . ($year - 1));
        }
        return $at === false ? null : $at;
    }

    return null;
}

/**
 * Which lines keep coming back, with the variable parts taken out.
 *
 * The normalisation is the whole idea. Ten thousand lines differing only in a
 * PID, an IP and a request path are one event, and counting them as one event
 * with a count of ten thousand is the only presentation of that fact anybody
 * can act on.
 *
 * What is replaced is deliberately conservative: numbers, addresses, quoted
 * strings, hex blobs and bracketed fields. Replacing more would fold together
 * lines that are genuinely different - "permission denied" and "no such file"
 * must not become one row - and the failure mode of folding too much is that
 * the finding disappears into a generic bucket.
 *
 * @return array{groups: list<array>, lines: int, distinct: int}
 */
function repeatedLines(array $rows, int $keep = 15): array
{
    $groups = [];

    foreach ($rows as $row) {
        $text = (string)($row['text'] ?? '');
        if (trim($text) === '') {
            continue;
        }

        $shape = normaliseLogLine($text);
        if (!isset($groups[$shape])) {
            $groups[$shape] = [
                'shape' => $shape,
                'count' => 0,
                'example' => $text,
                'files' => [],
                'first_at' => null,
                'last_at' => null,
            ];
        }

        $groups[$shape]['count']++;
        $groups[$shape]['files'][(string)($row['label'] ?? '')] = true;

        $at = $row['at'] ?? null;
        if ($at !== null) {
            $groups[$shape]['first_at'] = $groups[$shape]['first_at'] === null
                ? $at : min($groups[$shape]['first_at'], $at);
            $groups[$shape]['last_at'] = $groups[$shape]['last_at'] === null
                ? $at : max($groups[$shape]['last_at'], $at);
        }
    }

    $out = [];
    foreach ($groups as $group) {
        $group['files'] = array_values(array_filter(array_keys($group['files'])));

        /*
         * A rate, where the timestamps allow one.
         *
         * "41,000 times" is a finding; "41,000 times in the last four minutes"
         * is an emergency, and the difference is not visible in the count.
         */
        $span = ($group['last_at'] ?? 0) - ($group['first_at'] ?? 0);
        $group['per_minute'] = $span > 0 ? round($group['count'] / ($span / 60), 1) : null;
        $group['span_seconds'] = $span > 0 ? $span : null;

        $out[] = $group;
    }

    usort($out, fn(array $a, array $b): int => $b['count'] <=> $a['count']);

    return [
        'groups' => array_slice($out, 0, $keep),
        'lines' => count($rows),
        'distinct' => count($groups),
    ];
}

/**
 * A log line with the parts that vary between occurrences taken out.
 *
 * Order matters here: the longer, more specific patterns run first, so an IPv4
 * address is replaced as an address rather than as four numbers.
 */
function normaliseLogLine(string $line): string
{
    $shape = $line;

    // Timestamps in the shapes logLineTime knows about.
    $shape = preg_replace('/\[[A-Z][a-z]{2} [A-Z][a-z]{2} +\d+ [\d:.]+ \d{4}\]/', '[TIME]', $shape);
    $shape = preg_replace('#\[\d{2}/[A-Z][a-z]{2}/\d{4}:[\d:]+ [+-]\d{4}\]#', '[TIME]', $shape);
    $shape = preg_replace('/\d{4}-\d{2}-\d{2}[T ][\d:]+(?:[.,]\d+)?(?:Z|[+-][\d:]+)?/', 'TIME', $shape);
    $shape = preg_replace('/^[A-Z][a-z]{2} +\d+ \d{2}:\d{2}:\d{2}/', 'TIME', $shape);

    // Addresses before numbers, or an IPv4 becomes four separate N's.
    $shape = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', 'IP', $shape);
    $shape = preg_replace('/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{0,4}\b/i', 'IP6', $shape);

    // Quoted runs, which in an access log are the whole request.
    $shape = preg_replace('/"[^"]*"/', '"S"', $shape);
    $shape = preg_replace("/'[^']*'/", "'S'", $shape);

    // Long hex - session ids, hashes, request ids.
    $shape = preg_replace('/\b[0-9a-f]{8,}\b/i', 'HEX', $shape);

    // Paths, which differ per request and are usually the only difference.
    $shape = preg_replace('#(?<![\w.])/[^\s:,()"\']{2,}#', '/PATH', $shape);

    // Anything left that is a number.
    $shape = preg_replace('/\b\d+\b/', 'N', $shape);

    // Whitespace last, so the collapsing does not merge tokens above.
    $shape = trim((string)preg_replace('/\s+/', ' ', (string)$shape));

    return mb_substr($shape, 0, 200);
}
