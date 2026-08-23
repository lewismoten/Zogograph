<?php
declare(strict_types=1);

/*
 * Reading the end of a log file.
 *
 * The whole reason this exists is a ten gigabyte `error_log`, so the one rule
 * that governs everything here is: never read the file. Not into memory, not
 * into a string, not with file(), not with SplFileObject in a loop from the
 * top. The end of a log is at the end, so the reader seeks there and walks
 * backwards in blocks, and the cost of showing the last two hundred lines of a
 * ten gigabyte file is the same as for a ten kilobyte one.
 *
 * Searching cannot be free in the same way - a match might be anywhere - so it
 * is bounded instead: it scans backwards through a fixed budget of bytes and
 * says so when it stops early. A search that quietly gave up would be worse
 * than one that admits it only looked at the last sixty-four megabytes.
 */

require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/collect.php';

/** Read backwards in blocks of this size. */
const TAIL_BLOCK = 65536;
/** The furthest a search will read before giving up and saying so. */
const TAIL_SEARCH_BUDGET = 64 * 1024 * 1024;
/** Longest single line kept. A log with no newlines is not a log. */
const TAIL_MAX_LINE = 8192;

/**
 * Resolves a path the signed-in user may read.
 *
 * Narrower than the delete rules and separate from them on purpose: being
 * allowed to *look at* a file and being allowed to remove it are different
 * permissions, and the set of things worth reading (logs, error logs) is not
 * the set of things worth deleting (trash, temp).
 */
function resolveViewable(array $config, string $requested): string
{
    if ($requested === '' || str_contains($requested, "\0")) {
        throw new ActionRefused('empty or malformed path');
    }

    $real = realpath($requested);
    if ($real === false || !is_file($real)) {
        throw new ActionRefused('no such file');
    }

    $root = realpath((string)$config['scan_root']);
    if ($root === false || !str_starts_with($real . '/', rtrim($root, '/') . '/')) {
        throw new ActionRefused('outside the scan root');
    }
    if (is_link($requested) || is_link($real)) {
        throw new ActionRefused('symlinks are not read');
    }

    $dataDir = realpath(dirname((string)$config['database']));
    if ($dataDir !== false && str_starts_with($real . '/', $dataDir . '/')) {
        throw new ActionRefused('not the monitor\'s own data');
    }

    $category = categoryOf($real, $config['scan'], $root);
    $allowed = $config['viewable_categories'] ?? ['logs', 'errors'];
    if ($category === null || !in_array($category, $allowed, true)) {
        throw new ActionRefused('only log files can be read here');
    }

    if (!is_readable($real)) {
        throw new ActionRefused('the file is not readable by this user');
    }

    return $real;
}

/**
 * The last lines of a file, oldest first, without reading the whole thing.
 *
 * `page` counts backwards from the end: page 0 is the last `lines`, page 1 the
 * `lines` before those. With a `needle`, only matching lines are counted, so
 * paging walks through matches rather than through the file.
 *
 * @return array{
 *   lines: list<array{n:int, text:string}>,
 *   size:int, scanned:int, truncated:bool, reached_start:bool,
 *   total_lines:?int, matched:int
 * }
 */
function tailFile(
    string $path,
    int $lines = 200,
    int $page = 0,
    string $needle = '',
    bool $caseSensitive = false
): array {
    $lines = max(1, min(2000, $lines));
    $page = max(0, $page);

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new ActionRefused('could not open the file');
    }

    $size = (int)max(0, @filesize($path) ?: 0);
    $position = $size;
    $remainder = '';
    $collected = [];
    $skip = $page * $lines;
    $matched = 0;
    $scanned = 0;
    $searching = $needle !== '';
    $budget = $searching ? TAIL_SEARCH_BUDGET : PHP_INT_MAX;

    /*
     * Line numbers are counted from the end, negative-style, then flipped at
     * the finish. Counting from the start would mean knowing how many lines the
     * file has, which means reading all of it - the one thing we will not do.
     */
    $fromEnd = 0;

    while ($position > 0 && count($collected) < $lines && $scanned < $budget) {
        $read = (int)min(TAIL_BLOCK, $position);
        $position -= $read;
        fseek($handle, $position);
        $block = (string)fread($handle, $read);
        $scanned += $read;

        $chunk = $block . $remainder;
        $parts = explode("\n", $chunk);
        // The first piece may be half a line: the rest of it is further back.
        $remainder = $position > 0 ? array_shift($parts) : '';

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $text = rtrim($parts[$i], "\r");
            $fromEnd++;

            if ($text === '' && $i === count($parts) - 1 && $fromEnd === 1) {
                // The trailing newline every well-behaved log ends with.
                $fromEnd--;
                continue;
            }

            if ($searching && !lineMatches($text, $needle, $caseSensitive)) {
                continue;
            }

            $matched++;
            if ($skip > 0) {
                $skip--;
                continue;
            }

            $collected[] = [
                'n' => $fromEnd,
                'text' => strlen($text) > TAIL_MAX_LINE
                    ? substr($text, 0, TAIL_MAX_LINE) . ' …[line truncated]'
                    : $text,
            ];

            if (count($collected) >= $lines) {
                break;
            }
        }
    }

    fclose($handle);

    // Gathered newest-first because that is the direction it was read; shown
    // oldest-first because that is the direction a log is read.
    $collected = array_reverse($collected);

    return [
        'lines' => $collected,
        'size' => $size,
        'scanned' => $scanned,
        'truncated' => $searching && $scanned >= $budget && $position > 0,
        'reached_start' => $position <= 0,
        'matched' => $matched,
        'searching' => $searching,
    ];
}

function lineMatches(string $line, string $needle, bool $caseSensitive): bool
{
    return $caseSensitive
        ? str_contains($line, $needle)
        : stripos($line, $needle) !== false;
}
