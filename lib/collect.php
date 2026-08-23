<?php
declare(strict_types=1);

/*
 * Taking the readings.
 *
 * Two kinds, on two clocks, because they cost wildly different amounts:
 *
 *   vitals - disk, inodes, load, memory, cpu. A handful of file reads. Cheap
 *            enough to take every minute from cron, and cheap enough that the
 *            dashboard can ask for a fresh one while somebody is watching a
 *            problem happen.
 *   scan   - walk the whole tree and size everything. Minutes of IO. Hourly at
 *            most, and the thing that answers "what is growing".
 *
 * Everything here is written so that a reading it cannot take comes back null
 * rather than throwing. A shared host that will not show you /proc should still
 * give you disk history.
 */

require_once __DIR__ . '/db.php';

/* ---------------------------------------------------------------- vitals -- */

function takeVitals(array $config, PDO $pdo, string $source = 'cron'): array
{
    $now = time();

    $disk = diskUsage($config['scan_root']);
    $inodes = inodeUsage($config['scan_root']);
    $mem = memoryUsage();
    $cpu = cpuTicks();
    $load = loadAverage();

    // Busy time since the last reading, whoever took it. Null on the first one,
    // because a rate needs two points and inventing one would be a lie.
    $percent = null;
    if ($cpu !== null) {
        $previous = $pdo->query(
            'SELECT cpu_total_ticks, cpu_idle_ticks FROM vitals
             WHERE cpu_total_ticks IS NOT NULL ORDER BY taken_at DESC, id DESC LIMIT 1'
        )->fetch();

        if ($previous && $cpu['total'] > (int)$previous['cpu_total_ticks']) {
            $totalDelta = $cpu['total'] - (int)$previous['cpu_total_ticks'];
            $idleDelta = $cpu['idle'] - (int)$previous['cpu_idle_ticks'];
            $percent = round(max(0.0, min(100.0, (1 - $idleDelta / $totalDelta) * 100)), 2);
        }
    }

    $row = [
        'taken_at' => $now,
        'disk_total_bytes' => $disk['total'],
        'disk_used_bytes' => $disk['used'],
        'disk_free_bytes' => $disk['free'],
        'disk_used_percent' => $disk['used_percent'],
        'inodes_total' => $inodes['total'],
        'inodes_used' => $inodes['used'],
        'inodes_free' => $inodes['free'],
        'inodes_used_percent' => $inodes['used_percent'],
        'load1' => $load[0],
        'load5' => $load[1],
        'load15' => $load[2],
        'cpu_total_ticks' => $cpu['total'] ?? null,
        'cpu_idle_ticks' => $cpu['idle'] ?? null,
        'cpu_percent' => $percent,
        'mem_total_bytes' => $mem['total'],
        'mem_available_bytes' => $mem['available'],
        'mem_used_percent' => $mem['used_percent'],
        'swap_total_bytes' => $mem['swap_total'],
        'swap_used_bytes' => $mem['swap_used'],
        'source' => $source,
    ];

    $columns = implode(', ', array_keys($row));
    $holders = ':' . implode(', :', array_keys($row));
    $pdo->prepare("INSERT INTO vitals ($columns) VALUES ($holders)")->execute($row);
    $row['id'] = (int)$pdo->lastInsertId();

    return $row;
}

function diskUsage(string $path): array
{
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false || $total <= 0) {
        return ['total' => null, 'used' => null, 'free' => null, 'used_percent' => null];
    }
    $used = $total - $free;
    return [
        'total' => (int)$total,
        'used' => (int)$used,
        'free' => (int)$free,
        'used_percent' => round(($used / $total) * 100, 2),
    ];
}

/**
 * Inodes, from `df -iP`.
 *
 * POSIX output, because the default columns differ between Linux and BSD and
 * the -P flag is the one thing both agree on.
 */
function inodeUsage(string $path): array
{
    $empty = ['total' => null, 'used' => null, 'free' => null, 'used_percent' => null];

    $out = shellRead('df -iP ' . escapeshellarg($path) . ' 2>/dev/null');
    if ($out === null) {
        return $empty;
    }

    $lines = preg_split('/\R/', trim($out)) ?: [];
    if (count($lines) < 2) {
        return $empty;
    }

    $fields = preg_split('/\s+/', trim($lines[count($lines) - 1])) ?: [];
    if (count($fields) < 5) {
        return $empty;
    }

    $total = (int)$fields[1];
    $used = (int)$fields[2];
    $free = (int)$fields[3];
    if ($total <= 0) {
        return $empty;
    }

    return [
        'total' => $total,
        'used' => $used,
        'free' => $free,
        'used_percent' => round(($used / $total) * 100, 2),
    ];
}

function loadAverage(): array
{
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    if (!is_array($load) || count($load) < 3) {
        return [null, null, null];
    }
    return [round($load[0], 2), round($load[1], 2), round($load[2], 2)];
}

/**
 * Total and idle CPU time since boot.
 *
 * /proc/stat on Linux. Elsewhere there is no equivalent counter, so the caller
 * gets null and the CPU chart is simply empty rather than wrong.
 */
function cpuTicks(): ?array
{
    $line = @file_get_contents('/proc/stat');
    if ($line !== false && preg_match('/^cpu\s+(.*)$/m', $line, $m)) {
        $parts = array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []);
        if (count($parts) >= 5) {
            // user nice system idle iowait irq softirq steal ...
            $total = array_sum($parts);
            $idle = $parts[3] + ($parts[4] ?? 0);
            return ['total' => $total, 'idle' => $idle];
        }
    }

    return null;
}

function memoryUsage(): array
{
    $empty = [
        'total' => null, 'available' => null, 'used_percent' => null,
        'swap_total' => null, 'swap_used' => null,
    ];

    $raw = @file_get_contents('/proc/meminfo');
    if ($raw === false) {
        return $empty;
    }

    $kb = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
            $kb[$m[1]] = (int)$m[2];
        }
    }
    if (!isset($kb['MemTotal']) || $kb['MemTotal'] <= 0) {
        return $empty;
    }

    $total = $kb['MemTotal'] * 1024;
    // MemAvailable is the kernel's own estimate of what a new process could
    // get. It is a far better number than free, which counts cache as used.
    $available = ($kb['MemAvailable'] ?? ($kb['MemFree'] ?? 0)) * 1024;
    $swapTotal = ($kb['SwapTotal'] ?? 0) * 1024;
    $swapFree = ($kb['SwapFree'] ?? 0) * 1024;

    return [
        'total' => $total,
        'available' => $available,
        'used_percent' => round((($total - $available) / $total) * 100, 2),
        'swap_total' => $swapTotal,
        'swap_used' => $swapTotal - $swapFree,
    ];
}

function shellRead(string $command): ?string
{
    if (!function_exists('shell_exec')) {
        return null;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return null;
    }
    $out = @shell_exec($command);
    return is_string($out) && trim($out) !== '' ? $out : null;
}

/* ------------------------------------------------------------------ scan -- */

/**
 * Walks the tree and records what is where.
 *
 * One pass. Every file is charged to at most one category, directory rules
 * first, so the totals add up to something meaningful. Directory sizes are
 * accumulated up the tree as the walk unwinds, which is what makes "this
 * directory got ten thousand files today" answerable at all.
 */
function runScan(array $config, PDO $pdo): array
{
    $started = time();
    $begin = microtime(true);

    $pdo->prepare('INSERT INTO scans (started_at) VALUES (?)')->execute([$started]);
    $scanId = (int)$pdo->lastInsertId();

    $root = normalisePath($config['scan_root']);
    $rules = $config['scan'];
    $excluded = array_map('normalisePath', $rules['exclude_paths'] ?? []);

    $dirBytes = [];
    $dirFiles = [];
    $fileBytes = [];
    $totals = [];
    $seenFiles = 0;
    $seenDirs = 0;
    $seenBytes = 0;
    $error = null;

    try {
        walk($root, $root, $rules, $excluded, $dirBytes, $dirFiles, $fileBytes,
             $totals, $seenFiles, $seenDirs, $seenBytes);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $top = $rules['top_count'] ?? 25;

    /*
     * Only the interesting paths are written.
     *
     * The whole point of keeping per-path history is to see what grows, and
     * writing every file in the home directory every hour would be a database
     * that grows faster than the disk it is watching. So: the biggest files,
     * the biggest directories, the biggest of each category, and the fullest
     * directories by count. A path that matters will be in one of those.
     */
    $keep = [];
    foreach (['file' => $fileBytes, 'dir' => $dirBytes] as $kind => $sizes) {
        arsort($sizes);
        foreach (array_slice($sizes, 0, $top, true) as $path => $bytes) {
            $keep[$path] = ['kind' => $kind, 'bytes' => $bytes];
        }
    }

    arsort($dirFiles);
    foreach (array_slice($dirFiles, 0, $top, true) as $path => $count) {
        $keep[$path] ??= ['kind' => 'dir', 'bytes' => $dirBytes[$path] ?? 0];
    }

    foreach (categoryTops($fileBytes, $dirBytes, $rules, $top, $root) as $path => $kind) {
        $keep[$path] ??= [
            'kind' => $kind,
            'bytes' => ($kind === 'dir' ? ($dirBytes[$path] ?? 0) : ($fileBytes[$path] ?? 0)),
        ];
    }

    $now = time();
    $pdo->beginTransaction();
    try {
        $insertSize = $pdo->prepare(
            'INSERT INTO path_sizes (scan_id, path_id, bytes, files) VALUES (?, ?, ?, ?)'
        );
        foreach ($keep as $path => $info) {
            $id = pathId(
                $pdo,
                $path,
                $info['kind'],
                $now,
                categoryOf($path . ($info['kind'] === 'dir' ? '/x' : ''), $rules, $root)
            );
            $insertSize->execute([
                $scanId,
                $id,
                (int)$info['bytes'],
                (int)($dirFiles[$path] ?? ($info['kind'] === 'file' ? 1 : 0)),
            ]);
        }

        $insertTotal = $pdo->prepare(
            'INSERT INTO scan_totals (scan_id, category, bytes, files) VALUES (?, ?, ?, ?)'
        );
        foreach (['trash', 'logs', 'temp'] as $category) {
            $insertTotal->execute([
                $scanId,
                $category,
                (int)($totals[$category]['bytes'] ?? 0),
                (int)($totals[$category]['files'] ?? 0),
            ]);
        }

        $pdo->prepare(
            'UPDATE scans SET finished_at = ?, duration_ms = ?, files_seen = ?,
                    dirs_seen = ?, bytes_seen = ?, error = ? WHERE id = ?'
        )->execute([
            time(),
            (int)round((microtime(true) - $begin) * 1000),
            $seenFiles,
            $seenDirs,
            $seenBytes,
            $error,
            $scanId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'scan_id' => $scanId,
        'files' => $seenFiles,
        'dirs' => $seenDirs,
        'bytes' => $seenBytes,
        'kept' => count($keep),
        'error' => $error,
    ];
}

/**
 * One directory, and everything under it.
 *
 * Recursive rather than an iterator so that a directory's own total is known
 * when the call returns - which is what lets a parent add its children's bytes
 * without a second pass over the tree.
 *
 * @return array{0:int,1:int} bytes and file count under this directory
 */
function walk(
    string $dir,
    string $root,
    array $rules,
    array $excluded,
    array &$dirBytes,
    array &$dirFiles,
    array &$fileBytes,
    array &$totals,
    int &$seenFiles,
    int &$seenDirs,
    int &$seenBytes,
    int $depth = 0
): array {
    if ($depth > 40 || isExcludedPath($dir, $excluded)) {
        return [0, 0];
    }

    $handle = @opendir($dir);
    if ($handle === false) {
        return [0, 0];
    }

    $seenDirs++;
    $bytes = 0;
    $files = 0;

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $entry;

        // Never follow a link. Following them double counts at best and walks
        // out of the scan root at worst.
        if (is_link($full)) {
            continue;
        }

        if (is_dir($full)) {
            [$childBytes, $childFiles] = walk(
                $full, $root, $rules, $excluded, $dirBytes, $dirFiles, $fileBytes,
                $totals, $seenFiles, $seenDirs, $seenBytes, $depth + 1
            );
            $bytes += $childBytes;
            $files += $childFiles;
            continue;
        }

        $size = @filesize($full);
        if ($size === false) {
            continue;
        }

        $bytes += $size;
        $files++;
        $seenFiles++;
        $seenBytes += $size;
        $fileBytes[$full] = $size;

        $category = categoryOf($full, $rules, $root);
        if ($category !== null) {
            $totals[$category]['bytes'] = ($totals[$category]['bytes'] ?? 0) + $size;
            $totals[$category]['files'] = ($totals[$category]['files'] ?? 0) + 1;
        }
    }

    closedir($handle);

    $dirBytes[$dir] = $bytes;
    $dirFiles[$dir] = $files;

    return [$bytes, $files];
}

/**
 * Which category a path counts against, or null.
 *
 * Directory names win over extensions, so a `.tmp` inside a log directory is
 * logs. A file is charged once and once only, which is what makes the three
 * totals meaningful next to each other.
 *
 * Only the part below the scan root is examined. Whatever the account happens
 * to live under is not the user's doing and not their problem - a home
 * directory at /var/tmp/sites/example would otherwise have every single file in
 * it classified as temporary, and the "empty temp" button would offer to delete
 * the lot.
 */
function categoryOf(string $path, array $rules, string $root = ''): ?string
{
    $lower = strtolower($path);
    if ($root !== '') {
        $lowerRoot = strtolower(rtrim($root, '/'));
        if (str_starts_with($lower, $lowerRoot . '/')) {
            $lower = substr($lower, strlen($lowerRoot) + 1);
        }
    }

    $parts = explode('/', trim($lower, '/'));
    $dirs = array_slice($parts, 0, -1);

    foreach ($rules['category_dirs'] ?? [] as $category => $names) {
        foreach ($names as $name) {
            if (in_array(strtolower($name), $dirs, true)) {
                return $category;
            }
        }
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension !== '') {
        foreach ($rules['category_extensions'] ?? [] as $category => $extensions) {
            foreach ($extensions as $candidate) {
                if ($extension === strtolower($candidate)) {
                    return $category;
                }
            }
        }
    }

    return null;
}

/** The biggest files and directories within each category. */
function categoryTops(
    array $fileBytes,
    array $dirBytes,
    array $rules,
    int $top,
    string $root
): array {
    $picked = [];
    foreach (array_keys($rules['category_dirs'] ?? []) as $category) {
        foreach (['file' => $fileBytes, 'dir' => $dirBytes] as $kind => $sizes) {
            $inCategory = [];
            foreach ($sizes as $path => $bytes) {
                if (categoryOf($path . ($kind === 'dir' ? '/x' : ''), $rules, $root) === $category) {
                    $inCategory[$path] = $bytes;
                }
            }
            arsort($inCategory);
            foreach (array_slice($inCategory, 0, $top, true) as $path => $bytes) {
                $picked[$path] = $kind;
            }
        }
    }
    return $picked;
}

function normalisePath(string $path): string
{
    $real = realpath($path);
    return rtrim($real !== false ? $real : $path, DIRECTORY_SEPARATOR);
}

function isExcludedPath(string $path, array $excluded): bool
{
    $path = normalisePath($path);
    foreach ($excluded as $exclude) {
        if ($path === $exclude || str_starts_with($path . '/', $exclude . '/')) {
            return true;
        }
    }
    return false;
}
