<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/package-integrity.php';
function packageExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
if (!function_exists('sodium_crypto_sign_keypair')) { echo "package integrity checks skipped: sodium is unavailable\n"; exit(0); }
$root = sys_get_temp_dir() . '/hostscope-package-' . bin2hex(random_bytes(4));
mkdir($root . '/src', 0700, true);
file_put_contents($root . '/src/provider.php', "<?php return 'safe';\n");
$manifest = ['id' => 'test-package', 'version' => '1.0.0', 'name' => 'Test package'];
try {
    $pair = sodium_crypto_sign_keypair();
    $signed = packageSignManifest($manifest, $root, 'test-publisher', sodium_crypto_sign_secretkey($pair));
    packageExpect(packageVerifyIntegrity($signed, $root)['ok'], 'fresh package integrity did not verify');
    packageExpect(packageVerifySignature($signed, ['test-publisher' => ['public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]])['ok'],
        'fresh package signature did not verify');
    file_put_contents($root . '/src/provider.php', "<?php return 'changed';\n");
    packageExpect(!packageVerifyIntegrity($signed, $root)['ok'], 'changed package file was accepted');
    echo "package integrity checks passed\n";
} finally {
    @unlink($root . '/src/provider.php'); @rmdir($root . '/src'); @rmdir($root);
}
