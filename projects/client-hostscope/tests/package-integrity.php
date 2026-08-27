<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/package-integrity.php';
require_once __DIR__ . '/../lib/package-submission.php';
require_once __DIR__ . '/../plugins/hostscope-key-custody/src/KeyCustodyHttpClient.php';
require_once __DIR__ . '/../plugins/hostscope-replica-relay/src/ReplicaRelayHttpClient.php';
require_once __DIR__ . '/../plugins/sqlcipher-encrypted-sqlite/src/SqlcipherStorageProvider.php';
function packageExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
if (!function_exists('sodium_crypto_sign_keypair')) { echo "package integrity checks skipped: sodium is unavailable\n"; exit(0); }
$root = sys_get_temp_dir() . '/hostscope-package-' . bin2hex(random_bytes(4));
mkdir($root . '/src', 0700, true);
file_put_contents($root . '/src/provider.php', "<?php return 'safe';\n");
$manifest = ['id' => 'test-package', 'version' => '1.0.0', 'name' => 'Test package'];
try {
    $sqlcipher = new \HostScope\Plugin\SqlcipherEncryptedSqlite\SqlcipherStorageProvider();
    $sqlcipherCapability = $sqlcipher->capability();
    packageExpect($sqlcipherCapability['id'] === 'sqlcipher-encrypted-sqlite', 'SQLCipher provider identity is invalid');
    if ($sqlcipherCapability['state'] === 'unavailable') {
        packageExpect(str_contains($sqlcipherCapability['reason'], 'plaintext SQLite'), 'SQLCipher provider does not fail closed on a normal SQLite driver');
    }
    try { $sqlcipher->open($root . '/sqlcipher.db', 'short'); throw new RuntimeException('SQLCipher provider accepted an invalid key'); }
    catch (InvalidArgumentException|RuntimeException $error) { packageExpect($error->getMessage() !== 'SQLCipher provider accepted an invalid key', $error->getMessage()); }
    $custodyMetadata = \HostScope\Plugin\KeyCustody\KeyCustodyHttpClient::requestMetadata();
    packageExpect(ctype_digit($custodyMetadata['timestamp']) && preg_match('/^[A-Za-z0-9_-]{16,}$/', $custodyMetadata['nonce']) === 1,
        'custody client request metadata is not replay-safe');
    $custodyPair = sodium_crypto_sign_keypair(); $custodySecret = sodium_crypto_sign_secretkey($custodyPair);
    $custodyPayload = \HostScope\Plugin\KeyCustody\KeyCustodyHttpClient::requestPayload('POST', '/v1/keys/example/envelopes', $custodyMetadata['timestamp'], $custodyMetadata['nonce'], '{}');
    $custodySignature = sodium_crypto_sign_detached($custodyPayload, $custodySecret);
    packageExpect(sodium_crypto_sign_verify_detached($custodySignature, $custodyPayload, sodium_crypto_sign_publickey($custodyPair)),
        'custody request signature does not verify');
    try { new \HostScope\Plugin\KeyCustody\KeyCustodyHttpClient('https://user@example.test', 'test-token', base64_encode($custodySecret)); throw new RuntimeException('custody client accepted an ambiguous endpoint'); }
    catch (InvalidArgumentException $error) { packageExpect($error->getMessage() !== 'custody client accepted an ambiguous endpoint', $error->getMessage()); }
    $custodySource = file_get_contents(__DIR__ . '/../plugins/hostscope-key-custody/src/KeyCustodyHttpClient.php');
    packageExpect($custodySource !== false && str_contains($custodySource, 'int $requestedTtl = 0')
        && str_contains($custodySource, "'requested_ttl_seconds' => \$requestedTtl")
        && str_contains($custodySource, 'CURLOPT_SSL_VERIFYPEER => true')
        && str_contains($custodySource, 'CURLOPT_SSL_VERIFYHOST => 2'), 'custody client does not enforce its secure transport defaults');
    $relayPair = sodium_crypto_sign_keypair(); $relaySecret = sodium_crypto_sign_secretkey($relayPair);
    try { new \HostScope\Plugin\ReplicaRelay\ReplicaRelayHttpClient('https://user@example.test', 'test-token', base64_encode($relaySecret)); throw new RuntimeException('relay client accepted an ambiguous endpoint'); }
    catch (InvalidArgumentException $error) { packageExpect($error->getMessage() !== 'relay client accepted an ambiguous endpoint', $error->getMessage()); }
    $relaySource = file_get_contents(__DIR__ . '/../plugins/hostscope-replica-relay/src/ReplicaRelayHttpClient.php');
    packageExpect($relaySource !== false && str_contains($relaySource, 'CURLOPT_UPLOAD => true')
        && str_contains($relaySource, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($relaySource, 'CURLOPT_SSL_VERIFYHOST => 2'),
        'relay client does not stream ciphertext with secure transport defaults');
    $relayDirectory = __DIR__ . '/../plugins/hostscope-replica-relay';
    $relayManifest = json_decode((string)file_get_contents($relayDirectory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    packageExpect(is_array($relayManifest) && packageVerifyIntegrity($relayManifest, $relayDirectory)['ok'],
        'replica relay package manifest does not cover its installed files');
    $pair = sodium_crypto_sign_keypair();
    $signed = packageSignManifest($manifest, $root, 'test-publisher', sodium_crypto_sign_secretkey($pair));
    packageExpect(packageVerifyIntegrity($signed, $root)['ok'], 'fresh package integrity did not verify');
    packageExpect(packageVerifySignature($signed, ['test-publisher' => ['public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]])['ok'],
        'fresh package signature did not verify');
    file_put_contents($root . '/manifest.json', json_encode($signed, JSON_THROW_ON_ERROR));
    if (class_exists('ZipArchive')) {
        $bundle = packageCreateReviewBundle($root, $root . '/review.zip', base64_encode(sodium_crypto_sign_publickey($pair)));
        packageExpect($bundle['files'] === 1 && is_file($root . '/review.zip'), 'review bundle was not created');
        @unlink($root . '/review.zip');
    }
    file_put_contents($root . '/src/provider.php', "<?php return 'changed';\n");
    packageExpect(!packageVerifyIntegrity($signed, $root)['ok'], 'changed package file was accepted');
    echo "package integrity checks passed\n";
} finally {
    @unlink($root . '/manifest.json'); @unlink($root . '/src/provider.php'); @rmdir($root . '/src'); @rmdir($root);
}
