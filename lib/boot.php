<?php
declare(strict_types=1);

/*
 * The error boundary. Loaded first, before anything that can fail.
 *
 * The old handling was a try/catch around the request, which sounds like
 * enough and is not: a fatal during `require` happens before the try block
 * exists, so it escaped entirely. What the browser got was either a blank 500
 * or - worse, with display_errors on - HTML warnings printed into the middle
 * of a JSON body, with a 200 status on top. Both are unreadable, and the
 * second is a lie.
 *
 * So the boundary is installed at the very top of the request and catches all
 * three ways PHP can fail:
 *
 *   set_error_handler       warnings and notices
 *   set_exception_handler   anything thrown and not caught
 *   register_shutdown_function  fatals, which no handler can catch
 *
 * Every one of them ends the same way: a JSON body, the right status code, and
 * a line in a log file this dashboard can show you. Nothing is ever printed
 * into a response by PHP itself.
 */

/** Where our own log lives when the config has not been read yet. */
const BOOT_FALLBACK_LOG = __DIR__ . '/../data/error.log';

/** Stop the log growing into the thing it is there to warn you about. */
const BOOT_LOG_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Installs the boundary.
 *
 * `$format` is 'json' for the API and 'html' for pages a person reads. Both
 * always produce a body; the difference is only what it looks like.
 */
function installErrorBoundary(string $format = 'json'): void
{
    /*
     * PHP must never write to the response itself.
     *
     * Anything it prints lands in the middle of whatever we were sending, and
     * on an API that means a JSON parse failure in the browser instead of the
     * actual error. It all goes to the log instead.
     */
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // Warnings and notices are recorded and execution continues: a notice
        // is not a reason to fail a request that would otherwise work.
        if (!in_array($severity, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            logProblem('warning', sprintf('%s in %s:%d', $message, $file, $line));
            return true;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $e) use ($format): void {
        respondWithFailure($format, $e->getMessage(), $e->getFile(), $e->getLine(), get_class($e));
    });

    register_shutdown_function(static function () use ($format): void {
        $last = error_get_last();
        if ($last === null) {
            return;
        }
        if (!in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        respondWithFailure($format, $last['message'], $last['file'], $last['line'], 'FatalError');
    });
}

/**
 * Says something went wrong, in whatever form the caller can read.
 *
 * The detail goes to the log and a short reference goes to the browser. Not to
 * be unhelpful - the reference is printed on the page and the dashboard will
 * show you the matching entry - but because the message names file paths and
 * sometimes SQL, and this endpoint answers to people who are not signed in.
 */
function respondWithFailure(
    string $format,
    string $message,
    string $file,
    int $line,
    string $kind
): void {
    $reference = substr(bin2hex(random_bytes(4)), 0, 8);
    logProblem('error', sprintf('[%s] %s: %s in %s:%d', $reference, $kind, $message, $file, $line));

    /*
     * Anything already half-written is thrown away.
     *
     * A fatal in the middle of building a response leaves a truncated body in
     * the output buffer, and appending an error to it produces something that
     * is neither valid JSON nor a readable page.
     */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Cache-Control: no-store');
        header('Content-Type: ' . ($format === 'json' ? 'application/json' : 'text/html; charset=utf-8'));
    }

    if ($format === 'json') {
        echo json_encode([
            'error' => 'Something went wrong on the server.',
            'reference' => $reference,
            'hint' => 'Sign in and open "Recent problems" on the dashboard, '
                . 'or read data/error.log, for the details behind this reference.',
        ]);
        return;
    }

    printf(
        '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<body style="font-family:system-ui;background:#111;color:#eee;padding:24px;max-width:60ch">'
        . '<h1 style="font-size:1.2rem">Something went wrong on the server.</h1>'
        . '<p>Reference <code style="background:#000;padding:2px 6px;border-radius:4px">%s</code></p>'
        . '<p style="color:#9a9a9a">The details are in <code>data/error.log</code>, '
        . 'and on the dashboard under "Recent problems" once you are signed in.</p>',
        htmlspecialchars($reference, ENT_QUOTES, 'UTF-8')
    );
}

/**
 * Writes one line to our own log.
 *
 * Our own, rather than relying on PHP's, because where PHP puts its log is a
 * host-by-host lottery - often nowhere at all, which is exactly the situation
 * that makes a 500 impossible to diagnose. This one has a known path, is denied
 * by data/.htaccess, and the dashboard can read it back.
 */
function logProblem(string $level, string $message): void
{
    $path = defined('DISKMON_LOG') ? DISKMON_LOG : BOOT_FALLBACK_LOG;

    $line = sprintf(
        "%s\t%s\t%s\t%s\n",
        date('c'),
        $level,
        $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '-'),
        str_replace(["\r", "\n"], ' ', $message)
    );

    // Also to PHP's log, for whoever is reading that instead.
    error_log('[diskmon] ' . trim($line));

    $directory = dirname($path);
    if (!is_dir($directory)) {
        // Nowhere to write it. PHP's own log above is all there is.
        return;
    }

    /*
     * Truncated rather than rotated when it gets too big.
     *
     * A monitor whose own log fills the disk would be a poor advertisement, and
     * keeping one old copy would double the worst case for no real benefit -
     * the recent entries are the ones anybody reads.
     */
    if (is_file($path) && filesize($path) > BOOT_LOG_MAX_BYTES) {
        @file_put_contents($path, "-- truncated at " . date('c') . " --\n");
    }

    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    @chmod($path, 0600);
}

/**
 * The last lines of our own log, newest first.
 *
 * @return list<array{at: string, level: string, where: string, message: string}>
 */
function recentProblems(string $path, int $limit = 60): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -$limit);

    $out = [];
    foreach (array_reverse($lines) as $line) {
        $parts = explode("\t", $line, 4);
        if (count($parts) < 4) {
            $out[] = ['at' => '', 'level' => 'note', 'where' => '', 'message' => $line];
            continue;
        }
        $out[] = ['at' => $parts[0], 'level' => $parts[1], 'where' => $parts[2], 'message' => $parts[3]];
    }

    return $out;
}

/**
 * The same problem, once, with a count.
 *
 * A failing endpoint is not one problem: the dashboard polls, every poll calls
 * the API, and one broken function becomes forty identical lines within a
 * minute. Listed flat that reads as forty faults and buries the three others
 * that are actually different.
 *
 * The grouping key has to ignore the reference. Every entry carries its own
 * `[bd94dc4b]` so a failed request on screen can be matched to its cause here -
 * which means two records of the identical fault never match as text. Stripping
 * it is the whole trick; one sample reference is kept so the link back still
 * works.
 *
 * @return array{rows: list<array>, lines: int}
 */
function groupedProblems(string $path, int $scan = 4000): array
{
    if (!is_file($path)) {
        return ['rows' => [], 'lines' => 0];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $total = count($lines);

    $groups = [];
    foreach (array_slice($lines, -$scan) as $line) {
        $parts = explode("\t", $line, 4);
        if (count($parts) < 4) {
            $parts = ['', 'note', '', $line];
        }
        [$at, $level, $where, $message] = $parts;

        $reference = preg_match('/\[([0-9a-f]{8})\]/', $message, $m) ? $m[1] : null;
        $canonical = trim((string)preg_replace('/\[[0-9a-f]{8}\]\s*/', '', $message));

        $key = $level . "\0" . $where . "\0" . $canonical;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'level' => $level,
                'where' => $where,
                'message' => $canonical,
                'reference' => $reference,
                'count' => 0,
                'first' => $at,
                'last' => $at,
            ];
        }

        $groups[$key]['count']++;
        $groups[$key]['last'] = $at;
        $groups[$key]['reference'] ??= $reference;
    }

    // Most recent first: what broke last is what somebody is looking for.
    $rows = array_values($groups);
    usort($rows, fn(array $a, array $b): int => strcmp((string)$b['last'], (string)$a['last']));

    return ['rows' => $rows, 'lines' => $total];
}

/**
 * Empties the problem log.
 *
 * Truncated rather than deleted, so the file keeps whatever ownership and
 * permissions it already had - recreating it later as a different user is how a
 * log silently stops being written to.
 *
 * There is no per-entry delete. Entries are lines in a text file with no
 * identity beyond their position, so "dismiss this one" would mean rewriting
 * the file around a line number that shifts every time anything is appended.
 * Grouping made the list readable; clearing it is the honest way to reset it.
 */
function clearProblems(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $handle = @fopen($path, 'w');
    if ($handle === false) {
        throw new RuntimeException('the problem log is not writable');
    }
    fclose($handle);

    return count($lines);
}
