<?php
declare(strict_types=1);

/* Trusted Hostscope peers are configured locally, never discovered from a
 * browser-supplied address.  A peer is only a saved OIDC relying-party record
 * for now; exchanging tokens and rendering remote measurements comes after the
 * authorization-code callback and token validation exist. */

function peerDefinitions(array $config): array
{
    $rows = [];
    foreach ((array)($config['peers'] ?? []) as $name => $peer) {
        if (!is_string($name) || !is_array($peer)) {
            continue;
        }
        $rows[] = [
            'name' => $name,
            'label' => (string)($peer['label'] ?? $name),
            'issuer' => (string)($peer['issuer'] ?? ''),
            'base_url' => (string)($peer['base_url'] ?? ''),
            'redirect_uri' => (string)($peer['redirect_uri'] ?? ''),
            'client_id' => (string)($peer['client_id'] ?? ''),
            'has_secret' => trim((string)($peer['client_secret'] ?? '')) !== '',
        ];
    }
    return $rows;
}

function peerUrl(string $url): string
{
    $url = rtrim(trim($url), '/');
    $parts = parse_url($url);
    if ($url === '' || $parts === false || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new ActionRefused('a peer address must be a plain HTTPS URL');
    }
    return $url;
}

/** Read discovery over TLS and require its issuer to match exactly. */
function peerTest(array $peer): array
{
    $issuer = peerUrl((string)($peer['issuer'] ?? ''));
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL is not available on this host.'];
    }
    $curl = curl_init($issuer . '/.well-known/openid-configuration');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $status !== 200) {
        return ['ok' => false, 'error' => $error !== '' ? $error : 'discovery returned HTTP ' . $status];
    }
    $discovery = json_decode($body, true);
    if (!is_array($discovery) || !hash_equals($issuer, (string)($discovery['issuer'] ?? ''))
        || !is_string($discovery['authorization_endpoint'] ?? null)
        || !is_string($discovery['token_endpoint'] ?? null)
        || !is_string($discovery['jwks_uri'] ?? null)) {
        return ['ok' => false, 'error' => 'discovery is incomplete or its issuer does not match'];
    }
    return ['ok' => true, 'issuer' => $issuer,
        'authorization_endpoint' => $discovery['authorization_endpoint'],
        'token_endpoint' => $discovery['token_endpoint'], 'jwks_uri' => $discovery['jwks_uri']];
}

function peerDecodeBase64Url(string $value): string|false
{
    $pad = strlen($value) % 4;
    return base64_decode(strtr($value . ($pad ? str_repeat('=', 4 - $pad) : ''), '-_', '+/'), true);
}

function peerGetJson(string $url, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        throw new ActionRefused('PHP cURL is not available on this host.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $status !== 200) {
        throw new ActionRefused($error !== '' ? $error : 'remote server returned HTTP ' . $status);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new ActionRefused('remote server did not return JSON');
    }
    return $decoded;
}

function peerPostFormJson(string $url, array $fields): array
{
    if (!function_exists('curl_init')) throw new ActionRefused('PHP cURL is not available on this host.');
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if ($body === false || !is_array($decoded) || $status < 200 || $status >= 300) {
        throw new ActionRefused($error !== '' ? $error : (string)($decoded['error'] ?? 'remote token request failed'));
    }
    return $decoded;
}

function peerStartLogin(array $config, string $name): never
{
    $peer = $config['peers'][$name] ?? null;
    if (!is_array($peer)) {
        throw new ActionRefused('unknown trusted server');
    }
    if (trim((string)($peer['client_secret'] ?? '')) === '') {
        throw new ActionRefused('this peer needs a confidential client secret before it can connect');
    }
    $test = peerTest($peer);
    if (empty($test['ok'])) {
        throw new ActionRefused((string)($test['error'] ?? 'peer discovery failed'));
    }
    $redirect = (string)($peer['redirect_uri'] ?? '');
    if ($redirect === '' || !str_starts_with($redirect, 'https://')) {
        throw new ActionRefused('this peer needs its registered HTTPS redirect URI');
    }
    $state = openIdBase64Url(random_bytes(24));
    $verifier = openIdBase64Url(random_bytes(48));
    $nonce = openIdBase64Url(random_bytes(24));
    $_SESSION['peer_oauth'][$state] = [
        'name' => $name, 'verifier' => $verifier, 'nonce' => $nonce,
        'expires_at' => time() + 300, 'authorization_endpoint' => $test['authorization_endpoint'],
        'token_endpoint' => $test['token_endpoint'], 'jwks_uri' => $test['jwks_uri'],
    ];
    $params = [
        'response_type' => 'code', 'client_id' => (string)$peer['client_id'], 'redirect_uri' => $redirect,
        'scope' => 'openid profile groups', 'state' => $state, 'nonce' => $nonce,
        'code_challenge_method' => 'S256', 'code_challenge' => openIdBase64Url(hash('sha256', $verifier, true)),
    ];
    header('Location: ' . $test['authorization_endpoint'] . (str_contains($test['authorization_endpoint'], '?') ? '&' : '?')
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 302);
    exit;
}

function peerJwkPublicKey(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
        return null;
    }
    $n = peerDecodeBase64Url($jwk['n']);
    $e = peerDecodeBase64Url($jwk['e']);
    if ($n === false || $e === false) return null;
    $length = static function (int $size): string {
        if ($size < 128) return chr($size);
        if ($size <= 255) return "\x81" . chr($size);
        return "\x82" . pack('n', $size);
    };
    $integer = static function (string $part) use ($length): string {
        if (ord($part[0]) >= 128) $part = "\0" . $part;
        return "\x02" . $length(strlen($part)) . $part;
    };
    $sequence = static function (string $part) use ($length): string {
        return "\x30" . $length(strlen($part)) . $part;
    };
    $rsa = $sequence($integer($n) . $integer($e));
    $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = $sequence($algorithm . "\x03" . $length(strlen($rsa) + 1) . "\0" . $rsa);
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function peerVerifyToken(string $token, array $flow, array $peer): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new ActionRefused('remote ID token is not a JWT');
    [$head, $body, $signature] = array_map('peerDecodeBase64Url', $parts);
    $header = $head === false ? null : json_decode($head, true);
    $claims = $body === false ? null : json_decode($body, true);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || $signature === false) {
        throw new ActionRefused('remote ID token has an unsupported format');
    }
    $keys = peerGetJson((string)$flow['jwks_uri'])['keys'] ?? [];
    $jwk = null;
    foreach ($keys as $candidate) if (is_array($candidate) && hash_equals((string)($candidate['kid'] ?? ''), (string)($header['kid'] ?? ''))) $jwk = $candidate;
    $public = is_array($jwk) ? peerJwkPublicKey($jwk) : null;
    if ($public === null || openssl_verify($parts[0] . '.' . $parts[1], $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
        throw new ActionRefused('remote ID token signature did not verify');
    }
    $aud = $claims['aud'] ?? null;
    $audiences = is_array($aud) ? $aud : [$aud];
    if (!hash_equals((string)$peer['issuer'], (string)($claims['iss'] ?? ''))
        || !in_array((string)$peer['client_id'], $audiences, true)
        || !hash_equals((string)$flow['nonce'], (string)($claims['nonce'] ?? ''))
        || (int)($claims['exp'] ?? 0) < time() || !is_string($claims['sub'] ?? null)) {
        throw new ActionRefused('remote ID token claims did not match this login');
    }
    return $claims;
}

function peerRemoteSnapshot(array $config, string $name): array
{
    $peer = $config['peers'][$name] ?? null;
    $token = $_SESSION['peer_tokens'][$name] ?? null;
    if (!is_array($peer) || !is_array($token) || (int)($token['expires_at'] ?? 0) < time()) {
        throw new ActionRefused('connect this trusted server before reading its data');
    }
    return peerGetJson(rtrim((string)$peer['base_url'], '/') . '/peer-data.php', [
        'Authorization: Bearer ' . (string)$token['id_token'],
    ]);
}
