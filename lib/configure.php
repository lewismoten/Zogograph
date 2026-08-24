<?php
declare(strict_types=1);

/*
 * What a config is made of, described once.
 *
 * Two front ends ask these questions - the browser and the command line - and
 * they used to be two separate lists of prompts. One list, described as data,
 * is the only way they stay the same: a field added here appears in both, with
 * the same label, the same default and the same validation.
 *
 * The defaults all come from lib/config.sample.php, which remains the single
 * description of what an option means.
 */

/**
 * Every category the rules can produce, in a stable order.
 *
 * Derived rather than written out, because it was written out - in the scan, in
 * the API's column list and in the dashboard's filter - and adding a fourth
 * meant finding all three. A category defined in the config and missing from
 * one of those lists is one that gets collected and never shown.
 */
function categoryNames(array $rules): array
{
    $names = [];
    foreach (['category_dirs', 'category_files', 'category_extensions'] as $group) {
        foreach (array_keys($rules[$group] ?? []) as $category) {
            $names[$category] = true;
        }
    }
    return array_keys($names);
}

/**
 * Every question the wizard asks.
 *
 * `path` is where the answer lands in the config array. `type` decides how it
 * is prompted for and how it is parsed. `when` hides a field unless another
 * answer makes it relevant.
 */
function configFields(array $sample): array
{
    return [
        [
            'key' => 'scan_root',
            'path' => ['scan_root'],
            'type' => 'text',
            'label' => 'Directory to watch',
            'help' => 'Everything is measured under here, and nothing outside it can ever be deleted.',
            'default' => (string)$sample['scan_root'],
            'validate' => function (string $value): ?string {
                if ($value === '') {
                    return 'A directory is required.';
                }
                if (!is_dir($value)) {
                    return "There is no directory at $value.";
                }
                if (!is_readable($value)) {
                    return "$value is not readable by this user.";
                }
                return null;
            },
        ],
        [
            'key' => 'timezone',
            'path' => ['timezone'],
            'type' => 'text',
            'label' => 'Timezone',
            'help' => 'Used for the timestamps on the dashboard and in alert emails.',
            'default' => (string)$sample['timezone'],
            'validate' => fn(string $v): ?string => in_array($v, timezone_identifiers_list(), true)
                ? null
                : 'Not a timezone PHP knows. Try America/New_York or UTC.',
        ],
        [
            'key' => 'password',
            'path' => ['password_hash'],
            'type' => 'password',
            'label' => 'Sign-in password',
            'help' => 'Reveals real paths and enables deleting. Leave blank to disable sign-in, '
                . 'which leaves the dashboard readable with every path masked.',
            'default' => '',
        ],
        [
            'key' => 'alerts_enabled',
            'path' => ['alerts', 'enabled'],
            'type' => 'bool',
            'label' => 'Send alert emails',
            'help' => 'The point of the thing: it tells you before the disk fills, not after.',
            'default' => (bool)$sample['alerts']['enabled'],
        ],
        [
            'key' => 'alerts_to',
            'path' => ['alerts', 'to'],
            'type' => 'email',
            'label' => 'Send alerts to',
            'default' => (string)$sample['alerts']['to'],
            'when' => 'alerts_enabled',
            'validate' => fn(string $v): ?string => filter_var($v, FILTER_VALIDATE_EMAIL)
                ? null
                : 'That does not look like an email address.',
        ],
        [
            'key' => 'alerts_from',
            'path' => ['alerts', 'from'],
            'type' => 'email',
            'label' => 'Send alerts from',
            'help' => 'Blank uses whatever the server sends as by default.',
            'default' => (string)$sample['alerts']['from'],
            'when' => 'alerts_enabled',
            'optional' => true,
            'validate' => fn(string $v): ?string => $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL)
                ? null
                : 'That does not look like an email address.',
        ],
        [
            'key' => 'disk_free_bytes',
            'path' => ['alerts', 'disk_free_bytes'],
            'type' => 'bytes',
            'label' => 'Warn when free space drops below',
            'help' => 'Write it how you say it: 5G, 500M, or a plain number of bytes.',
            'default' => (int)$sample['alerts']['disk_free_bytes'],
            'when' => 'alerts_enabled',
        ],
        [
            'key' => 'projected_full_hours',
            'path' => ['alerts', 'projected_full_hours'],
            'type' => 'number',
            'label' => 'Warn when the trend says it will be full within (hours)',
            'help' => 'Fitted over the last day of readings. This is the alert that arrives '
                . 'while there is still time to do something.',
            'default' => (float)$sample['alerts']['projected_full_hours'],
            'when' => 'alerts_enabled',
            'validate' => fn(string $v): ?string => is_numeric($v) && (float)$v > 0
                ? null
                : 'Enter a number of hours.',
        ],
        [
            'key' => 'path_jump_bytes',
            'path' => ['alerts', 'path_jump_bytes'],
            'type' => 'bytes',
            'label' => 'Warn when one file or directory grows in a single scan by',
            'help' => 'The alert that names the culprit rather than the symptom.',
            'default' => (int)$sample['alerts']['path_jump_bytes'],
            'when' => 'alerts_enabled',
        ],
        [
            'key' => 'cpu_percent',
            'path' => ['alerts', 'cpu_percent'],
            'type' => 'number',
            'label' => 'Warn when CPU stays at or above (%)',
            'help' => 'Sustained, not a spike: it has to hold across the whole window '
                . 'below. Zero turns it off. On CloudLinux this is a share of what the '
                . 'account may use, not of the machine.',
            'default' => (float)($sample['alerts']['cpu_percent'] ?? 95),
            'when' => 'alerts_enabled',
            'validate' => fn(string $v): ?string => is_numeric($v) && (float)$v >= 0 && (float)$v <= 100
                ? null
                : 'Enter a percentage between 0 and 100.',
        ],
        [
            'key' => 'cpu_minutes',
            'path' => ['alerts', 'cpu_minutes'],
            'type' => 'int',
            'label' => 'How long it must stay there (minutes)',
            'help' => 'A burst cannot hold across a long window, which is the whole point '
                . 'of measuring duration rather than height.',
            'default' => (int)($sample['alerts']['cpu_minutes'] ?? 15),
            'when' => 'alerts_enabled',
            'validate' => fn(string $v): ?string => is_numeric($v) && (int)$v >= 1
                ? null
                : 'Enter a number of minutes, at least 1.',
        ],
        [
            'key' => 'cooldown_seconds',
            'path' => ['alerts', 'cooldown_seconds'],
            'type' => 'seconds',
            'label' => 'Wait at least this long before repeating an alert',
            'help' => 'Written how you say it: 6h, 90m, 2d. A plain number is seconds. '
                . 'An alert that arrives every minute becomes a filter rule within a day.',
            'default' => (int)$sample['alerts']['cooldown_seconds'],
            'when' => 'alerts_enabled',
        ],

        /*
         * The baseline rules.
         *
         * Only the switch and the two numbers worth arguing about are asked
         * here. The rest have sensible defaults that depend on each other, and
         * a wizard page of seventeen tuning knobs is one nobody finishes; they
         * are all documented in lib/config.sample.php for anyone who wants them.
         */
        [
            'key' => 'anomalies_enabled',
            'path' => ['alerts', 'anomalies', 'enabled'],
            'type' => 'bool',
            'label' => 'Also alert on what is unusual, not just what is large',
            'help' => 'Compares each service, category and file against its own history. '
                . 'It catches the things no threshold can - a job that normally takes two '
                . 'minutes still running after an hour - and stays quiet about the nightly '
                . 'backup that always grows. Says nothing until it has history to say it from.',
            'default' => (bool)($sample['alerts']['anomalies']['enabled'] ?? true),
            'when' => 'alerts_enabled',
        ],
        [
            'key' => 'anomalies_min_readings',
            'path' => ['alerts', 'anomalies', 'min_readings'],
            'type' => 'int',
            'label' => 'Readings of history needed before it will judge anything',
            'help' => 'Below this it says nothing at all. Too low and its first day is '
                . 'all false alarms, which is how these get turned off.',
            'default' => (int)($sample['alerts']['anomalies']['min_readings'] ?? 8),
            'when' => 'alerts_enabled',
            'validate' => fn(string $v): ?string => is_numeric($v) && (int)$v >= 3
                ? null
                : 'Enter a number of readings, at least 3.',
        ],
        [
            'key' => 'anomalies_service_percent',
            'path' => ['alerts', 'anomalies', 'service_pss_percent'],
            'type' => 'number',
            'label' => 'Warn when a service holds more memory than usual by (%)',
            'help' => 'Against its own median over the last day, not against a fixed size. '
                . 'A service that grows and stays grown is leaking; one that grows and comes '
                . 'back was busy.',
            'default' => (float)($sample['alerts']['anomalies']['service_pss_percent'] ?? 25),
            'when' => 'alerts_enabled',
            'validate' => fn(string $v): ?string => is_numeric($v) && (float)$v > 0
                ? null
                : 'Enter a percentage above 0.',
        ],

        [
            'key' => 'deletable_categories',
            'path' => ['deletable_categories'],
            'type' => 'categories',
            'label' => 'Categories that may be deleted from the dashboard',
            'help' => 'Only ever by a signed-in session, and only inside the watched directory.',
            'default' => (array)$sample['deletable_categories'],
            // Whatever the rules define, so a category added to the config
            // shows up here instead of being quietly undeletable.
            'options' => categoryNames($sample['scan']),
        ],

        /*
         * The host half.
         *
         * Only useful with root on the machine, so it is asked last and can be
         * turned off in one click. Everything under it is hidden until it is on.
         */
        [
            'key' => 'host_enabled',
            'path' => ['host', 'enabled'],
            'type' => 'bool',
            'label' => 'Also collect host-wide readings (needs root)',
            'help' => 'Adds a second collector, run by root from WHM, that sees what this '
                . 'account cannot: other users\' processes and memory, kernel slab caches, '
                . 'and root-owned logs under /etc. On CloudLinux this is the only way to find '
                . 'out what is using the machine.',
            'default' => (bool)($sample['host']['enabled'] ?? true),
        ],
        [
            'key' => 'host_owner',
            'path' => ['host', 'owner'],
            'type' => 'text',
            'label' => 'Account the dashboard runs as',
            'help' => 'Root creates the host database, so without this only root can read it '
                . 'and the panel stays empty. Almost always the cPanel username.',
            // Detected rather than guessed: the user PHP is running as right
            // now is the one that will be reading the file later.
            'default' => currentAccountName() ?: (string)($sample['host']['owner'] ?? ''),
            'when' => 'host_enabled',
            'validate' => fn(string $v): ?string => $v === '' || preg_match('/^[a-z_][a-z0-9_-]*$/i', $v)
                ? null
                : 'That does not look like a username.',
            'optional' => true,
        ],
        [
            'key' => 'host_watch',
            'path' => ['host', 'watch'],
            'type' => 'lines',
            'label' => 'Root-owned files to watch, one pattern per line',
            'help' => 'Apache writes each domain\'s logs under /etc owned by root, where a '
                . 'rotated log can reach several hundred megabytes invisibly. Shell globs.',
            'default' => (array)($sample['host']['watch'] ?? []),
            'when' => 'host_enabled',
        ],
        [
            'key' => 'host_keep_days',
            'path' => ['host', 'keep_days'],
            'type' => 'int',
            'label' => 'Days of host readings to keep',
            'help' => 'A host reading is much bigger than an account one - it carries every '
                . 'user, the biggest processes and the slab table.',
            'default' => (int)($sample['host']['keep_days'] ?? 14),
            'when' => 'host_enabled',
            'validate' => fn(string $v): ?string => is_numeric($v) && (float)$v >= 1
                ? null
                : 'Enter a number of days, at least 1.',
        ],
    ];
}

/**
 * The account this process is running as, for the host database's owner.
 *
 * Detected rather than defaulted to a name in the sample file, because a
 * hardcoded username is right on exactly one machine and silently wrong
 * everywhere else - and being wrong here produces a database the dashboard
 * cannot read, with nothing to say why.
 */
function currentAccountName(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && !empty($info['name'])) {
            return (string)$info['name'];
        }
    }

    $out = @shell_exec('id -un 2>/dev/null');
    return is_string($out) ? trim($out) : '';
}

/** Whether a field applies, given the answers so far. */
function fieldApplies(array $field, array $values): bool
{
    return !isset($field['when']) || !empty($values[$field['when']]);
}

/**
 * Turns one raw answer into the value that belongs in the config.
 *
 * Returns [value, error]. The error is what the caller shows; a field that
 * cannot be parsed keeps its default so the rest of the form still renders.
 */
function parseField(array $field, mixed $raw): array
{
    $type = $field['type'];

    if ($type === 'bool') {
        if (is_string($raw)) {
            return [(bool)preg_match('/^(y|yes|1|on|true)$/i', trim($raw)), null];
        }
        return [(bool)$raw, null];
    }

    /*
     * A list, one per line - or comma separated, because the command-line
     * wizard asks its questions on one line and cannot offer a textarea.
     * Accepting both means the same field works in both front ends.
     */
    if ($type === 'lines') {
        $text = is_array($raw) ? implode("\n", $raw) : (string)$raw;
        $parts = preg_split('/[\r\n,]+/', $text) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return [$clean, null];
    }

    if ($type === 'categories') {
        $chosen = is_array($raw) ? $raw : [];
        $allowed = $field['options'];
        return [array_values(array_intersect($allowed, array_map('strval', $chosen))), null];
    }

    $value = is_string($raw) ? trim($raw) : '';

    if ($value === '' && ($field['optional'] ?? false)) {
        return ['', null];
    }

    if (isset($field['validate'])) {
        $problem = ($field['validate'])($value);
        if ($problem !== null) {
            return [$field['default'], $problem];
        }
    }

    if ($type === 'bytes') {
        $parsed = parseBytes($value, true);
        return $parsed === null
            ? [$field['default'], 'Enter a size, like 5G or 500M or a plain number of bytes.']
            : [$parsed, null];
    }

    if ($type === 'seconds') {
        $parsed = parseDuration($value);
        return $parsed === null
            ? [$field['default'], 'Enter a time, like 6h or 90m or 2d. A plain number is seconds.']
            : [$parsed, null];
    }

    if ($type === 'number') {
        return [(float)$value, null];
    }

    // Whole numbers stay whole. A day count written as 14.0 in a config file
    // reads like a rounding error somebody should look into.
    if ($type === 'int') {
        return [(int)$value, null];
    }

    return [$value, null];
}

/**
 * Builds the finished config from the sample and the answers.
 *
 * Merged over the sample rather than written from scratch: everything nobody
 * was asked about - the category rules, the excluded paths, the thresholds that
 * are fine as they are - comes from the sample at whatever it says today.
 */
function buildConfig(array $sample, array $values, string $passwordHash, string $maskSecret): array
{
    $config = $sample;

    foreach (configFields($sample) as $field) {
        if ($field['type'] === 'password' || !fieldApplies($field, $values)) {
            continue;
        }
        if (!array_key_exists($field['key'], $values)) {
            continue;
        }
        assignPath($config, $field['path'], $values[$field['key']]);
    }

    $config['password_hash'] = $passwordHash;
    $config['mask_secret'] = $maskSecret;

    return $config;
}

function assignPath(array &$target, array $path, mixed $value): void
{
    $cursor = &$target;
    foreach ($path as $step) {
        if (!is_array($cursor[$step] ?? null)) {
            $cursor[$step] ??= null;
        }
        $cursor = &$cursor[$step];
    }
    $cursor = $value;
}

/* ------------------------------------------------------------------ sizes -- */

/**
 * A size, accepting the way people actually write sizes.
 *
 * "5G" is what somebody types when asked for a threshold. Making them work out
 * 5368709120 is how a config ends up three orders of magnitude off with an
 * alert that never fires.
 */
function parseBytes(string $value, bool $units = true): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (ctype_digit($value)) {
        return (int)$value;
    }
    if (!$units || !preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt])i?b?$/i', $value, $m)) {
        return null;
    }

    $scale = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4];
    return (int)round((float)$m[1] * $scale[strtolower($m[2])]);
}

/**
 * A duration as something a person can read back.
 *
 * The field used to show 21600 and expect the reader to know that is six hours.
 * Nobody knows that, and a number you cannot check is a number you cannot
 * safely change - so it is shown as 6h and typed back the same way.
 *
 * Exact divisors only, matching formatBytes: 5400 seconds becomes 90m rather
 * than 1.5h, because a whole number in a smaller unit is easier to trust than a
 * fraction of a larger one.
 */
function formatDuration(int $seconds): string
{
    foreach ([['d', 86400], ['h', 3600], ['m', 60]] as [$suffix, $scale]) {
        if ($seconds >= $scale && $seconds % $scale === 0) {
            return ($seconds / $scale) . $suffix;
        }
    }
    return $seconds . 's';
}

/**
 * Reads a duration typed in any reasonable way.
 *
 * Bare digits stay seconds, so nothing that worked before stops working. The
 * spelled-out forms are here because somebody typing a timeout is thinking in
 * hours and will write "6 hours" as readily as "6h".
 */
function parseDuration(string $value): ?int
{
    $value = trim(strtolower($value));
    if ($value === '') {
        return null;
    }
    if (ctype_digit($value)) {
        return (int)$value;
    }

    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([a-z]+)$/', $value, $m)) {
        return null;
    }

    $units = [
        86400 => ['d', 'day', 'days'],
        3600 => ['h', 'hr', 'hrs', 'hour', 'hours'],
        60 => ['m', 'min', 'mins', 'minute', 'minutes'],
        1 => ['s', 'sec', 'secs', 'second', 'seconds'],
    ];

    foreach ($units as $scale => $names) {
        if (in_array($m[2], $names, true)) {
            return (int)round((float)$m[1] * $scale);
        }
    }

    return null;
}

function formatBytes(int $bytes): string
{
    foreach ([['T', 1024 ** 4], ['G', 1024 ** 3], ['M', 1024 ** 2], ['K', 1024]] as [$suffix, $scale]) {
        if ($bytes >= $scale && $bytes % $scale === 0) {
            return ($bytes / $scale) . $suffix;
        }
    }
    return (string)$bytes;
}

/** How a field's current value should appear in a prompt or an input box. */
function displayValue(array $field, mixed $value): string
{
    return match ($field['type']) {
        'bytes' => formatBytes((int)$value),
        'seconds' => formatDuration((int)$value),
        'bool' => $value ? 'y' : 'n',
        'categories' => implode(', ', (array)$value),
        'lines' => implode("\n", (array)$value),
        default => (string)$value,
    };
}

/* --------------------------------------------------------------- printing -- */

/**
 * The finished config, as PHP source.
 *
 * `var_export` would do the job but produces `array (` and one entry per line
 * with no shape to it, and this is a file somebody has to read and edit later.
 * The header points at the sample, which is where the explanation of every
 * option lives - duplicating those comments here is how the two drift apart.
 */
function renderConfig(array $config, string $libDir): string
{
    $when = date('Y-m-d H:i');
    $lines = [
        '<?php',
        'declare(strict_types=1);',
        '',
        '/*',
        " * Disk Usage Monitor configuration, written by setup.php on $when.",
        ' *',
        ' * Every option is described in lib/config.sample.php. This file holds the',
        ' * password hash and the mask secret, so it is gitignored and lib/.htaccess',
        ' * denies the directory - keep both of those true.',
        ' *',
        ' * Changing mask_secret renames every masked path on the dashboard. It is',
        ' * safe to do, but the pseudonyms people have got used to will all change.',
        ' */',
        '',
        'return ' . renderValue($config, 0, $libDir) . ';',
        '',
    ];

    return implode("\n", $lines);
}

function renderValue(mixed $value, int $depth, string $libDir = ''): string
{
    $pad = str_repeat('    ', $depth + 1);
    $close = str_repeat('    ', $depth);

    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $parts = [];
        foreach ($value as $key => $item) {
            $rendered = is_string($item)
                ? relativeToLib($item, $libDir)
                : renderValue($item, $depth + 1, $libDir);
            $parts[] = $isList
                ? $pad . $rendered . ','
                : $pad . renderScalar($key) . ' => ' . $rendered . ',';
        }

        return "[\n" . implode("\n", $parts) . "\n" . $close . ']';
    }

    return renderScalar($value);
}

/**
 * Paths under lib/ are written back as __DIR__ expressions.
 *
 * The sample computes the database path and the exclusions from __DIR__.
 * Exporting the result would freeze whatever absolute path the wizard happened
 * to run from, so a config generated on a laptop and pasted onto the server
 * would point at a directory that is not there - and the exclusion that stops
 * the scanner reporting its own database would quietly stop matching.
 */
function relativeToLib(string $value, string $libDir): string
{
    if ($libDir !== '' && str_starts_with($value, $libDir . '/')) {
        return '__DIR__ . ' . renderScalar(substr($value, strlen($libDir)));
    }
    return renderScalar($value);
}

function renderScalar(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        return (string)$value;
    }
    if (is_float($value)) {
        // A float that prints as "5" reads back as an int, which then fails a
        // strict comparison somewhere far from here.
        $printed = (string)$value;
        return str_contains($printed, '.') ? $printed : $printed . '.0';
    }

    return "'" . strtr((string)$value, ["\\" => '\\\\', "'" => "\\'"]) . "'";
}
