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

    /*
     * A new id for the authenticated session, so a token someone else already
     * has cannot become a signed-in one.
     *
     * Not deleting the old session file, which is the argument to look at
     * twice. Keeping it is not a fixation hole: what stays behind is the
     * session as it was a moment ago, which is an anonymous one - `signed_in`
     * is written below, to the new id, and never to the old. A planted id
     * therefore stays worth nothing.
     *
     * What deleting it would risk is the sign-out fault described above, in a
     * second form. On a host with session.use_strict_mode on, a request that
     * left before this line arrives holding an id whose file has just been
     * removed; PHP rejects unknown ids under strict mode, mints a new one, and
     * sends a Set-Cookie that lands on top of the authenticated cookie. This
     * install cannot set that ini, so it defends against it instead. Unlike the
     * sign-out fault this one has not been observed here - strict mode is off
     * on the machine it was tested on - so it is a precaution, not a fix.
     */
    session_regenerate_id(false);
    $_SESSION['signed_in'] = true;
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

/**
 * Empties the session, and leaves it there.
 *
 * The obvious version deleted the cookie and destroyed the session, and it
 * caused a fault that took a packet log to see. This dashboard always has
 * several polls in the air. With the cookie gone, the next one to arrive has no
 * session id at all, so PHP mints a fresh one and sends a Set-Cookie for it -
 * and if somebody has signed back in during those few hundred milliseconds,
 * that anonymous cookie lands on top of their authenticated one. Signing out
 * and straight back in left you looking at a signed-in page that the server
 * considered a stranger, until a reload.
 *
 * Emptying it in place has none of that. Every request in flight finds a
 * session that exists and is anonymous, which is the truth, and no Set-Cookie
 * is sent at all. The id survives sign-out, which is safe: it names an empty
 * session, and signing in regenerates it before writing anything to it.
 */
function signOut(): void
{
    $_SESSION = [];
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
