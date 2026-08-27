<?php
declare(strict_types=1);

/* A local marketplace is deliberately data-only. A manifest advertises a
 * package for review; it does not load files, register hooks, or execute code. */

const MARKETPLACE_CAPABILITIES = ['alert_recommendations', 'dashboard_cards', 'integration_settings'];
const MARKETPLACE_MAX_PACKAGES = 200;
const MARKETPLACE_MAX_MANIFEST_BYTES = 65536;

/** @return array{id:string,version:string,name:string,publisher:string,summary:string,license:string,capabilities:list<string>} */
function marketplaceManifest(array $raw): array
{
    $id = trim((string)($raw['id'] ?? ''));
    $version = trim((string)($raw['version'] ?? ''));
    $name = trim((string)($raw['name'] ?? ''));
    $publisher = trim((string)($raw['publisher'] ?? ''));
    $summary = trim((string)($raw['summary'] ?? ''));
    $license = trim((string)($raw['license'] ?? ''));
    if (!preg_match('/^[a-z][a-z0-9-]{1,62}$/', $id)) throw new InvalidArgumentException('package id is invalid');
    if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-z0-9.-]+)?$/i', $version)) throw new InvalidArgumentException('package version is invalid');
    foreach (['name' => $name, 'publisher' => $publisher, 'summary' => $summary, 'license' => $license] as $field => $value) {
        if ($value === '' || mb_strlen($value) > ($field === 'summary' ? 500 : 120)) throw new InvalidArgumentException("package $field is invalid");
    }
    $capabilities = array_values(array_unique(array_filter((array)($raw['capabilities'] ?? []), 'is_string')));
    if ($capabilities === [] || count($capabilities) > 8 || array_diff($capabilities, MARKETPLACE_CAPABILITIES) !== []) {
        throw new InvalidArgumentException('package capabilities are invalid');
    }
    sort($capabilities, SORT_STRING);
    return compact('id', 'version', 'name', 'publisher', 'summary', 'license', 'capabilities');
}

/** @return array{packages:list<array>,errors:list<string>} */
function marketplaceCatalog(array $config): array
{
    $directory = (string)($config['marketplace']['directory'] ?? '');
    if ($directory === '' || !is_dir($directory) || !is_readable($directory)) return ['packages' => [], 'errors' => []];
    $packages = []; $errors = [];
    foreach (array_slice(scandir($directory) ?: [], 0, MARKETPLACE_MAX_PACKAGES + 2) as $entry) {
        if ($entry === '.' || $entry === '..' || !preg_match('/^[a-z][a-z0-9-]{1,62}\.json$/', $entry)) continue;
        $file = $directory . '/' . $entry;
        if (is_link($file) || !is_file($file) || filesize($file) > MARKETPLACE_MAX_MANIFEST_BYTES) { $errors[] = "$entry is not a safe manifest"; continue; }
        $raw = json_decode((string)file_get_contents($file), true);
        try { if (!is_array($raw)) throw new InvalidArgumentException('not JSON'); $manifest = marketplaceManifest($raw); if ($manifest['id'] . '.json' !== $entry) throw new InvalidArgumentException('id does not match filename'); $packages[$manifest['id']] = $manifest; }
        catch (Throwable $error) { $errors[] = "$entry: " . $error->getMessage(); }
    }
    ksort($packages, SORT_STRING);
    return ['packages' => array_values($packages), 'errors' => array_slice($errors, 0, 30)];
}

/** @return list<array> Catalog rows paired with their explicit local state. */
function marketplacePackages(array $config, PDO $pdo): array
{
    $catalog = marketplaceCatalog($config);
    $states = [];
    foreach ($pdo->query('SELECT package_id, version, enabled_at, changed_at, changed_by FROM marketplace_packages')->fetchAll() as $row) $states[$row['package_id']] = $row;
    return array_map(function (array $package) use ($states): array {
        $state = $states[$package['id']] ?? null;
        return $package + ['enabled' => $state !== null && $state['enabled_at'] !== null,
            'installed_version' => $state['version'] ?? null, 'changed_at' => $state['changed_at'] ?? null];
    }, $catalog['packages']);
}

/** Enablement never trusts a caller-provided manifest or capability list. */
function marketplaceSetEnabled(array $config, PDO $pdo, string $id, bool $enabled, string $actor): array
{
    $catalog = marketplaceCatalog($config);
    $package = current(array_filter($catalog['packages'], fn(array $row): bool => $row['id'] === $id));
    if (!is_array($package)) throw new InvalidArgumentException('package is not available in the local catalog');
    $now = time();
    $pdo->prepare('INSERT INTO marketplace_packages (package_id, version, enabled_at, changed_at, changed_by)
        VALUES (?, ?, ?, ?, ?) ON CONFLICT(package_id) DO UPDATE SET version = excluded.version,
        enabled_at = excluded.enabled_at, changed_at = excluded.changed_at, changed_by = excluded.changed_by')
        ->execute([$id, $package['version'], $enabled ? $now : null, $now, mb_substr($actor, 0, 120)]);
    return $package + ['enabled' => $enabled];
}
