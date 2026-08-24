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
            "CREATE TABLE host_files (
                sample_id INTEGER NOT NULL REFERENCES host_samples(id) ON DELETE CASCADE,
                path      TEXT NOT NULL,
                bytes     INTEGER,
                owner     TEXT,
                mtime     INTEGER
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
