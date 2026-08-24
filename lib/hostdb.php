<?php
declare(strict_types=1);

/*
 * The host's own database.
 *
 * A second SQLite file, deliberately. The account collector runs as the cPanel
 * user once a minute; this one runs as root on whatever schedule WHM allows.
 * Pointing both at one file would mean two writers of different privilege in
 * the same write-ahead log, and the first time root created a WAL the account
 * could not write to, collection would stop with a lock error nobody would
 * think to look for. Separate files means the only thing they share is a
 * directory.
 *
 * The file is created by root and then handed to the account that owns the
 * dashboard, so the web side can read it as an ordinary file it owns. Root
 * stays the only writer.
 */

// hostMigrate leans on columnExists from db.php. Required rather than assumed:
// this file is included by setup.php and api.php on different paths.
require_once __DIR__ . '/db.php';

/**
 * The command that collects a host reading, with real paths filled in.
 *
 * Written out rather than described, because the two things people get wrong
 * are the absolute path to the install and which of the eight PHP binaries on a
 * cPanel box to use - and both are known here. Shared between the setup page
 * and the dashboard so the two can never print different instructions.
 */
function hostCronCommand(?string $root = null): string
{
    $root ??= dirname(__DIR__);

    $php = PHP_BINDIR . '/php';
    foreach (['/usr/local/bin/php', '/opt/cpanel/ea-php85/root/usr/bin/php'] as $candidate) {
        if (is_executable($candidate)) {
            $php = $candidate;
            break;
        }
    }

    return sprintf('%s %s/host-cron.php --collect --prune', $php, $root);
}

/**
 * The same, as a crontab line for root.
 *
 * Every five minutes: a reading walks every process in /proc, which is not free
 * on a busy box, and nothing it watches moves faster than that.
 */
function hostCronLine(?string $root = null): string
{
    return '*/5 * * * * ' . hostCronCommand($root) . ' --quiet';
}

/** Where the host database lives when the config does not say. */
function hostDatabasePath(array $config): string
{
    $configured = (string)($config['host']['database'] ?? '');
    if ($configured !== '') {
        return $configured;
    }
    // Beside the account database, which is already denied over HTTP.
    return dirname((string)$config['database']) . '/host.db';
}

/**
 * Opens the host database, creating and migrating it if needed.
 *
 * $forWriting is the difference between the root collector and the dashboard.
 * The dashboard must never migrate or create: it runs as a user who may not be
 * able to write the file at all, and a half-created database owned by the wrong
 * user is a mess to unpick. It opens what is there and reads it.
 */
function hostDb(array $config, bool $forWriting): ?PDO
{
    static $handles = [];
    $key = $forWriting ? 'rw' : 'ro';
    if (isset($handles[$key])) {
        return $handles[$key];
    }

    $file = hostDatabasePath($config);

    if (!$forWriting) {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $handles[$key] = $pdo;
    }

    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create $dir");
    }

    $fresh = !file_exists($file);

    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 10000');

    hostMigrate($pdo);

    if ($fresh) {
        hostHandOver($config, $file);
    }

    return $handles[$key] = $pdo;
}

/**
 * Gives the database to whoever runs the dashboard.
 *
 * Root creates it, so by default root is the only one who can read it - and the
 * dashboard would show an empty panel with no clue why. The owner comes from
 * the config rather than being guessed, because guessing wrong here means
 * either an unreadable file or a world-readable one, and the second is worse:
 * this database lists every process on the machine with its command line.
 *
 * Group-readable at most. Never world.
 */
function hostHandOver(array $config, string $file): void
{
    @chmod($file, 0640);

    $owner = (string)($config['host']['owner'] ?? '');
    if ($owner === '' || !function_exists('chown')) {
        return;
    }

    // The WAL and shared-memory files are part of the database: an owner who
    // cannot read those cannot read a database in WAL mode at all.
    foreach ([$file, $file . '-wal', $file . '-shm'] as $part) {
        if (file_exists($part)) {
            @chown($part, $owner);
            @chmod($part, 0640);
        }
    }
}

function hostMigrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');
    $at = (int)($pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_version')->fetchColumn());

    foreach (hostMigrations() as $version => $statements) {
        if ($version <= $at) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            foreach ($statements as $sql) {
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

function hostMigrations(): array
{
    return [
        1 => [
            /*
             * One row per collection: the whole memory picture at an instant.
             *
             * Wide and flat rather than normalised, because every column is
             * read together and none of them repeat - this is a reading, not a
             * catalogue. The derived columns (used, anon, unaccounted) are
             * stored rather than computed on read so that a change to how they
             * are worked out cannot silently rewrite history.
             */
            "CREATE TABLE host_samples (
                id              INTEGER PRIMARY KEY,
                taken_at        INTEGER NOT NULL,
                mem_total       INTEGER,
                mem_free        INTEGER,
                mem_available   INTEGER,
                buffers         INTEGER,
                cached          INTEGER,
                swap_cached     INTEGER,
                shmem           INTEGER,
                anon_pages      INTEGER,
                mapped          INTEGER,
                slab            INTEGER,
                sreclaimable    INTEGER,
                sunreclaim      INTEGER,
                page_tables     INTEGER,
                kernel_stack    INTEGER,
                dirty           INTEGER,
                swap_total      INTEGER,
                swap_free       INTEGER,
                used_bytes      INTEGER,
                cache_bytes     INTEGER,
                unaccounted     INTEGER,
                rss_sum_bytes   INTEGER,
                pss_sum_bytes   INTEGER,
                pss_procs       INTEGER,
                swap_sum_bytes  INTEGER,
                proc_count      INTEGER,
                thread_count    INTEGER,
                shm_segments    INTEGER,
                shm_bytes       INTEGER,
                devshm_total    INTEGER,
                devshm_used     INTEGER,
                load1           REAL,
                load5           REAL,
                load15          REAL,
                cpu_cores       INTEGER,
                source          TEXT NOT NULL DEFAULT 'cron'
            )",
            'CREATE INDEX host_samples_taken_at ON host_samples (taken_at)',

            /*
             * Memory per user, which is the column CageFS makes invisible.
             *
             * The account cannot see that `git` is holding two gigabytes,
             * because from inside the cage those processes do not exist. This
             * table is the whole reason the host collector is worth running.
             */
            "CREATE TABLE host_users (
                sample_id  INTEGER NOT NULL REFERENCES host_samples(id) ON DELETE CASCADE,
                user       TEXT NOT NULL,
                procs      INTEGER NOT NULL,
                threads    INTEGER,
                rss_bytes  INTEGER,
                pss_bytes  INTEGER,
                swap_bytes INTEGER
            )",
            'CREATE INDEX host_users_sample ON host_users (sample_id)',

            "CREATE TABLE host_processes (
                sample_id       INTEGER NOT NULL REFERENCES host_samples(id) ON DELETE CASCADE,
                pid             INTEGER NOT NULL,
                user            TEXT,
                name            TEXT,
                rss_bytes       INTEGER,
                pss_bytes       INTEGER,
                swap_bytes      INTEGER,
                vsz_bytes       INTEGER,
                threads         INTEGER,
                elapsed_seconds INTEGER,
                command         TEXT
            )",
            'CREATE INDEX host_processes_sample ON host_processes (sample_id)',

            /*
             * Kernel slab caches, which belong to nobody and are real memory.
             *
             * A quarter of a gigabyte of xfs_inode does not appear in any
             * process, in any account, or in `free`'s idea of used. It is the
             * first thing to check when the numbers do not add up.
             */
            "CREATE TABLE host_slab (
                sample_id    INTEGER NOT NULL REFERENCES host_samples(id) ON DELETE CASCADE,
                name         TEXT NOT NULL,
                objs         INTEGER,
                obj_bytes    INTEGER,
                cache_bytes  INTEGER
            )",
            'CREATE INDEX host_slab_sample ON host_slab (sample_id)',

            /*
             * Files worth watching that the account cannot see at all.
             *
             * Apache writes a domain's logs under /etc/apache2, owned by root.
             * A 763 MB rotated log there costs the same disk as one in the home
             * directory and is invisible to every tool the account can run.
             */
            /*
             * Device, inode and link count, not just size.
             *
             * Apache publishes the same log twice - once flat and once under a
             * per-account directory - as two names for one inode. They are not
             * two files and they do not cost two allocations, and a collector
             * that reports the sum of their sizes invents disk that does not
             * exist. The identity of a file is (device, inode); the path is
             * only a name for it.
             */
            "CREATE TABLE host_files (
                sample_id INTEGER NOT NULL REFERENCES host_samples(id) ON DELETE CASCADE,
                path      TEXT NOT NULL,
                bytes     INTEGER,
                owner     TEXT,
                mtime     INTEGER,
                device    INTEGER,
                inode     INTEGER,
                links     INTEGER
            )",
            'CREATE INDEX host_files_sample ON host_files (sample_id)',

            /*
             * CloudLinux limits, when the machine has them.
             *
             * A process killed for hitting a memory limit leaves nothing behind
             * except a fault counter, so a rising count here is often the only
             * evidence of what happened.
             */
            "CREATE TABLE host_lve (
                sample_id     INTEGER NOT NULL REFERENCES host_samples(id) ON DELETE CASCADE,
                user          TEXT NOT NULL,
                cpu_percent   REAL,
                mem_bytes     INTEGER,
                procs         INTEGER,
                faults_cpu    INTEGER,
                faults_mem    INTEGER,
                faults_procs  INTEGER
            )",
            'CREATE INDEX host_lve_sample ON host_lve (sample_id)',
        ],

        /*
         * Columns added after the first release.
         *
         * These also appear in the CREATE TABLE above, so a fresh install gets
         * them there and skips this step - the ALTER guard in hostMigrate()
         * makes a column that already exists a no-op. They are repeated here
         * because a database created before they were added has already
         * recorded version 1 and will never run that statement again.
         *
         * That is the whole reason a shipped migration is never edited in
         * place: changing step 1 fixes new installs and silently leaves every
         * existing one behind, which surfaces later as "table host_files has no
         * column named device" on the one machine that matters.
         */
        2 => [
            'ALTER TABLE host_files ADD COLUMN device INTEGER',
            'ALTER TABLE host_files ADD COLUMN inode INTEGER',
            'ALTER TABLE host_files ADD COLUMN links INTEGER',
            // Belt and braces: added the same careless way, one release earlier.
            'ALTER TABLE host_samples ADD COLUMN pss_procs INTEGER',
        ],

        /*
         * The long series: one row per service per reading.
         *
         * Deliberately *not* hanging off host_samples with a cascade, unlike
         * every other detail table here. Those carry a process list and a slab
         * table and are pruned at a fortnight to keep the file small; this one
         * is a name and five integers, and its whole value is being older than
         * that. "Forgejo is using 1.8 GB" is a fact; "Forgejo used 700 MB last
         * month, 1.2 GB last week and 1.8 GB now" is a diagnosis, and it needs
         * history the detail tables cannot afford to keep.
         *
         * Keyed by service name rather than pid on purpose. A pid is gone the
         * moment the daemon restarts, and a series that resets to zero every
         * time something is upgraded cannot show a leak.
         */
        3 => [
            "CREATE TABLE host_services (
                taken_at    INTEGER NOT NULL,
                name        TEXT NOT NULL,
                procs       INTEGER NOT NULL,
                threads     INTEGER,
                rss_bytes   INTEGER,
                pss_bytes   INTEGER,
                swap_bytes  INTEGER,
                read_bytes  INTEGER,
                write_bytes INTEGER
            )",
            'CREATE INDEX host_services_at ON host_services (taken_at)',
            // The index that makes one service's history a range scan rather
            // than a walk of every reading ever taken.
            'CREATE INDEX host_services_name ON host_services (name, taken_at)',
        ],

        /*
         * Disk I/O: the devices, the pressure, and who caused it.
         *
         * The per-service columns are repeated from the CREATE TABLE above for
         * the reason written at the top of the README's schema section - a step
         * already recorded is never run again, so a column added only there
         * would reach new installs and silently skip every existing one.
         */
        4 => [
            'ALTER TABLE host_services ADD COLUMN read_bytes INTEGER',
            'ALTER TABLE host_services ADD COLUMN write_bytes INTEGER',

            // Pressure stall for I/O: the share of time tasks were blocked
            // waiting on the disk. `full` is the share when nothing at all
            // could run, which is what a machine grinding to a halt looks like.
            'ALTER TABLE host_samples ADD COLUMN io_pressure_some REAL',
            'ALTER TABLE host_samples ADD COLUMN io_pressure_full REAL',
            'ALTER TABLE host_samples ADD COLUMN io_read_bytes INTEGER',
            'ALTER TABLE host_samples ADD COLUMN io_write_bytes INTEGER',
            'ALTER TABLE host_samples ADD COLUMN io_ms INTEGER',

            /*
             * Per device, per reading.
             *
             * Counters since boot, not rates. A rate is a difference between
             * two readings and storing it alone would make a gap in collection
             * indistinguishable from a quiet hour; keeping the counters means
             * the difference can always be recovered, over whatever interval
             * actually elapsed.
             */
            "CREATE TABLE host_disks (
                sample_id   INTEGER NOT NULL REFERENCES host_samples(id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                stacked     INTEGER NOT NULL DEFAULT 0,
                read_bytes  INTEGER,
                write_bytes INTEGER,
                read_ios    INTEGER,
                write_ios   INTEGER,
                io_ms       INTEGER,
                queue_ms    INTEGER,
                in_flight   INTEGER
            )",
            'CREATE INDEX host_disks_sample ON host_disks (sample_id)',
        ],

        /*
         * What the collector could see when it ran.
         *
         * Recorded because the dashboard runs as the account and the collector
         * runs as root, and the two see different machines. Without this the
         * panel can only report the account's view - which on CloudLinux is a
         * filtered /proc that says nothing about the host - and a reader asking
         * "does this machine have PSI?" gets an answer about the cage instead.
         */
        5 => [
            'ALTER TABLE host_samples ADD COLUMN kernel TEXT',
            'ALTER TABLE host_samples ADD COLUMN diskstats_available INTEGER',
            'ALTER TABLE host_samples ADD COLUMN psi_available INTEGER',
        ],

        /*
         * Why pressure stall info is missing, worked out where it can be.
         *
         * The dashboard runs as the account and cannot read /boot/config-*,
         * which is the file that says whether CONFIG_PSI was compiled in. The
         * collector runs as root and can, so the answer is recorded at
         * collection time and simply read back later.
         */
        6 => [
            'ALTER TABLE host_samples ADD COLUMN psi_reason TEXT',
        ],

        // The same boot column as the account side, for the same reason.
        7 => [
            'ALTER TABLE host_samples ADD COLUMN boot_at INTEGER',

            /*
             * Boot sessions and how the one before each ended.
             *
             * Keyed on the boot time, so re-reading `last` on every collection
             * refreshes what is known without duplicating anything - the
             * current session's row gains an end time once it has one.
             *
             * Kept apart from host_samples and not pruned with them: this is
             * the record of what happened to the machine, and it is smaller and
             * longer-lived than any reading.
             */
            "CREATE TABLE host_boots (
                booted_at     INTEGER PRIMARY KEY,
                kernel        TEXT,
                ended         TEXT,
                shutdown_at   INTEGER,
                still_running INTEGER NOT NULL DEFAULT 0,
                seen_at       INTEGER NOT NULL
            )",
        ],

        /*
         * Clear the boot history once.
         *
         * Boots collected before the timezone fix were stored at an epoch four
         * hours out, and the table is keyed on that epoch - so the corrected
         * reading inserts alongside the wrong one instead of replacing it, and
         * every restart appears twice, once in each timezone. Deleting rather
         * than trying to match them up is safe because this table is derived:
         * it is a cache of what `last` says, and the next collection rebuilds
         * it from wtmp in full.
         */
        8 => [
            'DELETE FROM host_boots',
        ],

        /*
         * How long each service's processes have been alive.
         *
         * The oldest and the newest of the group, because together they say
         * what neither says alone: a group whose oldest is hours old and whose
         * newest is seconds old is churning, not merely long-lived.
         *
         * Recorded for the anomaly that spots a batch job which never finished.
         * Eleven webalizer processes still sitting there after 78 minutes look
         * exactly like one running normally unless their age is written down
         * and compared against how long that job usually takes.
         */
        9 => [
            'ALTER TABLE host_services ADD COLUMN oldest_seconds INTEGER',
            'ALTER TABLE host_services ADD COLUMN newest_seconds INTEGER',
        ],

        /*
         * What each process has actually read from and written to a disk.
         *
         * Per service was already recorded and is the right unit for a chart -
         * "MariaDB is writing 40 MB/s" is the sentence somebody wants. It is
         * the wrong unit for finding the culprit: eleven webalizer processes
         * sum into one row, and the backup that runs as a shell script shows up
         * under whatever it last exec'd.
         *
         * Nullable, deliberately. /proc/<pid>/io is readable by root and by the
         * process's owner and by nobody else, so a collector without root gets
         * nothing for other people's processes - and NULL says that, where 0
         * would claim the process sat idle.
         */
        10 => [
            'ALTER TABLE host_processes ADD COLUMN read_bytes INTEGER',
            'ALTER TABLE host_processes ADD COLUMN write_bytes INTEGER',
        ],

        /*
         * Whether each watched daemon was alive at each reading.
         *
         * Its own timestamp rather than a sample_id, like host_services and for
         * the same reason: this is the table somebody reads in three months to
         * ask when MariaDB last fell over, and it must outlive the per-reading
         * detail that gets pruned at a fortnight. A row is a name and a handful
         * of integers, so a year of it costs less than a day of process rows.
         *
         * state is running / stopped / absent / unknown, and the last two are
         * not the same as the second. See lib/daemons.php - a service that is
         * not installed here must never be shown as down, or the panel has a
         * permanent red row and stops being read.
         */
        11 => [
            'CREATE TABLE host_daemons (
                taken_at INTEGER NOT NULL,
                name TEXT NOT NULL,
                label TEXT NOT NULL,
                state TEXT NOT NULL,
                unit TEXT,
                units_total INTEGER NOT NULL DEFAULT 0,
                units_active INTEGER NOT NULL DEFAULT 0,
                active_state TEXT,
                sub_state TEXT,
                restarts INTEGER,
                started_at INTEGER,
                procs INTEGER NOT NULL DEFAULT 0,
                pid INTEGER,
                source TEXT NOT NULL
            )',
            'CREATE INDEX host_daemons_at ON host_daemons (taken_at)',
            'CREATE INDEX host_daemons_name ON host_daemons (name, taken_at)',
        ],

        /*
         * Where the disk actually went, from root's point of view.
         *
         * Three tables because there are three different answers to "72 GB is
         * used and I can only find 4", and they are not variations of each
         * other - see lib/hostdisk.php.
         *
         * All three keep their own timestamp rather than a sample_id: a walk of
         * the disk is expensive and is not run on every collection, so these
         * rows do not line up with the per-minute samples and must not be
         * pruned with them.
         */
        12 => [
            'CREATE TABLE host_mounts (
                taken_at INTEGER NOT NULL,
                device TEXT NOT NULL,
                mount TEXT NOT NULL,
                type TEXT,
                total_bytes INTEGER NOT NULL,
                used_bytes INTEGER NOT NULL,
                free_bytes INTEGER NOT NULL,
                used_percent REAL,
                also_at TEXT
            )',
            'CREATE INDEX host_mounts_at ON host_mounts (taken_at)',

            'CREATE TABLE host_dirs (
                taken_at INTEGER NOT NULL,
                path TEXT NOT NULL,
                depth INTEGER NOT NULL,
                bytes INTEGER NOT NULL,
                files INTEGER NOT NULL
            )',
            'CREATE INDEX host_dirs_at ON host_dirs (taken_at, bytes)',
            'CREATE INDEX host_dirs_path ON host_dirs (path)',

            'CREATE TABLE host_deleted (
                taken_at INTEGER NOT NULL,
                pid INTEGER,
                process TEXT,
                path TEXT NOT NULL,
                bytes INTEGER NOT NULL,
                holders INTEGER NOT NULL DEFAULT 1
            )',
            'CREATE INDEX host_deleted_at ON host_deleted (taken_at)',

            'CREATE TABLE host_walks (
                taken_at INTEGER NOT NULL,
                root TEXT NOT NULL,
                complete INTEGER NOT NULL,
                seconds REAL,
                dirs_scanned INTEGER,
                deleted_bytes INTEGER,
                deleted_readable INTEGER,
                note TEXT
            )',
            'CREATE INDEX host_walks_at ON host_walks (taken_at)',
        ],
    ];
}

/**
 * Drops old readings.
 *
 * The detail tables are much larger than the samples they hang off, so they go
 * first and by cascade - deleting a sample takes its processes, its slab rows
 * and its files with it.
 */
function hostPrune(PDO $pdo, int $keepDays): int
{
    $cutoff = time() - max(1, $keepDays) * 86400;
    $stmt = $pdo->prepare('DELETE FROM host_samples WHERE taken_at < ?');
    $stmt->execute([$cutoff]);
    return $stmt->rowCount();
}

/**
 * Drops old service readings, on their own much longer clock.
 *
 * Separate from the samples because the point of this table is to outlive them.
 * A service row is a name and five integers - at a reading every five minutes
 * and forty services, roughly a megabyte a month - so keeping a quarter of a
 * year costs almost nothing and is what makes "and what was it in June?"
 * answerable.
 */
function hostPruneServices(PDO $pdo, int $keepDays): int
{
    $cutoff = time() - max(1, $keepDays) * 86400;
    $stmt = $pdo->prepare('DELETE FROM host_services WHERE taken_at < ?');
    $stmt->execute([$cutoff]);
    $removed = $stmt->rowCount();

    // Daemon states keep the same long clock: both are the tables whose value
    // is being older than everything else.
    $stmt = $pdo->prepare('DELETE FROM host_daemons WHERE taken_at < ?');
    $stmt->execute([$cutoff]);

    return $removed + $stmt->rowCount();
}
