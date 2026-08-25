<?php
declare(strict_types=1);

/*
 * Asking a URL whether it is still working, on a schedule.
 *
 * Everything else here watches the machine from the inside, which cannot answer
 * the only question that matters to whoever is visiting: does the site respond?
 * A server can be healthy by every internal measure while the site returns 500
 * because a config file is wrong, or return 200 with an empty page because a
 * database is down. Nothing in /proc knows.
 *
 * Three things are recorded per check, and each catches a different failure:
 *
 *   the status code   the blunt one - 500, 502, 404, or nothing at all
 *   the response time a site that answers in nine seconds is failing even
 *                     though every request technically succeeds
 *   a value from the body   the subtle one. A page that returns 200 with the
 *                     wrong content is the failure mode nothing else sees, and
 *                     extracting a number from it turns "is it up" into "is it
 *                     right" - a queue depth, a record count, a version.
 *
 * This runs from the account's cron, not root's: it is an outbound HTTP request
 * and needs no privilege. That also means it is checking from where this
 * machine sits, which is worth remembering - it cannot tell you the site is
 * unreachable from elsewhere.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/actions.php';

/** How long to wait for a response before calling it a failure. */
const URLWATCH_TIMEOUT = 15;

/** The largest body this will read, so a huge download cannot stall the cron. */
const URLWATCH_MAX_BYTES = 2 * 1024 * 1024;

/** How many checks one install may have. */
const URLWATCH_MAX = 60;

/**
 * The intervals on offer.
 *
 * A fixed list because each one is a promise about how often the cron can
 * actually deliver: a one-minute check on an account whose cron runs every five
 * is a lie, and the interface says so rather than silently under-delivering.
 *
 * @return array<int, string>
 */
function urlWatchIntervals(): array
{
    return [
        60 => 'every minute',
        300 => 'every 5 minutes',
        600 => 'every 10 minutes',
        1800 => 'every 30 minutes',
        3600 => 'hourly',
        14400 => 'every 4 hours',
        86400 => 'daily',
        604800 => 'weekly',
    ];
}

/**
 * The ways a check can look at a response body.
 *
 * `none` is the common case and is listed rather than implied, because "just
 * check it answers" is a legitimate choice and should not look like a setting
 * somebody forgot to fill in.
 *
 * @return array<string, array{label: string, hint: string, needs_value: bool, extracts: bool}>
 */
function urlWatchChecks(): array
{
    return [
        'none' => [
            'label' => 'Nothing — just the status code',
            'hint' => 'Whether it answers, and how quickly.',
            'needs_value' => false,
            'extracts' => false,
        ],
        'contains' => [
            'label' => 'The body contains this text',
            'hint' => 'The simplest guard against a page that returns 200 and is empty, or '
                . 'has been replaced by a holding page.',
            'needs_value' => true,
            'extracts' => false,
        ],
        'missing' => [
            'label' => 'The body does NOT contain this text',
            'hint' => 'For catching something that should never appear — a stack trace, a '
                . '"database error", the word "suspended".',
            'needs_value' => true,
            'extracts' => false,
        ],
        'regex' => [
            'label' => 'A regular expression matches',
            'hint' => 'If it has a capturing group, whatever the first group matches is '
                . 'recorded as a number and charted over time.',
            'needs_value' => true,
            'extracts' => true,
        ],
        'json' => [
            'label' => 'A value from JSON',
            'hint' => 'A dotted path such as <code>data.queue.depth</code>. Array positions '
                . 'are numbers: <code>items.0.count</code>.',
            'needs_value' => true,
            'extracts' => true,
        ],
        'meta' => [
            'label' => 'An HTML meta tag',
            'hint' => 'The name of a meta tag; its content is recorded. Useful because a page '
                . 'can publish a build number or a record count without showing it.',
            'needs_value' => true,
            'extracts' => true,
        ],
        'xpath' => [
            'label' => 'An XPath expression',
            'hint' => 'For XML and well-formed HTML. The first matching node\'s text is '
                . 'recorded.',
            'needs_value' => true,
            'extracts' => true,
        ],
    ];
}

/**
 * Every check, with when each is next due.
 *
 * @return list<array>
 */
function urlWatchList(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT * FROM url_checks ORDER BY label, url')->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $now = time();
    foreach ($rows as &$row) {
        $row['enabled'] = (bool)$row['enabled'];
        $row['interval_label'] = urlWatchIntervals()[(int)$row['interval_seconds']]
            ?? sprintf('every %d seconds', (int)$row['interval_seconds']);

        $last = (int)($row['last_at'] ?? 0);
        $row['due_at'] = $last === 0 ? $now : $last + (int)$row['interval_seconds'];
        $row['overdue'] = $row['enabled'] && $row['due_at'] < $now - 60;
    }
    unset($row);

    return $rows;
}

/**
 * Runs every check that is due, and returns what happened.
 *
 * Due-ness is decided from the last recorded run rather than from a schedule
 * this process keeps: the cron may not have run for an hour, and when it does
 * the right thing is to catch up once, not to pretend the missed runs happened.
 *
 * @return array{ran: int, failed: int, rows: list<array>}
 */
function urlWatchRunDue(PDO $pdo, ?int $only = null, ?callable $fetcher = null): array
{
    $now = time();
    $ran = 0;
    $failed = 0;
    $rows = [];

    foreach (urlWatchList($pdo) as $check) {
        if ($only !== null) {
            if ((int)$check['id'] !== $only) {
                continue;
            }
        } elseif (!$check['enabled'] || $check['due_at'] > $now) {
            continue;
        }

        $result = urlWatchRunOne($check, $fetcher);
        urlWatchStore($pdo, (int)$check['id'], $result);

        $ran++;
        if (!$result['ok']) {
            $failed++;
        }
        $rows[] = ['id' => (int)$check['id'], 'label' => $check['label']] + $result;
    }

    return ['ran' => $ran, 'failed' => $failed, 'rows' => $rows];
}

/**
 * One request, and everything measured about it.
 *
 * @return array<string, mixed>
 */
function urlWatchRunOne(array $check, ?callable $fetcher = null): array
{
    $started = microtime(true);
    $fetched = $fetcher === null
        ? urlWatchFetch((string)$check['url'], [
            'follow_redirects' => (bool)($check['follow_redirects'] ?? true),
            'method' => (string)($check['method'] ?? 'GET'),
            // Only when asked, and only with something to send: a conditional
            // request with no ETag is just an ordinary one with extra words.
            'etag' => !empty($check['send_etag']) ? ($check['last_etag'] ?? null) : null,
        ])
        : $fetcher((string)$check['url']);

    $result = [
        'at' => time(),
        'status' => $fetched['status'],
        'ms' => (int)round((microtime(true) - $started) * 1000),
        'bytes' => strlen((string)$fetched['body']),
        'value' => null,
        'matched' => null,
        'ok' => false,
        'error' => $fetched['error'],
        'hops' => (int)($fetched['hops'] ?? 0),
        'landed' => $fetched['landed'] ?? null,
        'etag' => $fetched['etag'] ?? null,
        'headers' => $fetched['headers'] ?? '',
    ];

    if ($fetched['error'] !== null) {
        return $result;
    }

    /*
     * The expected status, which is not always 200.
     *
     * A URL that should redirect, or an endpoint that correctly answers 401 to
     * an unauthenticated request, is working exactly as intended - and a monitor
     * that insisted on 200 would report it as broken for ever, which is how a
     * monitor gets ignored.
     */
    $expect = (int)($check['expect_status'] ?? 200);
    $statusOk = $expect === 0
        ? $fetched['status'] >= 200 && $fetched['status'] < 400
        : $fetched['status'] === $expect;

    /*
     * A 304 has no body, so a body rule cannot be applied to it.
     *
     * This is not a failure: a conditional request answered "unchanged" is the
     * server behaving correctly and is often the thing being tested. Running
     * the content rule against an empty body would report it as broken.
     */
    if ($fetched['status'] === 304) {
        $result['ok'] = $statusOk || $expect === 200;
        $result['matched'] = null;
        $result['error'] = $result['ok']
            ? null
            : sprintf('answered 304, expected %d', $expect);
        return $result;
    }

    $inspection = urlWatchInspect(
        (string)($check['check_kind'] ?? 'none'),
        (string)($check['check_value'] ?? ''),
        (string)$fetched['body']
    );

    $result['matched'] = $inspection['matched'];
    $result['value'] = $inspection['value'];
    $result['ok'] = $statusOk && $inspection['matched'] !== false;

    if (!$statusOk) {
        $result['error'] = sprintf(
            'answered %d, expected %s',
            $fetched['status'],
            $expect === 0 ? 'any success' : (string)$expect
        );
    } elseif ($inspection['matched'] === false) {
        $result['error'] = $inspection['why'];
    }

    return $result;
}

/**
 * Looks at a body according to one rule.
 *
 * `matched` is null when the rule is "none" - nothing was asked, so nothing
 * passed or failed. That is deliberately distinct from false: a check with no
 * body rule must not be reported as having satisfied one.
 *
 * @return array{matched: ?bool, value: ?float, why: string}
 */
function urlWatchInspect(string $kind, string $needle, string $body): array
{
    $none = ['matched' => null, 'value' => null, 'why' => ''];

    if ($kind === 'none' || $needle === '') {
        return $none;
    }

    switch ($kind) {
        case 'contains':
            $found = str_contains($body, $needle);
            return ['matched' => $found, 'value' => null,
                    'why' => $found ? '' : 'the body does not contain that text'];

        case 'missing':
            $found = str_contains($body, $needle);
            return ['matched' => !$found, 'value' => null,
                    'why' => $found ? 'the body contains text it should not' : ''];

        case 'regex':
            /*
             * Delimited here rather than by whoever typed it.
             *
             * A pattern from a form arriving with its own delimiters could
             * carry modifiers - `/e` is gone but `/x` and friends still change
             * meaning - and letting the input choose them is letting the input
             * choose the language. The `u` modifier is ours: bodies are text.
             */
            $pattern = '/' . str_replace('/', '\\/', $needle) . '/u';
            $found = @preg_match($pattern, $body, $m);

            if ($found === false) {
                return ['matched' => false, 'value' => null,
                        'why' => 'that is not a valid regular expression'];
            }
            if ($found === 0) {
                return ['matched' => false, 'value' => null, 'why' => 'the pattern did not match'];
            }
            return ['matched' => true, 'value' => urlWatchNumber($m[1] ?? null), 'why' => ''];

        case 'json':
            $data = json_decode($body, true);
            if (!is_array($data)) {
                return ['matched' => false, 'value' => null,
                        'why' => 'the response is not JSON'];
            }
            $found = urlWatchDig($data, $needle);
            if ($found === null) {
                return ['matched' => false, 'value' => null,
                        'why' => 'there is nothing at that path'];
            }
            return ['matched' => true, 'value' => urlWatchNumber($found), 'why' => ''];

        case 'meta':
            $quoted = preg_quote($needle, '/');
            if (preg_match(
                '/<meta[^>]+name\s*=\s*["\']' . $quoted . '["\'][^>]*content\s*=\s*["\']([^"\']*)/i',
                $body,
                $m
            ) || preg_match(
                '/<meta[^>]+content\s*=\s*["\']([^"\']*)["\'][^>]*name\s*=\s*["\']' . $quoted . '["\']/i',
                $body,
                $m
            )) {
                return ['matched' => true, 'value' => urlWatchNumber($m[1]), 'why' => ''];
            }
            return ['matched' => false, 'value' => null, 'why' => 'no meta tag by that name'];

        case 'xpath':
            return urlWatchXpath($body, $needle);
    }

    return $none;
}

/**
 * An XPath lookup, with the parser's complaints kept to itself.
 *
 * Real HTML is full of things libxml objects to, and a monitor that reported
 * every unclosed tag as a failure would report every page as failing. Errors
 * are collected and discarded; only whether a node was found decides the result.
 */
function urlWatchXpath(string $body, string $expression): array
{
    if (!class_exists('DOMDocument')) {
        return ['matched' => false, 'value' => null,
                'why' => 'this PHP has no DOM extension, so XPath cannot be used'];
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();

    $loaded = str_contains(ltrim($body), '<?xml')
        ? @$document->loadXML($body)
        : @$document->loadHTML($body);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return ['matched' => false, 'value' => null, 'why' => 'the response could not be parsed'];
    }

    $xpath = new DOMXPath($document);
    $nodes = @$xpath->query($expression);

    if ($nodes === false) {
        return ['matched' => false, 'value' => null, 'why' => 'that is not a valid XPath expression'];
    }
    if ($nodes->length === 0) {
        return ['matched' => false, 'value' => null, 'why' => 'nothing matched that expression'];
    }

    $text = trim((string)$nodes->item(0)?->textContent);
    return ['matched' => true, 'value' => urlWatchNumber($text), 'why' => ''];
}

/**
 * A dotted path into decoded JSON.
 *
 * Array positions are plain numbers, so `items.0.count` works - which is what
 * anybody writing one of these expects, and avoids inventing a syntax.
 */
function urlWatchDig(array $data, string $path): mixed
{
    $cursor = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            return null;
        }
        $cursor = $cursor[$part];
    }
    return is_scalar($cursor) ? $cursor : null;
}

/**
 * Whatever was extracted, as a number if it can be one.
 *
 * The point of extracting a value is to chart it, and a chart needs numbers.
 * Something that is not one is not an error - a version string is a perfectly
 * good thing to watch for *changes* in - so it is kept as text alongside and
 * only the numeric form is charted.
 */
function urlWatchNumber(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    $text = trim((string)$value);
    // Digit grouping and a trailing unit are common in the things worth
    // watching: "1,240 records", "98%".
    $text = str_replace(',', '', $text);

    return preg_match('/-?\d+(?:\.\d+)?/', $text, $m) ? (float)$m[0] : null;
}

/**
 * Fetches a URL, without following redirects into somewhere else entirely.
 *
 * @return array{status: int, body: string, error: ?string}
 */
function urlWatchFetch(string $url, array $how = []): array
{
    $parts = parse_url($url);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
        return ['status' => 0, 'body' => '', 'error' => 'that is not an http or https URL'];
    }

    $follow = (bool)($how['follow_redirects'] ?? true);
    $method = strtoupper((string)($how['method'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $method = 'GET';
    }

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => URLWATCH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            /*
             * Following redirects is a choice, not a default to hide.
             *
             * Following is right for "is the site up". It is exactly wrong when
             * the redirect is the thing being tested: a check on the plain-HTTP
             * address that follows to HTTPS reports 200 for ever and never
             * notices the day the redirect stops happening.
             */
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Hostscope/1.0 (+uptime check)',
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => true,
            // A monitor that ignored certificate problems would report a site
            // with an expired certificate as perfectly healthy, which is one of
            // the failures it most needs to catch.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        /*
         * A conditional request, when asked for.
         *
         * With the last response's ETag this asks "has it changed", and a well
         * behaved server answers 304 with no body. That is a legitimate and
         * useful thing to check - a page that stops sending 304 is a caching
         * problem - and it is nonsense as a default, because a 304 has no body
         * to run a content rule against.
         */
        if (!empty($how['etag'])) {
            $options[CURLOPT_HTTPHEADER] = ['If-None-Match: ' . $how['etag']];
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $hops = (int)curl_getinfo($handle, CURLINFO_REDIRECT_COUNT);
        $landed = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $headerSize = (int)curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error = curl_errno($handle) === 0 ? null : curl_error($handle);

        /*
         * No curl_close: it has done nothing since PHP 8.0 and is deprecated
         * from 8.5, which would put a notice in the problem log on every single
         * check. The handle is released when it goes out of scope.
         */

        $headers = is_string($raw) ? substr($raw, 0, $headerSize) : '';
        $body = is_string($raw) ? substr($raw, $headerSize) : '';

        return [
            'status' => $status,
            'body' => substr($body, 0, URLWATCH_MAX_BYTES),
            'headers' => $headers,
            'error' => $error,
            'hops' => $hops,
            // Only when it actually went somewhere else, so the column means
            // "this moved" rather than repeating the address on every row.
            'landed' => ($landed !== '' && $landed !== $url) ? $landed : null,
            'etag' => urlWatchHeader($headers, 'etag'),
            'method' => $method,
        ];
    }

    $context = stream_context_create(['http' => [
        'timeout' => URLWATCH_TIMEOUT,
        'ignore_errors' => true,
        'user_agent' => 'Hostscope/1.0 (+uptime check)',
        'method' => $method,
        'max_redirects' => $follow ? 5 : 1,
        'follow_location' => $follow ? 1 : 0,
        'header' => empty($how['etag']) ? '' : 'If-None-Match: ' . $how['etag'],
    ]]);

    $body = @file_get_contents($url, false, $context, 0, URLWATCH_MAX_BYTES);
    if ($body === false) {
        return ['status' => 0, 'body' => '', 'error' => 'the request failed'];
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    return [
        'status' => $status,
        'body' => $body,
        'headers' => implode("\n", $http_response_header ?? []),
        'error' => null,
        'hops' => 0,
        'landed' => null,
        'etag' => urlWatchHeader(implode("\n", $http_response_header ?? []), 'etag'),
        'method' => $method,
    ];
}

/** One header out of a raw response head, case-insensitively. */
function urlWatchHeader(string $headers, string $name): ?string
{
    $pattern = '/^' . preg_quote($name, '/') . '\s*:\s*(.+?)\s*$/im';
    return preg_match($pattern, $headers, $m) ? trim($m[1]) : null;
}

function urlWatchStore(PDO $pdo, int $id, array $result): void
{
    $pdo->prepare(
        'INSERT INTO url_results
            (check_id, at, status, ms, bytes, value, matched, ok, error, redirected_to, hops)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id, $result['at'], $result['status'], $result['ms'], $result['bytes'],
        $result['value'],
        $result['matched'] === null ? null : ($result['matched'] ? 1 : 0),
        $result['ok'] ? 1 : 0,
        $result['error'],
        $result['landed'] ?? null,
        (int)($result['hops'] ?? 0),
    ]);

    /*
     * The ETag is remembered so the next conditional request has one to send.
     *
     * Only worth keeping when the check asked for it; storing one otherwise
     * would quietly change what the next request looks like.
     */
    $pdo->prepare(
        'UPDATE url_checks SET last_at = ?, last_ok = ?, last_status = ?, last_error = ?,
                last_etag = COALESCE(?, last_etag)
          WHERE id = ?'
    )->execute([
        $result['at'], $result['ok'] ? 1 : 0, $result['status'], $result['error'],
        $result['etag'] ?? null, $id,
    ]);
}

/**
 * Saves a check, validating everything the runner will later rely on.
 *
 * A bad regular expression stored now is a failed check every minute for ever,
 * reported as the site being down - so patterns are compiled here, once, where
 * the person who typed it is still looking at the screen.
 */
function urlWatchSave(PDO $pdo, array $input): int
{
    $url = trim((string)($input['url'] ?? ''));
    $parts = parse_url($url);

    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || ($parts['host'] ?? '') === '') {
        throw new ActionRefused('that is not an http or https URL');
    }

    $interval = (int)($input['interval_seconds'] ?? 300);
    if (!isset(urlWatchIntervals()[$interval])) {
        throw new ActionRefused('that is not one of the intervals on offer');
    }

    $kind = (string)($input['check_kind'] ?? 'none');
    $kinds = urlWatchChecks();
    if (!isset($kinds[$kind])) {
        throw new ActionRefused('that is not one of the checks on offer');
    }

    $value = trim((string)($input['check_value'] ?? ''));
    if ($kinds[$kind]['needs_value'] && $value === '') {
        throw new ActionRefused('that check needs something to look for');
    }

    if ($kind === 'regex' && @preg_match('/' . str_replace('/', '\\/', $value) . '/u', '') === false) {
        throw new ActionRefused('that regular expression will not compile');
    }

    $expect = (int)($input['expect_status'] ?? 200);
    if ($expect !== 0 && ($expect < 100 || $expect > 599)) {
        throw new ActionRefused('that is not a status code');
    }

    $id = (int)($input['id'] ?? 0);
    $label = trim((string)($input['label'] ?? '')) ?: ($parts['host'] . ($parts['path'] ?? ''));

    $method = strtoupper((string)($input['method'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        throw new ActionRefused('that is not a method this will send');
    }

    /*
     * A body rule needs a body, and HEAD has none by definition.
     *
     * Refused rather than silently ignored: a check configured to look for text
     * in a response that can never contain any would fail every run, and be
     * reported as the site being broken.
     */
    if ($method === 'HEAD' && $kind !== 'none') {
        throw new ActionRefused(
            'a HEAD request has no body, so there is nothing for a content check to look at. '
            . 'Use GET, or set the check to "just the status code".'
        );
    }

    $follow = empty($input['follow_redirects']) ? 0 : 1;
    $etag = empty($input['send_etag']) ? 0 : 1;

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE url_checks SET url = ?, label = ?, interval_seconds = ?, expect_status = ?,
                    check_kind = ?, check_value = ?, enabled = ?, follow_redirects = ?,
                    method = ?, send_etag = ? WHERE id = ?'
        )->execute([
            $url, $label, $interval, $expect, $kind, $value,
            empty($input['enabled']) ? 0 : 1, $follow, $method, $etag, $id,
        ]);
        return $id;
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM url_checks')->fetchColumn();
    if ($count >= URLWATCH_MAX) {
        throw new ActionRefused('that is as many checks as this will run');
    }

    $pdo->prepare(
        'INSERT INTO url_checks
            (url, label, interval_seconds, expect_status, check_kind, check_value, enabled,
             follow_redirects, method, send_etag, added_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $url, $label, $interval, $expect, $kind, $value,
        empty($input['enabled']) ? 0 : 1, $follow, $method, $etag, time(),
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * One check's history, for the charts.
 *
 * Three series from one query, because they are three views of the same request
 * and must describe the same moments - fetching them separately would let the
 * status line and the timing line disagree about when something happened.
 */
function urlWatchHistory(PDO $pdo, int $id, int $from, int $to): array
{
    $stmt = $pdo->prepare(
        'SELECT at, status, ms, value, matched, ok, error FROM url_results
          WHERE check_id = ? AND at BETWEEN ? AND ? ORDER BY at'
    );
    $stmt->execute([$id, $from, $to]);
    $rows = $stmt->fetchAll();

    $ok = 0;
    $slowest = 0;
    $total = 0;
    foreach ($rows as $row) {
        if ((int)$row['ok'] === 1) {
            $ok++;
        }
        $slowest = max($slowest, (int)$row['ms']);
        $total += (int)$row['ms'];
    }

    return [
        'rows' => $rows,
        'checks' => count($rows),
        'ok' => $ok,
        'uptime_percent' => $rows === [] ? null : round(($ok / count($rows)) * 100, 2),
        'average_ms' => $rows === [] ? null : (int)round($total / count($rows)),
        'slowest_ms' => $slowest,
        // Whether the extracted value is worth charting at all.
        'has_value' => (bool)array_filter($rows, fn(array $r): bool => $r['value'] !== null),
    ];
}
