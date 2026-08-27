<?php
declare(strict_types=1);

/* Apache's optional modules are runtime concerns; the required policy is not. */

$policy = file_get_contents(__DIR__ . '/../.htaccess');
if ($policy === false) {
    throw new RuntimeException('could not read Apache policy');
}

foreach ([
    "Content-Security-Policy \"default-src 'self'",
    'X-Content-Type-Options "nosniff"',
    'X-Frame-Options "DENY"',
    'Referrer-Policy "no-referrer"',
    'Permissions-Policy "camera=(), geolocation=(), microphone=(), payment=(), usb=()"',
    '<FilesMatch "\\.(db|db-journal|db-wal|db-shm|sqlite|sqlite3)$">',
    '<Files "cron.php">',
    '<Files "host-cron.php">',
] as $required) {
    if (!str_contains($policy, $required)) {
        throw new RuntimeException('missing required Apache protection: ' . $required);
    }
}

$nginx = file_get_contents(__DIR__ . '/../deploy/nginx.conf.example');
if ($nginx === false) {
    throw new RuntimeException('could not read Nginx deployment policy');
}
foreach ([
    'location ~* \\.(?:db|db-journal|db-wal|db-shm|sqlite|sqlite3)$ { deny all; }',
    'location ~ ^/(?:data|lib)(?:/|$) { deny all; }',
    'location ~ ^/(?:cron|host-cron)\\.php$ { deny all; }',
    "frame-ancestors 'none'",
    'X-Content-Type-Options "nosniff"',
    'X-Frame-Options "DENY"',
    'Referrer-Policy "no-referrer"',
    'Cache-Control "no-store"',
] as $required) {
    if (!str_contains($nginx, $required)) {
        throw new RuntimeException('missing Nginx protection: ' . $required);
    }
}

$diagnosticExport = file_get_contents(__DIR__ . '/../diagnostic-export.php');
if ($diagnosticExport === false) {
    throw new RuntimeException('could not read diagnostic export endpoint');
}
foreach ([
    "(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'",
    'isSignedIn()',
    "accessAllows(\$config, 'pages', 'system')",
    "csrfValid(\$_POST['csrf'] ?? null)",
    "logAction(\$pdo, 'diagnostic_export'",
    "'format' => 'hostscope-diagnostic-v1'",
    "'no credentials, paths, or hostnames'",
] as $required) {
    if (!str_contains($diagnosticExport, $required)) {
        throw new RuntimeException('missing diagnostic export safeguard: ' . $required);
    }
}

$auditExport = file_get_contents(__DIR__ . '/../audit-export.php');
if ($auditExport === false) {
    throw new RuntimeException('could not read audit export endpoint');
}
foreach ([
    "(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'",
    'isSignedIn()',
    "accessAllows(\$config, 'pages', 'deletions')",
    "csrfValid(\$_POST['csrf'] ?? null)",
    "logAction(\$pdo, 'audit_export'",
    "preg_match('/^[=+\\-@]/', \$text)",
    'ORDER BY at ASC',
] as $required) {
    if (!str_contains($auditExport, $required)) {
        throw new RuntimeException('missing audit export safeguard: ' . $required);
    }
}

$export = file_get_contents(__DIR__ . '/../database-export.php');
if ($export === false) {
    throw new RuntimeException('could not read database export endpoint');
}
foreach ([
    '($_SERVER[\'REQUEST_METHOD\'] ?? \'\') !== \'POST\'',
    'isSignedIn()',
    'accessAllows($config, \'write\')',
    'csrfValid($_POST[\'csrf\'] ?? null)',
    'snapshotDatabase($pdo, $temporary)',
    'logAction($pdo, \'database_export\'',
    '@unlink($temporary)',
] as $required) {
    if (!str_contains($export, $required)) {
        throw new RuntimeException('missing database export safeguard: ' . $required);
    }
}

$restore = file_get_contents(__DIR__ . '/../scripts/restore-account.php');
if ($restore === false) {
    throw new RuntimeException('could not read account restore command');
}
foreach ([
    "PHP_SAPI !== 'cli'",
    "'--writers-stopped'",
    'databaseFullIntegrity($candidate)',
    "name = 'vitals'",
    'snapshotDatabase($live, $recovery)',
    '@rename($temporary, $target)',
    '@unlink($target . \'-wal\')',
] as $required) {
    if (!str_contains($restore, $required)) {
        throw new RuntimeException('missing account restore safeguard: ' . $required);
    }
}

$api = file_get_contents(__DIR__ . '/../api.php');
if ($api === false || !str_contains($api, "'peer-transport-rotate' => doPeerTransportRotate")) {
    throw new RuntimeException('remote delivery rotation route is missing');
}
foreach ([
    'function doPeerTransportRotate',
    'requireWrite($post)',
    '$peer[\'transport_secret\'] = $credential;',
    "'peer_transport_rotate'",
] as $required) {
    if (!str_contains($api, $required)) {
        throw new RuntimeException('missing peer credential rotation safeguard: ' . $required);
    }
}

echo "HTTP protection policy checks passed\n";
