#!/usr/bin/env php
<?php
declare(strict_types=1);

/* This worker is for a separately deployed OS/container sandbox only. It
 * exposes a deliberately harmless first operation: provider capability facts.
 * It receives no HostScope config, secrets, core database handles, or private
 * package database path. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI-only worker.\n"); }
if (getenv('HOSTSCOPE_PACKAGE_SANDBOX') !== '1') { fwrite(STDERR, "Refusing to run outside an attested package sandbox.\n"); exit(77); }
if ($argc !== 3 || $argv[1] !== '--package-root') { fwrite(STDERR, "Usage: package-runtime-worker.php --package-root /sandbox/read-only/package\n"); exit(64); }
$root = realpath($argv[2]);
if ($root === false || is_link($argv[2]) || !is_file($root . '/manifest.json')) { fwrite(STDERR, "Package root is unavailable.\n"); exit(1); }
$input = stream_get_contents(STDIN, 65537);
if (!is_string($input) || strlen($input) > 65536) { fwrite(STDERR, "Worker request is too large.\n"); exit(1); }
try {
    $request = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    $manifest = json_decode((string)file_get_contents($root . '/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($request) || ($request['api'] ?? null) !== '1.0' || ($request['operation'] ?? null) !== 'capability'
        || !is_array($manifest) || ($request['package_id'] ?? null) !== ($manifest['id'] ?? null)) throw new RuntimeException('Worker request is invalid.');
    $runtime = (array)($manifest['runtime'] ?? []); $entrypoint = (string)($runtime['entrypoint'] ?? ''); $class = (string)($runtime['provider_class'] ?? '');
    if ($entrypoint === '' || $class === '' || str_contains($entrypoint, '..') || str_starts_with($entrypoint, '/')) throw new RuntimeException('Package runtime declaration is invalid.');
    $entry = realpath($root . '/' . $entrypoint);
    if ($entry === false || is_link($root . '/' . $entrypoint) || !str_starts_with($entry, $root . '/') || !is_file($entry)) throw new RuntimeException('Package entry point is unavailable.');
    require $entry;
    if (!class_exists($class)) throw new RuntimeException('Package provider class is unavailable.');
    $result = (new $class())->capability();
    if (!is_array($result) || !is_string($result['id'] ?? null) || !is_string($result['state'] ?? null) || !is_string($result['reason'] ?? null)
        || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 4096) throw new RuntimeException('Package capability response is invalid.');
    fwrite(STDOUT, json_encode(['api' => '1.0', 'package_id' => $manifest['id'], 'capability' => $result], JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) { fwrite(STDERR, 'Worker refused: ' . $error->getMessage() . "\n"); exit(1); }
