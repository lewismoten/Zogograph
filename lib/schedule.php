<?php
declare(strict_types=1);

/*
 * Editing the crontab from a web page.
 *
 * A crontab line is a command the machine will run as you, on a timer. Letting
 * a browser put text into one is remote code execution with extra steps, so
 * this file never does. There is no field anywhere that becomes part of a
 * command.
 *
 * Instead: the schedule is chosen from a fixed list of presets, the PHP binary
 * is chosen from a list of binaries found on disk, and the command itself is
 * built here from constants. The only thing crossing from the browser is which
 * option was picked, and an option that is not on the list is refused.
 *
 * The other rule is that the rest of your crontab is none of our business. Our
 * lines live between two marker comments and everything outside them is copied
 * through untouched, in order, byte for byte.
 */

require_once __DIR__ . '/actions.php';

const CRON_BEGIN = '# BEGIN diskmon — managed by the dashboard, edited between these markers';
const CRON_END = '# END diskmon';

/**
 * The schedules on offer, and the cron expression each one means.
 *
 * A closed list. `minute` is filled in for the hourly and daily ones so two
 * jobs do not start on the same tick, but it is an integer this file clamps,
 * never a string that reaches the crontab.
 */
function cronPresets(): array
{
    return [
        'vitals' => [
            'off' => ['label' => 'Off', 'expr' => null],
            'every-minute' => ['label' => 'Every minute', 'expr' => '* * * * *'],
            'every-5' => ['label' => 'Every 5 minutes', 'expr' => '*/5 * * * *'],
            'every-15' => ['label' => 'Every 15 minutes', 'expr' => '*/15 * * * *'],
            'hourly' => ['label' => 'Hourly', 'expr' => '%M * * * *'],
        ],
        'scan' => [
            'off' => ['label' => 'Off', 'expr' => null],
            'hourly' => ['label' => 'Hourly', 'expr' => '%M * * * *'],
            'every-6' => ['label' => 'Every 6 hours', 'expr' => '%M */6 * * *'],
            'daily' => ['label' => 'Once a day', 'expr' => '%M %H * * *'],
        ],
    ];
}

/** The flags each job runs with. Constants, not input. */
function cronJobs(): array
{
    return [
        'vitals' => ['--vitals', '--alerts', '--quiet'],
        'scan' => ['--scan', '--alerts', '--prune', '--quiet'],
    ];
}

/**
 * PHP binaries that actually exist on this machine.
 *
 * Offered as a list rather than a text box for the obvious reason, and worth
 * offering at all because the binary the web server runs is very often not the
 * CLI one - `PHP_BINARY` under mod_php or FPM points at the wrong thing, and a
 * cron job that runs the wrong PHP fails in a way that is tedious to diagnose.
 */
function phpBinaries(): array
{
    $candidates = [
        PHP_BINDIR . '/php',
        '/usr/local/bin/php',
        '/usr/bin/php',
        '/opt/cpanel/ea-php82/root/usr/bin/php',
        '/opt/cpanel/ea-php83/root/usr/bin/php',
    ];

    if (PHP_SAPI === 'cli') {
        array_unshift($candidates, PHP_BINARY);
    }

    $found = [];
    foreach ($candidates as $path) {
        if (is_file($path) && is_executable($path) && !isset($found[$path])) {
            $found[$path] = true;
        }
    }

    return array_keys($found);
}

/**
 * Reads the current crontab.
 *
 * An empty crontab and no crontab command at all are different things, and the
 * caller has to be able to tell them apart: writing a fresh crontab over
 * somebody's existing one because `crontab -l` was unavailable would be the
 * worst bug in this project.
 *
 * @return array{available: bool, raw: string, reason: ?string}
 */
function readCrontab(): array
{
    if (!function_exists('shell_exec')) {
        return ['available' => false, 'raw' => '', 'reason' => 'shell_exec is disabled here.'];
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return ['available' => false, 'raw' => '', 'reason' => 'shell_exec is disabled here.'];
    }

    $output = @shell_exec('crontab -l 2>/dev/null');
    if ($output === null) {
        // Either there is no crontab yet, or there is no crontab command. The
        // second is the dangerous one, so it is checked for separately.
        $which = trim((string)@shell_exec('command -v crontab 2>/dev/null'));
        if ($which === '') {
            return [
                'available' => false,
                'raw' => '',
                'reason' => 'No crontab command on this host. Add the lines through your control panel.',
            ];
        }
        return ['available' => true, 'raw' => '', 'reason' => null];
    }

    return ['available' => true, 'raw' => (string)$output, 'reason' => null];
}

/** The lines this dashboard manages, pulled out of a crontab. */
function managedBlock(string $crontab): array
{
    $lines = preg_split('/\R/', $crontab) ?: [];
    $inside = false;
    $ours = [];

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), CRON_BEGIN)) {
            $inside = true;
            continue;
        }
        if (trim($line) === CRON_END) {
            $inside = false;
            continue;
        }
        if ($inside && trim($line) !== '') {
            $ours[] = $line;
        }
    }

    return $ours;
}

/**
 * Puts a new managed block into a crontab, leaving everything else alone.
 *
 * Everything outside the markers is copied through in order. If there is no
 * block yet, ours goes on the end. If the new block is empty, the markers go
 * too, so removing the schedule leaves no litter behind.
 */
function replaceManagedBlock(string $crontab, array $ourLines): string
{
    $lines = preg_split('/\R/', rtrim($crontab, "\n")) ?: [];
    $kept = [];
    $inside = false;
    $sawBlock = false;

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), CRON_BEGIN)) {
            $inside = true;
            $sawBlock = true;
            continue;
        }
        if (trim($line) === CRON_END) {
            $inside = false;
            continue;
        }
        if (!$inside) {
            $kept[] = $line;
        }
    }

    // A crontab that was only ever our block leaves a run of blank lines.
    while ($kept && trim(end($kept)) === '') {
        array_pop($kept);
    }

    if ($ourLines) {
        if ($kept) {
            $kept[] = '';
        }
        $kept[] = CRON_BEGIN;
        foreach ($ourLines as $line) {
            $kept[] = $line;
        }
        $kept[] = CRON_END;
    }

    unset($sawBlock);

    return $kept ? implode("\n", $kept) . "\n" : '';
}

/**
 * Builds our crontab lines from choices, and refuses anything not on the list.
 *
 * This is the only place a schedule becomes text, and every part of that text
 * is either a constant or an integer that has been clamped. `$choices` is
 * whatever arrived in the request; nothing in it is trusted.
 */
function buildCronLines(array $choices, string $php, string $scriptDir): array
{
    $presets = cronPresets();
    $jobs = cronJobs();

    if (!in_array($php, phpBinaries(), true)) {
        throw new ActionRefused('that is not one of the PHP binaries found on this host');
    }

    $script = realpath($scriptDir . '/cron.php');
    if ($script === false) {
        throw new ActionRefused('cannot find cron.php');
    }

    $minute = max(0, min(59, (int)($choices['minute'] ?? 7)));
    $hour = max(0, min(23, (int)($choices['hour'] ?? 2)));

    $lines = [];
    foreach ($jobs as $job => $flags) {
        $pick = (string)($choices[$job] ?? 'off');
        if (!isset($presets[$job][$pick])) {
            throw new ActionRefused("unknown schedule for $job");
        }

        $expr = $presets[$job][$pick]['expr'];
        if ($expr === null) {
            continue;
        }

        $expr = str_replace(['%M', '%H'], [(string)$minute, (string)$hour], $expr);

        /*
         * escapeshellarg on both paths.
         *
         * They are already constrained - one is off a list of binaries that
         * exist, the other is realpath of a file next to this one - but a path
         * with a space in it would otherwise split into two arguments, and
         * quoting the thing that goes into a shell is not a step to skip on the
         * grounds that it is probably fine.
         */
        $lines[] = sprintf(
            '%s %s %s %s',
            $expr,
            escapeshellarg($php),
            escapeshellarg($script),
            implode(' ', $flags)
        );
    }

    return $lines;
}

/**
 * Installs a crontab.
 *
 * Written to a temporary file and loaded with `crontab <file>` rather than
 * piped, because a pipe means building a shell command containing the content,
 * and the content is the one thing that must never be inside a command string.
 */
function writeCrontab(string $content): void
{
    $temporary = tempnam(sys_get_temp_dir(), 'diskmon');
    if ($temporary === false) {
        throw new ActionRefused('could not create a temporary file');
    }

    try {
        if (@file_put_contents($temporary, $content) === false) {
            throw new ActionRefused('could not write the temporary crontab');
        }
        @chmod($temporary, 0600);

        $result = @shell_exec('crontab ' . escapeshellarg($temporary) . ' 2>&1');
        $left = trim((string)$result);
        if ($left !== '') {
            throw new ActionRefused('crontab refused it: ' . $left);
        }
    } finally {
        @unlink($temporary);
    }
}

/**
 * What the dashboard shows about the current schedule.
 *
 * The managed lines are handed back verbatim so somebody can see exactly what
 * is installed, rather than a rendering of what we think we installed.
 */
function scheduleState(string $scriptDir): array
{
    $crontab = readCrontab();

    return [
        'available' => $crontab['available'],
        'reason' => $crontab['reason'],
        'lines' => $crontab['available'] ? managedBlock($crontab['raw']) : [],
        'other_lines' => $crontab['available']
            ? max(0, count(array_filter(
                preg_split('/\R/', trim($crontab['raw'])) ?: [],
                fn(string $l): bool => trim($l) !== ''
            )) - count(managedBlock($crontab['raw'])) - 2)
            : 0,
        'binaries' => phpBinaries(),
        'presets' => cronPresets(),
        'script' => realpath($scriptDir . '/cron.php') ?: null,
    ];
}
