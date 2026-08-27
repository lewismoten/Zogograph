#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This key command is CLI-only.\n"); }
if ($argc !== 2 || !str_starts_with($argv[1], '/')) { fwrite(STDERR, "Usage: php scripts/master-key.php /absolute/private/master.key\n"); exit(64); }
$path = $argv[1];
if (is_link($path) || file_exists($path)) { fwrite(STDERR, "Refusing to replace an existing or symbolic-link master key.\n"); exit(1); }
$directory = dirname($path);
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { fwrite(STDERR, "Could not create the master-key directory.\n"); exit(1); }
if (!is_writable($directory) || file_put_contents($path, base64_encode(random_bytes(32)) . "\n", LOCK_EX) === false) { fwrite(STDERR, "Could not write the master key.\n"); exit(1); }
chmod($path, 0600);
fwrite(STDOUT, "Created 256-bit custody master key at {$path} (0600). Store an offline recovery copy separately.\n");
