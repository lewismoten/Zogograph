<?php
declare(strict_types=1);

return [
    // Keep this SQLite database outside the document root or deny it at the web server.
    'database' => __DIR__ . '/data/key-custody.db',
    // Absolute 0600 file outside the web root. Create 32 random bytes, Base64 encoded.
    'master_key_file' => '/secure/hostscope-key-custody/master.key',
    // Development may set this false only on loopback. Production must use HTTPS.
    'require_https' => true,
    // Optional HTTPS account portal/checkout base managed by the commercial site.
    'billing_portal_url' => '',
];
