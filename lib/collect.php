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
require_once __DIR__ . '/configure.php';

/* ---------------------------------------------------------------- vitals -- */

function takeVitals(
    array $config,
    PDO $pdo,
    string $source = 'cron',
    string $procRoot = '/proc'
): array {
    $now = time();

    $disk = diskUsage($config['scan_root']);
    $inodes = inodeUsage($config['scan_root']);
    $mem = memoryUsage();
    $cpu = cpuTicks();
    $load = loadAverage();
    $net = networkCounters();
    // Injectable, so the rate arithmetic below can be driven from a fixture
    // rather than only on the machine it is aimed at.
    $disks = diskStats($procRoot);
    $ownIo = processIoTotals($procRoot);
    $pressure = pressureIo($procRoot);

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

    /*
     * Throughput since the last reading.
     *
     * Null on the first one, and null again after a reboot: the counters are
     * cumulative since boot, so a smaller number than last time means the
     * machine restarted, and dividing that difference would report a
     * spectacular negative transfer rate.
     */
    $rxRate = null;
    $txRate = null;
    if ($net !== null) {
        $previous = $pdo->query(
            'SELECT taken_at, net_rx_bytes, net_tx_bytes FROM vitals
             WHERE net_rx_bytes IS NOT NULL ORDER BY taken_at DESC, id DESC LIMIT 1'
        )->fetch();

        if ($previous) {
            $seconds = $now - (int)$previous['taken_at'];
            $rxDelta = $net['rx'] - (int)$previous['net_rx_bytes'];
            $txDelta = $net['tx'] - (int)$previous['net_tx_bytes'];
            if ($seconds > 0 && $rxDelta >= 0 && $txDelta >= 0) {
                $rxRate = round($rxDelta / $seconds, 2);
                $txRate = round($txDelta / $seconds, 2);
            }
        }
    }

    /*
     * I/O rates, on the same terms as the network ones above.
     *
     * Utilisation comes from io_ms, the milliseconds during which the device
     * had anything in flight: divided by the elapsed wall clock it is the share
     * of time the disk was doing something. Capped at 100 because with several
     * devices summed it can exceed a single device's worth, and a disk that is
     * 340% busy is not a sentence anybody should have to read.
     */
    $ioRead = $ioWrite = $ioUtil = null;
    if ($disks !== null) {
        $previous = $pdo->query(
            'SELECT taken_at, io_read_bytes, io_write_bytes, io_ms FROM vitals
             WHERE io_read_bytes IS NOT NULL ORDER BY taken_at DESC, id DESC LIMIT 1'
        )->fetch();

        if ($previous) {
            $seconds = $now - (int)$previous['taken_at'];
            $readDelta = $disks['total']['read_bytes'] - (int)$previous['io_read_bytes'];
            $writeDelta = $disks['total']['write_bytes'] - (int)$previous['io_write_bytes'];
            $busyDelta = $disks['total']['io_ms'] - (int)$previous['io_ms'];

            if ($seconds > 0 && $readDelta >= 0 && $writeDelta >= 0 && $busyDelta >= 0) {
                $ioRead = round($readDelta / $seconds, 2);
                $ioWrite = round($writeDelta / $seconds, 2);
                $ioUtil = round(min(100.0, $busyDelta / ($seconds * 10)), 2);
            }
        }
    }

    $ownRead = $ownWrite = null;
    if ($ownIo !== null) {
        $previous = $pdo->query(
            'SELECT taken_at, own_read_bytes, own_write_bytes FROM vitals
             WHERE own_read_bytes IS NOT NULL ORDER BY taken_at DESC, id DESC LIMIT 1'
        )->fetch();

        if ($previous) {
            $seconds = $now - (int)$previous['taken_at'];
            $readDelta = $ownIo['read'] - (int)$previous['own_read_bytes'];
            $writeDelta = $ownIo['write'] - (int)$previous['own_write_bytes'];
            /*
             * Negative is normal here, unlike for the device counters: these
             * are summed over whatever processes exist right now, so a busy
             * process exiting takes its lifetime total out of the sum. There is
             * no rate to report for that interval, only an unknown.
             */
            if ($seconds > 0 && $readDelta >= 0 && $writeDelta >= 0) {
                $ownRead = round($readDelta / $seconds, 2);
                $ownWrite = round($writeDelta / $seconds, 2);
            }
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
        'net_rx_bytes' => $net['rx'] ?? null,
        'net_tx_bytes' => $net['tx'] ?? null,
        'net_rx_rate' => $rxRate,
        'net_tx_rate' => $txRate,

        'io_read_bytes' => $disks['total']['read_bytes'] ?? null,
        'io_write_bytes' => $disks['total']['write_bytes'] ?? null,
        'io_ms' => $disks['total']['io_ms'] ?? null,
        'io_read_rate' => $ioRead,
        'io_write_rate' => $ioWrite,
        'io_util_percent' => $ioUtil,

        'own_read_bytes' => $ownIo['read'] ?? null,
        'own_write_bytes' => $ownIo['write'] ?? null,
        'own_read_rate' => $ownRead,
        'own_write_rate' => $ownWrite,

        'io_pressure_some' => $pressure['some10'] ?? null,
        'io_pressure_full' => $pressure['full10'] ?? null,
        'cpu_cores' => cpuCores(),
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

/**
 * Bytes in and out since boot, summed over the real interfaces.
 *
 * Loopback is excluded because it is not bandwidth - a busy local database can
 * push gigabytes over `lo` without a byte leaving the machine, and counting it
 * makes the graph a measure of something nobody is asking about.
 *
 * @return array{rx: int, tx: int}|null
 */
function networkCounters(): ?array
{
    $raw = @file_get_contents('/proc/net/dev');
    if ($raw === false) {
        return null;
    }

    $rx = 0;
    $tx = 0;
    $found = false;

    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        if (!preg_match('/^\s*([^:]+):\s*(.*)$/', $line, $m)) {
            continue;
        }
        $name = trim($m[1]);
        if ($name === 'lo' || str_starts_with($name, 'veth') || str_starts_with($name, 'docker')) {
            continue;
        }
        $fields = preg_split('/\s+/', trim($m[2])) ?: [];
        if (count($fields) < 9) {
            continue;
        }
        $rx += (int)$fields[0];
        $tx += (int)$fields[8];
        $found = true;
    }

    return $found ? ['rx' => $rx, 'tx' => $tx] : null;
}

/** How many cores the load average is spread over, or null. */
function cpuCores(): ?int
{
    $raw = @file_get_contents('/proc/cpuinfo');
    if ($raw !== false) {
        $count = preg_match_all('/^processor\s*:/m', $raw);
        if ($count > 0) {
            return $count;
        }
    }

    $nproc = shellRead('getconf _NPROCESSORS_ONLN 2>/dev/null');
    return $nproc !== null && ctype_digit(trim($nproc)) ? (int)trim($nproc) : null;
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

    $seenInodes = [];
    $linkCounts = [];

    try {
        walk($root, $root, $rules, $excluded, $dirBytes, $dirFiles, $fileBytes,
             $totals, $seenFiles, $seenDirs, $seenBytes, $seenInodes, $linkCounts);
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
            'INSERT INTO path_sizes (scan_id, path_id, bytes, files, links)
             VALUES (?, ?, ?, ?, ?)'
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
                $linkCounts[$path] ?? null,
            ]);
        }

        $insertTotal = $pdo->prepare(
            'INSERT INTO scan_totals (scan_id, category, bytes, files) VALUES (?, ?, ?, ?)'
        );
        foreach (categoryNames($rules) as $category) {
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
/*
 * One file can live at several paths.
 *
 * A hard link is not a copy: two directory entries pointing at one inode share
 * a single allocation on disk. Apache does this routinely - the same log is
 * published at /etc/apache2/logs/domlogs/site and again under a per-account
 * directory - and `ls -l` gives it away with a link count of 2:
 *
 *     -rw-r----- 2 root regaldra 199M ... git.lewismoten.com-ssl_log
 *
 * Adding both paths up reports 398 MB of disk that does not exist, and the
 * error compounds: the totals are wrong, the category is wrong, the growth
 * figures are wrong, and the projection built on them is wrong. So bytes are
 * counted once per unique (device, inode), not once per path.
 *
 * Which path gets the bytes is whichever the walk reaches first, so directory
 * subtotals are order-dependent. That is exactly what `du` does, and for the
 * same reason: any other choice makes the subtotals stop adding up to the
 * total. The alternative - counting it in both and once in the grand total -
 * produces a table whose column does not sum to its own footer.
 *
 * The set only holds files with more than one link. Almost every file has
 * exactly one, and remembering every inode in a home directory would cost more
 * memory than the scan is allowed to use.
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
    array &$seenInodes = [],
    array &$linkCounts = [],
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
                $totals, $seenFiles, $seenDirs, $seenBytes, $seenInodes, $linkCounts,
                $depth + 1
            );
            $bytes += $childBytes;
            $files += $childFiles;
            continue;
        }

        /*
         * stat rather than filesize, for the link count and the inode.
         *
         * One syscall gives size, device, inode and links together; filesize()
         * is that same stat with everything but the size thrown away.
         */
        $stat = @stat($full);
        if ($stat === false) {
            continue;
        }

        $size = (int)$stat['size'];
        $links = (int)($stat['nlink'] ?? 1);

        /*
         * A second path onto an inode already counted contributes nothing.
         *
         * It is still recorded and still shown - you need to see that the path
         * exists, and which of them to remove - but its bytes are already in
         * the totals under the first path that reached them.
         */
        $duplicate = false;
        if ($links > 1) {
            $key = $stat['dev'] . ':' . $stat['ino'];
            $duplicate = isset($seenInodes[$key]);
            $seenInodes[$key] = true;
            $linkCounts[$full] = $links;
        }

        $fileBytes[$full] = $size;

        if ($duplicate) {
            // Counted as a file, because it is one, but not as bytes.
            $files++;
            $seenFiles++;
            continue;
        }

        $bytes += $size;
        $files++;
        $seenFiles++;
        $seenBytes += $size;

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

    /*
     * A whole filename wins over everything else.
     *
     * Checked first and against the full path, before the scan root is even
     * trimmed off, because these are the files that hide: `error_log` inside a
     * `logs/` directory would otherwise be counted as logs and vanish into a
     * total, when the whole reason for the rule is that one of them can be ten
     * gigabytes on its own.
     */
    $name = strtolower(basename($path));
    foreach ($rules['category_files'] ?? [] as $category => $names) {
        foreach ($names as $candidate) {
            if ($name === strtolower($candidate)) {
                return $category;
            }
        }
    }

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
    foreach (categoryNames($rules) as $category) {
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

/* ------------------------------------------------------------- disk i/o -- */

/*
 * Disk I/O, from two angles that answer different questions.
 *
 * /proc/diskstats is the device: how much the disk itself is doing, and how
 * close to saturated it is. Inside CageFS an account can usually still read it,
 * but what it reports is the *host's* disk - every account's traffic together -
 * so it says "the disk is busy" and never "you are the reason".
 *
 * /proc/<pid>/io is the other half. Summed across the processes this user can
 * see it gives that user's own block-layer traffic, and inside a cage the only
 * processes visible are the account's own - which makes the sum exactly "what
 * this account is doing to the disk". That is the number an account can act on.
 *
 * Both are counters since boot, so both are stored raw and turned into rates
 * against the previous reading, the same way the network and CPU figures are.
 */

/** Bytes per sector in /proc/diskstats. Fixed at 512 regardless of the device. */
const DISKSTAT_SECTOR = 512;

/**
 * Per-device I/O counters, plus a total across the real devices.
 *
 * Partitions are excluded, or every byte is counted twice: /proc/diskstats
 * lists sda and sda1 side by side and the partition's traffic is already in the
 * whole disk's. A name is treated as a partition when stripping its trailing
 * digits - and an optional `p`, for nvme0n1p1 - leaves another name in the same
 * file.
 *
 * @return array{devices: array<string, array>, total: array}|null
 */
function diskStats(string $procRoot = '/proc'): ?array
{
    $raw = @file_get_contents($procRoot . '/diskstats');
    if ($raw === false) {
        return null;
    }

    $rows = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        // major minor name then at least the eleven classic counters.
        if (count($parts) < 14) {
            continue;
        }
        $name = $parts[2];
        $f = array_slice($parts, 3);

        $rows[$name] = [
            'name' => $name,
            'read_ios' => (int)$f[0],
            'read_bytes' => (int)$f[2] * DISKSTAT_SECTOR,
            'read_ms' => (int)$f[3],
            'write_ios' => (int)$f[4],
            'write_bytes' => (int)$f[6] * DISKSTAT_SECTOR,
            'write_ms' => (int)$f[7],
            'in_flight' => (int)$f[8],
            // Milliseconds during which anything at all was in flight. Divided
            // by elapsed time this is utilisation - the figure that says the
            // disk itself is the bottleneck rather than merely busy.
            'io_ms' => (int)$f[9],
            'queue_ms' => (int)$f[10],
        ];
    }

    if (!$rows) {
        return null;
    }

    $names = array_keys($rows);
    $devices = [];
    foreach ($rows as $name => $row) {
        if (preg_match('/^(loop|ram|zram|fd|sr)\d/', $name)) {
            continue;
        }
        $parent = preg_replace('/p?\d+$/', '', $name);
        if ($parent !== $name && in_array($parent, $names, true)) {
            continue;
        }

        /*
         * Stacked devices are listed but kept out of the total.
         *
         * LVM and software RAID sit on top of real hardware: a write to dm-0 is
         * remapped onto sda, and *both* counters advance for the one write.
         * Adding them together reports twice the traffic that happened - the
         * same mistake as counting a hard link twice, one layer down. They are
         * still worth showing individually, because "which volume is busy" is a
         * real question; they are just not additional bytes.
         */
        $row['stacked'] = (bool)preg_match('/^(dm-|md)/', $name);
        $devices[$name] = $row;
    }

    $total = ['read_ios' => 0, 'read_bytes' => 0, 'write_ios' => 0,
              'write_bytes' => 0, 'io_ms' => 0, 'queue_ms' => 0];
    foreach ($devices as $row) {
        if ($row['stacked']) {
            continue;
        }
        foreach ($total as $key => $_) {
            $total[$key] += $row[$key];
        }
    }

    return ['devices' => $devices, 'total' => $total];
}

/**
 * Pressure stall information for I/O, where the kernel provides it.
 *
 * The best single answer to "is the disk actually hurting anything", and better
 * than utilisation for it. A device can sit at 100% utilised and bother nobody
 * if the work is asynchronous writeback; PSI measures the opposite thing -
 * whether tasks are *stalled waiting*, which is what makes a machine feel slow.
 *
 * `some` is the share of time at least one task was blocked on I/O, `full` the
 * share when every runnable task was. Full above a few percent means the
 * machine is doing nothing but waiting for the disk.
 *
 * @return array{some10: float, some60: float, full10: float, full60: float}|null
 */
function pressureIo(string $procRoot = '/proc'): ?array
{
    $raw = @file_get_contents($procRoot . '/pressure/io');
    if ($raw === false) {
        return null;
    }

    $out = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        if (preg_match('/^(some|full)\s+avg10=([\d.]+)\s+avg60=([\d.]+)/', trim($line), $m)) {
            $out[$m[1] . '10'] = (float)$m[2];
            $out[$m[1] . '60'] = (float)$m[3];
        }
    }

    return isset($out['some10']) ? $out + ['full10' => 0.0, 'full60' => 0.0] : null;
}

/**
 * Block-layer bytes read and written by the processes this user can see.
 *
 * `read_bytes`/`write_bytes` rather than `rchar`/`wchar`: the latter count every
 * byte passed to read() and write(), including the ones served from page cache
 * that never touched a disk. A process re-reading the same cached file all day
 * looks like a heavy reader by rchar and does no I/O at all.
 *
 * Inside CageFS this is the account's own traffic and nothing else, which is
 * exactly what makes it worth collecting there.
 *
 * @return array{read: int, write: int, cancelled: int, procs: int}|null
 */
function processIoTotals(string $procRoot = '/proc'): ?array
{
    $entries = @scandir($procRoot);
    if ($entries === false) {
        return null;
    }

    $read = $write = $cancelled = $seen = 0;
    $any = false;

    foreach ($entries as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        // Unreadable for another user's process without privilege, and gone
        // entirely if it exited mid-scan. Neither is worth a word.
        $raw = @file_get_contents("$procRoot/$entry/io");
        if ($raw === false) {
            continue;
        }

        $any = true;
        $seen++;
        if (preg_match('/^read_bytes:\s+(\d+)/m', $raw, $m)) {
            $read += (int)$m[1];
        }
        if (preg_match('/^write_bytes:\s+(\d+)/m', $raw, $m)) {
            $write += (int)$m[1];
        }
        /*
         * Writes charged and then withdrawn - a file deleted before its dirty
         * pages were ever flushed. Subtracting it is what makes "wrote 4 GB"
         * mean four gigabytes actually went to the platter.
         */
        if (preg_match('/^cancelled_write_bytes:\s+(\d+)/m', $raw, $m)) {
            $cancelled += (int)$m[1];
        }
    }

    return $any ? ['read' => $read, 'write' => $write,
                   'cancelled' => $cancelled, 'procs' => $seen] : null;
}

/* ---------------------------------------------------------- diagnosis -- */

/**
 * Why pressure stall information is missing, in the terms that fix it.
 *
 * Three independent ways to lack it, needing three different responses, and the
 * file's absence alone does not say which. Note the version test is reported
 * rather than concluded from: RHEL - and so CloudLinux - backported PSI into
 * their 4.18 kernels, so "4.18 is below 4.20" is exactly the wrong conclusion
 * on the machines this tool is aimed at.
 */
function pressureDiagnosis(string $procRoot = '/proc', ?string $bootConfig = null): string
{
    if (@is_readable($procRoot . '/pressure/io')) {
        return '';
    }

    $kernel = php_uname('r');
    $parts = ["Kernel $kernel."];

    if (!@is_readable($procRoot . '/stat')) {
        return "Kernel $kernel. /proc is not readable here at all, so nothing can be said.";
    }

    /*
     * A filtered /proc means this account cannot diagnose the kernel, and that
     * is all it means.
     *
     * An earlier version of this went further and blamed CageFS for the missing
     * /proc/pressure as well, on the grounds that /proc/diskstats was hidden
     * too. That turned out to be wrong on a real machine: enabling psi=1 and
     * rebooting made /proc/pressure appear *inside* the cage while
     * /proc/diskstats stayed hidden. The two absences had different causes -
     * one filtered, one not enabled - and asserting a single explanation for
     * both pointed at the wrong thing. So this now reports what it can see and
     * defers the question rather than answering it from the wrong side.
     */
    if (!@is_readable($procRoot . '/diskstats')) {
        return "Kernel $kernel. This account sees a filtered /proc: /proc/stat is "
            . 'readable but /proc/diskstats is not, which is CageFS. Whether the kernel '
            . 'has pressure stall info cannot be told from in here — the host collector '
            . 'reads it as root and records the answer, which the host panel shows.';
    }

    if (!@file_exists($procRoot . '/pressure')) {
        $parts[] = 'There is no /proc/pressure directory.';
    }

    // World-readable on most distributions, and absent inside a cage - which is
    // itself the answer, so say so rather than staying silent.
    // Whether the switch is already thrown changes the advice completely, so it
    // is read before anything is recommended rather than tacked on afterwards.
    $cmdline = @file_get_contents($procRoot . '/cmdline');
    $booted = is_string($cmdline) && preg_match('/(^|\s)psi=1(\s|$)/', $cmdline);

    $config = $bootConfig ?? "/boot/config-$kernel";
    if (@is_readable($config)) {
        $text = (string)@file_get_contents($config);
        if (!preg_match('/^CONFIG_PSI=y/m', $text)) {
            $parts[] = 'This kernel was built without CONFIG_PSI, so it cannot be turned on '
                . 'without a different kernel.';
        } elseif (preg_match('/^CONFIG_PSI_DEFAULT_DISABLED=y/m', $text)) {
            /*
             * The RHEL and CloudLinux case. Built in, off unless asked for -
             * which is why a 5.14 kernel can be well past the version that
             * introduced PSI and still not have the file.
             */
            $parts[] = $booted
                ? 'The kernel has CONFIG_PSI and psi=1 is already on the command line, '
                    . 'so this needs a reboot to take effect — or something is hiding '
                    . '/proc/pressure.'
                : 'The kernel has CONFIG_PSI but ships disabled: add psi=1 to the kernel '
                    . 'command line (grubby --update-kernel=ALL --args="psi=1") and reboot.';
        } else {
            $parts[] = 'The kernel has CONFIG_PSI and is not built to default it off, '
                . 'so something else is hiding /proc/pressure.';
        }
    } else {
        $parts[] = "Cannot read $config to tell whether CONFIG_PSI was compiled in — "
            . 'check it as root, or read /proc/pressure/io there.';
        if (!$booted) {
            $parts[] = 'psi=1 is not on the boot command line.';
        }
    }

    return implode(' ', $parts);
}

/**
 * Why the device counters are missing.
 *
 * The distinction that matters is between "this kernel has no such file" and
 * "this account is not allowed to see it". They look identical from here and
 * need opposite responses.
 */
function diskstatsDiagnosis(string $procRoot = '/proc'): string
{
    if (@is_readable($procRoot . '/diskstats')) {
        return '';
    }

    if (!@is_readable($procRoot . '/stat')) {
        return '/proc is not readable at all here.';
    }

    return 'Hidden from this account — /proc/stat is readable but /proc/diskstats is '
        . 'not, which is CageFS filtering /proc. Nothing is broken: the host collector '
        . 'reads the real device counters, and this account\'s own I/O is still '
        . 'measured from its processes.';
}

