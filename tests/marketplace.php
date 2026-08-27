<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/marketplace.php';
function marketplaceExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$root = sys_get_temp_dir() . '/hostscope-marketplace-' . bin2hex(random_bytes(4));
mkdir($root, 0700);
file_put_contents($root . '/uptime-pack.json', json_encode(['id'=>'uptime-pack','version'=>'1.0.0','name'=>'Uptime pack','publisher'=>'HostScope','summary'=>'Useful uptime recommendations.','license'=>'Proprietary','capabilities'=>['alert_recommendations'], 'recommendations'=>[['rule'=>'host_uptime','severity'=>'warning','reason'=>'Review unexpected restarts.']]]));
file_put_contents($root . '/bad.json', '{');
$catalog = marketplaceCatalog(['marketplace' => ['directory' => $root]]);
marketplaceExpect(count($catalog['packages']) === 1 && $catalog['packages'][0]['id'] === 'uptime-pack', 'valid marketplace manifest was not discovered');
marketplaceExpect(count($catalog['errors']) === 1, 'malformed manifest was not reported');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE marketplace_packages (package_id TEXT PRIMARY KEY, version TEXT, enabled_at INTEGER, changed_at INTEGER, changed_by TEXT)');
$enabled = marketplaceSetEnabled(['marketplace' => ['directory' => $root]], $pdo, 'uptime-pack', true, 'administrator');
marketplaceExpect($enabled['enabled'] === true && marketplacePackages(['marketplace' => ['directory' => $root]], $pdo)[0]['enabled'] === true,
    'catalog package enablement was not persisted');
marketplaceExpect(marketplaceAlertRecommendations(['marketplace' => ['directory' => $root]], $pdo)[0]['rule'] === 'host_uptime',
    'enabled recommendation package was not exposed as data-only guidance');
$disabled = marketplaceSetEnabled(['marketplace' => ['directory' => $root]], $pdo, 'uptime-pack', false, 'administrator');
marketplaceExpect($disabled['enabled'] === false && marketplaceAlertRecommendations(['marketplace' => ['directory' => $root]], $pdo) === [],
    'disabled recommendation package remained active');
try { marketplaceManifest(['id'=>'Bad','version'=>'1','name'=>'x','publisher'=>'x','summary'=>'x','license'=>'x','capabilities'=>['shell']]); throw new RuntimeException('unsafe manifest was accepted'); } catch (InvalidArgumentException) {}
try { marketplaceManifest(['id'=>'bad-guidance','version'=>'1.0.0','name'=>'x','publisher'=>'x','summary'=>'x','license'=>'x','capabilities'=>['alert_recommendations'], 'recommendations'=>[['rule'=>'same_rule','severity'=>'warning','reason'=>'x'], ['rule'=>'same_rule','severity'=>'error','reason'=>'y']]]); throw new RuntimeException('duplicate package guidance was accepted'); } catch (InvalidArgumentException) {}
$api = file_get_contents(__DIR__ . '/../api.php');
marketplaceExpect($api !== false && str_contains($api, "'marketplace' => 'sys-marketplace'")
    && str_contains($api, "'marketplace-enable' => doMarketplaceEnable")
    && str_contains($api, "function doMarketplaceEnable(array \$config, PDO \$pdo, bool \$post): array\n{\n    requireWrite(\$post);"),
    'marketplace administration route is missing its write authorization');
unlink($root . '/uptime-pack.json'); unlink($root . '/bad.json'); rmdir($root);
echo "marketplace checks passed\n";
