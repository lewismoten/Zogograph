<?php
declare(strict_types=1);

/*
 * The config wizard, in a browser or at a prompt.
 *
 * Both front ends ask the same questions, because both read them from
 * lib/configure.php. Answers are overlaid on lib/config.sample.php, so the
 * sample stays the single description of what an option is and what it
 * defaults to.
 *
 * Who is allowed to write lib/config.php:
 *
 *   the file is missing        -> anybody. This is an install, and there is no
 *                                 password yet to ask anyone for.
 *   the file exists            -> only somebody who can type the password that
 *                                 is already in it.
 *   the file exists, sign-in
 *   was never configured       -> nobody. There is no secret to prove, so there
 *                                 is nothing to authorise with.
 *
 * Anyone who cannot write still gets the finished text to paste, which needs
 * filesystem access and is therefore its own authorisation.
 *
 * The existing config is read for exactly two values - the password hash to
 * check against, and the mask secret so that reconfiguring does not rename
 * every masked path on the dashboard. Neither is ever shown to somebody who has
 * not proved the password.
 *
 * The install window is still a window: between uploading these files and
 * finishing setup, whoever reaches this page first chooses the password. Do it
 * straight away, and delete setup.php afterwards - the page says so itself.
 */

$root = __DIR__;
$samplePath = $root . '/lib/config.sample.php';
$targetPath = $root . '/lib/config.php';

if (!is_file($samplePath)) {
    $missing = 'Cannot find lib/config.sample.php next to this script.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $missing . "\n");
        exit(1);
    }
    http_response_code(500);
    exit($missing);
}

require_once $root . '/lib/configure.php';

/**
 * How long an unlock lasts. Long enough to fill a form in, and no longer.
 *
 * Declared before the dispatch below, not beside the function that uses it:
 * unlike a function, a file-scope `const` is not hoisted, so one written
 * further down the file simply does not exist yet when runWeb() reads it.
 */
const SETUP_UNLOCK_SECONDS = 900;

$sample = require $samplePath;
$fields = configFields($sample);
$existing = readExistingSecrets($targetPath);

if (PHP_SAPI === 'cli') {
    runCli($sample, $fields, $root, $targetPath, $existing, array_slice($argv, 1));
    exit(0);
}

runWeb($sample, $fields, $root, $targetPath, $existing);

/**
 * The two values a reconfigure needs from the config already in place.
 *
 * Deliberately narrow. Everything else about the existing install is none of
 * this page's business, and pulling the whole array into scope is how a value
 * ends up echoed into the form by accident.
 *
 * @return array{password_hash: string, mask_secret: string}|null null if there
 *         is no config yet.
 */
function readExistingSecrets(string $targetPath): ?array
{
    if (!is_file($targetPath)) {
        return null;
    }

    try {
        $current = require $targetPath;
    } catch (Throwable) {
        // A config too broken to load cannot authorise anything, but it is
        // still a file, so it still may not be overwritten.
        return ['password_hash' => '', 'mask_secret' => ''];
    }

    return [
        'password_hash' => is_array($current) ? (string)($current['password_hash'] ?? '') : '',
        'mask_secret' => is_array($current) ? (string)($current['mask_secret'] ?? '') : '',
    ];
}

/**
 * Whether a supplied password authorises replacing the config.
 *
 * An install with no password configured cannot be authorised at all - there is
 * no secret to prove. That is deliberate: the alternative is a system anybody
 * can reconfigure, which is worse than one that makes you use a text editor.
 */
function mayOverwrite(?array $existing, string $supplied): bool
{
    if ($existing === null) {
        return true;
    }
    if ($existing['password_hash'] === '' || $supplied === '') {
        return false;
    }
    return password_verify($supplied, $existing['password_hash']);
}

/** The whole existing config, read only once the password has been proved. */
function readExistingConfig(string $targetPath): ?array
{
    try {
        $current = require $targetPath;
    } catch (Throwable) {
        return null;
    }
    return is_array($current) ? $current : null;
}

/** Reads one value out of a nested config by its path, or a fallback. */
function valueAtPath(array $config, array $path, mixed $fallback): mixed
{
    $cursor = $config;
    foreach ($path as $step) {
        if (!is_array($cursor) || !array_key_exists($step, $cursor)) {
            return $fallback;
        }
        $cursor = $cursor[$step];
    }
    return $cursor;
}

function startSetupSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Strict',
    ]);
    // Its own name: an unlock here must not be reachable from the dashboard's
    // session, nor the other way round.
    session_name('diskmonsetup');
    session_start();
}

/** A message left behind by a redirect, shown once. */
function takeSetupError(): ?string
{
    $message = $_SESSION['setup_error'] ?? null;
    unset($_SESSION['setup_error']);
    return is_string($message) ? $message : null;
}

/* ------------------------------------------------------------------- web -- */

function runWeb(
    array $sample,
    array $fields,
    string $root,
    string $targetPath,
    ?array $existing
): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    // A page with a password field and a generated secret on it has no business
    // in anybody's frame.
    header('X-Frame-Options: DENY');

    $posted = $_SERVER['REQUEST_METHOD'] === 'POST';
    $configured = $existing !== null;

    /*
     * Unlocking, and why it needs a session at all.
     *
     * Without one, a reconfigure starts from the sample's defaults rather than
     * from what the system is actually set to - so anybody adjusting one
     * threshold would silently reset the scan root, the timezone and the alert
     * address back to whatever the sample says. Showing the current values
     * means proving the password first, and proving it once rather than on
     * every keystroke means remembering it, which is a session.
     *
     * It is deliberately short and deliberately its own cookie: nothing the
     * dashboard's own session can do should reach the installer, and nothing
     * granted here should outlive the sitting.
     */
    startSetupSession();
    $unlocked = $configured
        && !empty($_SESSION['setup_unlocked'])
        && (time() - (int)$_SESSION['setup_unlocked']) < SETUP_UNLOCK_SECONDS;

    if (($_GET['lock'] ?? '') !== '') {
        unset($_SESSION['setup_unlocked']);
        header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'), true, 303);
        return;
    }

    if ($posted && ($_POST['intent'] ?? '') === 'unlock') {
        if (mayOverwrite($existing, (string)($_POST['current_password'] ?? ''))) {
            $_SESSION['setup_unlocked'] = time();
            // Redirected rather than rendered, so a refresh does not re-post a
            // password and the URL people end up bookmarking is a plain GET.
            header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'), true, 303);
            return;
        }
        $_SESSION['setup_error'] = $existing['password_hash'] === ''
            ? 'This install has no password set, so nothing can authorise replacing its config.'
            : 'That is not the current password.';
        header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'), true, 303);
        return;
    }

    /*
     * Where the form starts from.
     *
     * The system's own settings once unlocked, the sample's defaults otherwise.
     * The secrets are never among them: the password box is always empty, and
     * the mask secret is not a form field at all.
     */
    $values = [];
    $current = $unlocked ? readExistingConfig($targetPath) : null;
    foreach ($fields as $field) {
        $values[$field['key']] = $current !== null
            ? valueAtPath($current, $field['path'], $field['default'])
            : $field['default'];
    }

    $errors = [];
    $refusal = null;
    $rendered = null;
    $written = false;
    $writeError = null;
    $authorised = !$configured;
    $rotate = false;

    if ($posted) {
        foreach ($fields as $field) {
            $key = $field['key'];
            if ($field['type'] === 'password') {
                continue;
            }
            $blank = $field['type'] === 'bool' || $field['type'] === 'categories' ? [] : '';
            [$parsed, $problem] = parseField($field, $_POST[$key] ?? $blank);
            $values[$key] = $parsed;
            if ($problem !== null && fieldApplies($field, $values)) {
                $errors[$key] = $problem;
            }
        }

        $supplied = (string)($_POST['current_password'] ?? '');
        $authorised = $unlocked || mayOverwrite($existing, $supplied);
        $rotate = !empty($_POST['rotate_mask']);

        /*
         * A wrong password is not a validation failure.
         *
         * It decides *whether the file is written*, and nothing else. Treated
         * as an error it would block the config from being generated at all,
         * which leaves somebody who mistyped with neither a written file nor
         * the text to paste - the two things this page is for.
         */
        if ($configured && $supplied !== '' && !$authorised) {
            $refusal = $existing['password_hash'] === ''
                ? 'This install has no password set, so nothing can authorise replacing its config.'
                : 'That is not the current password. Nothing was written; here is the text to paste.';
        }

        $password = (string)($_POST['password'] ?? '');
        $again = (string)($_POST['password_again'] ?? '');
        $hash = null;

        if ($password !== '' || $again !== '') {
            if (strlen($password) < 8) {
                $errors['password'] = 'Use at least 8 characters.';
            } elseif ($password !== $again) {
                $errors['password'] = 'Those did not match.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
            }
        } elseif ($configured && $authorised) {
            // Blank means "leave it as it is" when there is something to leave.
            $hash = $existing['password_hash'];
        } elseif ($configured) {
            /*
             * Blank, configured, and not authorised.
             *
             * The old hash cannot be carried into text shown to somebody who
             * has not proved the password, and emitting an empty one would hand
             * them a config that silently disables sign-in the moment they
             * paste it. Asking for a password is the only honest option.
             */
            $errors['password'] = 'Choose a password. The current one cannot be carried over '
                . 'into text shown to somebody who has not entered it.';
        } else {
            // A fresh install may legitimately choose to have no sign-in.
            $hash = '';
        }

        if (!$errors) {
            /*
             * Reusing the mask secret matters more than it looks.
             *
             * It is the key the signed-out pseudonyms are derived from, so a
             * new one renames every masked path at once. Kept unless asked to
             * rotate - and only available to reuse at all when the password
             * has been proved, since reusing it means printing it.
             */
            $mask = ($authorised && $configured && !$rotate && $existing['mask_secret'] !== '')
                ? $existing['mask_secret']
                : bin2hex(random_bytes(32));

            $config = buildConfig($sample, $values, (string)$hash, $mask);
            $rendered = renderConfig($config, $root . '/lib');

            if ($authorised) {
                [$written, $writeError] = writeConfig($targetPath, $rendered, $configured);
            }
        }
    }

    $refusal ??= takeSetupError();

    echo webPage(
        $fields, $values, $errors, $rendered, $written, $writeError,
        $configured, $authorised, $posted, $existing, $refusal, $unlocked
    );
}

/**
 * Puts the config on disk.
 *
 * `x` mode for a first install, so the "does it exist" check and the create are
 * one operation rather than two with a gap between them. A replacement is
 * written through a temporary file and renamed, so a full disk or a killed
 * request cannot leave a half-written config behind - which on this particular
 * file would lock everybody out.
 *
 * @return array{0: bool, 1: ?string} written, and why not
 */
function writeConfig(string $targetPath, string $text, bool $replacing): array
{
    if (!$replacing) {
        $handle = @fopen($targetPath, 'x');
        if ($handle === false) {
            return [false, is_file($targetPath)
                ? 'Something created lib/config.php while you were filling this in. Nothing was overwritten.'
                : 'Could not create lib/config.php. The web server may not be allowed to write '
                  . 'into lib/. Copy the text below into the file instead.'];
        }
        fwrite($handle, $text);
        fclose($handle);
        @chmod($targetPath, 0600);
        return [true, null];
    }

    $temporary = $targetPath . '.new';
    if (@file_put_contents($temporary, $text, LOCK_EX) === false) {
        return [false, 'Could not write into lib/. Copy the text below into the file instead.'];
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $targetPath)) {
        @unlink($temporary);
        return [false, 'Could not replace lib/config.php. Copy the text below into it instead.'];
    }

    return [true, null];
}

function webPage(
    array $fields,
    array $values,
    array $errors,
    ?string $rendered,
    bool $written,
    ?string $writeError,
    bool $configured,
    bool $authorised,
    bool $posted,
    ?array $existing,
    ?string $refusal,
    bool $unlocked
): string {
    $out = [];
    $out[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    $out[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $out[] = '<title>Disk Usage Monitor — setup</title>';
    $out[] = '<link rel="stylesheet" href="index.css">';
    $out[] = '<style>'
        . 'body{max-width:880px}'
        . '.wiz label{display:grid;gap:6px;margin-bottom:16px}'
        . '.wiz input[type=text],.wiz input[type=password],.wiz input[type=email]{width:100%}'
        . '.wiz .help{color:var(--muted);font-size:.82rem}'
        . '.wiz .bad{color:#ffb0b0;font-size:.82rem}'
        . '.wiz fieldset{border:1px solid var(--line);border-radius:10px;padding:12px;margin:0 0 16px}'
        . '.wiz legend{color:var(--muted);font-size:.85rem;padding:0 6px}'
        . '.wiz .row{display:flex;gap:14px;flex-wrap:wrap}'
        . '.wiz .row label{display:flex;align-items:center;gap:6px;margin:0}'
        . 'textarea{width:100%;min-height:340px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;'
        . 'font-size:.8rem;background:#111;color:#dfe6d6;border:1px solid var(--line);'
        . 'border-radius:10px;padding:12px}'
        . '.banner{border-radius:12px;padding:12px 14px;margin-bottom:16px;border:1px solid}'
        . '.banner p:last-child{margin-bottom:0}'
        . '.banner.warn{border-color:#7a5a30;background:#2b2114}'
        . '.banner.ok{border-color:#2f6f3a;background:#152b1c}'
        . '.banner.crit{border-color:#7a3030;background:#2b1414}'
        . 'code{background:#111;padding:1px 5px;border-radius:4px}'
        . 'pre{overflow-x:auto}'
        . '</style></head><body class="wiz">';

    $out[] = '<h1>Disk Usage Monitor — setup</h1>';

    if ($written) {
        $out[] = '<div class="banner ok"><strong>Written to <code>lib/config.php</code>.</strong>'
            . '<p>Take the first readings, then open the dashboard:</p>'
            . '<pre><code>php cron.php --vitals' . "\n" . 'php cron.php --scan</code></pre>'
            . '<p><strong>Now delete <code>setup.php</code>.</strong> It will not let anybody '
            . 'replace your config without the password, but there is no reason to leave a '
            . 'setup page on a live site.</p>'
            . '<p><a href="./">Open the dashboard</a></p></div>';
        $out[] = '</body></html>';
        return implode("\n", $out);
    }

    if (!$configured) {
        $out[] = '<div class="banner crit"><strong>Nothing is configured yet.</strong>'
            . '<p>Until you finish, anybody who can reach this page can set the password. '
            . 'Do it now, and delete <code>setup.php</code> afterwards.</p></div>';
    } elseif ($existing['password_hash'] === '') {
        $out[] = '<div class="banner warn"><strong>Already configured, with no password.</strong>'
            . '<p>There is no secret to prove, so nothing can authorise replacing the config '
            . 'from here. Fill this in and paste the result into <code>lib/config.php</code> '
            . 'yourself — having a file to edit is the authorisation.</p></div>';
    } else {
        $out[] = '<div class="banner warn"><strong>Already configured.</strong>'
            . '<p>Enter the current password below and this will replace '
            . '<code>lib/config.php</code>. Without it you still get the finished text to '
            . 'copy and paste.</p></div>';
    }

    if ($refusal !== null) {
        $out[] = '<div class="banner crit">' . h($refusal) . '</div>';
    }
    if ($writeError !== null) {
        $out[] = '<div class="banner crit">' . h($writeError) . '</div>';
    }
    if ($posted && $errors) {
        $out[] = '<div class="banner crit">Some answers need another look.</div>';
    }

    $out[] = '<form method="post">';

    if ($configured && $existing['password_hash'] !== '' && !$unlocked) {
        // Its own form, so pressing enter in the password box unlocks rather
        // than submitting a whole config full of defaults.
        $out[] = '</form><form method="post"><input type="hidden" name="intent" value="unlock">'
            . '<fieldset><legend>Authorise</legend>'
            . '<span class="help">The password this install already uses. Enter it to edit the '
            . 'current settings and replace the file. Skip it and the form below starts from the '
            . 'defaults and only produces text to paste.</span>'
            . '<label style="margin-top:10px">Current password'
            . '<input type="password" name="current_password" autocomplete="current-password"></label>'
            . '<button type="submit">Unlock</button>'
            . '</fieldset></form><form method="post">';
    }

    if ($unlocked) {
        $out[] = '<fieldset><legend>Unlocked</legend>'
            . '<span class="help">These are the settings this install is running with. '
            . 'Saving replaces <code>lib/config.php</code>.</span>'
            . '<label class="row" style="margin:10px 0 0"><input type="checkbox" name="rotate_mask" '
            . 'value="1"> Also generate a new mask secret</label>'
            . '<span class="help">Only on purpose: it renames every masked path at once.</span>'
            . '<p style="margin:10px 0 0"><a href="?lock=1">Lock again</a></p>'
            . '</fieldset>';
    }

    foreach ($fields as $field) {
        $out[] = renderField($field, $values, $errors, $configured);
    }

    $out[] = '<button type="submit">'
        . (!$configured || $unlocked ? 'Write lib/config.php' : 'Show me the config')
        . '</button>';
    $out[] = '</form>';

    if ($rendered !== null) {
        $out[] = '<h2 style="margin-top:28px">Copy this into <code>lib/config.php</code></h2>';
        $out[] = '<p class="muted small">Replacing the whole file. Keep it out of reach — '
            . '<code>lib/.htaccess</code> already denies the directory.'
            . ($configured && !$authorised
                ? ' This text carries a <strong>new</strong> mask secret, so using it renames '
                  . 'every masked path on the dashboard.'
                : '')
            . '</p>';
        $out[] = '<textarea readonly onclick="this.select()">' . h($rendered) . '</textarea>';
    }

    $out[] = '</body></html>';
    return implode("\n", $out);
}

function renderField(array $field, array $values, array $errors, bool $configured): string
{
    $key = $field['key'];
    $label = h($field['label']);
    $help = isset($field['help']) ? '<span class="help">' . h($field['help']) . '</span>' : '';
    $bad = isset($errors[$key]) ? '<span class="bad">' . h($errors[$key]) . '</span>' : '';

    if ($field['type'] === 'password') {
        $note = $configured
            ? 'Leave blank to keep the current password.'
            : h($field['help']);
        return '<fieldset><legend>' . $label . '</legend>'
            . '<span class="help">' . $note . '</span>'
            . '<label style="margin-top:10px">New password'
            . '<input type="password" name="password" autocomplete="new-password"></label>'
            . '<label>Again<input type="password" name="password_again" autocomplete="new-password"></label>'
            . $bad . '</fieldset>';
    }

    if ($field['type'] === 'bool') {
        $checked = !empty($values[$key]) ? ' checked' : '';
        return '<fieldset><legend>' . $label . '</legend>'
            . '<label class="row" style="margin:0"><input type="checkbox" name="' . h($key)
            . '" value="1"' . $checked . '> Yes</label>'
            . $help . $bad . '</fieldset>';
    }

    if ($field['type'] === 'categories') {
        $chosen = (array)$values[$key];
        $boxes = '';
        foreach ($field['options'] as $option) {
            $checked = in_array($option, $chosen, true) ? ' checked' : '';
            $boxes .= '<label><input type="checkbox" name="' . h($key) . '[]" value="'
                . h($option) . '"' . $checked . '> ' . h($option) . '</label>';
        }
        return '<fieldset><legend>' . $label . '</legend>'
            . '<div class="row">' . $boxes . '</div>' . $help . $bad . '</fieldset>';
    }

    $type = $field['type'] === 'email' ? 'email' : 'text';

    return '<label>' . $label
        . '<input type="' . $type . '" name="' . h($key) . '" value="'
        . h(displayValue($field, $values[$key])) . '">'
        . $help . $bad . '</label>';
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------- cli -- */

function runCli(
    array $sample,
    array $fields,
    string $root,
    string $targetPath,
    ?array $existing,
    array $flags
): void {
    $has = fn(string $flag): bool => in_array($flag, $flags, true);
    $configured = $existing !== null;

    if ($has('--help') || $has('-h')) {
        echo <<<TEXT
        Disk Usage Monitor — config wizard

          php setup.php              ask, then print the config to paste
          php setup.php --write      ask, then write lib/config.php
          php setup.php --defaults   take every default without asking

        Or open setup.php in a browser, which asks the same questions.

        lib/config.php is written freely when it does not exist. Once it does,
        replacing it needs the password already in it - otherwise the wizard
        prints the text and lets you edit the file yourself.

        TEXT;
        return;
    }

    $quiet = $has('--defaults');

    say('');
    say('  Disk Usage Monitor — config wizard');
    say($configured
        ? '  lib/config.php exists. Replacing it needs the password it already holds.'
        : '  Press enter to accept the value in brackets.');
    say('');

    $authorised = !$configured;
    if ($configured && $has('--write')) {
        if ($existing['password_hash'] === '') {
            say('  This install has no password set, so nothing can authorise replacing it.');
            say('  The config will be printed instead.');
        } else {
            $current = readSecret('  Current password: ', canHideInput());
            $authorised = mayOverwrite($existing, $current);
            say($authorised
                ? '  Authorised.'
                : '  That is not the current password. The config will be printed instead.');
        }
        say('');
    }

    $values = [];
    foreach ($fields as $field) {
        if (!fieldApplies($field, $values)) {
            // Skipped questions keep their default, so the config stays whole.
            $values[$field['key']] = $field['default'];
            continue;
        }
        $values[$field['key']] = askField($field, $quiet);
    }

    $hash = askPasswordHash($quiet, $configured && $authorised);
    if ($hash === null) {
        $hash = $configured && $authorised ? $existing['password_hash'] : '';
        say('  Password: unchanged.');
    }

    $mask = ($authorised && $configured && $existing['mask_secret'] !== '')
        ? $existing['mask_secret']
        : bin2hex(random_bytes(32));
    say($mask === ($existing['mask_secret'] ?? null)
        ? '  Mask secret: kept, so masked names do not all change.'
        : '  Mask secret: generated (32 random bytes).');

    $config = buildConfig($sample, $values, $hash, $mask);
    $text = renderConfig($config, $root . '/lib');

    say('');

    if ($has('--write') && $authorised) {
        [$written, $problem] = writeConfig($targetPath, $text, $configured);
        if ($written) {
            say('  Written to lib/config.php (mode 600).');
            say('');
            say('  Now take the first readings:');
            say('    php cron.php --vitals');
            say('    php cron.php --scan');
            say('');
            return;
        }
        say('  ' . $problem);
        say('');
    }

    say('  ── Copy everything between the lines into lib/config.php ────────────');
    say('');
    echo $text;
    say('');
    say('  ── end ──────────────────────────────────────────────────────────────');
    say('');
}

function askField(array $field, bool $quiet): mixed
{
    if ($field['type'] === 'categories') {
        $chosen = [];
        foreach ($field['options'] as $option) {
            if (askYesNo("Allow deleting $option", in_array($option, $field['default'], true), $quiet)) {
                $chosen[] = $option;
            }
        }
        return $chosen;
    }

    if ($field['type'] === 'bool') {
        return askYesNo($field['label'], (bool)$field['default'], $quiet);
    }

    while (true) {
        $raw = prompt($field['label'], displayValue($field, $field['default']), $quiet);
        [$value, $problem] = parseField($field, $raw);
        if ($problem === null) {
            return $value;
        }
        complain($problem);
        if ($quiet) {
            return $field['default'];
        }
    }
}

function prompt(string $question, string $default, bool $quiet): string
{
    if ($quiet) {
        return $default;
    }

    fwrite(STDERR, sprintf('  %s [%s]: ', $question, $default));
    $line = fgets(STDIN);
    if ($line === false) {
        // No terminal and nothing piped: take the default rather than spinning
        // forever on an empty stream.
        fwrite(STDERR, "\n");
        return $default;
    }

    $value = trim($line);
    return $value === '' ? $default : $value;
}

function askYesNo(string $question, bool $default, bool $quiet): bool
{
    while (true) {
        $answer = prompt($question . ' (y/n)', $default ? 'y' : 'n', $quiet);
        if (preg_match('/^(y|yes|n|no)$/i', $answer)) {
            return (bool)preg_match('/^y/i', $answer);
        }
        complain('Answer y or n.');
        if ($quiet) {
            return $default;
        }
    }
}

function complain(string $problem): void
{
    // On a terminal the newline comes from the key the user just pressed.
    // Piped, nothing echoes, so the complaint would land on the end of the
    // prompt it is complaining about.
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "\n");
    }
    say('    ' . $problem);
}

/**
 * Reads a new password without echoing it, twice, and returns the hash.
 *
 * Returns null for "leave it alone", which is only offered when there is an
 * existing password the caller is allowed to keep. `stty -echo` is the only
 * portable way to hide input from PHP CLI; where it is unavailable the wizard
 * says so plainly rather than quietly echoing a password into somebody's
 * scrollback and shell history.
 */
function askPasswordHash(bool $quiet, bool $mayKeep): ?string
{
    if ($quiet) {
        return $mayKeep ? null : '';
    }

    say('');
    say('  Sign-in password. Reveals real paths and enables deleting.');
    say($mayKeep
        ? '  Leave blank to keep the current one.'
        : '  Leave blank to disable sign-in entirely.');

    $hidden = canHideInput();
    if (!$hidden) {
        say('  (this terminal will echo what you type)');
    }

    while (true) {
        $first = readSecret('  Password: ', $hidden);
        if ($first === '') {
            if ($mayKeep) {
                return null;
            }
            say('  Sign-in disabled. Every path stays masked for everybody.');
            return '';
        }
        if (strlen($first) < 8) {
            complain('Use at least 8 characters.');
            continue;
        }
        if ($first !== readSecret('  Again: ', $hidden)) {
            complain('Those did not match.');
            continue;
        }

        say('  Password: hashed.');
        return password_hash($first, PASSWORD_DEFAULT);
    }
}

function canHideInput(): bool
{
    if (!function_exists('shell_exec') || stripos(PHP_OS_FAMILY, 'win') === 0) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('shell_exec', $disabled, true) && stream_isatty(STDIN);
}

function readSecret(string $label, bool $hidden): string
{
    fwrite(STDERR, $label);
    if ($hidden) {
        @shell_exec('stty -echo');
    }
    $value = fgets(STDIN);
    if ($hidden) {
        @shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
    return $value === false ? '' : trim($value);
}

/** Prompts go to stderr, so `php setup.php > out.php` writes only the config. */
function say(string $line): void
{
    fwrite(STDERR, $line . "\n");
}
