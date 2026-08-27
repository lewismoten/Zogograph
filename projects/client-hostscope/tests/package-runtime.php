<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/package-runtime.php';
require_once __DIR__ . '/../lib/configure.php';
function runtimeExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
if (!function_exists('sodium_crypto_sign_keypair')) { echo "package runtime checks skipped: sodium is unavailable\n"; exit(0); }
$root = sys_get_temp_dir() . '/hostscope-runtime-' . bin2hex(random_bytes(4));
$source = $root . '/source';
mkdir($source . '/src', 0700, true);
mkdir($source . '/migrations', 0700, true);
mkdir($root . '/web', 0700, true);
file_put_contents($source . '/src/Provider.php', "<?php declare(strict_types=1);\n");
file_put_contents($source . '/settings.schema.json', "{}\n");
file_put_contents($source . '/migrations/001-private-evidence.sql', "CREATE TABLE private_evidence (value TEXT NOT NULL);\n");
try {
    $pair = sodium_crypto_sign_keypair();
    $trustedKey = ['test-publisher' => ['publisher' => 'Test publisher',
        'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]];
    runtimeExpect(packageRuntimeTrustedKeys($trustedKey) === $trustedKey, 'trusted publisher key was not normalized');
    $fields = array_column(configFields(require __DIR__ . '/../lib/config.sample.php'), null, 'key');
    $field = $fields['package_runtime_trusted_keys'];
    [, $keyProblem] = parseField($field, json_encode($trustedKey, JSON_THROW_ON_ERROR));
    runtimeExpect($keyProblem === null, 'valid trusted publisher key was rejected by setup');
    [, $badKeyProblem] = parseField($field, '{"test-publisher":{"publisher":"Test publisher","public_key":"bad"}}');
    runtimeExpect($badKeyProblem !== null, 'invalid trusted publisher key was accepted by setup');
    [, $secretKeyProblem] = parseField($fields['package_runtime_secret_key_file'], '');
    runtimeExpect($secretKeyProblem === null, 'optional package secret key field rejected an empty value');
    $manifest = [
        'id' => 'test-runtime', 'version' => '1.0.0', 'publisher' => 'Test publisher',
        'capabilities' => ['package_private_database'],
        'runtime' => ['kind' => 'hostscope-isolated-php-package', 'entrypoint' => 'src/Provider.php'],
        'settings_schema' => 'settings.schema.json',
        'data' => ['migrations' => ['migrations/001-private-evidence.sql']],
    ];
    $signed = packageSignManifest($manifest, $source, 'test-publisher', sodium_crypto_sign_secretkey($pair));
    file_put_contents($source . '/manifest.json', json_encode($signed, JSON_THROW_ON_ERROR));
    $keyPath = $root . '/package-secrets.key'; encryptionCreateLocalKey($keyPath, $root . '/web');
    $config = ['database' => $root . '/stats.db', 'encryption' => ['local_key_file' => $keyPath], 'package_runtime' => ['directory' => $root . '/installed', 'trusted_keys' => [
        'test-publisher' => ['publisher' => 'Test publisher', 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))],
    ], 'secret_key_file' => '']];
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    migrate($pdo, true);
    $installed = packageRuntimeInstall($config, $pdo, $source, 'administrator');
    runtimeExpect(is_file($installed['install_path'] . '/src/Provider.php'), 'signed package file was not installed');
    $before = packageRuntimePackages($pdo)[0];
    runtimeExpect(!$before['enabled'] && $before['state'] === 'needs explicit capability grants', 'package activated without a grant');
    try { packageRuntimeSetEnabled($pdo, 'test-runtime', [], true, 'administrator'); throw new RuntimeException('missing grant was accepted'); }
    catch (InvalidArgumentException $error) { runtimeExpect($error->getMessage() !== 'missing grant was accepted', $error->getMessage()); }
    packageRuntimeSetEnabled($pdo, 'test-runtime', ['package_private_database'], true, 'administrator');
    $after = packageRuntimePackages($pdo)[0];
    runtimeExpect($after['enabled'] && $after['state'] === 'registered; execution runtime pending', 'package was not registered after explicit grant');
    $private = packageRuntimePrivateDatabase($pdo, $config, 'test-runtime');
    runtimeExpect((int)$private->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'private_evidence'")->fetchColumn() === 1,
        'signed package private migration was not applied');
    runtimeExpect((int)$private->query('SELECT COUNT(*) FROM package_runtime_migrations')->fetchColumn() === 1,
        'private migration was not recorded');
    packageRuntimePrivateDatabase($pdo, $config, 'test-runtime');
    runtimeExpect((int)$private->query('SELECT COUNT(*) FROM package_runtime_migrations')->fetchColumn() === 1,
        'private migration was applied more than once');
    $reference = packageRuntimeSecretStore($pdo, $config, 'test-runtime', 'remote-client-credential', 'administrator');
    runtimeExpect(str_starts_with($reference, 'secret_ref:test-runtime:'), 'package secret reference is not package-scoped');
    runtimeExpect(packageRuntimeSecretValue($pdo, $config, 'test-runtime', $reference) === 'remote-client-credential', 'encrypted package secret could not be recovered');
    packageRuntimeSaveSettings($pdo, $config, 'test-runtime', ['retention_days' => 30, 'custody_secret' => $reference], 'administrator');
    runtimeExpect(packageRuntimeSettings($pdo, $config, 'test-runtime')['retention_days'] === 30,
        'package SQLite-backed settings were not retained');
    try { packageRuntimeSaveSettings($pdo, $config, 'test-runtime', ['custody_secret' => 'raw-secret'], 'administrator'); throw new RuntimeException('raw package secret was accepted'); }
    catch (InvalidArgumentException $error) { runtimeExpect($error->getMessage() !== 'raw package secret was accepted', $error->getMessage()); }
    try { packageRuntimeSaveSettings($pdo, $config, 'test-runtime', ['custody_secret' => 'secret_ref:another-package:abcdefghijklmnop'], 'administrator'); throw new RuntimeException('another package secret reference was accepted'); }
    catch (InvalidArgumentException $error) { runtimeExpect($error->getMessage() !== 'another package secret reference was accepted', $error->getMessage()); }
    try { packageRuntimeSecretValue($pdo, $config, 'wrong-package', $reference); throw new RuntimeException('another package read a secret'); }
    catch (InvalidArgumentException $error) { runtimeExpect($error->getMessage() !== 'another package read a secret', $error->getMessage()); }
    packageRuntimeSecretDelete($pdo, $config, 'test-runtime', $reference);
    try { packageRuntimeSecretValue($pdo, $config, 'test-runtime', $reference); throw new RuntimeException('deleted package secret was recovered'); }
    catch (InvalidArgumentException $error) { runtimeExpect($error->getMessage() !== 'deleted package secret was recovered', $error->getMessage()); }
    echo "package runtime checks passed\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $path) {
        if (is_dir($path)) packageRuntimeRemoveDirectory($path); else @unlink($path);
    }
    @rmdir($root);
}
