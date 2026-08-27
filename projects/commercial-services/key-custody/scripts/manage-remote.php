#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This management command is CLI-only.\n"); }

function custodyManageUsage(): never
{
    fwrite(STDERR, "Usage: php scripts/manage-remote.php --base-url https://custody.example/v1 [--token-file /secure/management.token] [--tls-spki-pin sha256//...] COMMAND [ARGUMENTS]\n\n"
        . "Commands:\n  health\n  keys\n  create-key NAME TTL_SECONDS\n  update-policy KEY_ID TTL_SECONDS [NAME]\n  rotate-key KEY_ID\n  disable-key KEY_ID\n  retire-key KEY_ID\n  envelopes KEY_ID\n  delete-envelope KEY_ID ARTIFACT_ID --confirm-recovery\n  clients\n  create-client LABEL\n  revoke-client CREDENTIAL_ID\n  rotate-client-credentials\n  rotate-management-credentials\n  audit [LIMIT]\n  subscription\n  request-plan PLAN\n  request-cancel\n  withdraw-request REQUEST_ID\n  billing-portal\n  checkout\n");
    exit(64);
}

function custodyManageToken(string $path): string
{
    if (!str_starts_with($path, '/')) throw new InvalidArgumentException('management token file must be an absolute, non-link private file (0600)');
    $directory = dirname($path);
    if (is_link($directory) || !is_dir($directory)) {
        throw new InvalidArgumentException('management token directory must be an absolute, non-link private directory');
    }
    $real = realpath($directory);
    $path = is_string($real) ? $real . '/' . basename($path) : '';
    if ($path === '' || is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0) {
        throw new InvalidArgumentException('management token file must be an absolute, non-link private file (0600)');
    }
    $token = trim((string)file_get_contents($path));
    if (!preg_match('~^tok_[a-f0-9]{20}\.[A-Za-z0-9+/_-]+={0,2}$~', $token)) throw new InvalidArgumentException('management token file is invalid');
    return $token;
}

function custodyManageBaseUrl(string $base): string
{
    $parsed = parse_url($base);
    if (!filter_var($base, FILTER_VALIDATE_URL) || !is_array($parsed) || strtolower((string)($parsed['scheme'] ?? '')) !== 'https'
        || !is_string($parsed['host'] ?? null) || $parsed['host'] === '' || isset($parsed['user']) || isset($parsed['pass'])
        || isset($parsed['query']) || isset($parsed['fragment'])) throw new InvalidArgumentException('management base URL must be an unambiguous HTTPS URL');
    $base = rtrim($base, '/');
    if (!str_ends_with(strtolower((string)(parse_url($base, PHP_URL_PATH) ?? '')), '/v1')) $base .= '/v1';
    return $base;
}

function custodyManageRequest(string $base, ?string $token, ?string $pin, string $method, string $path, ?array $body = null): array
{
    $handle = curl_init($base . $path);
    if ($handle === false) throw new RuntimeException('could not initialize custody management request');
    try {
        $headers = ['Accept: application/json'];
        if ($token !== null) array_unshift($headers, 'Authorization: Bearer ' . $token);
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers];
        if ($pin !== null) $options[CURLOPT_PINNEDPUBLICKEY] = $pin;
        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR); $options[CURLOPT_POSTFIELDS] = $encoded;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($handle, $options); $raw = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (!is_string($raw)) throw new RuntimeException('custody management request failed: ' . curl_error($handle));
        $response = json_decode($raw, true);
        if (!is_array($response)) throw new RuntimeException('custody management service returned invalid JSON');
        if ($status < 200 || $status >= 300) throw new RuntimeException('custody management request was refused: ' . (string)($response['error'] ?? 'HTTP ' . $status));
        return $response;
    } finally { curl_close($handle); }
}

if (!function_exists('curl_init')) { fwrite(STDERR, "cURL is required for remote custody management.\n"); exit(1); }
$options = []; $arguments = [];
for ($index = 1; $index < $argc; $index++) {
    $argument = $argv[$index];
    if ($argument === '--confirm-recovery') { $options['confirm-recovery'] = true; continue; }
    foreach (['base-url', 'token-file', 'tls-spki-pin'] as $name) {
        if ($argument === '--' . $name) {
            if (!isset($argv[++$index])) custodyManageUsage();
            $options[$name] = $argv[$index]; continue 2;
        }
        if (str_starts_with($argument, '--' . $name . '=')) { $options[$name] = substr($argument, strlen($name) + 3); continue 2; }
    }
    if (str_starts_with($argument, '--')) custodyManageUsage();
    $arguments[] = $argument;
}
if (!isset($options['base-url']) || $arguments === []) custodyManageUsage();
try {
    $base = custodyManageBaseUrl((string)$options['base-url']);
    $pin = isset($options['tls-spki-pin']) ? (string)$options['tls-spki-pin'] : null;
    if ($pin !== null && preg_match('~^sha256//[A-Za-z0-9+/]{43}=$~', $pin) !== 1) throw new InvalidArgumentException('TLS public-key pin is invalid');
    $command = array_shift($arguments); $method = 'GET'; $path = ''; $body = null;
    $token = $command === 'health' ? null : (isset($options['token-file']) ? custodyManageToken((string)$options['token-file']) : null);
    if ($command !== 'health' && $token === null) custodyManageUsage();
    if ($command === 'health' && $arguments === []) $path = '/health';
    elseif ($command === 'keys' && $arguments === []) $path = '/keys';
    elseif ($command === 'create-key' && count($arguments) === 2 && ctype_digit($arguments[1])) { $method = 'POST'; $path = '/keys'; $body = ['name' => $arguments[0], 'authorization_ttl_seconds' => (int)$arguments[1]]; }
    elseif ($command === 'update-policy' && (count($arguments) === 2 || count($arguments) === 3) && preg_match('/^key_[a-f0-9]{32}$/', $arguments[0]) && ctype_digit($arguments[1])) { $method = 'PATCH'; $path = '/keys/' . $arguments[0]; $body = ['action' => 'update_policy', 'authorization_ttl_seconds' => (int)$arguments[1]] + (isset($arguments[2]) ? ['name' => $arguments[2]] : []); }
    elseif (in_array($command, ['rotate-key', 'disable-key', 'retire-key'], true) && count($arguments) === 1 && preg_match('/^key_[a-f0-9]{32}$/', $arguments[0])) { $method = 'PATCH'; $path = '/keys/' . $arguments[0]; $body = ['action' => str_replace('-key', '', $command)]; }
    elseif ($command === 'envelopes' && count($arguments) === 1 && preg_match('/^key_[a-f0-9]{32}$/', $arguments[0])) $path = '/keys/' . $arguments[0] . '/envelopes';
    elseif ($command === 'delete-envelope' && count($arguments) === 2 && !empty($options['confirm-recovery']) && preg_match('/^key_[a-f0-9]{32}$/', $arguments[0])) { $method = 'DELETE'; $path = '/keys/' . $arguments[0] . '/envelopes/' . rawurlencode($arguments[1]); $body = ['confirm_recovery' => true]; }
    elseif ($command === 'clients' && $arguments === []) $path = '/client-credentials';
    elseif ($command === 'create-client' && count($arguments) === 1) { $method = 'POST'; $path = '/client-credentials'; $body = ['label' => $arguments[0]]; }
    elseif ($command === 'revoke-client' && count($arguments) === 1 && preg_match('/^tok_[a-f0-9]{20}$/', $arguments[0])) { $method = 'DELETE'; $path = '/client-credentials/' . $arguments[0]; }
    elseif ($command === 'rotate-client-credentials' && $arguments === []) { $method = 'POST'; $path = '/client-credentials/rotate'; $body = []; }
    elseif ($command === 'rotate-management-credentials' && $arguments === []) { $method = 'POST'; $path = '/management-credentials/rotate'; $body = []; }
    elseif ($command === 'audit' && (count($arguments) === 0 || (count($arguments) === 1 && ctype_digit($arguments[0]))) && (!isset($arguments[0]) || (int)$arguments[0] >= 1 && (int)$arguments[0] <= 100)) $path = '/audit' . (isset($arguments[0]) ? '?limit=' . $arguments[0] : '');
    elseif ($command === 'subscription' && $arguments === []) $path = '/subscription';
    elseif ($command === 'request-plan' && count($arguments) === 1) { $method = 'PATCH'; $path = '/subscription'; $body = ['action' => 'change_plan', 'plan' => $arguments[0]]; }
    elseif ($command === 'request-cancel' && $arguments === []) { $method = 'PATCH'; $path = '/subscription'; $body = ['action' => 'cancel']; }
    elseif ($command === 'withdraw-request' && count($arguments) === 1 && preg_match('/^subreq_[a-f0-9]{24}$/', $arguments[0])) { $method = 'DELETE'; $path = '/subscription/requests/' . $arguments[0]; }
    elseif (in_array($command, ['billing-portal', 'checkout'], true) && $arguments === []) { $method = 'POST'; $path = '/billing/' . ($command === 'billing-portal' ? 'portal' : 'checkout'); $body = []; }
    else custodyManageUsage();
    fwrite(STDOUT, json_encode(custodyManageRequest($base, $token, $pin, $method, $path, $body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) { fwrite(STDERR, 'Remote custody management failed: ' . $error->getMessage() . "\n"); exit(1); }
