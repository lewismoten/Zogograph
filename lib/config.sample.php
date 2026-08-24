<?php
declare(strict_types=1);

/*
 * Copy this to lib/config.php and edit it. config.php is not in git.
 *
 * Generate the password hash with:
 *   php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
 *
 * Generate the mask secret with:
 *   php -r 'echo bin2hex(random_bytes(32)), "\n";'
 */

return [
    // What to walk. Everything the monitor reports lives under here, and every
    // destructive action is refused outside it.
    'scan_root' => getenv('HOME') ?: '/home/regaldra',
    'timezone' => 'America/New_York',

    'database' => __DIR__ . '/../data/stats.db',

    // Sign-in. Without this nobody can unmask paths or delete anything, which
    // is a safe default for a fresh install.
    'password_hash' => '',

    /*
     * Salts the pseudonyms shown to signed-out visitors.
     *
     * Without a secret the mask is just a hash of the path, and anybody who
     * suspects a path exists can confirm it by hashing their guess. Must be
     * set, and must not change, or every chart legend renames itself.
     */
    'mask_secret' => '',

    // How long a signed-in session lasts without activity, in seconds.
    'session_lifetime' => 43200,

    'alerts' => [
        'enabled' => false,
        'to' => '',
        'from' => '',
        // No more than one message per rule per this many seconds, however bad
        // things get. An alert that arrives every minute is a filter rule.
        /*
         * CPU held at the ceiling, not a moment of it.
         *
         * A single reading at 100% is a backup starting or a page rendering and
         * is over before anybody could read about it. What matters is the CPU
         * *staying* there, which is what a runaway process looks like.
         *
         * So all three of these define the rule together: at least `cpu_share`
         * of the readings across the last `cpu_minutes` must be at or above
         * `cpu_percent`. A spike cannot satisfy that however tall it is.
         *
         * Mind what the percentage means on CloudLinux: inside CageFS
         * /proc/stat is virtualised to the account's own CPU allowance, so 95
         * here is 95% of what this account may use - which is a real thing to
         * know about, and not the same as the server being at 95%.
         *
         * Set cpu_percent to 0 to turn the rule off.
         */
        'cpu_percent' => 95.0,
        'cpu_minutes' => 15,
        'cpu_share' => 0.9,

        // 21600 seconds is six hours.
        'cooldown_seconds' => 21600,

        /*
         * Anomalies: what is unusual, rather than what is large.
         *
         * The settings above are thresholds, and thresholds have two failure
         * modes. They fire when nothing is wrong - a directory that gains
         * 200 MB every night trips the category rule every night, and after a
         * week nobody reads the mail. And they stay silent when something is:
         * eleven webalizer processes sat on this server for 78 minutes without
         * crossing any of them, because each one was small and the disk was
         * fine. What gave it away was that the job normally takes two minutes,
         * and no threshold can encode "normally" - that number is different on
         * every server.
         *
         * These rules compare each thing against its own history instead. They
         * need no tuning to start with and are deliberately quiet: none of them
         * says anything until it has `min_readings` of history to say it from,
         * and every proportional test is paired with an absolute floor so that
         * 25% of something tiny stays out of your inbox.
         *
         * They are gated by 'enabled' above as well as this one - anomalies
         * ride the same email and the same cooldown as everything else.
         */
        'anomalies' => [
            'enabled' => true,

            // Nothing is judged against less history than this.
            'min_readings' => 8,

            /*
             * Two windows, because the two kinds of subject have different
             * rhythms. A daemon appears in every reading, so a day of history
             * is hundreds of samples. A job that runs four times a day appears
             * in four, and nothing can be concluded from four - the rules that
             * watch those look back a week to have anything to compare with.
             */
            'baseline_hours' => 24,
            'service_baseline_hours' => 168,

            // A service holding materially more memory than it usually does.
            // Both have to be exceeded, so a quarter of a small service is not
            // grounds for mail.
            'service_pss_percent' => 25.0,
            'service_pss_floor_bytes' => 67108864,      // 64 MiB

            // A category that jumped, where jumping is not what it does. The
            // multiple is what keeps the nightly backup quiet while the same
            // growth somewhere flat still reports.
            'category_floor_bytes' => 104857600,        // 100 MB
            'category_multiple' => 3.0,

            // A file growing far faster than that same file grows. Only the
            // biggest few movers in a scan are examined; the rest cost more to
            // check than they could ever be worth.
            'file_floor_bytes' => 52428800,             // 50 MiB
            'file_multiple' => 4.0,
            'file_limit' => 5,

            // Load above one job per core, held across this many consecutive
            // readings. Load counts work waiting as well as work running.
            'load_readings' => 3,

            // Swap filling *while* available memory falls. Either alone is
            // meaningless; together they are the kernel running out of options.
            'swap_floor_bytes' => 33554432,             // 32 MiB

            // Many more copies of something than there usually are.
            'process_multiple' => 3.0,
            'process_floor' => 4,

            /*
             * Something still running long after that thing normally finishes.
             *
             * This one needs the host collector, and it is the rule that would
             * have caught the webalizer jobs. It never judges daemons: a
             * process that has simply been up for nine days is not late for
             * anything. See tracksTheClock() in lib/anomalies.php for how the
             * two are told apart, which is the whole difficulty of the rule.
             */
            'age_multiple' => 3.0,
            'age_floor_seconds' => 900,
        ],

        // Fire when free space drops below this, in bytes, or below this
        // fraction of the filesystem.
        'disk_free_bytes' => 5 * 1024 * 1024 * 1024,
        'disk_free_percent' => 5.0,
        'inodes_free_percent' => 5.0,

        // Fire when a category or a single path grows by more than this in one
        // scan interval.
        'category_jump_bytes' => 1024 * 1024 * 1024,
        'path_jump_bytes' => 512 * 1024 * 1024,
        // Or when a directory gains this many files in one interval, which is
        // the shape a runaway log or a stuck job makes.
        'path_jump_files' => 5000,

        // Fire when free space is falling fast enough to run out within this
        // many hours, judged from the recent trend.
        'projected_full_hours' => 72.0,
    ],

    'scan' => [
        // How many offenders to keep per category, per scan.
        'top_count' => 25,
        // Directory names matched case-insensitively.
        'category_dirs' => [
            'trash' => ['.trash', 'trash'],
            'logs' => ['log', 'logs'],
            'temp' => ['tmp', 'temp', 'temporary'],
        ],
        /*
         * Whole filenames, matched case-insensitively, wherever they turn up.
         *
         * These win over both the directory and the extension rules, which is
         * the point of them: Apache and cPanel drop `error_log` all over an
         * account, one per directory that has ever thrown a warning, and any
         * one of them can quietly reach ten gigabytes. Counted as logs they
         * disappear into the log total; counted on their own you can see them.
         */
        'category_files' => [
            'errors' => ['error_log', 'php_errorlog', 'error.log'],
        ],

        // Standalone files outside matching directories.
        'category_extensions' => [
            'logs' => ['log'],
            'temp' => ['tmp', 'temp'],
        ],
        'exclude_paths' => [
            __DIR__ . '/../data',
        ],
    ],

    /*
     * What may be deleted, and from where.
     *
     * Deleting is the one thing here that cannot be undone, so it is not
     * governed by the same list that decides what gets *counted*. A path has to
     * be inside scan_root, inside one of these categories, not a symlink, and
     * requested by a signed-in session over POST with a matching token.
     */
    'deletable_categories' => ['trash', 'temp'],

    /*
     * What a signed-in session may read the contents of.
     *
     * Separate from deletable_categories, and deliberately a different set:
     * being allowed to look at a file and being allowed to remove it are
     * different permissions. Reading is for finding out what filled the disk,
     * which means logs; deleting is for trash and scratch space.
     */
    'viewable_categories' => ['logs', 'errors'],

    /*
     * Recording what was running, and when it is worth doing.
     *
     * Only while the machine is actually struggling. Every process every minute
     * would be a table growing faster than the disk it is watching; the three
     * minutes the CPU was pinned is small and is the only part anybody reads.
     *
     * On CloudLinux, mind what the percentage means. Inside CageFS /proc/stat is
     * virtualised to the account's own CPU allowance, so 70 here is 70% of what
     * this account may use - which a single busy PHP request can reach while the
     * server is otherwise idle. If the record fills with moments that were not
     * interesting, raise it; the host collector reports the machine's real load
     * separately.
     */
    'processes' => [
        'record_when_cpu_above' => 70.0,
        'record_when_memory_above' => 85.0,
        'keep' => 8,

        /*
         * Whether a signed-in session may stop processes.
         *
         * Set it to false and the buttons are not drawn and the endpoints
         * refuse - worth doing if the dashboard is reachable from anywhere you
         * would not want a stolen session to have a kill switch.
         */
        'may_stop' => true,

        /*
         * Names that are never signalled, however they are asked for.
         *
         * The account's own plumbing. Killing sshd from a web page locks you
         * out of the machine you are trying to fix; killing the shell you are
         * sitting in is a support ticket. Note that this is matched against the
         * bare executable name, so 'php' here would also protect 'php cron.php'.
         *
         * Omit the key entirely to use the built-in list in lib/signals.php,
         * which is this and a few more. Set it to an empty array to protect
         * nothing by name - pid 1, this dashboard's own ancestry and other
         * users' processes are still refused, because those are not opinions.
         */
        'protect' => [
            'init', 'systemd', 'launchd',
            'sshd', 'bash', 'sh', 'zsh', 'login', 'tmux', 'screen',
            'cron', 'crond', 'cpsrvd', 'cpanel',
            'httpd', 'nginx', 'apache2', 'php-fpm', 'lsphp', 'litespeed',
            'mysqld', 'mariadbd', 'exim', 'dovecot', 'named',
        ],

        /*
         * Most processes one stop may signal at once.
         *
         * A brake on the pattern matcher rather than on you. A regular
         * expression that is one character broader than intended is the classic
         * way to empty an account, and it always looks correct until it runs.
         */
        'max_group' => 40,
    ],

    /*
     * The host-side collector, run by root out of WHM's crontab.
     *
     * Only relevant if you have root on the machine. Inside CageFS a cPanel
     * account gets its own /proc holding only its own processes, so the account
     * collector can watch memory vanish and have nothing to blame - the process
     * holding two gigabytes belongs to another user and, from inside the cage,
     * does not exist. Neither do the kernel's slab caches, nor a 763 MB Apache
     * log under /etc that costs exactly as much disk as one in the home
     * directory.
     *
     * host-cron.php fills that gap. It writes to its own database so that two
     * writers of different privilege never share a write-ahead log.
     */
    /*
     * How long the monitor keeps its own history.
     *
     * A reading a minute is half a million rows a year, and a monitor that
     * fills the disk it is watching has failed in the most embarrassing way
     * available to it. So old readings are compacted: many rows become one
     * covering a longer span, and the further back you look the coarser it
     * gets.
     *
     * A compacted row holds the *worst* moment in its bucket, not the average
     * and not an arbitrary sample. That matters more than it sounds: a minute
     * at 100% CPU inside an otherwise idle hour averages to 1.7% and vanishes,
     * and a spike is most of the reason to keep a year of history at all. The
     * cost is that an old point reads as "load reached 8 during this hour"
     * rather than "load was 8" - which is the more useful of the two.
     *
     * Zero anywhere means for ever, never "delete everything".
     */
    /*
     * What the monitor knows about logs specifically.
     *
     * Logs caused the incident this tool was written after, and they have a
     * life cycle that general rules cannot see. A log rotates; a .bkup beside
     * one is meant to be temporary; two names for one file is one file. None of
     * that is visible to a rule that knows only how big something is.
     *
     * Set any threshold to 0 to turn that rule off.
     */
    'logs' => [
        // Any single log above this, whatever it is doing. A log has no
        // natural size the way a database or an archive does.
        'max_bytes' => 1073741824,                  // 1 GiB

        /*
         * Bytes an hour, against a ceiling rather than against the file's own
         * past. The baseline rule in lib/anomalies.php asks whether a rate is
         * unusual for that file and answers no for a log that has written a
         * gigabyte an hour all week - which is exactly when somebody wants to
         * be told.
         */
        'fast_bytes_per_hour' => 104857600,         // 100 MiB/h

        /*
         * A job writes one of these beside the log it is processing and
         * removes it when it finishes. One still there a day later means the
         * job died halfway - the signature of the Webalizer incident, and
         * invisible to everything else because the file is small.
         */
        'backup_suffixes' => ['.bkup', '.bak', '.tmp', '.partial'],
        'backup_max_age_hours' => 24,

        /*
         * Rotation appears in the history as a fall, when the file is renamed
         * or truncated and starts again. A log this big with no fall at all
         * across this many days is one whose rotation is not running.
         */
        'rotate_after_days' => 8,
        'rotate_min_bytes' => 104857600,            // 100 MiB

        /*
         * Error-log acceleration: the recent half of the history against the
         * earlier half. A site that has begun to fail writes errors faster and
         * faster, and the useful moment to hear about it is while the curve is
         * bending rather than when it crosses a line. Both a multiple and a
         * floor, because doubling from nothing is still nothing.
         */
        'acceleration_multiple' => 4.0,
        'acceleration_floor_per_hour' => 10485760,  // 10 MiB/h
        'error_names' => ['error_log', 'error.log', 'php_errorlog', 'php-error.log'],
    ],

    'retention' => [
        // Every reading exactly as collected.
        'raw_days' => 7,
        // Then one row per five minutes.
        'five_minute_days' => 30,
        // Then one row per hour.
        'hourly_days' => 365,
        // Then one row per day, kept indefinitely - a few hundred rows a year,
        // and what makes "was this normal last summer?" answerable.
        'daily_days' => 0,

        /*
         * Untouched history either side of an alert.
         *
         * The hour around the thing that went wrong is the whole reason for
         * keeping any of this, and compacting away the readings that sit
         * beside an incident record would be a poor joke. These stay at full
         * resolution however old they get.
         */
        'protect_hours' => 2,

        /*
         * Incident snapshots, kept separately from everything else because
         * they are the evidence rather than the measurements. One per alerting
         * evaluation - not one per reading - at roughly 26 KB, so even a bad
         * year is a few hundred rows and a handful of megabytes.
         */
        'incident_days' => 0,
        'alert_days' => 0,
        'action_days' => 0,

        'scan_days' => 400,
        'process_days' => 400,
    ],

    'host' => [
        /*
         * Whether any of this is in play.
         *
         * Off means host-cron.php refuses to run and the dashboard hides the
         * panel entirely - worth setting if you have no root on the machine,
         * since none of it can work without it.
         */
        'enabled' => true,

        // Defaults to host.db beside the account database, which .htaccess
        // already denies over HTTP.
        'database' => null,

        /*
         * Who the dashboard runs as.
         *
         * Root creates the database, so without this only root can read it and
         * the dashboard shows an empty panel with no clue why. Set it to the
         * cPanel username. The wizard fills it in with whatever account it is
         * running as, which is the right answer in every normal case.
         *
         * Blank means no handover: root keeps the file to itself and the
         * dashboard cannot read it. That is the safe direction to fail in -
         * this database lists every process on the machine with its command
         * line, so a wrong guess that made it world-readable would be worse
         * than one that made it unreadable.
         */
        'owner' => '',

        'keep_days' => 14,

        /*
         * Which daemons to watch, and what they are called here.
         *
         * Leave this out entirely and a sensible list is used: Apache, MariaDB,
         * Forgejo, MongoDB, Redis, Icecast, PHP-FPM, Exim, Dovecot and BIND.
         * Each knows several names for itself, because the same service is
         * httpd on Red Hat and apache2 on Debian.
         *
         * Setting it *replaces* that list rather than adding to it. Somebody
         * who writes out the services they care about means those services, and
         * quietly watching ten more would be the monitor arguing with them.
         *
         * A service on the list that this machine does not run is reported as
         * "not installed" and never alerts. That is the point of having a
         * generous default: there is no cost to watching for something that
         * was never here, and a real cost to leaving out the one that dies.
         *
         *   'daemons' => [
         *       ['name' => 'httpd', 'label' => 'Apache',
         *        'units' => ['httpd', 'apache2'],   // systemd, globs allowed
         *        'processes' => ['httpd']],         // fallback without systemd
         *   ],
         */
        'daemons' => null,

        /*
         * How long to keep the per-service memory series.
         *
         * Much longer than the rest, because these rows are a name and five
         * integers - about a megabyte a month - and their entire value is being
         * older than the detail tables. Answering "was Forgejo this big in
         * June?" is the difference between a diagnostic and a record.
         */
        'keep_service_days' => 90,

        /*
         * Files worth watching that the account cannot stat.
         *
         * Apache writes each domain's logs under /etc/apache2 owned by root.
         * Anything smaller than watch_min_bytes is ignored, or this becomes a
         * list of several hundred empty logs.
         */
        'watch' => [
            '/etc/apache2/logs/domlogs/*',
            '/etc/apache2/logs/domlogs/*/*',
            '/usr/local/apache/logs/*log',
            '/var/log/messages*',
        ],
        'watch_min_bytes' => 1048576,
    ],
];
