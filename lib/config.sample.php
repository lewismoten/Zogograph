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
        'cooldown_seconds' => 21600,

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
];
