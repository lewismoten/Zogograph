<?php
declare(strict_types=1);

/* An opt-in, core-owned marketplace capability. It never executes package
 * files: the package only grants this fixed scanner during an existing walk. */

const PATH_HYGIENE_PACKAGE = 'hostscope-path-hygiene';
const PATH_HYGIENE_LIMIT = 50;

function pathHygieneEnabled(array $config, PDO $pdo): bool
{
    if (!function_exists('marketplacePackages')) return false;
    foreach (marketplacePackages($config, $pdo) as $package) {
        if ($package['id'] === PATH_HYGIENE_PACKAGE && $package['enabled']
            && in_array('path_hygiene', $package['capabilities'], true)) return true;
    }
    return false;
}

/** @return array{enabled:bool,findings:list<array>,total:int,truncated:bool} */
function pathHygieneStart(bool $enabled): array
{
    return ['enabled' => $enabled, 'findings' => [], 'total' => 0, 'truncated' => false];
}

/** Records only names that routinely confuse terminals, scripts, or copy/paste. */
function pathHygieneObserve(string $path, string $name, array &$state): void
{
    if (!$state['enabled']) return;
    $reason = null;
    if (preg_match('/[\x00-\x1f\x7f]/', $name)) $reason = 'contains a control character';
    elseif (preg_match('/^\s|\s$/u', $name)) $reason = 'starts or ends with whitespace';
    elseif (str_starts_with($name, '-')) $reason = 'starts with a dash and can be treated as a command option';
    if ($reason === null) return;

    $state['total']++;
    if (count($state['findings']) >= PATH_HYGIENE_LIMIT) {
        $state['truncated'] = true;
        return;
    }
    $state['findings'][] = ['path' => $path, 'reason' => $reason];
}

function pathHygieneStore(PDO $pdo, int $scanId, array $state): void
{
    if (!$state['enabled']) return;
    $pdo->prepare('INSERT INTO path_hygiene_scans (scan_id, checked_at, findings_json, findings_total, truncated)
        VALUES (?, ?, ?, ?, ?)')->execute([
        $scanId, time(), json_encode($state['findings'], JSON_UNESCAPED_SLASHES),
        $state['total'], $state['truncated'] ? 1 : 0,
    ]);
}

/** @return ?array{checked_at:int,findings:list<array>,total:int,truncated:bool} */
function pathHygieneLast(PDO $pdo): ?array
{
    try { $row = $pdo->query('SELECT checked_at, findings_json, findings_total, truncated FROM path_hygiene_scans ORDER BY scan_id DESC LIMIT 1')->fetch(); }
    catch (Throwable) { return null; }
    if (!is_array($row)) return null;
    $findings = json_decode((string)$row['findings_json'], true);
    return ['checked_at' => (int)$row['checked_at'], 'findings' => is_array($findings) ? $findings : [],
        'total' => (int)$row['findings_total'], 'truncated' => (bool)$row['truncated']];
}
