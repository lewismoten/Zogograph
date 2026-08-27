<?php
declare(strict_types=1);

/* A local marketplace is deliberately data-only. A manifest advertises a
 * package for review; it does not load files, register hooks, or execute code. */

const MARKETPLACE_CAPABILITIES = ['alert_recommendations'];
const MARKETPLACE_MAX_PACKAGES = 200;
const MARKETPLACE_MAX_MANIFEST_BYTES = 65536;
const MARKETPLACE_SIGNATURE_ALGORITHM = 'ed25519';

/** @return array{id:string,version:string,name:string,publisher:string,summary:string,license:string,capabilities:list<string>,recommendations:list<array{rule:string,severity:string,reason:string}>,signature:?array{key_id:string,algorithm:string,value:string}} */
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
    $recommendations = [];
    $rules = [];
    foreach ((array)($raw['recommendations'] ?? []) as $recommendation) {
        if (!is_array($recommendation) || !in_array('alert_recommendations', $capabilities, true)) throw new InvalidArgumentException('package recommendations are invalid');
        $rule = trim((string)($recommendation['rule'] ?? ''));
        $severity = trim((string)($recommendation['severity'] ?? ''));
        $reason = trim((string)($recommendation['reason'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_:-]{1,80}$/', $rule) || !in_array($severity, ['info', 'warning', 'error', 'critical'], true) || $reason === '' || mb_strlen($reason) > 400) throw new InvalidArgumentException('package recommendation is invalid');
        if (isset($rules[$rule])) throw new InvalidArgumentException('package recommendation rule is duplicated');
        $rules[$rule] = true;
        $recommendations[] = compact('rule', 'severity', 'reason');
    }
    if (count($recommendations) > 30) throw new InvalidArgumentException('package has too many recommendations');
    $signature = null;
    if (array_key_exists('signature', $raw)) {
        if (!is_array($raw['signature'])) throw new InvalidArgumentException('package signature is invalid');
        $key_id = trim((string)($raw['signature']['key_id'] ?? ''));
        $algorithm = trim((string)($raw['signature']['algorithm'] ?? ''));
        $value = trim((string)($raw['signature']['value'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9-]{1,62}$/', $key_id) || $algorithm !== MARKETPLACE_SIGNATURE_ALGORITHM
            || base64_decode($value, true) === false) throw new InvalidArgumentException('package signature is invalid');
        $signature = compact('key_id', 'algorithm', 'value');
    }
    sort($capabilities, SORT_STRING);
    return compact('id', 'version', 'name', 'publisher', 'summary', 'license', 'capabilities', 'recommendations', 'signature');
}

/** Stable signed bytes: package JSON objects have lexical keys, arrays keep their order. */
function marketplaceCanonicalJson(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) return '[' . implode(',', array_map(marketplaceCanonicalJson(...), $value)) . ']';
        ksort($value, SORT_STRING);
        $items = [];
        foreach ($value as $key => $item) $items[] = json_encode((string)$key, JSON_UNESCAPED_SLASHES) . ':' . marketplaceCanonicalJson($item);
        return '{' . implode(',', $items) . '}';
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** @return array{status:string,detail:string,can_enable:bool,key_id:?string} */
function marketplaceProvenance(array $config, array $manifest): array
{
    $signature = $manifest['signature'];
    if ($signature === null) return ['status' => 'local unsigned', 'detail' => 'Local manifest; reviewed by an administrator.', 'can_enable' => true, 'key_id' => null];
    $key_id = $signature['key_id'];
    $key = (array)(($config['marketplace']['trusted_keys'] ?? [])[$key_id] ?? []);
    if (($key['publisher'] ?? null) !== $manifest['publisher']) return ['status' => 'untrusted', 'detail' => 'No matching trusted publisher key.', 'can_enable' => false, 'key_id' => $key_id];
    $public = base64_decode((string)($key['public_key'] ?? ''), true);
    $signed = base64_decode($signature['value'], true);
    if ($public === false || strlen($public) !== 32 || $signed === false || strlen($signed) !== 64) {
        return ['status' => 'invalid', 'detail' => 'Publisher key or signature is malformed.', 'can_enable' => false, 'key_id' => $key_id];
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) return ['status' => 'unavailable', 'detail' => 'PHP sodium support is unavailable.', 'can_enable' => false, 'key_id' => $key_id];
    $payload = $manifest;
    unset($payload['signature']);
    $verified = sodium_crypto_sign_verify_detached($signed, marketplaceCanonicalJson($payload), $public);
    return $verified
        ? ['status' => 'verified', 'detail' => 'Signature verified against the configured publisher key.', 'can_enable' => true, 'key_id' => $key_id]
        : ['status' => 'invalid', 'detail' => 'Signature does not match this manifest.', 'can_enable' => false, 'key_id' => $key_id];
}

/** @return list<array{package:string,rule:string,severity:string,reason:string}> */
function marketplaceAlertRecommendations(array $config, PDO $pdo): array
{
    $out = [];
    foreach (marketplacePackages($config, $pdo) as $package) {
        if (!$package['enabled']) continue;
        foreach ($package['recommendations'] as $recommendation) $out[] = ['package' => $package['id']] + $recommendation;
    }
    return $out;
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
        try { if (!is_array($raw)) throw new InvalidArgumentException('not JSON'); $manifest = marketplaceManifest($raw); if ($manifest['id'] . '.json' !== $entry) throw new InvalidArgumentException('id does not match filename'); $manifest['provenance'] = marketplaceProvenance($config, $manifest); $packages[$manifest['id']] = $manifest; }
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
    if (!$package['provenance']['can_enable']) throw new InvalidArgumentException('package provenance cannot be verified');
    $now = time();
    $pdo->prepare('INSERT INTO marketplace_packages (package_id, version, enabled_at, changed_at, changed_by)
        VALUES (?, ?, ?, ?, ?) ON CONFLICT(package_id) DO UPDATE SET version = excluded.version,
        enabled_at = excluded.enabled_at, changed_at = excluded.changed_at, changed_by = excluded.changed_by')
        ->execute([$id, $package['version'], $enabled ? $now : null, $now, mb_substr($actor, 0, 120)]);
    return $package + ['enabled' => $enabled];
}
