#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This provisioning command is CLI-only.\n"); }
require_once __DIR__ . '/../lib/store.php';
if ($argc !== 2 || trim($argv[1]) === '') { fwrite(STDERR, "Usage: php scripts/provision-tenant.php \"Tenant name\"\n"); exit(64); }
$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) { fwrite(STDERR, "Copy config.sample.php to config.php before provisioning a tenant.\n"); exit(1); }
$config = require $configFile;
if (!is_array($config)) { fwrite(STDERR, "Key-custody configuration is invalid.\n"); exit(1); }
try {
    $tenant = keyCustodyCreateTenant(keyCustodyOpen($config), $argv[1]);
    fwrite(STDOUT, "Tenant: {$tenant['tenant_id']}\nManagement token (account, billing, key policy): {$tenant['management_token']}\nEnrolled-client token (unwrap/envelope only): {$tenant['client_token']}\n\nStore both tokens securely now. The service stores only hashes and cannot show them again.\n");
} catch (Throwable $error) { fwrite(STDERR, 'Tenant provisioning failed: ' . $error->getMessage() . "\n"); exit(1); }
