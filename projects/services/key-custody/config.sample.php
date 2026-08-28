<?php
declare(strict_types=1);

return [
    // Private service data directory, outside the deployed web root (0700 is created automatically).
    'database' => '/var/lib/zogograph-key-custody/key-custody.db',
    // Absolute 0600 file outside the web root. Create 32 random bytes, Base64 encoded.
    'master_key_file' => '/secure/zogograph-key-custody/master.key',
    // Development may set this false only on loopback. Production must use HTTPS.
    'require_https' => true,
    // Optional HTTPS account portal/checkout base managed by the commercial site.
    'billing_portal_url' => '',
    // Optional private 0600 token file for the commercial billing control plane.
    // It authorizes subscription entitlement updates only; it cannot unwrap keys.
    'billing_service_token_file' => '',
];
