#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This token command is CLI-only.\n"); }
if ($argc !== 2 || !str_starts_with($argv[1], '/')) { fwrite(STDERR, "Usage: php scripts/billing-token.php /absolute/private/billing.token\n"); exit(64); }
$path = $argv[1];
if (is_link($path) || file_exists($path)) { fwrite(STDERR, "Refusing to replace an existing or symbolic-link billing token.\n"); exit(1); }
$directory = dirname($path);
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { fwrite(STDERR, "Could not create the billing-token directory.\n"); exit(1); }
$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$private = realpath($directory); $webRoot = realpath(__DIR__ . '/..');
if ($private === false || is_link($directory) || !is_writable($private) || (is_string($webRoot) && ($private === $webRoot || str_starts_with($private, rtrim($webRoot, '/') . '/')))) { fwrite(STDERR, "Could not write the billing token.\n"); exit(1); }
$path = $private . '/' . basename($path);
$previous = umask(0077); $handle = @fopen($path, 'x'); umask($previous);
if ($handle === false) { fwrite(STDERR, "Could not write the billing token.\n"); exit(1); }
try {
    if (fwrite($handle, $token . "\n") === false || !fflush($handle)) throw new RuntimeException();
    chmod($path, 0600);
} catch (Throwable) { fclose($handle); @unlink($path); fwrite(STDERR, "Could not write the billing token.\n"); exit(1); }
fclose($handle);
fwrite(STDOUT, "Created billing-control token at {$path} (0600). Give it only to the commercial billing service.\n");
