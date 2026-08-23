<?php
declare(strict_types=1);

/*
 * A diagnostic page that depends on nothing.
 *
 * The health check inside api.php is more useful than this one, and completely
 * unreachable in the situation that matters: if api.php cannot load, neither
 * can the endpoint that would tell you why. So this file requires nothing,
 * includes nothing, and does not touch the database or the config. It will run
 * on an install that is otherwise entirely broken.
 *
 * It is readable without signing in, because a broken install has to be
 * diagnosable by whoever is looking at it, and there is no session to check
 * when the code that manages sessions is the thing that will not load. So it
 * reports *states* and never paths, never the config, never the contents of
 * anything - "the data directory is not writable", not where it is.
 *
 * Delete it along with setup.php if you would rather not have it there.
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = __DIR__;

/** Each file the app needs, and whether PHP can actually parse it. */
$required = [
    'api.php', 'cron.php', 'index.html', 'index.css', 'index.js',
    'lib/boot.php', 'lib/db.php', 'lib/collect.php', 'lib/alerts.php',
    'lib/auth.php', 'lib/mask.php', 'lib/actions.php', 'lib/logs.php',
    'lib/schedule.php', 'lib/configure.php', 'lib/config.sample.php',
];

$files = [];
$missing = 0;
$broken = 0;

foreach ($required as $name) {
    $path = $root . '/' . $name;
    $state = 'ok';

    if (!is_file($path)) {
        $state = 'missing';
        $missing++;
    } elseif (!is_readable($path)) {
        $state = 'unreadable';
        $broken++;
    } elseif (str_ends_with($name, '.php')) {
        /*
         * Parsed, not just counted.
         *
         * A file present but truncated by a half-finished upload is the failure
         * this page exists to catch, and it looks identical to a healthy one
         * until PHP tries to compile it.
         *
         * In a try/catch because TOKEN_PARSE *throws* ParseError rather than
         * returning false, and `@` does not suppress an exception. Without
         * this, the page died on exactly the broken file it was written to
         * find - which is the least useful moment for a diagnostic to fail.
         */
        try {
            token_get_all(file_get_contents($path) ?: '', TOKEN_PARSE);
        } catch (Throwable) {
            $state = 'will not parse';
            $broken++;
        }
    }

    $files[$name] = $state;
}

$configured = is_file($root . '/lib/config.php');
$dataDir = $root . '/data';

$environment = [
    'PHP version' => PHP_VERSION,
    'PHP is 8.1 or newer' => PHP_VERSION_ID >= 80100,
    'PDO SQLite driver' => in_array('sqlite', PDO::getAvailableDrivers(), true),
    'lib/config.php exists' => $configured,
    'data directory exists' => is_dir($dataDir),
    'data directory writable' => is_dir($dataDir) && is_writable($dataDir),
    'problem log written' => is_file($dataDir . '/error.log'),
    'PHP has an error log configured' => (string)ini_get('error_log') !== '',
    'mail() available' => function_exists('mail'),
    'shell_exec available' => function_exists('shell_exec')
        && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true),
];

$verdict = $missing > 0
    ? "$missing file(s) missing — the upload is incomplete."
    : ($broken > 0
        ? "$broken file(s) present but unreadable or truncated — upload them again."
        : (!$configured
            ? 'The code is fine. Nothing is configured yet — open setup.php.'
            : 'Everything this page can check looks right.'));

function mark(bool $ok): string
{
    return $ok
        ? '<span style="color:#75d58a">&#10003;</span>'
        : '<span style="color:#ff7f7f">&#10007;</span>';
}

function esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Disk Usage Monitor — health</title>
<style>
:root { color-scheme: dark; }
body {
  font-family: system-ui, -apple-system, sans-serif;
  background: #111; color: #eee; padding: 24px;
  max-width: 780px; margin-inline: auto; line-height: 1.5;
}
h1 { font-size: 1.25rem; }
h2 { font-size: 1rem; margin-top: 28px; }
table { width: 100%; border-collapse: collapse; font-size: .9rem; }
td { padding: 5px 8px; border-bottom: 1px solid #333; }
td:first-child { width: 2ch; }
.verdict { border: 1px solid #7a5a30; background: #2b2114; border-radius: 10px; padding: 12px 14px; }
.verdict.ok { border-color: #2f6f3a; background: #152b1c; }
.muted { color: #9a9a9a; font-size: .85rem; }
code { background: #000; padding: 1px 5px; border-radius: 4px; }
</style>
</head>
<body>

<h1>Disk Usage Monitor — health</h1>

<p class="verdict <?= $missing === 0 && $broken === 0 ? 'ok' : '' ?>"><?= esc($verdict) ?></p>

<p class="muted">
  This page loads nothing and reads nothing, so it works even when the rest does
  not. It shows states rather than paths, because it answers to anyone who can
  reach it.
</p>

<h2>Environment</h2>
<table>
<?php foreach ($environment as $label => $value): ?>
  <tr>
    <td><?= is_bool($value) ? mark($value) : '&#183;' ?></td>
    <td><?= esc($label) ?></td>
    <td class="muted"><?= is_bool($value) ? '' : esc((string)$value) ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>Files</h2>
<table>
<?php foreach ($files as $name => $state): ?>
  <tr>
    <td><?= mark($state === 'ok') ?></td>
    <td><code><?= esc($name) ?></code></td>
    <td class="muted"><?= $state === 'ok' ? '' : esc($state) ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>If the dashboard is returning errors</h2>
<ol class="muted">
  <li>Any red mark above is the cause. Fix that first.</li>
  <li>If everything here is green, the failure is at runtime. The dashboard
      shows a reference when a request fails; find that reference in
      <code>data/error.log</code>, or under <strong>Recent problems</strong> on
      the dashboard once you are signed in.</li>
  <li>If <em>PHP has an error log configured</em> is red, that is why you cannot
      find one anywhere on the server — this project writes its own to
      <code>data/error.log</code> regardless.</li>
</ol>

</body>
</html>
