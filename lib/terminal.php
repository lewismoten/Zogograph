<?php
declare(strict_types=1);

/*
 * A shell, in a web page.
 *
 * Be honest about what this is: it runs whatever is typed into it, as the user
 * the web server runs as, and returns the output. That is a web shell. Anybody
 * who gets the dashboard password gets this, and no amount of care taken below
 * changes that - which is why it is off until somebody turns it on in the
 * config file, and why the page says so rather than burying it.
 *
 * It exists because the alternative in practice is worse. The assistant's job
 * is to work out what is wrong, and half of what it needs cannot be reached
 * from PHP running as this account: the process table as root sees it, the
 * output of the host's own tools, whatever a support technician asked for.
 * Without this the answer is "open an SSH session and paste this back to me",
 * which loses the context, the transcript, and usually the person.
 *
 * What it deliberately does NOT do:
 *
 *   the assistant never runs anything   it can *recommend* a command, which
 *                                       appears here for a person to read and
 *                                       run. There is no tool that executes.
 *   no shell state between commands     each one is its own process. `cd` does
 *                                       not persist, and pretending otherwise
 *                                       by stitching a fake session together
 *                                       would be a lie about what ran.
 *   nothing is hidden                   every command is written to the action
 *                                       log with who ran it, before it runs.
 *
 * Long-running commands are started detached with their output going to a file,
 * and the page polls that file from the offset it has already read. That is the
 * only shape that works here: there is no worker, PHP's request dies when the
 * response is sent, and a `tail -f` held open inside a request would hold a
 * whole FPM child hostage until it timed out.
 */

require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/collect.php';
require_once __DIR__ . '/spans.php';
// rememberSetting and recallSetting, which the unlock clock is kept in.
require_once __DIR__ . '/symlinks.php';

/** Longest a command may run before it is killed. */
const TERMINAL_MAX_SECONDS = 900;

/** Most output kept for one command. Past this the tail is kept and said so. */
const TERMINAL_MAX_BYTES = 4 * 1024 * 1024;

/** Most finished commands kept on disk. */
const TERMINAL_KEEP = 40;

/** Is it turned on, and can this machine actually do it? */
function terminalSettings(array $config, ?PDO $pdo = null): array
{
    $set = $config['terminal'] ?? [];
    $can = terminalCanRun();

    /*
     * Two places the permission can come from, and the stored one wins.
     *
     * The config file is the install's starting position. The stored setting is
     * somebody signed in deciding, on the page, with the warning in front of
     * them - which is the same deliberate act the config file was asking for,
     * only reachable by the person who actually wants it rather than by
     * whoever can edit PHP on the server.
     *
     * Stored as a string because that is what settings are; absent means the
     * config file has not been overruled, which is not the same as false.
     */
    $stored = $pdo === null ? null : recallSetting($pdo, 'terminal_permitted');
    $wanted = $stored === null
        ? (bool)($set['enabled'] ?? false)
        : $stored === '1';

    $permitted = $wanted && $can['ok'];

    /*
     * Two switches, and they answer different questions.
     *
     * `permitted` is whether this install is allowed to have a shell in a web
     * page at all. That is a decision made once, deliberately, by somebody who
     * read what it means - from the config file or from the page, but never by
     * accident.
     *
     * `unlocked_until` is a clock: is it open right now. It closes itself,
     * which is the point - a web shell left permanently open is a web shell
     * whoever gets the password inherits. Making somebody re-open it is a small
     * cost paid by the one person who wants it and a real obstacle to anybody
     * who should not have it.
     */
    $until = 0;
    if ($permitted && $pdo !== null) {
        $until = (int)(recallSetting($pdo, 'terminal_unlocked_until') ?? 0);
    }

    $open = $permitted && $until > time();

    return [
        'enabled' => $open,
        'permitted' => $permitted,
        'wanted' => $wanted,
        // Which of the two said so, because "turn it off" needs to know whether
        // turning it off here is enough or whether the config file will just
        // turn it back on.
        'from_config' => $stored === null,
        'config_says' => (bool)($set['enabled'] ?? false),
        'can' => $can['ok'],
        'why' => $can['why'],
        'unlocked_until' => $open ? $until : 0,
        'unlocked_for' => $open ? $until - time() : 0,
        'max_seconds' => max(5, min(TERMINAL_MAX_SECONDS, (int)($set['max_seconds'] ?? 300))),
        'shell' => (string)($set['shell'] ?? '/bin/sh'),
        'spans' => terminalUnlockSpans(),
    ];
}

/**
 * What TERM the command is told it has.
 *
 * xterm-256color rather than dumb, because dumb is what was making `clear`,
 * `tput` and everything curses-based refuse to start. It is a claim about
 * capability rather than a lie: the page renders colour, bold, and the cursor
 * controls that matter for progress lines. It is NOT a full terminal - there is
 * no keyboard attached to the running process - and programs that need one still
 * cannot be driven from here.
 */
const TERMINAL_TERM = 'xterm-256color';

/**
 * How much output one command may leave behind.
 *
 * `yes test` wrote 4.8 GB before anybody noticed, because nothing runs between
 * requests to notice. Eight megabytes is more scrollback than anybody reads and
 * small enough that a runaway costs a moment rather than a disk.
 */
function terminalMaxBytes(array $config): int
{
    $set = (int)($config['terminal']['max_output_bytes'] ?? 0);

    return $set > 0 ? max(64 * 1024, min(256 * 1024 * 1024, $set)) : 8 * 1024 * 1024;
}

/**
 * The exit status a command left behind, if it has finished.
 *
 * Written by the epilogue rather than read from the process, because by the
 * time anything here is alive to ask, the process is gone - it was detached on
 * purpose so that a slow command does not hold a request open.
 */
function terminalExitCode(array $config, string $id): ?int
{
    $file = terminalDirectory($config) . '/' . $id . '.st';
    if (!is_file($file)) {
        return null;
    }

    $said = trim((string)@file_get_contents($file));

    return $said === '' || !ctype_digit($said) ? null : (int)$said;
}

/**
 * Allows, or stops allowing, a shell in a web page.
 *
 * Stored rather than written into the config file. Rewriting PHP that the
 * application then loads is a class of thing worth not building at all, and the
 * setting reads the same either way.
 *
 * Turning it off does not close an open session on its own, so this locks it
 * too - "off" that leaves a shell running for another twenty minutes is not off.
 */
function terminalPermit(PDO $pdo, bool $allowed): void
{
    rememberSetting($pdo, 'terminal_permitted', $allowed ? '1' : '0');
    if (!$allowed) {
        terminalLock($pdo);
    }
}

/** How long it may be unlocked for. Nothing longer than an afternoon. */
function terminalUnlockSpans(): array
{
    return [
        900 => '15 minutes',
        1800 => '30 minutes',
        3600 => 'an hour',
        7200 => 'two hours',
        14400 => 'four hours',
    ];
}

/**
 * Opens the terminal for a while.
 *
 * Returns when it will close. Extending is the same call: the new window
 * replaces the old rather than adding to it, so "extend by an hour" always
 * means an hour from now and never quietly compounds into a day.
 */
function terminalUnlock(array $config, PDO $pdo, int $seconds, string $who): int
{
    $settings = terminalSettings($config, $pdo);

    if (!$settings['permitted']) {
        throw new ActionRefused(
            $settings['can']
                ? 'the terminal is switched off in the configuration, so it cannot be '
                  . 'unlocked from here'
                : (string)$settings['why']
        );
    }

    if (!isset(terminalUnlockSpans()[$seconds])) {
        throw new ActionRefused('that is not one of the lengths on offer');
    }

    $until = time() + $seconds;
    rememberSetting($pdo, 'terminal_unlocked_until', (string)$until);

    return $until;
}

/** Closes it again. */
function terminalLock(PDO $pdo): void
{
    rememberSetting($pdo, 'terminal_unlocked_until', '0');
}

/**
 * Whether the functions this needs are available at all.
 *
 * Shared hosts disable them routinely, and finding that out from a blank output
 * box is the worst way to learn it.
 */
function terminalCanRun(): array
{
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'why' => 'PHP has no shell_exec on this host.'];
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    if (in_array('shell_exec', $disabled, true)) {
        return [
            'ok' => false,
            'why' => 'shell_exec is in disable_functions on this host, so no command can '
                . 'be started. Nothing here can work around that, and nothing should try.',
        ];
    }

    return ['ok' => true, 'why' => null];
}

/** Where the transcripts live: beside the databases, which .htaccess denies. */
function terminalDirectory(array $config): string
{
    return dirname((string)$config['database']) . '/terminal';
}

/* ------------------------------------------------------------------ *
 * Sessions
 * ------------------------------------------------------------------ */

/**
 * A run of commands that belong together.
 *
 * The commands were already on disk, one file each, and that is a pile rather
 * than a record: "what did I do on Tuesday" meant reading twenty JSON files in
 * modification order and hoping. A session groups them the way the work
 * actually happened, carries a topic somebody can search for later, and is what
 * makes the whole thing reviewable rather than merely logged.
 *
 * Which session a command belongs to is decided when it starts: the newest one,
 * unless the gap since the last command is long enough that it was plainly a
 * different sitting.
 */
const TERMINAL_SESSION_GAP = 3600;

/** The sessions file, which is small enough to rewrite whole. */
function terminalSessionsFile(array $config): string
{
    return terminalDirectory($config) . '/sessions.json';
}

/**
 * Every session, newest first.
 *
 * @return list<array>
 */
function terminalSessions(array $config): array
{
    $file = terminalSessionsFile($config);
    $raw = is_file($file) ? file_get_contents($file) : false;
    $found = $raw === false ? null : json_decode($raw, true);

    if (!is_array($found)) {
        return [];
    }

    usort($found, fn(array $a, array $b): int => ($b['started_at'] ?? 0) <=> ($a['started_at'] ?? 0));

    return array_values(array_filter($found, 'is_array'));
}

/** Writes the list back. */
function terminalWriteSessions(array $config, array $sessions): void
{
    $file = terminalSessionsFile($config);
    @file_put_contents($file, json_encode(array_values($sessions), JSON_PRETTY_PRINT));
    @chmod($file, 0600);
}

/**
 * The session a command starting now belongs to, making one if needed.
 *
 * The gap is what decides it. Somebody who ran three commands, went to lunch and
 * came back to run three more was doing two different things, and a record that
 * says otherwise is a worse record than no grouping at all.
 */
function terminalSessionFor(array $config, string $who): array
{
    $sessions = terminalSessions($config);
    $now = time();

    if ($sessions !== []) {
        $newest = $sessions[0];
        if ($now - (int)($newest['last_at'] ?? 0) < TERMINAL_SESSION_GAP) {
            $newest['last_at'] = $now;
            $newest['commands'] = (int)($newest['commands'] ?? 0) + 1;
            $sessions[0] = $newest;
            terminalWriteSessions($config, $sessions);

            return ['id' => (string)$newest['id'], 'seq' => (int)$newest['commands']];
        }
    }

    $session = [
        'id' => bin2hex(random_bytes(6)),
        'started_at' => $now,
        'last_at' => $now,
        'commands' => 1,
        'topic' => '',
        'who' => $who,
    ];

    array_unshift($sessions, $session);

    /*
     * A hundred sessions, oldest dropped.
     *
     * The commands themselves are swept on their own schedule; this is only the
     * index, and an index that grows for ever is a file that eventually takes a
     * second to parse on every page load.
     */
    terminalWriteSessions($config, array_slice($sessions, 0, 100));

    return ['id' => (string)$session['id'], 'seq' => 1];
}

/**
 * Which session this command is in, and where in it.
 *
 * The position matters because several commands can start within the same
 * second - a script, or somebody typing fast - and a second is the finest
 * timestamp on disk. Sorting by time alone put them in whatever order the
 * filesystem happened to list them, which for a transcript is no order at all.
 *
 * @return array{session: string, seq: int}
 */
function terminalOrdinal(array $config, string $who): array
{
    $where = terminalSessionFor($config, $who);

    return ['session' => $where['id'], 'seq' => $where['seq']];
}

/** Names a session, or renames it. */
function terminalNameSession(array $config, string $id, string $topic): bool
{
    $topic = trim(preg_replace('/\s+/u', ' ', $topic) ?? $topic);
    if (mb_strlen($topic) > 80) {
        throw new ActionRefused('that topic is longer than the list will show');
    }

    $sessions = terminalSessions($config);
    $found = false;

    foreach ($sessions as $i => $session) {
        if ((string)($session['id'] ?? '') === $id) {
            $sessions[$i]['topic'] = $topic;
            $found = true;
        }
    }

    if ($found) {
        terminalWriteSessions($config, $sessions);
    }

    return $found;
}

/**
 * Everything one session ran, with as much of each answer as was asked for.
 *
 * `$withOutput` is the whole reason this takes a parameter. The commands alone
 * are safe to show and safe to hand to an assistant - they are what somebody
 * typed. The output is whatever the machine said back, which can be a password
 * file, a database dump or a customer's email address, and deserves its own
 * decision.
 */
function terminalSessionLog(array $config, string $id, bool $withOutput, int $bytes = 65536): array
{
    $directory = terminalDirectory($config);
    $files = glob($directory . '/*.json') ?: [];
    $rows = [];

    foreach ($files as $file) {
        $meta = json_decode((string)@file_get_contents($file), true);
        if (!is_array($meta) || (string)($meta['session'] ?? '') !== $id) {
            continue;
        }

        $outFile = $directory . '/' . $meta['id'] . '.out';
        $size = is_file($outFile) ? (int)filesize($outFile) : 0;
        $running = terminalRunning($meta);
        $ended = !$running && is_file($outFile) ? (int)filemtime($outFile) : null;

        $row = [
            'id' => (string)$meta['id'],
            'seq' => (int)($meta['seq'] ?? 0),
            'command' => (string)$meta['command'],
            'started_at' => (int)$meta['started_at'],
            'who' => (string)($meta['who'] ?? ''),
            'running' => $running,
            'bytes' => $size,
            'took' => $ended === null ? null : max(0, $ended - (int)$meta['started_at']),
            'exit_code' => terminalExitCode($config, (string)$meta['id']),
            'timed_out' => (bool)($meta['timed_out'] ?? false),
            'stopped_by' => $meta['stopped_by'] ?? null,
        ];

        if ($withOutput && $size > 0) {
            /*
             * The end of the output, not the beginning.
             *
             * What a command was doing when it stopped is almost always the
             * half worth reading, and an error is usually the last thing said.
             */
            $handle = @fopen($outFile, 'rb');
            if ($handle !== false) {
                if ($size > $bytes) {
                    fseek($handle, $size - $bytes);
                }
                $row['text'] = (string)fread($handle, $bytes);
                $row['text_from_end'] = $size > $bytes;
                fclose($handle);
            }
        }

        $rows[] = $row;
    }

    // By position in the session, falling back to time for anything written
    // before positions were recorded.
    usort($rows, fn(array $a, array $b): int =>
        [$a['seq'], $a['started_at']] <=> [$b['seq'], $b['started_at']]);

    return $rows;
}

/**
 * Starts a command and returns immediately.
 *
 * Detached deliberately. The request that starts a command must not wait for it
 * - a `find /` over a large account outlives any sensible request timeout, and
 * holding the connection open until it finishes is how one curious click takes
 * the dashboard down for everyone.
 *
 * @return array{id: string, pid: int}
 */
function terminalStart(array $config, PDO $pdo, string $command, string $who): array
{
    $settings = terminalSettings($config, $pdo);

    if (!$settings['enabled']) {
        throw new ActionRefused(
            !$settings['can']
                ? (string)$settings['why']
                : ($settings['permitted']
                    ? 'the terminal has locked itself again. Unlock it and try again.'
                    : 'the terminal is switched off in the configuration')
        );
    }

    $command = trim($command);
    if ($command === '') {
        throw new ActionRefused('type a command first');
    }
    if (strlen($command) > 8000) {
        throw new ActionRefused('that command is longer than this will run');
    }

    $directory = terminalDirectory($config);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
        throw new ActionRefused('could not make a place to keep the output');
    }
    terminalSweep($config);

    $id = bin2hex(random_bytes(8));
    $out = $directory . '/' . $id . '.out';

    /*
     * Started through a shell, because that is what was asked for.
     *
     * Pipes, redirection and globs are the reason somebody wants a terminal
     * rather than a list of buttons, so the input is a shell command and is
     * documented as one. Escaping the pieces would produce something that looks
     * like a shell and behaves like nothing else.
     *
     * Backgrounded and detached, and it has to be. The first version used
     * proc_open and then proc_close, which reads as "start it and let go" and
     * is not: proc_close waits for the child. A ten-second command took ten
     * seconds to *start*, with the browser holding the connection the whole
     * time - the exact blocking this design exists to avoid, hidden behind a
     * comment claiming the opposite. Measured, not reasoned about: the test
     * that caught it timed the call.
     *
     * setsid where it exists, so the command leads its own process group and
     * stopping it takes the whole pipeline rather than just the first stage.
     */
    $runner = shellRead('command -v setsid 2>/dev/null') !== null ? 'setsid ' : '';

    /*
     * The working directory carries over, because people expect it to.
     *
     * Each command really is its own process - that has not changed and cannot,
     * short of keeping a shell alive between requests on a web server. What
     * changed is that `cd` used to be silently useless: you typed `cd /var/log`,
     * got no error, typed `ls`, and were shown the directory you started in. It
     * was documented as "cd does not carry over", which is a true sentence and
     * no help at all to somebody who has just been shown the wrong files.
     *
     * So the directory is remembered and each command starts there, and where
     * the command finishes is written to a sidecar file and remembered in turn.
     * `pwd` runs in the same shell as the command, so it sees any `cd` the
     * command did - including one buried in a script.
     *
     * The page shows the directory beside the box. That is what makes this
     * honest rather than magic: the state is somewhere you can see it.
     */
    $cwd = terminalCwd($config, $pdo);
    $where = $directory . '/' . $id . '.cwd';
    $status = $directory . '/' . $id . '.st';

    /*
     * The command goes in a file, and the shell runs the file.
     *
     * It used to be spliced into a one-line `sh -c 'cd X; <command>; pwd > Y'`,
     * which works for `ls -la` and breaks the moment the command has structure
     * of its own. A here-document was the report: everything after the first
     * line ended up inside it, so the shell swallowed the bookkeeping as data
     * and then complained that the delimiter never arrived. The user saw their
     * own `pwd > .../e1dc9c8d.cwd` printed back at them.
     *
     * A file has no such problem. What is typed is written verbatim, on its own
     * lines, with the prologue above it and the epilogue below - so a
     * here-document, a `for` loop, a function definition and a comment all
     * behave exactly as they would in a file you ran yourself.
     */
    $script = $directory . '/' . $id . '.sh';

    $prologue = implode("\n", [
        '#!/bin/sh',
        /*
         * TERM, because half the things people type need one.
         *
         * `clear`, `tput`, `top`, `watch` and anything using curses refuse to
         * start without it, and what they say is "TERM environment variable not
         * set" - which reads as a fault in this terminal rather than a missing
         * variable. Setting it also turns colour on in every tool that checks:
         * ls, grep, git and systemctl all colour their own output when TERM
         * says the terminal can take it.
         */
        'TERM=' . escapeshellarg(TERMINAL_TERM) . '; export TERM',
        'COLUMNS=120; export COLUMNS',
        'LINES=40; export LINES',
        // Where the last command left off. Absent or deleted falls back to the
        // shell's own default rather than failing the whole command.
        /*
         * The bookkeeping runs on the way out, however the way out happens.
         *
         * It was a few lines appended after the command, which works right up
         * until the command ends the shell itself - `exit 42`, or a script that
         * calls exit, or anything fatal. Then those lines never run, and the
         * exit status and the working directory are simply lost: `exit 42`
         * reported no status at all.
         *
         * A trap on EXIT fires in every one of those cases, and `$?` inside it
         * is the status the shell is leaving with.
         */
        '__done() { __st=$?; pwd > ' . escapeshellarg($where) . ' 2>/dev/null; '
            . 'printf %s "$__st" > ' . escapeshellarg($status) . ' 2>/dev/null; }',
        'trap __done EXIT',
        'cd ' . escapeshellarg($cwd) . ' 2>/dev/null',
        '',
    ]);

    if (@file_put_contents($script, $prologue . $command . "\n") === false) {
        throw new ActionRefused('could not write the command down to run it');
    }
    @chmod($script, 0700);

    /*
     * A ceiling on the output, enforced by the pipe rather than by watching.
     *
     * `yes test` produced a 4.8 GB file and a browser that stopped responding.
     * Nothing was watching, because nothing runs between requests - so the only
     * thing that can stop it is something in the pipeline itself. `head -c`
     * closes the pipe at the limit, the writer gets SIGPIPE, and the command
     * dies of its own accord. That is the runaway brake, and it costs nothing
     * on every command that never reaches it.
     */
    $cap = terminalMaxBytes($config);
    $limiter = shellRead('command -v head 2>/dev/null') !== null
        ? ' | head -c ' . (int)$cap
        : '';

    $shell = escapeshellarg($settings['shell']);
    $inner = sprintf('%s %s < /dev/null 2>&1%s > %s',
        $shell, escapeshellarg($script), $limiter, escapeshellarg($out));

    $said = shellRead(sprintf('%snohup %s -c %s > /dev/null 2>&1 & echo $!',
        $runner, $shell, escapeshellarg($inner)));

    $pid = (int)trim((string)$said);

    if ($pid <= 0) {
        throw new ActionRefused('the command could not be started');
    }

    file_put_contents($directory . '/' . $id . '.json', json_encode([
        'id' => $id,
        'command' => $command,
        'pid' => $pid,
        'started_at' => time(),
        'who' => $who,
        'max_seconds' => $settings['max_seconds'],
        'started_in' => $cwd,
    ] + terminalOrdinal($config, $who), JSON_PRETTY_PRINT));

    return ['id' => $id, 'pid' => $pid];
}

/**
 * Where the next command will run.
 *
 * Remembered between commands, and validated every time it is read: a directory
 * that has been deleted since would otherwise make every subsequent command
 * fail with a message about the wrong thing.
 *
 * The default is the directory being watched, which is where somebody
 * troubleshooting this dashboard almost always wants to be.
 */
function terminalCwd(array $config, ?PDO $pdo): string
{
    $home = (string)($config['scan_root'] ?? '');
    if ($home === '' || !is_dir($home)) {
        $home = getcwd() ?: '/';
    }

    if ($pdo === null) {
        return $home;
    }

    $stored = (string)(recallSetting($pdo, 'terminal_cwd') ?? '');

    return $stored !== '' && is_dir($stored) ? $stored : $home;
}

/**
 * Takes the directory a finished command left behind.
 *
 * Called on read rather than on finish, because nothing else runs: the request
 * that started the command is long gone by the time it ends.
 */
function terminalTakeCwd(array $config, PDO $pdo, string $id): void
{
    $file = terminalDirectory($config) . '/' . $id . '.cwd';
    if (!is_file($file)) {
        return;
    }

    $where = trim((string)@file_get_contents($file));
    @unlink($file);

    /*
     * Only a directory that exists, and only an absolute path.
     *
     * `pwd` gives an absolute path, so anything else means the file was not
     * written by pwd - and this value goes into the next command line.
     */
    if ($where !== '' && str_starts_with($where, '/') && is_dir($where)) {
        rememberSetting($pdo, 'terminal_cwd', $where);
    }
}

/** What a command has printed since a given offset, and whether it is still going. */
function terminalRead(array $config, string $id, int $from = 0): array
{
    $meta = terminalMeta($config, $id);
    $out = terminalDirectory($config) . '/' . $id . '.out';

    $size = is_file($out) ? (int)filesize($out) : 0;
    $from = max(0, min($from, $size));

    $text = '';
    if ($size > $from) {
        $handle = @fopen($out, 'rb');
        if ($handle !== false) {
            fseek($handle, $from);
            $text = (string)fread($handle, min($size - $from, 256 * 1024));
            fclose($handle);
        }
    }

    $running = terminalRunning($meta);
    $ran = time() - (int)$meta['started_at'];

    /*
     * A command past its limit is stopped here rather than left going.
     *
     * There is nothing else to notice it: the page that started it may be
     * closed, and nothing else runs. Checked on read, which is the only moment
     * this code is alive.
     */
    if ($running && $ran > (int)$meta['max_seconds']) {
        terminalStop($config, $id);
        $running = false;
        $meta['timed_out'] = true;
        terminalWriteMeta($config, $id, $meta);
    }

    return [
        'id' => $id,
        'command' => (string)$meta['command'],
        'pid' => (int)$meta['pid'],
        'started_at' => (int)$meta['started_at'],
        'who' => (string)($meta['who'] ?? ''),
        'running' => $running,
        'timed_out' => (bool)($meta['timed_out'] ?? false),
        'stopped_by' => $meta['stopped_by'] ?? null,
        'ran_for' => $ran,
        // How long it took, once it is over. Live, `ran_for` is the same thing
        // still counting.
        'took' => $running ? null : $ran,
        'text' => $text,
        'from' => $from,
        'to' => $from + strlen($text),
        'size' => $size,
        /*
         * Whether the pipeline cut it off.
         *
         * Within a few bytes of the ceiling means head closed the pipe, which
         * means the command was killed by SIGPIPE rather than finishing - and
         * saying "finished" about that would be wrong.
         */
        'capped' => $size >= terminalMaxBytes($config),
        'cap' => terminalMaxBytes($config),
        'exit_code' => terminalExitCode($config, $id),
        'truncated' => $size > TERMINAL_MAX_BYTES,
    ];
}

/**
 * Is that process still ours and still alive?
 *
 * Two questions, and the second one alone is a bug waiting to happen: pids are
 * reused, and a check that only asks "is something running as 4021" will
 * eventually say yes about a completely unrelated process. The start time is
 * compared as well, so a recycled pid reads as finished rather than as somebody
 * else's work.
 */
function terminalRunning(array $meta): bool
{
    $pid = (int)($meta['pid'] ?? 0);
    if ($pid <= 0 || !empty($meta['stopped_by']) || !empty($meta['timed_out'])) {
        return false;
    }

    if (function_exists('posix_kill')) {
        if (!@posix_kill($pid, 0)) {
            return false;
        }
    } elseif (!is_dir('/proc/' . $pid)) {
        return false;
    }

    /*
     * Same process, or a new one wearing its number?
     *
     * /proc/<pid>/stat field 22 is the start time in clock ticks since boot. A
     * process that started before the command was recorded cannot be it.
     */
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    if ($stat === false) {
        // No procfs - macOS, or a hardened container. The pid check is all
        // there is, and saying so beats pretending to a certainty we lack.
        return true;
    }

    $after = substr($stat, (int)strrpos($stat, ')') + 2);
    $fields = explode(' ', $after);
    $ticks = isset($fields[19]) ? (float)$fields[19] : 0.0;

    if ($ticks <= 0) {
        return true;
    }

    $hz = 100.0;
    $uptime = (float)strtok((string)@file_get_contents('/proc/uptime'), ' ');
    $startedAgo = $uptime - ($ticks / $hz);

    // Generous: clock ticks and wall seconds are not the same measurement, and
    // the question is only "is this obviously a different process".
    return $startedAgo <= (time() - (int)$meta['started_at']) + 120;
}

/** Kills a running command. */
function terminalStop(array $config, string $id, string $who = 'the operator'): bool
{
    $meta = terminalMeta($config, $id);
    $pid = (int)$meta['pid'];

    if ($pid <= 0 || !terminalRunning($meta)) {
        return false;
    }

    /*
     * The group, not the process.
     *
     * A pipeline is several processes and killing only the first leaves the
     * rest reading from a closed pipe or, worse, still writing to the output
     * file. Negative pid means the group; proc_open's child leads its own only
     * if setsid ran, so this falls back to the single pid when that fails.
     */
    $killed = false;
    if (function_exists('posix_kill')) {
        $killed = @posix_kill(-$pid, SIGTERM) || @posix_kill($pid, SIGTERM);
    } else {
        $killed = shellRead('kill -TERM -' . $pid . ' 2>&1 || kill -TERM ' . $pid . ' 2>&1')
            !== null;
    }

    $meta['stopped_by'] = $who;
    $meta['stopped_at'] = time();
    terminalWriteMeta($config, $id, $meta);

    return $killed;
}

/** The commands on disk, newest first. */
function terminalHistory(array $config, int $limit = 20): array
{
    $directory = terminalDirectory($config);
    if (!is_dir($directory)) {
        return [];
    }

    $files = glob($directory . '/*.json') ?: [];
    usort($files, fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $out = [];
    foreach (array_slice($files, 0, $limit) as $file) {
        $meta = json_decode((string)@file_get_contents($file), true);
        if (!is_array($meta)) {
            continue;
        }
        $outFile = $directory . '/' . $meta['id'] . '.out';
        $running = terminalRunning($meta);
        $bytes = is_file($outFile) ? (int)filesize($outFile) : 0;

        /*
         * How long it took, from the file rather than from a clock.
         *
         * Nothing is alive when a command finishes, so the finish time is when
         * its output last changed - which is exact for anything that prints and
         * a lower bound for anything that does not. A command still running has
         * no duration yet; it has an age.
         */
        $ended = !$running && is_file($outFile) ? (int)filemtime($outFile) : null;

        $out[] = [
            'id' => (string)$meta['id'],
            'command' => (string)$meta['command'],
            'started_at' => (int)$meta['started_at'],
            'who' => (string)($meta['who'] ?? ''),
            'running' => $running,
            'bytes' => $bytes,
            'took' => $ended === null ? null : max(0, $ended - (int)$meta['started_at']),
            'exit_code' => terminalExitCode($config, (string)$meta['id']),
            'timed_out' => (bool)($meta['timed_out'] ?? false),
            'stopped_by' => $meta['stopped_by'] ?? null,
            'capped' => $bytes >= terminalMaxBytes($config),
        ];
    }

    return $out;
}

/** Reads one command's record, or refuses. */
function terminalMeta(array $config, string $id): array
{
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
        throw new ActionRefused('that is not a command this started');
    }

    $file = terminalDirectory($config) . '/' . $id . '.json';
    $meta = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;

    if (!is_array($meta)) {
        throw new ActionRefused('no such command');
    }

    return $meta;
}

function terminalWriteMeta(array $config, string $id, array $meta): void
{
    @file_put_contents(
        terminalDirectory($config) . '/' . $id . '.json',
        json_encode($meta, JSON_PRETTY_PRINT)
    );
}

/** Drops the oldest transcripts, so this cannot quietly fill the disk. */
function terminalSweep(array $config): int
{
    $directory = terminalDirectory($config);
    $files = glob($directory . '/*.json') ?: [];

    if (count($files) <= TERMINAL_KEEP) {
        return 0;
    }

    usort($files, fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
    $gone = 0;

    foreach (array_slice($files, 0, count($files) - TERMINAL_KEEP) as $file) {
        $meta = json_decode((string)@file_get_contents($file), true);
        if (is_array($meta) && terminalRunning($meta)) {
            continue;
        }
        @unlink($file);
        $stem = substr($file, 0, -5);
        // Every sidecar, or the directory fills with .st and .sh files that
        // nothing reads and nothing removes.
        foreach (['.out', '.st', '.sh', '.cwd'] as $part) {
            @unlink($stem . $part);
        }
        $gone++;
    }

    return $gone;
}

/** Removes one command's record and output. */
function terminalForget(array $config, string $id): bool
{
    $meta = terminalMeta($config, $id);

    if (terminalRunning($meta)) {
        throw new ActionRefused('that one is still running. Stop it first.');
    }

    $directory = terminalDirectory($config);
    @unlink($directory . '/' . $id . '.json');
    foreach (['.out', '.st', '.sh', '.cwd'] as $part) {
        @unlink($directory . '/' . $id . $part);
    }

    return true;
}
