<?php
declare(strict_types=1);

/*
 * Durable setup configuration, separate from the measurement database.
 *
 * config.php remains the boot strap: it tells PHP where data lives before any
 * database can be opened. Once setup.db exists, everything else is loaded from
 * it. Existing installs keep working because a full legacy config is also a
 * valid bootstrap until setup migrates it.
 */

function setupStorePath(array $bootstrap): string
{
    return dirname((string)($bootstrap['database'] ?? __DIR__ . '/../data/stats.db')) . '/setup.db';
}

function setupStoreOpen(array $bootstrap, bool $create = false): ?PDO
{
    $path = setupStorePath($bootstrap);
    if (!str_starts_with($path, '/') || is_link($path)) throw new RuntimeException('setup database path must be absolute and must not be a symbolic link');
    if (!$create && !is_file($path)) return null;
    $dir = dirname($path);
    if (is_link($dir)) throw new RuntimeException('setup database directory must not be a symbolic link');
    if ($create && !is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('could not create the setup data directory');
    }
    $real = realpath($dir);
    if ($real === false || !is_dir($real) || is_link($dir)) throw new RuntimeException('setup database directory is unavailable or unsafe');
    $path = $real . '/' . basename($path);
    if (is_link($path)) throw new RuntimeException('setup database path must not be a symbolic link');
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($create) {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS setup_values (
            name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at INTEGER NOT NULL
        )');
        @chmod($path, 0600);
    }
    return $pdo;
}

/** Loads setup.db when present, otherwise a legacy all-in-one config.php. */
function loadConfiguredSettings(string $configFile): array
{
    $bootstrap = require $configFile;
    if (!is_array($bootstrap)) throw new RuntimeException('config.php must return an array');
    try {
        $store = setupStoreOpen($bootstrap, false);
        if ($store === null) return $bootstrap;
        $value = $store->query("SELECT value FROM setup_values WHERE name = 'config'")->fetchColumn();
        $saved = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($saved)) return $bootstrap;
        // Paths required before setup.db opens always come from the bootstrap.
        foreach (['database', 'scan_root', 'timezone'] as $key) {
            if (array_key_exists($key, $bootstrap)) $saved[$key] = $bootstrap[$key];
        }
        return $saved;
    } catch (Throwable) {
        // A damaged optional setup store must not turn a recoverable legacy
        // config into a blank dashboard. setup.php can repair it explicitly.
        return $bootstrap;
    }
}

/** Writes full settings to setup.db and reduces config.php to boot essentials. */
function saveConfiguredSettings(array $config, string $configFile, string $libDir): void
{
    $bootstrap = [];
    foreach (['scan_root', 'timezone', 'database'] as $key) {
        if (array_key_exists($key, $config)) $bootstrap[$key] = $config[$key];
    }
    $current = is_file($configFile) ? require $configFile : null;
    $replaceBootstrap = !is_array($current) || $current !== $bootstrap;
    if ($replaceBootstrap && !is_writable(is_file($configFile) ? $configFile : dirname($configFile))) {
        throw new RuntimeException('could not update the bootstrap config');
    }
    $store = setupStoreOpen($bootstrap, true);
    if ($store === null) throw new RuntimeException('could not open setup.db');
    $encoded = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) throw new RuntimeException('could not encode settings');
    $store->prepare('INSERT INTO setup_values (name, value, updated_at) VALUES (?, ?, ?)
        ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
        ->execute(['config', $encoded, time()]);

    if (!$replaceBootstrap) return;

    $text = "<?php\ndeclare(strict_types=1);\n\n/* Boot settings. The rest live in ../data/setup.db. */\n\nreturn "
        . setupStoreRender($bootstrap, $libDir) . ";\n";
    $temporary = $configFile . '.new';
    if (@file_put_contents($temporary, $text, LOCK_EX) === false || !@rename($temporary, $configFile)) {
        @unlink($temporary);
        throw new RuntimeException('could not replace the bootstrap config');
    }
    @chmod($configFile, 0600);
}

/** Whether settings can be saved without changing the PHP bootstrap. */
function configuredSettingsWritable(string $configFile): bool
{
    if (!is_file($configFile)) return is_writable(dirname($configFile));
    try {
        $bootstrap = require $configFile;
        if (!is_array($bootstrap)) return false;
        $store = setupStorePath($bootstrap);
        return is_file($store) ? is_writable(dirname($store)) : is_writable($configFile);
    } catch (Throwable) {
        return false;
    }
}

function setupStoreRender(array $bootstrap, string $libDir): string
{
    $parts = [];
    foreach ($bootstrap as $key => $value) {
        $shown = is_string($value) && str_starts_with($value, rtrim($libDir, '/') . '/')
            ? "__DIR__ . " . var_export(substr($value, strlen(rtrim($libDir, '/'))), true)
            : var_export($value, true);
        $parts[] = '    ' . var_export($key, true) . ' => ' . $shown;
    }
    return "[\n" . implode(",\n", $parts) . ",\n]";
}
