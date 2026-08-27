<?php
declare(strict_types=1);

/* Installation facts shared by the browser and CLI setup surfaces. */

/** @return list<string> */
function setupCommandCapability(): array
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return array_values(array_filter(['exec', 'shell_exec', 'proc_open'],
        static fn(string $name): bool => function_exists($name) && !in_array($name, $disabled, true)));
}

/**
 * Installation facts that decide what HostScope can safely promise.
 *
 * `$runtime` is only an injectable observation seam for reproducible checks;
 * normal setup calls it with no override and reads the live PHP runtime.
 * @param array{php_version?: string, sqlite?: bool, commands?: list<string>, https?: bool, cli?: bool}|null $runtime
 * @return list<array{label: string, state: string, detail: string}>
 */
function setupPreflight(string $root, string $targetPath, ?array $runtime = null): array
{
    $runtime ??= [];
    $https = $runtime['https'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));
    $cli = $runtime['cli'] ?? PHP_SAPI === 'cli';
    $phpVersion = $runtime['php_version'] ?? PHP_VERSION;
    $sqlite = $runtime['sqlite'] ?? (extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true));
    $commands = $runtime['commands'] ?? setupCommandCapability();
    $data = $root . '/data';
    $phpOk = version_compare($phpVersion, '8.1.0', '>=');

    return [
        ['label' => 'PHP', 'state' => $phpOk ? 'ok' : 'error',
            'detail' => 'PHP ' . $phpVersion . ($phpOk ? ' is supported.' : ' is too old; HostScope needs PHP 8.1 or newer.')],
        ['label' => 'SQLite', 'state' => $sqlite ? 'ok' : 'error',
            'detail' => $sqlite ? 'PDO SQLite is available.' : 'PDO SQLite is required for collected data and settings.'],
        ['label' => 'Data directory', 'state' => is_dir($data) && is_writable($data) ? 'ok' : 'error',
            'detail' => is_dir($data) && is_writable($data) ? 'data/ is writable.' : 'data/ must exist and be writable by PHP.'],
        ['label' => 'Configuration', 'state' => configuredSettingsWritable($targetPath) ? 'ok' : 'error',
            'detail' => configuredSettingsWritable($targetPath) ? 'The configuration store can be written.' : 'PHP cannot write the configuration store.'],
        ['label' => 'HTTPS', 'state' => $https ? 'ok' : 'warning',
            'detail' => $https ? 'This setup page is using HTTPS.' : 'Serve setup and the dashboard over HTTPS before entering credentials.'],
        ['label' => 'Timezone', 'state' => date_default_timezone_get() !== '' ? 'ok' : 'warning',
            'detail' => 'PHP currently uses ' . date_default_timezone_get() . '. You can choose the dashboard timezone below.'],
        ['label' => 'Command capability', 'state' => $commands === [] ? 'warning' : 'ok',
            'detail' => $commands === [] ? 'Command execution is unavailable; PHP-only collection still works.'
                : 'Available: ' . implode(', ', $commands) . '. Features remain individually capability-gated.'],
        ['label' => 'Cron', 'state' => $cli || $commands !== [] ? 'ok' : 'warning',
            'detail' => $cli ? 'CLI PHP is available for cron.'
                : ($commands === [] ? 'Configure cron through your host panel; this web request cannot inspect it.'
                    : 'Schedule cron through your host panel or account crontab.')],
    ];
}
