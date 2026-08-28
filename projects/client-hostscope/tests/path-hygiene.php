<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/marketplace.php';
require_once __DIR__ . '/../lib/path-hygiene.php';

function pathHygieneExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$root = sys_get_temp_dir() . '/hostscope-path-hygiene-' . bin2hex(random_bytes(4));
mkdir($root, 0700);
file_put_contents($root . '/hostscope-path-hygiene.json', json_encode([
    'id' => 'hostscope-path-hygiene', 'version' => '1.0.0', 'name' => 'Path-name hygiene',
    'publisher' => 'HostScope', 'summary' => 'Advisory path name review.', 'license' => 'Proprietary',
    'capabilities' => ['path_hygiene'], 'recommendations' => [],
]));
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE marketplace_packages (package_id TEXT PRIMARY KEY, version TEXT, enabled_at INTEGER, changed_at INTEGER, changed_by TEXT)');
$pdo->exec('CREATE TABLE scans (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE path_hygiene_scans (scan_id INTEGER PRIMARY KEY, checked_at INTEGER, findings_json TEXT, findings_total INTEGER, truncated INTEGER)');
$config = ['marketplace' => ['directory' => $root]];
pathHygieneExpect(!pathHygieneEnabled($config, $pdo), 'disabled package enabled the scanner');
marketplaceSetEnabled($config, $pdo, 'hostscope-path-hygiene', true, 'administrator');
pathHygieneExpect(pathHygieneEnabled($config, $pdo), 'enabled package did not activate the fixed scanner');
$state = pathHygieneStart(true);
pathHygieneObserve('/tmp/normal name', 'normal name', $state);
pathHygieneObserve('/tmp/leading-space', ' leading', $state);
pathHygieneObserve('/tmp/trailing-space', 'trailing ', $state);
pathHygieneObserve('/tmp/dash', '-dash', $state);
pathHygieneObserve("/tmp/newline\n", "newline\n", $state);
pathHygieneExpect($state['total'] === 4 && count($state['findings']) === 4, 'path scanner classified names incorrectly');
$pdo->exec('INSERT INTO scans (id) VALUES (1)');
pathHygieneStore($pdo, 1, $state);
$stored = pathHygieneLast($pdo);
pathHygieneExpect($stored !== null && $stored['total'] === 4 && count($stored['findings']) === 4, 'path scan was not stored');
unlink($root . '/hostscope-path-hygiene.json'); rmdir($root);
echo "path hygiene checks passed\n";
