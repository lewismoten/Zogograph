<?php
declare(strict_types=1);

/*
 * Who is asking.
 *
 * There are exactly two answers - signed in or not - because there is exactly
 * one person this is for. A signed-out visitor still gets every number: sizes,
 * counts, trends, alerts. What signing in adds is the names, and the ability to
 * delete things.
 *
 * That split is the point. The numbers are what make the dashboard worth
 * leaving open on a second monitor; the names are what make it worth protecting.
 */

function startSession(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => dirname($_SERVER['SCRIPT_NAME'] ?? '/'),
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Strict',
    ]);
    session_name('diskmon');
    session_start();

    // Idle timeout, checked on every request rather than left to the cookie, so
    // closing the laptop does not leave an unmasked session open for a week.
    $lifetime = (int)($config['session_lifetime'] ?? 43200);
    $last = (int)($_SESSION['seen'] ?? 0);
    if ($last > 0 && time() - $last > $lifetime) {
        signOut();
        session_start();
    }
    $_SESSION['seen'] = time();
}

function isSignedIn(): bool
{
    return !empty($_SESSION['signed_in']);
}

/**
 * Checks a password.
 *
 * Deliberately slow to fail: without the sleep, the difference between "no
 * password is configured", "wrong password" and "right password" is measurable,
 * and password_verify against an empty hash returns instantly.
 */
function signIn(array $config, string $password): bool
{
    $hash = (string)($config['password_hash'] ?? '');
    if ($hash === '' || $password === '') {
        usleep(random_int(150000, 350000));
        return false;
    }

    if (!password_verify($password, $hash)) {
        usleep(random_int(150000, 350000));
        return false;
    }

    // A new id for the authenticated session, so a token someone else already
    // has cannot become a signed-in one.
    session_regenerate_id(true);
    $_SESSION['signed_in'] = true;
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

function signOut(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function csrfValid(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], $token);
}
