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

        /*
         * Why an alert fired: the machine as it stood at the moment.
         *
         * An alert says what crossed a line. It cannot say what was doing it,
         * and by the time anybody reads the mail the evidence is gone - the
         * runaway has been reaped, the log has been rotated, and all that is
         * left is a subject line nobody can act on. This is the difference
         * between knowing something happened and being able to find out what.
         *
         * One row per evaluation, not per alert: seven rules firing together
         * are describing one machine, and seven copies of its state would be
         * six copies too many. The alerts point at the incident instead.
         *
         * The evidence is JSON rather than columns. It is written once and
         * read whole, never queried by field, and its shape depends on what
         * the host allowed to be read - a table would need a column per fact
         * and a migration every time one more became reachable.
         */
        9 => [
            'CREATE TABLE incidents (
                id INTEGER PRIMARY KEY,
                captured_at INTEGER NOT NULL,
                triggered_by TEXT NOT NULL,
                evidence TEXT NOT NULL,
                bytes INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE INDEX incidents_at ON incidents (captured_at)',
            'ALTER TABLE alerts ADD COLUMN incident_id INTEGER',
        ],

        /*
         * Which daemons the dashboard has been asked to watch.
         *
         * In *this* database rather than the host one, which looks backwards
         * until you follow where the writes come from. host.db is created and
         * owned by root and handed to the account read-only, so the dashboard
         * physically cannot write a toggle there. It can write here, and the
         * collector runs as root and can read here. The data flows the only
         * direction it can.
         *
         * Absence means watched. A service is monitored until somebody says
         * otherwise, so a fresh install watches everything and a row only
         * appears once a choice has been made.
         */
        10 => [
            'CREATE TABLE daemon_watch (
                name TEXT PRIMARY KEY,
                watched INTEGER NOT NULL,
                changed_at INTEGER NOT NULL
            )',
        ],

        /*
         * How many raw readings one row now stands for.
         *
         * 1 for everything as collected. A compacted row carries the count of
         * what it replaced, so a chart can say "this point is the worst of 60
         * readings" instead of quietly implying it is a measurement of one
         * minute like every point beside it.
         *
         * Nullable, and read as 1 when absent: every row written before this
         * migration is a single reading, which is exactly what null means here.
         */
        11 => [
            'ALTER TABLE vitals ADD COLUMN samples INTEGER',
        ],

        /*
         * Jobs the dashboard would like root to do.
         *
         * The dashboard runs as the account and cannot run anything as root -
         * so a "walk the disk now" button cannot walk anything. What it can do
         * is leave a note here, which the root collector reads on its next run
         * and acts on. Same direction of travel as daemon_watch, and for the
         * same reason: this is the only database both of them can reach.
         *
         * No completion column. The collector must not write to the account's
         * database, so "done" is worked out by comparing this timestamp with
         * the newest row in host_walks - which the collector writes anyway.
         */
        12 => [
            'CREATE TABLE host_tasks (
                task TEXT PRIMARY KEY,
                requested_at INTEGER NOT NULL,
                requested_by TEXT
            )',
        ],

        /*
         * What this account can reach, as last probed.
         *
         * Stored rather than probed on every page load: the check shells out,
         * and a dashboard that spawned a shell every ten seconds to ask the
         * same question would be its own performance problem.
         */
        13 => [
            'CREATE TABLE capabilities (
                name TEXT PRIMARY KEY,
                group_name TEXT NOT NULL,
                checked_at INTEGER NOT NULL,
                path TEXT,
                executable INTEGER NOT NULL DEFAULT 0,
                alternate_path TEXT,
                version TEXT,
                source TEXT
            )',
            'CREATE TABLE capability_runs (
                ran_at INTEGER NOT NULL,
                seconds REAL,
                found INTEGER,
                total INTEGER,
                cagefs_installed INTEGER,
                cagefs_enabled INTEGER,
                cagefs_note TEXT
            )',
        ],

        /*
         * When a program appeared, vanished, or changed version.
         *
         * The interesting series is not what is installed - that is a list -
         * but when it moved. A package upgrade, a CageFS change, someone
         * removing a tool: each is one row here, and together they are the
         * history of what this account could do.
         *
         * Written by comparing a probe against the one before it, which is why
         * there is no schedule: a change is recorded when somebody checks, and
         * the timestamp is when it was *noticed* rather than when it happened.
         * Saying so matters - a weekly check turns a Tuesday upgrade into a
         * Sunday event.
         */
        14 => [
            'CREATE TABLE capability_changes (
                at INTEGER NOT NULL,
                name TEXT NOT NULL,
                group_name TEXT NOT NULL,
                side TEXT NOT NULL,
                old_state TEXT,
                new_state TEXT,
                old_version TEXT,
                new_version TEXT
            )',
            'CREATE INDEX capability_changes_at ON capability_changes (at)',
            'CREATE INDEX capability_changes_name ON capability_changes (name, at)',
        ],

        /*
         * Whether somebody has looked at an alert.
         *
         * The navigation badge counts what is unread, so without this it counts
         * everything raised in the last day for ever - and a badge that never
         * clears is one nobody reads. Acknowledging is not the same as
         * deleting: the alert stays in the history, it just stops asking.
         */
        /*
         * What kind of thing the space is, as distinct from what rule watches it.
         *
         * The categories are an alerting concern - logs, temp, trash - and a
         * file belongs to at most one of them, with most files belonging to
         * none. That makes them useless for the question "what is this disk
         * actually full of", where every byte has to land somewhere or the
         * answer does not add up. Types are that second partition: total,
         * exhaustive, and deliberately not the thing the delete buttons read.
         */
        /*
         * The extensions inside `other`, which is the slice nobody can act on.
         *
         * "31% other" is the least useful thing a chart can say - it names the
         * gap in its own knowledge and stops. Keeping the extensions that fell
         * through turns it into "31% is .bkup and .old", which is a finding.
         *
         * Only the ones with real weight are kept: an account has thousands of
         * distinct extensions with one file each, and storing that tail every
         * scan would cost more than the answer is worth.
         */
        /*
         * Your own names for addresses and ports.
         *
         * `1.2.3.4` is a fact and `Lewis: home` is what you actually know about
         * it, and the second one is the difference between reading a capture
         * and decoding it. The same for ports: a catalogue can say 8000 is
         * "often an app server" and only you know it is the Icecast stream.
         *
         * Kept in the account database rather than the host's, because these
         * are the operator's notes and should survive the host collector being
         * reinstalled, moved or run by somebody else.
         */
        /*
         * Watching a URL from the outside.
         *
         * The only check here that leaves the machine, and the only one that
         * can answer the question a visitor is asking. Results are kept
         * separately from the definition so a check can be edited without
         * losing its history - the history is usually the reason you are
         * looking at it.
         */
        /*
         * Domains, and the dates a person puts in a calendar.
         *
         * Together because they answer the same question - what is coming up
         * that I will regret forgetting - and because the calendar draws from
         * both. A domain expiry is an event this can discover; a VPS renewal is
         * one only the operator knows about, and both belong on the same page.
         */
        /*
         * How a URL check makes its request, not just what it asks for.
         *
         * Following redirects is the right default and is exactly wrong when
         * the redirect is the thing being tested - a check on the plain-HTTP
         * address that follows to HTTPS reports 200 and never notices the day
         * the redirect disappears. Same for the method: HEAD is cheaper and
         * some endpoints answer it differently, and OPTIONS is the only way to
         * test CORS at all.
         */
        /*
         * What a domain publishes, and when each record appeared or went.
         *
         * A record is kept rather than deleted when it stops resolving: "the MX
         * record disappeared last Tuesday" is the question somebody asks a week
         * later, and a row that was removed cannot answer it. The change log
         * beside it is what makes that query cheap.
         */
        28 => [
            /*
             * What the internet says an address is called.
             *
             * Deliberately not the `labels` table. That one is what *you*
             * decided an address means - "Lewis: home" - and is small,
             * hand-written and permanent. This is automatic, large,
             * occasionally wrong and stale within months, and mixing the two
             * would put a machine guess in a list somebody curated.
             *
             * A null name is a real answer: plenty of addresses have no PTR
             * record, and remembering that is what stops the same hopeless
             * address being re-asked every time it appears in a capture.
             */
            "CREATE TABLE rdns (
                address     TEXT PRIMARY KEY,
                name        TEXT,
                checked_at  INTEGER NOT NULL,
                first_seen  INTEGER NOT NULL,
                resolved_by TEXT NOT NULL,
                ms          INTEGER NOT NULL DEFAULT 0
            )",
            'CREATE INDEX rdns_checked ON rdns (checked_at)',

            /*
             * Addresses waiting to be looked up.
             *
             * A queue rather than a function call because the lookup leaves
             * this machine: a page showing forty addresses would make forty DNS
             * queries while somebody waited, and one slow resolver would turn
             * the page into a timeout. One is drained per collector run,
             * oldest first, so nothing is starved by newer arrivals.
             *
             * `taken_at` is set before the lookup runs, not after - a process
             * killed mid-query would otherwise leave the same row at the head
             * of the queue for every subsequent run to hang on.
             */
            "CREATE TABLE rdns_queue (
                id       INTEGER PRIMARY KEY,
                address  TEXT NOT NULL,
                asked_at INTEGER NOT NULL,
                asked_by TEXT NOT NULL,
                taken_at INTEGER,
                taken_by TEXT
            )",
            'CREATE INDEX rdns_queue_order ON rdns_queue (taken_at, asked_at)',
            'CREATE UNIQUE INDEX rdns_queue_pending ON rdns_queue (address) WHERE taken_at IS NULL',
        ],

        27 => [
            /*
             * What is installed, and when each thing last moved.
             *
             * Almost every "it was working yesterday" turns out to be something
             * changing underneath - a kernel that came with a package update,
             * an OpenSSL bump that moved a default. None of that is in any
             * chart here, and the machine does not keep a usable note of it
             * either: the package log is root's and says what was installed
             * rather than what is now answering.
             *
             * `first_seen` is when the *current* value first appeared, not when
             * the row was created - which is what makes "this kernel has been
             * running since March" answerable.
             */
            "CREATE TABLE software (
                side       TEXT NOT NULL,
                name       TEXT NOT NULL,
                label      TEXT NOT NULL,
                value      TEXT NOT NULL,
                note       TEXT,
                first_seen INTEGER NOT NULL,
                last_seen  INTEGER NOT NULL,
                PRIMARY KEY (side, name)
            )",

            /*
             * `at` is when a change was *noticed*, not when it happened.
             *
             * This is written on a probe rather than on a schedule, so a weekly
             * check turns a Tuesday upgrade into a Sunday event. The page says
             * so; recording it as the moment of the change would be a quiet lie.
             */
            "CREATE TABLE software_changes (
                id        INTEGER PRIMARY KEY,
                at        INTEGER NOT NULL,
                side      TEXT NOT NULL,
                name      TEXT NOT NULL,
                label     TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT NOT NULL
            )",
            'CREATE INDEX software_changes_at ON software_changes (at)',
            'CREATE INDEX software_changes_name ON software_changes (name, at)',

            /*
             * A fingerprint for the binaries that have no version to read.
             *
             * Plenty have no --version and plenty more print something
             * unparseable, and a change still matters: "this file is different
             * from the one that was here on Tuesday" is a smaller fact than a
             * version bump but it is the same kind of fact.
             */
            'ALTER TABLE capabilities ADD COLUMN fingerprint TEXT',
            'ALTER TABLE capability_changes ADD COLUMN old_fingerprint TEXT',
            'ALTER TABLE capability_changes ADD COLUMN new_fingerprint TEXT',
        ],

        26 => [
            /*
             * What somebody was thinking at the time.
             *
             * Every chart here answers "what happened" and none of them answer
             * "why" - the why is that somebody upgraded PHP on the Tuesday, and
             * six months later the chart still shows the step with nobody left
             * who remembers.
             *
             * `at` is when the note is *about*, which is not always when it was
             * written: an incident written up the next morning belongs on the
             * chart where the incident was, not where the writing was.
             */
            "CREATE TABLE notes (
                id         INTEGER PRIMARY KEY,
                at         INTEGER NOT NULL,
                written_at INTEGER NOT NULL,
                body       TEXT NOT NULL,
                scope      TEXT NOT NULL CHECK (scope IN ('plain','page','chart','site')),
                view       TEXT,
                chart      TEXT,
                link       TEXT,
                broadcast  INTEGER NOT NULL DEFAULT 0,
                who        TEXT,
                cleared_at INTEGER,
                cleared_by TEXT
            )",
            'CREATE INDEX notes_at ON notes (at)',
            'CREATE INDEX notes_view ON notes (view, at)',

            /*
             * What a note captured, kept with it.
             *
             * A monitor that rolls up its own evidence is not much use during
             * an incident, so a note can hold the data behind a page - or every
             * page - exactly as it arrived, outside every retention rule.
             */
            "CREATE TABLE note_snapshots (
                id      INTEGER PRIMARY KEY,
                note_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
                kind    TEXT NOT NULL,
                label   TEXT NOT NULL,
                bytes   INTEGER NOT NULL,
                body    BLOB NOT NULL,
                at      INTEGER NOT NULL
            )",
            'CREATE INDEX note_snapshots_note ON note_snapshots (note_id)',
        ],

        25 => [
            /*
             * Watching a value that is not a number.
             *
             * A regex check already pulled out group 1 and stored it as a REAL,
             * which is right for a queue depth and useless for the thing people
             * actually want to watch: a version string. `2.14.1` is not a
             * number and never will be, and the interesting event is not its
             * size but the moment it changes.
             *
             * So the text is kept beside the number, and which group to take is
             * a setting rather than always the first - a pattern often needs
             * context around the part that matters.
             */
            'ALTER TABLE url_results ADD COLUMN text_value TEXT',
            'ALTER TABLE url_checks ADD COLUMN capture_group INTEGER NOT NULL DEFAULT 1',
            // Whether a change in that value should raise an alert.
            'ALTER TABLE url_checks ADD COLUMN notify_change INTEGER NOT NULL DEFAULT 0',
            // The last text seen, so a change can be noticed without reading
            // back through the history on every run.
            'ALTER TABLE url_checks ADD COLUMN last_text TEXT',
        ],

        24 => [
            /*
             * What the last complete symlink walk found.
             *
             * Kept so that a *new* problem can be told from an old one. A loop
             * that has been there two years is a job for a rainy afternoon; one
             * that appeared this morning is a deploy that went wrong, and only
             * the second is worth waking anybody about.
             */
            "CREATE TABLE symlink_problems (
                path       TEXT NOT NULL,
                kind       TEXT NOT NULL,
                target     TEXT,
                first_seen INTEGER NOT NULL,
                last_seen  INTEGER NOT NULL,
                PRIMARY KEY (path, kind)
            )",
        ],

        23 => [
            /*
             * The body of a fetched page, kept just long enough to show it.
             *
             * The preview cannot use srcdoc: this page's Content-Security-Policy
             * is inherited by a srcdoc frame, and every inline style in somebody
             * else's markup then becomes a console error of ours. Serving the
             * body from its own URL gives the frame its own policy.
             *
             * Which means the body has to survive between the fetch and the
             * preview request. Kept by token, pruned hard - this is a scratch
             * buffer, not a history, and holding other people's pages any longer
             * than the moment they are being looked at is not something this
             * tool should do.
             */
            "CREATE TABLE fetch_bodies (
                token       TEXT PRIMARY KEY,
                url         TEXT NOT NULL,
                content_type TEXT,
                body        BLOB NOT NULL,
                at          INTEGER NOT NULL
            )",
            'CREATE INDEX fetch_bodies_at ON fetch_bodies (at)',
        ],

        22 => [
            "CREATE TABLE dns_records (
                id         INTEGER PRIMARY KEY,
                domain     TEXT NOT NULL,
                name       TEXT NOT NULL,
                type       TEXT NOT NULL,
                value      TEXT NOT NULL,
                ttl        INTEGER,
                first_seen INTEGER NOT NULL,
                last_seen  INTEGER NOT NULL,
                gone_at    INTEGER
            )",
            'CREATE UNIQUE INDEX dns_records_key ON dns_records (domain, name, type, value)',
            'CREATE INDEX dns_records_domain ON dns_records (domain, gone_at)',

            "CREATE TABLE dns_changes (
                id     INTEGER PRIMARY KEY,
                domain TEXT NOT NULL,
                at     INTEGER NOT NULL,
                change TEXT NOT NULL,
                name   TEXT NOT NULL,
                type   TEXT NOT NULL,
                value  TEXT NOT NULL
            )",
            'CREATE INDEX dns_changes_at ON dns_changes (at)',
        ],

        21 => [
            "ALTER TABLE url_checks ADD COLUMN follow_redirects INTEGER NOT NULL DEFAULT 1",
            "ALTER TABLE url_checks ADD COLUMN method TEXT NOT NULL DEFAULT 'GET'",
            "ALTER TABLE url_checks ADD COLUMN send_etag INTEGER NOT NULL DEFAULT 0",
            "ALTER TABLE url_checks ADD COLUMN last_etag TEXT",
            "ALTER TABLE url_results ADD COLUMN redirected_to TEXT",
            "ALTER TABLE url_results ADD COLUMN hops INTEGER NOT NULL DEFAULT 0",
        ],

        20 => [
            "CREATE TABLE domains (
                id               INTEGER PRIMARY KEY,
                name             TEXT NOT NULL UNIQUE,
                warn_days        INTEGER NOT NULL DEFAULT 45,
                interval_seconds INTEGER NOT NULL DEFAULT 604800,
                added_at         INTEGER NOT NULL,
                last_checked_at  INTEGER,
                expires_at       INTEGER,
                created_at       INTEGER,
                updated_at       INTEGER,
                registrar        TEXT,
                status           TEXT,
                nameservers      TEXT,
                auto_renew       INTEGER,
                last_error       TEXT,
                whois            TEXT
            )",

            /*
             * Dates the operator keeps, which nothing here could discover.
             *
             * A VPS renewing on the 3rd, a certificate bought outside this
             * machine, an annual invoice. `resolved_at` rather than deletion,
             * because "I have dealt with this" and "this never mattered" are
             * different and the first one is worth keeping a record of.
             */
            "CREATE TABLE events (
                id          INTEGER PRIMARY KEY,
                title       TEXT NOT NULL,
                at          INTEGER NOT NULL,
                repeats     TEXT NOT NULL DEFAULT 'once',
                link        TEXT,
                note        TEXT,
                priority    INTEGER NOT NULL DEFAULT 0,
                resolved_at INTEGER,
                added_at    INTEGER NOT NULL
            )",
            'CREATE INDEX events_at ON events (at)',
        ],

        19 => [
            "CREATE TABLE url_checks (
                id               INTEGER PRIMARY KEY,
                url              TEXT NOT NULL,
                label            TEXT NOT NULL,
                interval_seconds INTEGER NOT NULL,
                expect_status    INTEGER NOT NULL DEFAULT 200,
                check_kind       TEXT NOT NULL DEFAULT 'none',
                check_value      TEXT,
                enabled          INTEGER NOT NULL DEFAULT 1,
                added_at         INTEGER NOT NULL,
                last_at          INTEGER,
                last_ok          INTEGER,
                last_status      INTEGER,
                last_error       TEXT
            )",

            "CREATE TABLE url_results (
                id       INTEGER PRIMARY KEY,
                check_id INTEGER NOT NULL REFERENCES url_checks(id) ON DELETE CASCADE,
                at       INTEGER NOT NULL,
                status   INTEGER,
                ms       INTEGER,
                bytes    INTEGER,
                value    REAL,
                matched  INTEGER,
                ok       INTEGER NOT NULL DEFAULT 0,
                error    TEXT
            )",
            'CREATE INDEX url_results_check_at ON url_results (check_id, at)',
        ],

        18 => [
            "CREATE TABLE labels (
                id       INTEGER PRIMARY KEY,
                kind     TEXT NOT NULL,
                match    TEXT NOT NULL,
                label    TEXT NOT NULL,
                note     TEXT,
                added_at INTEGER NOT NULL
            )",
            'CREATE UNIQUE INDEX labels_kind_match ON labels (kind, match)',
        ],

        17 => [
            "CREATE TABLE scan_extensions (
                scan_id   INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
                extension TEXT NOT NULL,
                bytes     INTEGER NOT NULL,
                files     INTEGER NOT NULL,
                PRIMARY KEY (scan_id, extension)
            )",
        ],

        16 => [
            "CREATE TABLE scan_file_types (
                scan_id INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
                type    TEXT NOT NULL,
                bytes   INTEGER NOT NULL,
                files   INTEGER NOT NULL,
                PRIMARY KEY (scan_id, type)
            )",
        ],

        15 => [
            'ALTER TABLE alerts ADD COLUMN acknowledged_at INTEGER',
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
