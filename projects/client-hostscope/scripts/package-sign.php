#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This publisher command is CLI-only.\n"); }
require_once __DIR__ . '/../lib/package-integrity.php';

function packageSignUsage(): never
{
    fwrite(STDERR, "Usage:\n"
        . "  php scripts/package-sign.php keygen --key-id ID --publisher NAME\n"
        . "  php scripts/package-sign.php sign --package /secure/package-directory --key-id ID [--stdin]\n");
    exit(64);
}

function packageSignSecret(bool $stdin): string
{
    if ($stdin) return trim((string)stream_get_contents(STDIN));
    fwrite(STDERR, 'Base64 Ed25519 private key (input will be visible): ');
    return trim((string)fgets(STDIN));
}

if (!function_exists('sodium_crypto_sign_keypair')) { fwrite(STDERR, "PHP sodium support is required for package signing.\n"); exit(1); }
$command = $argv[1] ?? '';
$options = [];
for ($index = 2; $index < $argc; $index++) {
    $option = $argv[$index];
    if ($option === '--stdin') { $options['--stdin'] = true; continue; }
    if (!in_array($option, ['--key-id', '--publisher', '--package'], true) || ++$index >= $argc || isset($options[$option])) packageSignUsage();
    $options[$option] = $argv[$index];
}
if ($command === 'keygen') {
    $keyId = trim((string)($options['--key-id'] ?? ''));
    $publisher = trim((string)($options['--publisher'] ?? ''));
    if (isset($options['--stdin']) || !preg_match('/^[a-z][a-z0-9-]{1,62}$/', $keyId) || $publisher === '') packageSignUsage();
    $pair = sodium_crypto_sign_keypair();
    fwrite(STDOUT, "Configure this publisher key in HostScope package trust settings:\n"
        . json_encode(['key_id' => $keyId, 'publisher' => $publisher, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))], JSON_PRETTY_PRINT) . "\n\n"
        . "Private signing key — store offline; HostScope will not retain it:\n" . base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
    exit(0);
}
if ($command !== 'sign' || !isset($options['--package'], $options['--key-id'])) packageSignUsage();
try {
    $directory = realpath((string)$options['--package']);
    if ($directory === false || !is_file($directory . '/manifest.json')) throw new InvalidArgumentException('package manifest is unavailable');
    $manifest = json_decode((string)file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) throw new InvalidArgumentException('package manifest is not an object');
    $secret = base64_decode(packageSignSecret(!empty($options['--stdin'])), true);
    if ($secret === false) throw new InvalidArgumentException('private key is invalid');
    fwrite(STDOUT, json_encode(packageSignManifest($manifest, $directory, (string)$options['--key-id'], $secret),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) { fwrite(STDERR, 'Signing refused: ' . $error->getMessage() . "\n"); exit(1); }
