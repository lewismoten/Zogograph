<?php
declare(strict_types=1);

/*
 * The store, and the schema it is built from.
 *
 * One SQLite file. The thing worth saying about the schema is that a path is
 * written once and referred to by id everywhere else: a home directory has the
 * same few thousand interesting paths in it every hour, and at a sample an hour
 * the full strings would be the overwhelming majority of the database inside a
 * month. It also makes "how has this one file grown" a query against an index
 * rather than a scan of every sample.
 */

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $file = $config['database'];
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create $dir");
    }

    $fresh = !file_exists($file);

    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($fresh) {
        @chmod($file, 0600);
    }

    /*
     * Write-ahead logging, so a dashboard reading while the collector writes
     * does not get "database is locked" - which on a once-a-minute writer and a
     * ten-second poller would otherwise happen regularly.
     */
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);

    return $pdo;
}

/**
 * Brings the schema up to date.
 *
 * Numbered steps applied in order and recorded, so an install that has been
 * running for a year picks up new tables without being rebuilt, and so this
 * function is safe to call on every request.
 */
function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');
    $at = (int)($pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_version')->fetchColumn());

    foreach (migrations() as $version => $statements) {
        if ($version <= $at) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            foreach ($statements as $sql) {
                /*
                 * Adding a column that is already there is not a failure.
                 *
                 * A migration that dies halfway leaves its earlier statements
                 * applied but its version unrecorded, so the next run tries
                 * them again and SQLite refuses with "duplicate column name" -
                 * permanently, on every request, with no way out but a hand
                 * edit. Skipping the ones already done makes a retry work.
                 */
                if (preg_match('/^ALTER TABLE (\w+) ADD COLUMN (\w+)/i', trim($sql), $m)
                    && columnExists($pdo, $m[1], $m[2])) {
                    continue;
                }
                $pdo->exec($sql);
            }
            $pdo->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([$version]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function migrations(): array
{
    return [
        1 => [
            /*
             * Every path in the system, once.
             *
             * `kind` is 'file' or 'dir'. `category` is the one category a path
             * was last counted under, or null - a file belongs to at most one,
             * which is what makes the totals add up.
             */
            "CREATE TABLE paths (
                id        INTEGER PRIMARY KEY,
                path      TEXT NOT NULL UNIQUE,
                kind      TEXT NOT NULL CHECK (kind IN ('file','dir')),
                category  TEXT,
                first_seen INTEGER NOT NULL,
                last_seen  INTEGER NOT NULL
            )",
            'CREATE INDEX paths_category ON paths (category)',

            /*
             * Cheap samples: disk, inodes, load, memory, cpu.
             *
             * Taken every minute from cron and also on demand by the dashboard
             * when somebody turns on live updates, because none of it costs
             * more than reading a few files in /proc.
             */
            "CREATE TABLE vitals (
                id                 INTEGER PRIMARY KEY,
                taken_at           INTEGER NOT NULL,
                disk_total_bytes   INTEGER,
                disk_used_bytes    INTEGER,
                disk_free_bytes    INTEGER,
                disk_used_percent  REAL,
                inodes_total       INTEGER,
                inodes_used        INTEGER,
                inodes_free        INTEGER,
                inodes_used_percent REAL,
                load1              REAL,
                load5              REAL,
                load15             REAL,
                /*
                 * Busy and idle since boot, plus the percentage worked out
                 * against the row before this one.
                 *
                 * The raw counters are kept as well as the percentage because
                 * CPU use only exists as a difference between two moments: a
                 * sample on its own cannot say anything, and keeping the
                 * counters means a gap in collection produces an honest
                 * average over the gap instead of a wrong instant.
                 */
                cpu_total_ticks    INTEGER,
                cpu_idle_ticks     INTEGER,
                cpu_percent        REAL,
                mem_total_bytes    INTEGER,
                mem_available_bytes INTEGER,
                mem_used_percent   REAL,
                swap_total_bytes   INTEGER,
                swap_used_bytes    INTEGER,
                source             TEXT NOT NULL DEFAULT 'cron'
            )",
            'CREATE INDEX vitals_taken_at ON vitals (taken_at)',

            /*
             * Expensive samples: one row per walk of the tree.
             *
             * Kept apart from vitals because they happen on a different clock -
             * a scan costs minutes of IO and runs hourly at most, while vitals
             * cost nothing and run every minute.
             */
            "CREATE TABLE scans (
                id            INTEGER PRIMARY KEY,
                started_at    INTEGER NOT NULL,
                finished_at   INTEGER,
                duration_ms   INTEGER,
                files_seen    INTEGER,
                dirs_seen     INTEGER,
                bytes_seen    INTEGER,
                error         TEXT
            )",
            'CREATE INDEX scans_started_at ON scans (started_at)',

            "CREATE TABLE scan_totals (
                scan_id   INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
                category  TEXT NOT NULL,
                bytes     INTEGER NOT NULL,
                files     INTEGER NOT NULL,
                PRIMARY KEY (scan_id, category)
            )",

            /*
             * The size of one path at one scan. This is the table that answers
             * "what is growing", and the reason paths are normalised: at a few
             * thousand interesting paths an hour, storing the strings here
             * would be most of the database.
             */
            "CREATE TABLE path_sizes (
                scan_id  INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
                path_id  INTEGER NOT NULL REFERENCES paths(id) ON DELETE CASCADE,
                bytes    INTEGER NOT NULL,
                files    INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (scan_id, path_id)
            )",
            // The index that makes one path's history a range scan.
            'CREATE INDEX path_sizes_by_path ON path_sizes (path_id, scan_id)',

            "CREATE TABLE alerts (
                id         INTEGER PRIMARY KEY,
                raised_at  INTEGER NOT NULL,
                rule       TEXT NOT NULL,
                severity   TEXT NOT NULL,
                subject    TEXT NOT NULL,
                detail     TEXT NOT NULL,
                path_id    INTEGER REFERENCES paths(id) ON DELETE SET NULL,
                emailed_at INTEGER,
                email_error TEXT
            )",
            'CREATE INDEX alerts_raised_at ON alerts (raised_at)',
            'CREATE INDEX alerts_rule ON alerts (rule, raised_at)',

            /*
             * Every destructive action, with who asked and what happened.
             *
             * A dashboard that can delete files needs to be able to say what it
             * deleted, whatever the outcome - including the refusals, because a
             * run of refused deletes is what an attempt to escape scan_root
             * looks like.
             */
            "CREATE TABLE actions (
                id         INTEGER PRIMARY KEY,
                at         INTEGER NOT NULL,
                action     TEXT NOT NULL,
                target     TEXT,
                outcome    TEXT NOT NULL,
                bytes_freed INTEGER NOT NULL DEFAULT 0,
                files_removed INTEGER NOT NULL DEFAULT 0,
                detail     TEXT,
                remote_addr TEXT
            )",
            'CREATE INDEX actions_at ON actions (at)',
        ],

        2 => [
            /*
             * Network throughput, and how many cores the load average is over.
             *
             * Same shape as the CPU columns and for the same reason: the
             * interesting number is a rate, a rate is a difference between two
             * moments, and keeping the counters means a gap in collection
             * produces an honest average over the gap rather than a wrong
             * instant. The counters are cumulative since boot and reset when
             * the machine does, which the reader has to allow for.
             */
            'ALTER TABLE vitals ADD COLUMN net_rx_bytes INTEGER',
            'ALTER TABLE vitals ADD COLUMN net_tx_bytes INTEGER',
            'ALTER TABLE vitals ADD COLUMN net_rx_rate REAL',
            'ALTER TABLE vitals ADD COLUMN net_tx_rate REAL',
            /*
             * Load average means nothing without this.
             *
             * A load of 4 is a busy afternoon on eight cores and a fire on one,
             * and the dashboard cannot say which without knowing the number.
             */
            'ALTER TABLE vitals ADD COLUMN cpu_cores INTEGER',
        ],

        3 => [
            /*
             * What was running when the machine was busy.
             *
             * Written only when a reading crosses the CPU or memory threshold,
             * because a row per process per minute grows faster than anything
             * it could warn you about. Grouped by command name rather than by
             * pid: forty workers of one daemon are one answer, and a pid is
             * meaningless by the time anybody reads this.
             */
            "CREATE TABLE process_samples (
                id          INTEGER PRIMARY KEY,
                taken_at    INTEGER NOT NULL,
                reason      TEXT NOT NULL CHECK (reason IN ('cpu','memory')),
                name        TEXT NOT NULL,
                cpu_percent REAL,
                mem_percent REAL,
                rss_bytes   INTEGER,
                procs       INTEGER NOT NULL DEFAULT 1
            )",
            'CREATE INDEX process_samples_taken_at ON process_samples (taken_at)',
        ],

        4 => [
            /*
             * How long the group had been running when it was recorded.
             *
             * Both ends of it. A pool up for forty days whose newest member is
             * nine seconds old is churning - restarting as fast as it dies -
             * and that is a different fault from one merely running a long
             * time, told apart only by having both numbers.
             */
            'ALTER TABLE process_samples ADD COLUMN oldest_seconds INTEGER',
            'ALTER TABLE process_samples ADD COLUMN newest_seconds INTEGER',
        ],

        5 => [
            /*
             * How many directory entries point at this file.
             *
             * Above one, the path shares its bytes with another path: deleting
             * this one frees nothing until the last link goes. Per scan rather
             * than on `paths`, because a link count changes over time and the
             * interesting moment is when it does.
             */
            'ALTER TABLE path_sizes ADD COLUMN links INTEGER',
        ],

        /*
         * Disk I/O, from the device and from this account's own processes.
         *
         * Counters since boot are stored alongside the rates worked out from
         * them, for the reason the CPU columns give: a rate only exists as a
         * difference between two readings, and keeping the raw numbers means a
         * gap in collection produces an honest average over the gap rather than
         * a wrong instant.
         */
        6 => [
            // The device as a whole. Inside CageFS this is the host's disk -
            // every account's traffic together - so it says the disk is busy,
            // never that you are the reason.
            'ALTER TABLE vitals ADD COLUMN io_read_bytes INTEGER',
            'ALTER TABLE vitals ADD COLUMN io_write_bytes INTEGER',
            'ALTER TABLE vitals ADD COLUMN io_ms INTEGER',
            'ALTER TABLE vitals ADD COLUMN io_read_rate REAL',
            'ALTER TABLE vitals ADD COLUMN io_write_rate REAL',
            'ALTER TABLE vitals ADD COLUMN io_util_percent REAL',

            // What this account's own processes did. Inside a cage the only
            // processes visible are its own, which is what makes this the
            // figure an account can actually act on.
            'ALTER TABLE vitals ADD COLUMN own_read_bytes INTEGER',
            'ALTER TABLE vitals ADD COLUMN own_write_bytes INTEGER',
            'ALTER TABLE vitals ADD COLUMN own_read_rate REAL',
            'ALTER TABLE vitals ADD COLUMN own_write_rate REAL',

            // Pressure stall: the share of time tasks were blocked waiting on
            // the disk. Absent on kernels without CONFIG_PSI.
            'ALTER TABLE vitals ADD COLUMN io_pressure_some REAL',
            'ALTER TABLE vitals ADD COLUMN io_pressure_full REAL',
        ],

        /*
         * When the kernel booted, with every reading.
         *
         * One column, and it is what separates "the machine was off" from "the
         * collector was off" - two facts that look identical in a database
         * otherwise, and which need opposite responses.
         */
        7 => [
            'ALTER TABLE vitals ADD COLUMN boot_at INTEGER',
        ],

        /*
         * What set a busy record off, and how much of it the processes explain.
         *
         * Recorded together because the difference is the finding. A reading of
         * 78% with 3% visible means the cause had already exited by the time
         * `ps` ran; the same 78% with 74% visible names the culprit.
         */
        8 => [
            'ALTER TABLE process_samples ADD COLUMN trigger_percent REAL',
            'ALTER TABLE process_samples ADD COLUMN accounted_percent REAL',
        ],
    ];
}

/**
 * The id for a path, inserting it the first time it is seen.
 *
 * The caller is authoritative about the category, including when it says null.
 * An earlier version coalesced, to avoid a caller that did not know wiping the
 * value - but there is only one caller and it always knows, and coalescing
 * meant a path that stopped matching a rule kept the old label for ever. A
 * directory that was once called `tmp` would still be offered to "empty temp".
 *
 * Cached per process: a scan asks about the same directories thousands of
 * times, and a round trip to SQLite for each one is most of the scan.
 */
function pathId(PDO $pdo, string $path, string $kind, int $now, ?string $category): int
{
    static $cache = [];
    if (isset($cache[$path])) {
        $id = $cache[$path];
        $pdo->prepare('UPDATE paths SET last_seen = ?, category = ? WHERE id = ?')
            ->execute([$now, $category, $id]);
        return $id;
    }

    $pdo->prepare(
        'INSERT INTO paths (path, kind, category, first_seen, last_seen)
         VALUES (:path, :kind, :category, :now, :now)
         ON CONFLICT(path) DO UPDATE SET
            last_seen = :now,
            category = :category'
    )->execute([
        ':path' => $path,
        ':kind' => $kind,
        ':category' => $category,
        ':now' => $now,
    ]);

    $stmt = $pdo->prepare('SELECT id FROM paths WHERE path = ?');
    $stmt->execute([$path]);
    $id = (int)$stmt->fetchColumn();
    $cache[$path] = $id;
    return $id;
}
