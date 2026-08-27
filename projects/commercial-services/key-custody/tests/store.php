<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/store.php';
function custodyExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$root = sys_get_temp_dir() . '/hostscope-custody-' . bin2hex(random_bytes(4)); mkdir($root, 0700, true);
try {
    $pdo = keyCustodyOpen(['database' => $root . '/service.db']);
    $tenant = keyCustodyCreateTenant($pdo, 'Example');
    $management = keyCustodyAuthenticate($pdo, $tenant['management_token'], 'management');
    $client = keyCustodyAuthenticate($pdo, $tenant['client_token'], 'client');
    $request = keyCustodyAuthenticateClientRequest($pdo, $tenant['client_token'], (string)time(), 'nonce_' . bin2hex(random_bytes(12)));
    custodyExpect($request['tenant_id'] === $client['tenant_id'], 'replay-protected client request did not authenticate');
    try { keyCustodyAuthenticateClientRequest($pdo, $tenant['client_token'], (string)time(), 'nonce_reused_value_123'); keyCustodyAuthenticateClientRequest($pdo, $tenant['client_token'], (string)time(), 'nonce_reused_value_123'); throw new RuntimeException('reused nonce was accepted'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'reused nonce was accepted', $error->getMessage()); }
    custodyExpect($management['tenant_id'] === $client['tenant_id'], 'tenant tokens do not authenticate to the same tenant');
    $master = random_bytes(32); $key = keyCustodyCreateKey($pdo, $management['tenant_id'], 'primary', 300, $master);
    custodyExpect(keyCustodyListKeys($pdo, $management['tenant_id'])[0]['id'] === $key['id'], 'management key list omitted created key');
    custodyExpect(keyCustodySubscription($pdo, $management['tenant_id'])['state'] === 'active', 'initial subscription state is unavailable');
    $envelope = keyCustodyStoreEnvelope($pdo, $client['tenant_id'], $key['id'], 'account:20260827', random_bytes(80));
    custodyExpect(keyCustodyListEnvelopes($pdo, $management['tenant_id'], $key['id'])[0]['id'] === $envelope['id'], 'envelope metadata was not retained');
    custodyExpect(strlen(keyCustodyUnwrapKey($pdo, $client['tenant_id'], $key['id'], $master)) === 32, 'client unwrap did not recover a custody key');
    keyCustodySetSubscription($pdo, $management['tenant_id'], 'free', 'cancelled', 0, null);
    try { keyCustodyCreateKey($pdo, $management['tenant_id'], 'second', 300, $master); throw new RuntimeException('cancelled subscription created a key'); }
    catch (InvalidArgumentException $error) { custodyExpect($error->getMessage() !== 'cancelled subscription created a key', $error->getMessage()); }
    echo "key custody store checks passed\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $path) @unlink($path);
    @rmdir($root);
}
