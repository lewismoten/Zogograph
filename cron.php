#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Disk Usage Monitor
 *
 * Run from cron. Scans the configured home directory, records:
 * - trash/log/temp totals
 * - largest category directories
 * - largest files anywhere under the scan root
 * - filesystem bytes
 * - filesystem inode usage
 *
 * It writes:
 *   data/YYYY/MM/DD/HH-MM.json   snapshot
 *   data/YYYY/MM/DD.json         daily rollup
 *   data/YYYY/MM.json            monthly rollup
 *   data/YYYY.json               yearly rollup
 *   data/index.json              dashboard index
 */

$config = [
    'scan_root' => getenv('HOME') ?: '/home/regaldra',
    'data_root' => __DIR__ . '/data',
    'timezone' => 'America/New_York',
    'top_count' => 25,

    // Directory names matched case-insensitively.
    'category_dirs' => [
        'trash' => ['.trash', 'trash'],
        'logs'  => ['log', 'logs'],
        'temp'  => ['tmp', 'temp', 'temporary'],
    ],

    // Standalone files outside matching directories.
    'category_extensions' => [
        'logs' => ['log'],
        'temp' => ['tmp', 'temp'],
    ],

    // Skip the monitor's own data so it never reports itself as an offender.
    'exclude_paths' => [
        __DIR__ . '/data',
    ],
];

date_default_timezone_set($config['timezone']);

function normalizePath(string $path): string {
    $real = realpath($path);
    return $real !== false ? rtrim($real, DIRECTORY_SEPARATOR) : rtrim($path, DIRECTORY_SEPARATOR);
}

function isExcluded(string $path, array $excluded): bool {
    $path = normalizePath($path);
    foreach ($excluded as $exclude) {
        $exclude = normalizePath($exclude);
        if ($path === $exclude || str_starts_with($path . DIRECTORY_SEPARATOR, $exclude . DIRECTORY_SEPARATOR)) {
            return true;
        }
    }
    return false;
}

function relativeDisplay(string $path, string $root): string {
    $root = rtrim(normalizePath($root), DIRECTORY_SEPARATOR);
    $path = normalizePath($path);
    if ($path === $root) {
        return '~';
    }
    if (str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return '~/' . substr($path, strlen($root) + 1);
    }
    return $path;
}

/**
 * Find the first category-directory component in a path.
 * Returns [category, rootDirectory] or [null, null].
 */
function categoryFromPath(string $path, string $scanRoot, array $categoryDirs): array {
    $root = rtrim(normalizePath($scanRoot), DIRECTORY_SEPARATOR);
    $full = normalizePath($path);

    if (!str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
        return [null, null];
    }

    $relative = substr($full, strlen($root) + 1);
    $parts = explode(DIRECTORY_SEPARATOR, $relative);

    // Last component is the file name, so inspect directory components only.
    array_pop($parts);

    $built = $root;
    foreach ($parts as $part) {
        $built .= DIRECTORY_SEPARATOR . $part;
        $lower = strtolower($part);

        // Precedence avoids double counting if names overlap.
        foreach (['trash', 'logs', 'temp'] as $category) {
            foreach ($categoryDirs[$category] ?? [] as $name) {
                if ($lower === strtolower($name)) {
                    return [$category, $built];
                }
            }
        }
    }

    return [null, null];
}

function categoryFromExtension(string $path, array $extensions): ?string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // Trash intentionally has no extension fallback.
    foreach (['logs', 'temp'] as $category) {
        if (in_array($ext, $extensions[$category] ?? [], true)) {
            return $category;
        }
    }
    return null;
}

function addTopFile(array &$top, array $item, int $limit): void {
    $top[] = $item;

    // Keep memory bounded even on accounts with hundreds of thousands of files.
    if (count($top) > $limit * 4) {
        usort($top, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        $top = array_slice($top, 0, $limit);
    }
}

function finishTop(array $items, int $limit): array {
    usort($items, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    return array_slice($items, 0, $limit);
}

function inodeStats(string $path): array {
    $cmd = 'df -Pi ' . escapeshellarg($path) . ' 2>/dev/null';
    $output = shell_exec($cmd);

    if (!$output) {
        return [
            'supported' => false,
            'total' => null,
            'used' => null,
            'available' => null,
            'used_percent' => null,
        ];
    }

    $lines = preg_split('/\R/', trim($output));
    if (count($lines) < 2) {
        return [
            'supported' => false,
            'total' => null,
            'used' => null,
            'available' => null,
            'used_percent' => null,
        ];
    }

    // The data line can contain multiple spaces.
    $cols = preg_split('/\s+/', trim(end($lines)));
    if (count($cols) < 6) {
        return [
            'supported' => false,
            'total' => null,
            'used' => null,
            'available' => null,
            'used_percent' => null,
        ];
    }

    return [
        'supported' => true,
        'total' => (int)$cols[1],
        'used' => (int)$cols[2],
        'available' => (int)$cols[3],
        'used_percent' => (float)rtrim($cols[4], '%'),
    ];
}

function filesystemStats(string $path): array {
    $total = disk_total_space($path);
    $free = disk_free_space($path);

    if ($total === false || $free === false) {
        return [
            'total_bytes' => null,
            'used_bytes' => null,
            'available_bytes' => null,
            'used_percent' => null,
        ];
    }

    $used = $total - $free;

    return [
        'total_bytes' => (int)$total,
        'used_bytes' => (int)$used,
        'available_bytes' => (int)$free,
        'used_percent' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
    ];
}

function ensureDir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create directory: $dir");
    }
}

function writeJson(string $path, array $data): void {
    ensureDir(dirname($path));
    $tmp = $path . '.tmp';

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException("Unable to encode JSON for $path");
    }

    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write $tmp");
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Unable to replace $path");
    }
}

function readJson(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function summarizeSnapshots(array $snapshots): array {
    if (!$snapshots) {
        return [];
    }

    usort($snapshots, fn($a, $b) => ($a['epoch'] ?? 0) <=> ($b['epoch'] ?? 0));

    $metric = function(string $key) use ($snapshots): array {
        $vals = [];
        foreach ($snapshots as $s) {
            if (isset($s[$key]) && is_numeric($s[$key])) {
                $vals[] = (float)$s[$key];
            }
        }
        if (!$vals) {
            return ['min' => null, 'max' => null, 'avg' => null, 'latest' => null];
        }
        return [
            'min' => min($vals),
            'max' => max($vals),
            'avg' => array_sum($vals) / count($vals),
            'latest' => end($vals),
        ];
    };

    return [
        'count' => count($snapshots),
        'first_timestamp' => $snapshots[0]['timestamp'] ?? null,
        'last_timestamp' => end($snapshots)['timestamp'] ?? null,
        'trash_bytes' => $metric('trash_bytes'),
        'logs_bytes' => $metric('logs_bytes'),
        'temp_bytes' => $metric('temp_bytes'),
        'filesystem_available_bytes' => $metric('filesystem_available_bytes'),
        'filesystem_used_percent' => $metric('filesystem_used_percent'),
        'inodes_available' => $metric('inodes_available'),
        'inodes_used_percent' => $metric('inodes_used_percent'),
    ];
}

function snapshotSeriesRow(array $snapshot, string $path): array {
    return [
        'timestamp' => $snapshot['timestamp'] ?? null,
        'epoch' => $snapshot['epoch'] ?? null,
        'path' => $path,
        'trash_bytes' => $snapshot['categories']['trash']['bytes'] ?? 0,
        'logs_bytes' => $snapshot['categories']['logs']['bytes'] ?? 0,
        'temp_bytes' => $snapshot['categories']['temp']['bytes'] ?? 0,
        'filesystem_total_bytes' => $snapshot['filesystem']['total_bytes'] ?? null,
        'filesystem_used_bytes' => $snapshot['filesystem']['used_bytes'] ?? null,
        'filesystem_available_bytes' => $snapshot['filesystem']['available_bytes'] ?? null,
        'filesystem_used_percent' => $snapshot['filesystem']['used_percent'] ?? null,
        'inodes_total' => $snapshot['inodes']['total'] ?? null,
        'inodes_used' => $snapshot['inodes']['used'] ?? null,
        'inodes_available' => $snapshot['inodes']['available'] ?? null,
        'inodes_used_percent' => $snapshot['inodes']['used_percent'] ?? null,
    ];
}

function buildDailyRollup(string $dataRoot, string $year, string $month, string $day): void {
    $snapshotDir = "$dataRoot/$year/$month/$day";
    $rows = [];

    foreach (glob($snapshotDir . '/*.json') ?: [] as $file) {
        $s = readJson($file);
        if (!$s || ($s['type'] ?? '') !== 'snapshot') {
            continue;
        }
        $rel = substr($file, strlen(rtrim($dataRoot, '/')) + 1);
        $rows[] = snapshotSeriesRow($s, $rel);
    }

    usort($rows, fn($a, $b) => ($a['epoch'] ?? 0) <=> ($b['epoch'] ?? 0));

    writeJson("$dataRoot/$year/$month/$day.json", [
        'schema_version' => 1,
        'type' => 'rollup',
        'period' => 'day',
        'period_key' => "$year-$month-$day",
        'generated_at' => date(DATE_ATOM),
        'summary' => summarizeSnapshots($rows),
        'snapshots' => $rows,
    ]);
}

function buildMonthlyRollup(string $dataRoot, string $year, string $month): void {
    $days = [];
    $all = [];

    foreach (glob("$dataRoot/$year/$month/*.json") ?: [] as $file) {
        if (!preg_match('/\/(\d{2})\.json$/', $file, $m)) {
            continue;
        }
        $d = readJson($file);
        if (!$d || ($d['period'] ?? '') !== 'day') {
            continue;
        }

        $days[] = [
            'day' => $m[1],
            'path' => "$year/$month/{$m[1]}.json",
            'summary' => $d['summary'] ?? [],
        ];

        foreach ($d['snapshots'] ?? [] as $row) {
            $all[] = $row;
        }
    }

    usort($all, fn($a, $b) => ($a['epoch'] ?? 0) <=> ($b['epoch'] ?? 0));
    usort($days, fn($a, $b) => strcmp($a['day'], $b['day']));

    writeJson("$dataRoot/$year/$month.json", [
        'schema_version' => 1,
        'type' => 'rollup',
        'period' => 'month',
        'period_key' => "$year-$month",
        'generated_at' => date(DATE_ATOM),
        'summary' => summarizeSnapshots($all),
        'days' => $days,
        'snapshots' => $all,
    ]);
}

function buildYearlyRollup(string $dataRoot, string $year): void {
    $months = [];
    $all = [];

    foreach (glob("$dataRoot/$year/*.json") ?: [] as $file) {
        if (!preg_match('/\/(\d{2})\.json$/', $file, $m)) {
            continue;
        }
        $d = readJson($file);
        if (!$d || ($d['period'] ?? '') !== 'month') {
            continue;
        }

        $months[] = [
            'month' => $m[1],
            'path' => "$year/{$m[1]}.json",
            'summary' => $d['summary'] ?? [],
        ];

        foreach ($d['snapshots'] ?? [] as $row) {
            $all[] = $row;
        }
    }

    usort($all, fn($a, $b) => ($a['epoch'] ?? 0) <=> ($b['epoch'] ?? 0));
    usort($months, fn($a, $b) => strcmp($a['month'], $b['month']));

    writeJson("$dataRoot/$year.json", [
        'schema_version' => 1,
        'type' => 'rollup',
        'period' => 'year',
        'period_key' => $year,
        'generated_at' => date(DATE_ATOM),
        'summary' => summarizeSnapshots($all),
        'months' => $months,
        'snapshots' => $all,
    ]);
}

function rebuildIndex(string $dataRoot): void {
    $snapshots = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $dataRoot,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
        )
    );

    foreach ($it as $info) {
        if (!$info->isFile() || strtolower($info->getExtension()) !== 'json') {
            continue;
        }

        $path = $info->getPathname();
        if (basename($path) === 'index.json') {
            continue;
        }

        $rel = substr($path, strlen(rtrim($dataRoot, '/')) + 1);

        // Only snapshot paths have YYYY/MM/DD/HH-MM.json.
        if (!preg_match('#^\d{4}/\d{2}/\d{2}/\d{2}-\d{2}\.json$#', $rel)) {
            continue;
        }

        $data = readJson($path);
        if (!$data || ($data['type'] ?? '') !== 'snapshot') {
            continue;
        }

        $snapshots[] = [
            'timestamp' => $data['timestamp'] ?? null,
            'epoch' => $data['epoch'] ?? null,
            'path' => $rel,
        ];
    }

    usort($snapshots, fn($a, $b) => ($a['epoch'] ?? 0) <=> ($b['epoch'] ?? 0));

    $years = [];
    $months = [];
    $days = [];

    foreach ($snapshots as $s) {
        if (!preg_match('#^(\d{4})/(\d{2})/(\d{2})/#', $s['path'], $m)) {
            continue;
        }
        $years[$m[1]] = true;
        $months["{$m[1]}/{$m[2]}"] = true;
        $days["{$m[1]}/{$m[2]}/{$m[3]}"] = true;
    }

    writeJson("$dataRoot/index.json", [
        'schema_version' => 1,
        'generated_at' => date(DATE_ATOM),
        'years' => array_values(array_keys($years)),
        'months' => array_values(array_keys($months)),
        'days' => array_values(array_keys($days)),
        'snapshots' => $snapshots,
    ]);
}

// -------------------------------------------------------------------------
// Scan
// -------------------------------------------------------------------------

$scanRoot = normalizePath($config['scan_root']);
$dataRoot = normalizePath($config['data_root']);
ensureDir($dataRoot);

$categoryTotals = [
    'trash' => ['bytes' => 0, 'files' => 0],
    'logs' => ['bytes' => 0, 'files' => 0],
    'temp' => ['bytes' => 0, 'files' => 0],
];

$categoryDirectoryBytes = [
    'trash' => [],
    'logs' => [],
    'temp' => [],
];

$topFiles = [];
$scannedFiles = 0;
$scannedBytes = 0;
$scanErrors = 0;

$directory = new RecursiveDirectoryIterator(
    $scanRoot,
    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
);

$filter = new RecursiveCallbackFilterIterator(
    $directory,
    function (SplFileInfo $current) use ($config): bool {
        if ($current->isLink()) {
            return false;
        }
        return !isExcluded($current->getPathname(), $config['exclude_paths']);
    }
);

$iterator = new RecursiveIteratorIterator(
    $filter,
    RecursiveIteratorIterator::LEAVES_ONLY,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);

foreach ($iterator as $info) {
    try {
        if (!$info->isFile() || $info->isLink()) {
            continue;
        }

        $path = $info->getPathname();
        $size = $info->getSize();

        $scannedFiles++;
        $scannedBytes += $size;

        addTopFile($topFiles, [
            'path' => relativeDisplay($path, $scanRoot),
            'bytes' => $size,
            'modified_at' => date(DATE_ATOM, $info->getMTime()),
        ], $config['top_count']);

        // Directory classification has precedence over extension classification.
        [$category, $categoryRoot] = categoryFromPath(
            $path,
            $scanRoot,
            $config['category_dirs']
        );

        if ($category === null) {
            $category = categoryFromExtension($path, $config['category_extensions']);
            if ($category !== null) {
                // Standalone category files are attributed to their containing directory.
                $categoryRoot = dirname($path);
            }
        }

        if ($category !== null) {
            $categoryTotals[$category]['bytes'] += $size;
            $categoryTotals[$category]['files']++;

            $categoryRoot = $categoryRoot ?: dirname($path);
            if (!isset($categoryDirectoryBytes[$category][$categoryRoot])) {
                $categoryDirectoryBytes[$category][$categoryRoot] = 0;
            }
            $categoryDirectoryBytes[$category][$categoryRoot] += $size;
        }
    } catch (Throwable $e) {
        $scanErrors++;
    }
}

$topFiles = finishTop($topFiles, $config['top_count']);

foreach ($categoryDirectoryBytes as $category => $dirs) {
    $rows = [];
    foreach ($dirs as $path => $bytes) {
        $rows[] = [
            'path' => relativeDisplay($path, $scanRoot),
            'bytes' => $bytes,
        ];
    }
    $categoryTotals[$category]['top_directories'] = finishTop($rows, $config['top_count']);
}

$filesystem = filesystemStats($scanRoot);
$inodes = inodeStats($scanRoot);

$now = new DateTimeImmutable('now');
$year = $now->format('Y');
$month = $now->format('m');
$day = $now->format('d');
$time = $now->format('H-i');

$snapshot = [
    'schema_version' => 1,
    'type' => 'snapshot',
    'timestamp' => $now->format(DATE_ATOM),
    'epoch' => $now->getTimestamp(),
    'timezone' => $config['timezone'],
    'scan_root' => $scanRoot,
    'scan' => [
        'files' => $scannedFiles,
        'bytes' => $scannedBytes,
        'errors' => $scanErrors,
    ],
    'categories' => $categoryTotals,
    'filesystem' => $filesystem,
    'inodes' => $inodes,
    'top_files' => $topFiles,
];

$snapshotPath = "$dataRoot/$year/$month/$day/$time.json";
writeJson($snapshotPath, $snapshot);

buildDailyRollup($dataRoot, $year, $month, $day);
buildMonthlyRollup($dataRoot, $year, $month);
buildYearlyRollup($dataRoot, $year);
rebuildIndex($dataRoot);

echo "Wrote: $snapshotPath\n";
echo sprintf(
    "Trash: %.2f MiB | Logs: %.2f MiB | Temp: %.2f MiB\n",
    $categoryTotals['trash']['bytes'] / 1048576,
    $categoryTotals['logs']['bytes'] / 1048576,
    $categoryTotals['temp']['bytes'] / 1048576
);
