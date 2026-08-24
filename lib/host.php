<?php
declare(strict_types=1);

/*
 * What the account cannot see.
 *
 * Inside CageFS a cPanel account gets its own /proc containing only its own
 * processes, and its own view of the filesystem. That is the point of it, and
 * it is also why the account collector can watch memory disappear and have
 * nothing to blame: the process holding two gigabytes belongs to another user,
 * so from inside the cage it does not exist. Neither do the kernel's slab
 * caches, another account's swap, or a 763 MB Apache log under /etc.
 *
 * So this half runs as root, out of WHM's crontab, and writes to its own
 * database. Everything here reads /proc directly rather than shelling out to
 * ps, free or slabtop:
 *
 *   - those tools are not all installed everywhere, and their output format
 *     differs between procps and BSD userland in ways that are hard to notice
 *   - /proc/<pid>/smaps_rollup carries Pss, which no ps can report and which is
 *     the only per-process memory figure that adds up
 *   - a file can be read from a fixture directory, so the parsers in here are
 *     testable off a Linux box
 *
 * That last one matters more than it looks. Every function below takes the
 * root of the tree it reads as an argument.
 */

require_once __DIR__ . '/hostdb.php';
// shellRead lives in collect.php and redactSecrets in processes.php. Required
// explicitly rather than left to whatever the caller happened to load first.
require_once __DIR__ . '/collect.php';
require_once __DIR__ . '/processes.php';

/** Top slab caches, processes and files kept per reading. */
const HOST_KEEP_SLAB = 20;
const HOST_KEEP_PROCS = 25;

/** Processes whose Pss is worth the extra read, biggest by RSS first. */
const HOST_PSS_LIMIT = 60;

/**
 * Everything in /proc/meminfo, in bytes.
 *
 * Kept whole rather than picked over, because which line matters depends on
 * what is wrong, and the file is thirty short lines. Values are kB in the file
 * and bytes everywhere in this program - mixing the two is the classic way to
 * be wrong by a factor of 1024 in a number nobody checks.
 *
 * @return array<string, int>
 */
function hostMeminfo(string $procRoot = '/proc'): array
{
    $raw = @file_get_contents($procRoot . '/meminfo');
    if ($raw === false) {
        return [];
    }

    $out = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)(\s+kB)?/', $line, $m)) {
            $out[$m[1]] = (int)$m[2] * (isset($m[3]) && $m[3] !== '' ? 1024 : 1);
        }
    }

    return $out;
}

/**
 * Where the memory actually went.
 *
 * The question this whole file exists to answer, and the reason it is not
 * simply "look at the biggest process".
 *
 * `free` calls memory used when it is neither free nor reclaimable cache:
 *
 *     used = total - free - buffers - cached - reclaimable slab
 *
 * The trap is that adding up RSS across processes gives a much bigger number
 * than that - on the machine this was written for, 4.96 GiB of RSS against
 * 3.4 GiB genuinely in use. Nothing is wrong: RSS counts every shared page once
 * per process, so a hundred Apache workers each "own" the same copy of libc.
 * Chasing the difference is chasing an artefact, and it is exactly what
 * somebody staring at `ps --sort=-rss` will do. Pss - each page divided by the
 * number of processes sharing it - is the figure that adds up, so it is
 * collected too and shown beside RSS.
 *
 * `unaccounted` is what is left after everything nameable is subtracted:
 * process anonymous memory, page cache, tmpfs, slab, page tables and kernel
 * stacks. It should be small. When it is not, the memory has gone somewhere
 * that does not appear in any per-process view - a driver, a hypervisor
 * balloon, or a kernel leak - and knowing that is worth more than another
 * process list.
 *
 * @return array<string, int|null>
 */
function hostMemoryAccounting(array $mem, int $rssSum, int $pssSum): array
{
    $get = fn(string $key): int => (int)($mem[$key] ?? 0);

    $total = $get('MemTotal');
    if ($total <= 0) {
        return ['used_bytes' => null, 'cache_bytes' => null, 'unaccounted' => null];
    }

    $cache = $get('Buffers') + $get('Cached') + $get('SReclaimable');
    $used = $total - $get('MemFree') - $cache;

    /*
     * Shmem is counted inside Cached, so it is already in $cache. What is not:
     * anonymous pages, pages held in RAM that also have a copy in swap,
     * unreclaimable slab, page tables, kernel stacks and per-cpu areas. Those
     * are what "used" is made of on a machine behaving normally.
     *
     * VmallocUsed is deliberately left out. On older kernels it reported
     * address space rather than memory and could exceed the size of RAM, and
     * subtracting it would turn the remainder into a large negative number that
     * looked like a bug in this function rather than in the source.
     */
    $named = $get('AnonPages')
        + $get('SwapCached')
        + $get('SUnreclaim')
        + $get('PageTables')
        + $get('KernelStack')
        + $get('Percpu');

    return [
        'used_bytes' => $used,
        'cache_bytes' => $cache,
        'unaccounted' => $used - $named,
        'rss_sum_bytes' => $rssSum,
        'pss_sum_bytes' => $pssSum,
    ];
}

/**
 * Every process on the machine, read out of /proc.
 *
 * Pss comes from smaps_rollup, which the kernel builds by walking the page
 * tables - cheap per process but not free, and there can be a thousand
 * processes. So it is read for the biggest few by RSS, which are the only ones
 * anybody will look at, and the rest carry a null Pss rather than a wrong one.
 *
 * @return array{procs: list<array>, rss_sum: int, pss_sum: int, swap_sum: int,
 *               threads: int, available: bool}
 */
function hostProcesses(
    string $procRoot = '/proc',
    int $pssLimit = HOST_PSS_LIMIT,
    string $passwd = '/etc/passwd'
): array {
    $entries = @scandir($procRoot);
    if ($entries === false) {
        // Every key the success path returns, so a caller reading one of them
        // does not have to know which branch it came from.
        return ['procs' => [], 'rss_sum' => 0, 'pss_sum' => 0, 'pss_procs' => 0,
                'swap_sum' => 0, 'threads' => 0, 'available' => false];
    }

    $users = hostUserNames($passwd);
    $boot = hostBootTime($procRoot);
    $ticks = hostClockTicks();

    $procs = [];
    $rssSum = $swapSum = $threads = 0;

    foreach ($entries as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }

        $status = @file_get_contents("$procRoot/$entry/status");
        if ($status === false) {
            // Exited between the scandir and now, which is normal and not
            // worth a word: a process list is a photograph of a moving thing.
            continue;
        }

        $fields = [];
        foreach (preg_split('/\R/', $status) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_]+):\s*(.+)$/', $line, $m)) {
                $fields[$m[1]] = trim($m[2]);
            }
        }

        $kb = fn(string $key): int =>
            preg_match('/^(\d+)/', (string)($fields[$key] ?? ''), $m) ? (int)$m[1] * 1024 : 0;

        $uid = preg_match('/^(\d+)/', (string)($fields['Uid'] ?? ''), $m) ? (int)$m[1] : -1;
        $rss = $kb('VmRSS');
        $swap = $kb('VmSwap');
        $thr = (int)($fields['Threads'] ?? 1);

        $rssSum += $rss;
        $swapSum += $swap;
        $threads += $thr;

        $procs[] = [
            'pid' => (int)$entry,
            'user' => $users[$uid] ?? (string)$uid,
            'uid' => $uid,
            'name' => (string)($fields['Name'] ?? '?'),
            'rss_bytes' => $rss,
            'pss_bytes' => null,
            'swap_bytes' => $swap,
            'vsz_bytes' => $kb('VmSize'),
            'threads' => $thr,
            'elapsed_seconds' => hostElapsed($procRoot, (int)$entry, $boot, $ticks),
            'command' => hostCommandLine($procRoot, (int)$entry, (string)($fields['Name'] ?? '')),
        ];
    }

    // Biggest first, then Pss for the head of the list.
    usort($procs, fn(array $a, array $b): int => $b['rss_bytes'] <=> $a['rss_bytes']);

    $pssSum = 0;
    $pssCount = 0;
    foreach ($procs as $i => $proc) {
        if ($i >= $pssLimit) {
            break;
        }
        $pss = hostPss($procRoot, $proc['pid']);
        $procs[$i]['pss_bytes'] = $pss;
        if ($pss !== null) {
            $pssSum += $pss;
            $pssCount++;
        }
    }

    return [
        'procs' => $procs,
        'rss_sum' => $rssSum,
        'pss_sum' => $pssSum,
        // How many processes that total actually covers. Without it the sum
        // reads as a whole-machine figure when it is the biggest few.
        'pss_procs' => $pssCount,
        'swap_sum' => $swapSum,
        'threads' => $threads,
        'available' => true,
    ];
}

/** Proportional set size for one process, or null where the kernel will not say. */
function hostPss(string $procRoot, int $pid): ?int
{
    $raw = @file_get_contents("$procRoot/$pid/smaps_rollup");
    if ($raw === false) {
        return null;
    }
    return preg_match('/^Pss:\s+(\d+)\s*kB/m', $raw, $m) ? (int)$m[1] * 1024 : null;
}

/**
 * The command line, with the arguments back in one piece.
 *
 * /proc separates them with NUL bytes, which is the only reason a command line
 * with spaces in an argument can be read back correctly at all - and why this
 * is done here rather than by parsing ps output. A kernel thread has an empty
 * cmdline; it gets its name in brackets, the way ps writes it.
 */
function hostCommandLine(string $procRoot, int $pid, string $name): string
{
    $raw = @file_get_contents("$procRoot/$pid/cmdline");
    if ($raw === false || trim($raw, "\0") === '') {
        return $name === '' ? '' : "[$name]";
    }

    $line = trim(str_replace("\0", ' ', $raw));
    return mb_substr(redactSecrets($line), 0, 400);
}

/** Seconds since a process started, from its start time and the boot clock. */
function hostElapsed(string $procRoot, int $pid, int $boot, int $ticks): ?int
{
    $raw = @file_get_contents("$procRoot/$pid/stat");
    if ($raw === false || $boot <= 0 || $ticks <= 0) {
        return null;
    }

    /*
     * Parsed from the last ')' rather than by splitting on spaces.
     *
     * Field two is the executable name in brackets and it can contain both
     * spaces and brackets - "(Web Content)" is real - so counting spaces from
     * the left lands on the wrong field for exactly the processes most worth
     * looking at. Everything after the final ')' is fixed-width.
     */
    $close = strrpos($raw, ')');
    if ($close === false) {
        return null;
    }

    $rest = preg_split('/\s+/', trim(substr($raw, $close + 1))) ?: [];
    // state is [0]; starttime is field 22 overall, so [19] after the name.
    if (!isset($rest[19]) || !ctype_digit($rest[19])) {
        return null;
    }

    $started = $boot + (int)$rest[19] / $ticks;
    return max(0, (int)round(time() - $started));
}

function hostBootTime(string $procRoot = '/proc'): int
{
    $raw = @file_get_contents($procRoot . '/stat');
    return $raw !== false && preg_match('/^btime\s+(\d+)/m', $raw, $m) ? (int)$m[1] : 0;
}

/** Kernel clock ticks per second. Practically always 100, but not by standard. */
function hostClockTicks(): int
{
    if (function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK')) {
        $ticks = @posix_sysconf(POSIX_SC_CLK_TCK);
        if (is_int($ticks) && $ticks > 0) {
            return $ticks;
        }
    }
    $out = shellRead('getconf CLK_TCK 2>/dev/null');
    return $out !== null && ctype_digit(trim($out)) ? (int)trim($out) : 100;
}

/**
 * uid to name, read once.
 *
 * From posix_getpwuid where the extension is there, and /etc/passwd where it is
 * not. Names matter here in a way they do not in the account collector: "uid
 * 1005 is holding two gigabytes" is a puzzle, and "git is holding two
 * gigabytes" is an answer.
 *
 * @return array<int, string>
 */
function hostUserNames(string $passwd = '/etc/passwd'): array
{
    // Keyed by path, not a bare static: a cache that ignores its argument
    // returns the first caller's answer to everyone, which in a test means the
    // fixture silently reads the real machine and looks like it passed.
    static $cache = [];
    if (isset($cache[$passwd])) {
        return $cache[$passwd];
    }

    $names = [];
    $raw = @file_get_contents($passwd);
    if ($raw !== false) {
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 3 && ctype_digit($parts[2])) {
                $names[(int)$parts[2]] = $parts[0];
            }
        }
    }

    return $cache[$passwd] = $names;
}

/**
 * Memory added up per user.
 *
 * Pss where it was measured and RSS otherwise, kept in separate columns rather
 * than blended: a total that silently mixes two different measurements is worse
 * than two honest ones.
 *
 * @return list<array>
 */
function hostByUser(array $procs): array
{
    $users = [];

    foreach ($procs as $proc) {
        $name = $proc['user'];
        $users[$name] ??= [
            'user' => $name, 'procs' => 0, 'threads' => 0,
            'rss_bytes' => 0, 'pss_bytes' => 0, 'swap_bytes' => 0,
        ];

        $users[$name]['procs']++;
        $users[$name]['threads'] += (int)$proc['threads'];
        $users[$name]['rss_bytes'] += (int)$proc['rss_bytes'];
        $users[$name]['pss_bytes'] += (int)($proc['pss_bytes'] ?? 0);
        $users[$name]['swap_bytes'] += (int)$proc['swap_bytes'];
    }

    usort($users, fn(array $a, array $b): int => $b['rss_bytes'] <=> $a['rss_bytes']);
    return array_values($users);
}

/**
 * Kernel slab caches, biggest first.
 *
 * The cache size is worked out the way slabtop does it - slabs times pages per
 * slab times the page size - rather than as objects times object size. The two
 * disagree, because a slab is whole pages and the objects in it rarely divide
 * evenly into them; the difference is the memory actually held.
 *
 * @return list<array>
 */
function hostSlab(string $procRoot = '/proc', int $pageSize = 4096): array
{
    $raw = @file_get_contents($procRoot . '/slabinfo');
    if ($raw === false) {
        return [];
    }

    $out = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || str_starts_with($line, 'slabinfo')) {
            continue;
        }

        // name active objs size perslab pagesperslab : tunables ... : slabdata active_slabs num_slabs ...
        $parts = preg_split('/\s+/', $line) ?: [];
        if (count($parts) < 6) {
            continue;
        }

        $numSlabs = null;
        foreach ($parts as $i => $part) {
            if ($part === 'slabdata' && isset($parts[$i + 2])) {
                $numSlabs = (int)$parts[$i + 2];
                break;
            }
        }

        $objs = (int)$parts[2];
        $objSize = (int)$parts[3];
        $perSlab = max(1, (int)$parts[4]);
        $pagesPer = max(1, (int)$parts[5]);

        // Without a slabdata section, fall back to what the object counts imply.
        $slabs = $numSlabs ?? (int)ceil($objs / $perSlab);

        $out[] = [
            'name' => $parts[0],
            'objs' => $objs,
            'obj_bytes' => $objSize,
            'cache_bytes' => $slabs * $pagesPer * $pageSize,
        ];
    }

    usort($out, fn(array $a, array $b): int => $b['cache_bytes'] <=> $a['cache_bytes']);
    return $out;
}

/**
 * Shared memory: System V segments and the tmpfs at /dev/shm.
 *
 * Both are memory that belongs to no process in a way `ps` will show. A segment
 * whose creator has exited still occupies RAM until someone removes it, which
 * is a slow leak with no owner to point at.
 *
 * @return array{segments: int, bytes: int, devshm_total: ?int, devshm_used: ?int}
 */
function hostSharedMemory(): array
{
    $segments = 0;
    $bytes = 0;

    $out = shellRead('ipcs -m 2>/dev/null');
    if ($out !== null) {
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            // key shmid owner perms bytes nattch status
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) >= 6 && str_starts_with($parts[0], '0x') && ctype_digit($parts[4])) {
                $segments++;
                $bytes += (int)$parts[4];
            }
        }
    }

    $total = $used = null;
    if (is_dir('/dev/shm')) {
        $free = @disk_free_space('/dev/shm');
        $size = @disk_total_space('/dev/shm');
        if ($size !== false && $free !== false) {
            $total = (int)$size;
            $used = (int)($size - $free);
        }
    }

    return ['segments' => $segments, 'bytes' => $bytes,
            'devshm_total' => $total, 'devshm_used' => $used];
}

/**
 * Sizes of files the account cannot stat.
 *
 * Globs from the config, defaulting to Apache's domain logs - the place a
 * rotated log quietly reaches 763 MB while every tool the account can run
 * reports the home directory as unchanged.
 *
 * @return list<array>
 */
function hostWatchedFiles(array $config, int $limit = 40, string $passwd = '/etc/passwd'): array
{
    $globs = $config['host']['watch'] ?? [];
    $minimum = (int)($config['host']['watch_min_bytes'] ?? 1048576);

    $found = [];
    foreach ($globs as $pattern) {
        foreach (glob((string)$pattern) ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $size = @filesize($path);
            if ($size === false || $size < $minimum) {
                continue;
            }

            $uid = @fileowner($path);
            $found[$path] = [
                'path' => $path,
                'bytes' => (int)$size,
                'owner' => $uid === false ? null : (hostUserNames($passwd)[$uid] ?? (string)$uid),
                'mtime' => (int)(@filemtime($path) ?: 0),
            ];
        }
    }

    $rows = array_values($found);
    usort($rows, fn(array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
    return array_slice($rows, 0, $limit);
}

/**
 * CloudLinux limits and faults, where CloudLinux is installed.
 *
 * A process killed for exceeding an LVE limit leaves no other trace: it is gone
 * and nothing in the account's logs says why. A rising fault count is the
 * evidence, and it is only visible from the host.
 *
 * @return array{available: bool, reason: ?string, rows: list<array>}
 */
function hostLve(): array
{
    if (!is_file('/usr/sbin/lveinfo') && !is_file('/usr/bin/lveinfo')) {
        return ['available' => false, 'reason' => 'CloudLinux is not installed here.', 'rows' => []];
    }

    $out = shellRead('lveinfo --json --period=1h --by-fault=any 2>/dev/null');
    if ($out === null || trim($out) === '') {
        return ['available' => false, 'reason' => 'lveinfo returned nothing.', 'rows' => []];
    }

    $parsed = json_decode($out, true);
    if (!is_array($parsed)) {
        return ['available' => false, 'reason' => 'lveinfo output was not JSON.', 'rows' => []];
    }

    /*
     * Shape-tolerant on purpose.
     *
     * lveinfo's JSON has moved between CloudLinux releases, and a collector
     * that throws on an unexpected key would take the whole host reading down
     * with it over the least important table here. Anything unrecognised is
     * skipped and the rest is kept.
     */
    $rows = [];
    foreach ($parsed['data'] ?? $parsed as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $user = (string)($entry['user'] ?? $entry['username'] ?? $entry['id'] ?? '');
        if ($user === '') {
            continue;
        }
        $rows[] = [
            'user' => $user,
            'cpu_percent' => isset($entry['cpu']) ? (float)$entry['cpu'] : null,
            'mem_bytes' => isset($entry['mem']) ? (int)$entry['mem'] : null,
            'procs' => isset($entry['nproc']) ? (int)$entry['nproc'] : null,
            'faults_cpu' => (int)($entry['cpuf'] ?? $entry['cpu_faults'] ?? 0),
            'faults_mem' => (int)($entry['memf'] ?? $entry['mem_faults'] ?? 0),
            'faults_procs' => (int)($entry['nprocf'] ?? $entry['nproc_faults'] ?? 0),
        ];
    }

    return ['available' => true, 'reason' => null, 'rows' => $rows];
}

/**
 * Takes one host reading and writes it down.
 *
 * Everything in one transaction, so a reading is either wholly there or not
 * there at all: a sample row whose processes failed to insert would show up as
 * a machine with memory in use and nothing running on it, which is a worse lie
 * than a missing minute.
 *
 * @return array<string, mixed>
 */
function hostTakeReading(
    array $config,
    PDO $pdo,
    string $source = 'cron',
    string $procRoot = '/proc',
    string $passwd = '/etc/passwd'
): array {
    $mem = hostMeminfo($procRoot);
    $processes = hostProcesses($procRoot, HOST_PSS_LIMIT, $passwd);

    /*
     * A reading with no /proc behind it is not a reading.
     *
     * Without this the collector happily stores a row of nulls every minute,
     * and the dashboard draws a machine with no memory and nothing running on
     * it - which looks like a catastrophe rather than like a collector pointed
     * at the wrong place. Refusing says which it is.
     */
    if (!$processes['available'] && !$mem) {
        throw new RuntimeException(
            "nothing readable at $procRoot - this collector needs a Linux host with /proc"
        );
    }
    $accounting = hostMemoryAccounting($mem, $processes['rss_sum'], $processes['pss_sum']);
    $slab = hostSlab($procRoot);
    $shm = hostSharedMemory();
    $files = hostWatchedFiles($config, 40, $passwd);
    $lve = hostLve();
    $load = hostLoad($procRoot);

    $get = fn(string $key): ?int => isset($mem[$key]) ? (int)$mem[$key] : null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO host_samples
                (taken_at, mem_total, mem_free, mem_available, buffers, cached, swap_cached,
                 shmem, anon_pages, mapped, slab, sreclaimable, sunreclaim, page_tables,
                 kernel_stack, dirty, swap_total, swap_free, used_bytes, cache_bytes,
                 unaccounted, rss_sum_bytes, pss_sum_bytes, pss_procs, swap_sum_bytes, proc_count,
                 thread_count, shm_segments, shm_bytes, devshm_total, devshm_used,
                 load1, load5, load15, cpu_cores, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            time(),
            $get('MemTotal'), $get('MemFree'), $get('MemAvailable'), $get('Buffers'),
            $get('Cached'), $get('SwapCached'), $get('Shmem'), $get('AnonPages'),
            $get('Mapped'), $get('Slab'), $get('SReclaimable'), $get('SUnreclaim'),
            $get('PageTables'), $get('KernelStack'), $get('Dirty'),
            $get('SwapTotal'), $get('SwapFree'),
            $accounting['used_bytes'], $accounting['cache_bytes'], $accounting['unaccounted'],
            $processes['rss_sum'], $processes['pss_sum'], $processes['pss_procs'],
            $processes['swap_sum'],
            count($processes['procs']), $processes['threads'],
            $shm['segments'], $shm['bytes'], $shm['devshm_total'], $shm['devshm_used'],
            $load[0], $load[1], $load[2], cpuCores(),
            $source,
        ]);

        $sampleId = (int)$pdo->lastInsertId();

        $users = $pdo->prepare(
            'INSERT INTO host_users (sample_id, user, procs, threads, rss_bytes, pss_bytes, swap_bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (hostByUser($processes['procs']) as $row) {
            $users->execute([
                $sampleId, $row['user'], $row['procs'], $row['threads'],
                $row['rss_bytes'], $row['pss_bytes'], $row['swap_bytes'],
            ]);
        }

        $procs = $pdo->prepare(
            'INSERT INTO host_processes
                (sample_id, pid, user, name, rss_bytes, pss_bytes, swap_bytes, vsz_bytes,
                 threads, elapsed_seconds, command)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_slice($processes['procs'], 0, HOST_KEEP_PROCS) as $row) {
            $procs->execute([
                $sampleId, $row['pid'], $row['user'], $row['name'], $row['rss_bytes'],
                $row['pss_bytes'], $row['swap_bytes'], $row['vsz_bytes'], $row['threads'],
                $row['elapsed_seconds'], $row['command'],
            ]);
        }

        $slabs = $pdo->prepare(
            'INSERT INTO host_slab (sample_id, name, objs, obj_bytes, cache_bytes)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach (array_slice($slab, 0, HOST_KEEP_SLAB) as $row) {
            $slabs->execute([$sampleId, $row['name'], $row['objs'], $row['obj_bytes'], $row['cache_bytes']]);
        }

        $watched = $pdo->prepare(
            'INSERT INTO host_files (sample_id, path, bytes, owner, mtime) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($files as $row) {
            $watched->execute([$sampleId, $row['path'], $row['bytes'], $row['owner'], $row['mtime']]);
        }

        if ($lve['available']) {
            $limits = $pdo->prepare(
                'INSERT INTO host_lve (sample_id, user, cpu_percent, mem_bytes, procs,
                                       faults_cpu, faults_mem, faults_procs)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($lve['rows'] as $row) {
                $limits->execute([
                    $sampleId, $row['user'], $row['cpu_percent'], $row['mem_bytes'],
                    $row['procs'], $row['faults_cpu'], $row['faults_mem'], $row['faults_procs'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'sample_id' => $sampleId,
        'processes' => count($processes['procs']),
        'users' => count(hostByUser($processes['procs'])),
        'slab' => min(count($slab), HOST_KEEP_SLAB),
        'files' => count($files),
        'lve' => $lve['available'] ? count($lve['rows']) : null,
        'unaccounted' => $accounting['unaccounted'],
    ];
}

/** The three load figures, from /proc rather than sys_getloadavg for testability. */
function hostLoad(string $procRoot = '/proc'): array
{
    $raw = @file_get_contents($procRoot . '/loadavg');
    if ($raw !== false && preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', trim($raw), $m)) {
        return [(float)$m[1], (float)$m[2], (float)$m[3]];
    }

    $load = @sys_getloadavg();
    return is_array($load) ? [$load[0], $load[1], $load[2]] : [null, null, null];
}
