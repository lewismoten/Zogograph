<?php
declare(strict_types=1);

/* Registry and storage boundary for executable packages. This deliberately
 * does not include package entry points. Loading code comes only after a
 * separately reviewed isolation runtime is implemented. */
require_once __DIR__ . '/package-integrity.php';

const PACKAGE_RUNTIME_CAPABILITIES = [
    'storage_backend', 'live_database_encryption', 'package_private_database',
    'key_provider', 'ciphertext_replica', 'network_client',
];

/** @return array{id:string,version:string,publisher:string,capabilities:list<string>,runtime:array,integrity:array,signature:array} */
function packageRuntimeManifest(string $directory, array $trustedKeys): array
{
    $root = realpath($directory);
    if ($root === false || is_link($directory) || !is_file($root . '/manifest.json')) {
        throw new InvalidArgumentException('package manifest is unavailable');
    }
    $raw = json_decode((string)file_get_contents($root . '/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($raw)) throw new InvalidArgumentException('package manifest is invalid');
    $id = trim((string)($raw['id'] ?? ''));
    $version = trim((string)($raw['version'] ?? ''));
    $publisher = trim((string)($raw['publisher'] ?? ''));
    $capabilities = array_values(array_unique(array_filter((array)($raw['capabilities'] ?? []), 'is_string')));
    if (!preg_match('/^[a-z][a-z0-9-]{1,62}$/', $id)
        || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-z0-9.-]+)?$/i', $version)
        || $publisher === '' || $capabilities === [] || count($capabilities) > 12
        || array_diff($capabilities, PACKAGE_RUNTIME_CAPABILITIES) !== []) {
        throw new InvalidArgumentException('package identity or capabilities are invalid');
    }
    sort($capabilities, SORT_STRING);
    $runtime = (array)($raw['runtime'] ?? []);
    if (($runtime['kind'] ?? '') !== 'hostscope-isolated-php-package'
        || !packageRuntimeRelativePath((string)($runtime['entrypoint'] ?? ''))
        || !is_file($root . '/' . $runtime['entrypoint'])) {
        throw new InvalidArgumentException('package runtime declaration is invalid');
    }
    foreach (['settings_schema'] as $field) {
        if (isset($raw[$field]) && (!packageRuntimeRelativePath((string)$raw[$field]) || !is_file($root . '/' . $raw[$field]))) {
            throw new InvalidArgumentException('package settings declaration is invalid');
        }
    }
    $integrity = packageVerifyIntegrity($raw, $root);
    if (!$integrity['ok']) throw new InvalidArgumentException($integrity['detail']);
    $signature = packageVerifySignature($raw, $trustedKeys);
    if (!$signature['ok'] || (($trustedKeys[$signature['key_id']] ?? [])['publisher'] ?? null) !== $publisher) {
        throw new InvalidArgumentException($signature['ok'] ? 'package publisher does not match its trusted key' : $signature['detail']);
    }
    return ['id' => $id, 'version' => $version, 'publisher' => $publisher, 'capabilities' => $capabilities,
        'runtime' => $runtime, 'integrity' => (array)$raw['integrity'], 'signature' => (array)$raw['signature']];
}

function packageRuntimeRelativePath(string $path): bool
{
    return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, "\0")
        && !in_array('..', explode('/', str_replace('\\', '/', $path)), true);
}

function packageRuntimeDirectory(array $config): string
{
    $directory = rtrim((string)($config['package_runtime']['directory'] ?? ''), '/');
    if ($directory === '' || is_link($directory)) throw new InvalidArgumentException('package runtime directory is unsafe');
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('could not create package runtime directory');
    }
    $real = realpath($directory);
    if ($real === false || !is_writable($real)) throw new RuntimeException('package runtime directory is unavailable');
    @chmod($real, 0700);
    return $real;
}

/** @return array{id:string,version:string,publisher:string,install_path:string} */
function packageRuntimeInstall(array $config, PDO $pdo, string $source, string $actor): array
{
    $trusted = (array)($config['package_runtime']['trusted_keys'] ?? []);
    $manifest = packageRuntimeManifest($source, $trusted);
    $root = realpath($source);
    $base = packageRuntimeDirectory($config);
    $target = $base . '/' . $manifest['id'] . '-' . $manifest['version'];
    if (file_exists($target)) throw new InvalidArgumentException('that package version is already installed');
    if (!mkdir($target, 0700, true)) throw new RuntimeException('could not create package directory');
    try {
        packageRuntimeCopyFile($root . '/manifest.json', $target . '/manifest.json');
        foreach (array_keys($manifest['integrity']['files']) as $relative) {
            packageRuntimeCopyFile($root . '/' . $relative, $target . '/' . $relative);
        }
    } catch (Throwable $error) {
        packageRuntimeRemoveDirectory($target);
        throw $error;
    }
    $now = time();
    $pdo->prepare('INSERT INTO runtime_packages (package_id, version, publisher, manifest_json, install_path, installed_at, enabled_at, changed_at, changed_by)
        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)')
        ->execute([$manifest['id'], $manifest['version'], $manifest['publisher'],
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $target, $now, $now, mb_substr($actor, 0, 120)]);
    return ['id' => $manifest['id'], 'version' => $manifest['version'], 'publisher' => $manifest['publisher'], 'install_path' => $target];
}

function packageRuntimeCopyFile(string $source, string $destination): void
{
    if (is_link($source) || !is_file($source) || !is_readable($source)) throw new InvalidArgumentException('package source file is unsafe');
    $parent = dirname($destination);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) throw new RuntimeException('could not create package file directory');
    if (!copy($source, $destination)) throw new RuntimeException('could not install package file');
    @chmod($destination, 0640);
}

function packageRuntimeRemoveDirectory(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $path = $item->getPathname();
        if ($item->isLink()) @unlink($path); elseif ($item->isDir()) @rmdir($path); else @unlink($path);
    }
    @rmdir($directory);
}

/** @return list<array{id:string,version:string,publisher:string,enabled:bool,capabilities:list<string>,grants:list<string>,state:string}> */
function packageRuntimePackages(PDO $pdo): array
{
    $grants = [];
    foreach ($pdo->query('SELECT package_id, capability FROM runtime_package_grants')->fetchAll() as $row) {
        $grants[$row['package_id']][] = $row['capability'];
    }
    $out = [];
    foreach ($pdo->query('SELECT package_id, version, publisher, manifest_json, enabled_at FROM runtime_packages ORDER BY package_id')->fetchAll() as $row) {
        $manifest = json_decode((string)$row['manifest_json'], true) ?: [];
        $required = (array)($manifest['capabilities'] ?? []);
        $given = $grants[$row['package_id']] ?? [];
        sort($given, SORT_STRING);
        $enabled = $row['enabled_at'] !== null && $required === $given;
        $out[] = ['id' => $row['package_id'], 'version' => $row['version'], 'publisher' => $row['publisher'],
            'enabled' => $enabled, 'capabilities' => $required, 'grants' => $given,
            'state' => $enabled ? 'registered; execution runtime pending' : 'needs explicit capability grants'];
    }
    return $out;
}

function packageRuntimeSetEnabled(PDO $pdo, string $id, array $grants, bool $enabled, string $actor): void
{
    $row = $pdo->prepare('SELECT manifest_json FROM runtime_packages WHERE package_id = ?');
    $row->execute([$id]); $manifest = json_decode((string)$row->fetchColumn(), true);
    if (!is_array($manifest)) throw new InvalidArgumentException('package is not installed');
    $required = (array)($manifest['capabilities'] ?? []);
    $grants = array_values(array_unique(array_intersect(PACKAGE_RUNTIME_CAPABILITIES, array_map('strval', $grants))));
    sort($required, SORT_STRING); sort($grants, SORT_STRING);
    if ($enabled && $required !== $grants) throw new InvalidArgumentException('grant every capability declared by the signed package before enabling it');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM runtime_package_grants WHERE package_id = ?')->execute([$id]);
        $insert = $pdo->prepare('INSERT INTO runtime_package_grants (package_id, capability, granted_at, granted_by) VALUES (?, ?, ?, ?)');
        foreach ($grants as $grant) $insert->execute([$id, $grant, time(), mb_substr($actor, 0, 120)]);
        $pdo->prepare('UPDATE runtime_packages SET enabled_at = ?, changed_at = ?, changed_by = ? WHERE package_id = ?')
            ->execute([$enabled ? time() : null, time(), mb_substr($actor, 0, 120), $id]);
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}

function packageRuntimePrivateDatabase(array $config, string $id): PDO
{
    if (!preg_match('/^[a-z][a-z0-9-]{1,62}$/', $id)) throw new InvalidArgumentException('package id is invalid');
    $directory = packageRuntimeDirectory($config) . '/' . $id . '-data';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('could not create package data directory');
    $path = $directory . '/package.db';
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA busy_timeout = 5000'); @chmod($path, 0600);
    return $pdo;
}
