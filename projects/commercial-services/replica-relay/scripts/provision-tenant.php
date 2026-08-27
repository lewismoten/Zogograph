#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 2) { fwrite(STDERR, "Usage: php scripts/provision-tenant.php 'Customer name'\n"); exit(2); }
$root = dirname(__DIR__); $configFile = $root . '/config.php';
if (!is_file($configFile) || !is_array($config = require $configFile)) { fwrite(STDERR, "Create config.php from config.sample.php first.\n"); exit(1); }
require_once $root . '/lib/store.php';
$tenant = replicaRelayCreateTenant(replicaRelayOpen($config), $argv[1]); echo json_encode($tenant, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
