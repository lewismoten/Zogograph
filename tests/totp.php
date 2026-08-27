<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

function totpExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // RFC 6238 SHA-1 test key
totpExpect(totpValid($secret, '287082', 59), 'RFC 6238 TOTP vector was rejected');
totpExpect(!totpValid($secret, '287083', 59), 'incorrect TOTP code was accepted');
totpExpect(totpValid($secret, '287082', 89), 'one permitted TOTP clock step was rejected');
totpExpect(!totpValid($secret, '287082', 120), 'stale TOTP code was accepted');
totpExpect(totpBase32Decode('not-a-secret!') === false, 'invalid Base32 secret was accepted');
totpExpect(signInTotpSecret(['auth' => ['totp_secret' => 'legacy']], null) === 'legacy',
    'legacy TOTP secret was not selected');
totpExpect(signInTotpSecret(['auth' => ['totp_secret' => 'legacy']], ['totp_secret' => 'named']) === 'named',
    'named-user TOTP secret was not selected');

echo "TOTP checks passed\n";
