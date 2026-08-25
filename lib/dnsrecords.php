<?php
declare(strict_types=1);

/*
 * Every record a domain publishes, and what changed since last time.
 *
 * The gap this fills: a name resolving is not the same as a zone being right.
 * Mail stops arriving because an MX was dropped or an SPF record edited; a
 * subdomain starts pointing at an address that is not yours; a TTL is cut to
 * sixty seconds during a migration and never put back. None of that shows up
 * anywhere else on this site, and all of it is visible in the zone.
 *
 * Two problems, solved differently:
 *
 *   which records   asking for one type at a time. `ANY` is not an answer -
 *                   most resolvers now return a minimal or refused response to
 *                   it, and a zone that answers it does so inconsistently. So
 *                   the types worth having are asked for individually.
 *   which names     a zone transfer if the nameserver allows one from here,
 *                   which gives the authoritative list; cPanel's own record of
 *                   the account's domains, which is exact for this account; and
 *                   the names already known from previous runs. Not a wordlist:
 *                   guessing at names is slow, noisy, and produces a list of
 *                   things that do not exist.
 *
 * Every record is stored with when it was first and last seen, so "what
 * changed" is a query rather than a diff of two snapshots. A record that stops
 * appearing is marked gone rather than deleted - the interesting question is
 * usually about something that used to be there.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/inspect.php';

/** Types asked for on the apex of a zone. */
const DNS_APEX_TYPES = ['A', 'AAAA', 'NS', 'MX', 'TXT', 'SOA', 'CAA'];

/** Types asked for on a subdomain, where SOA and NS rarely apply. */
const DNS_NAME_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

/** How many names one sweep will look up, so a large zone cannot run for ever. */
const DNS_MAX_NAMES = 120;

/*
 * How long a whole sweep may take.
 *
 * This runs while somebody waits for the page, so it is bounded by the clock
 * rather than by a count: a zone of twenty names on a fast resolver finishes in
 * a second, and one name pointing at a nameserver that never answers would
 * otherwise hold the request open until PHP gave up.
 */
const DNS_SWEEP_BUDGET = 20.0;

/** Per-query patience. Two seconds is generous for a resolver that is working. */
const DNS_QUERY_TIME = 2;

/** And a hard cap on a zone transfer, which is TCP and can hang indefinitely. */
const DNS_TRANSFER_TIMEOUT = 5;

/*
 * How many nameservers to try a transfer against.
 *
 * They all hold the same zone, so a second opinion only guards against the
 * first being the misconfigured one. Trying all five of a large provider's
 * servers costs five timeouts to learn the same refusal.
 */
const DNS_TRANSFER_SERVERS = 2;

/**
 * Looks a whole domain up and records what it publishes.
 *
 * @return array{ok: bool, reason: string, names: int, records: int, changes: list<array>}
 */
function dnsSweep(array $config, PDO $pdo, string $domain, ?callable $runner = null): array
{
    $domain = strtolower(trim($domain, " \t."));

    if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain)) {
        return ['ok' => false, 'reason' => 'that is not a domain name',
                'names' => 0, 'records' => 0, 'changes' => []];
    }

    $dig = $runner === null ? whichBinary('dig') : 'dig';
    if ($dig === null) {
        return [
            'ok' => false,
            'reason' => 'dig is not installed here. On Red Hat it is in bind-utils; on '
                . 'Debian, dnsutils. The host collector can be asked to run it instead.',
            'names' => 0, 'records' => 0, 'changes' => [],
        ];
    }

    $names = dnsNamesToCheck($config, $pdo, $domain, $dig, $runner);
    $at = time();
    $seen = [];
    $asked = 0;
    $skipped = 0;
    $deadline = microtime(true) + DNS_SWEEP_BUDGET;

    foreach (array_slice($names, 0, DNS_MAX_NAMES) as $name) {
        if (microtime(true) > $deadline) {
            break;
        }

        /*
         * Does this name exist at all, before asking five questions about it.
         *
         * The common-name list is mostly names that do not exist on any given
         * zone, and asking each of them for five record types meant forty
         * lookups that all had to time out. One question first - and a name
         * that does not exist is skipped entirely.
         *
         * The apex always exists by definition, so it goes straight through.
         */
        if ($name !== $domain) {
            $exists = dnsNameExists($dig, $name, $runner);
            $asked++;
            if ($exists === false) {
                $skipped++;
                continue;
            }
        }

        $types = $name === $domain ? DNS_APEX_TYPES : DNS_NAME_TYPES;

        foreach ($types as $type) {
            if (microtime(true) > $deadline) {
                break 2;
            }
            $asked++;
            foreach (dnsQueryRecords($dig, $name, $type, $runner) as $record) {
                $seen[] = $record;
            }
        }
    }

    $ranOut = microtime(true) > $deadline;

    /*
     * An incomplete sweep never marks anything gone.
     *
     * dnsStore decides "gone" by absence, and a sweep that ran out of time is
     * full of absences that mean nothing. Reporting those as records that
     * disappeared would be inventing changes on the one page whose entire job
     * is to report real ones.
     */
    $changes = dnsStore($pdo, $domain, $seen, $at, !$ranOut);

    return [
        'ok' => true,
        'reason' => '',
        'names' => min(count($names), DNS_MAX_NAMES),
        'records' => count($seen),
        'lookups' => $asked,
        'skipped' => $skipped,
        'changes' => $changes,
        'truncated' => count($names) > DNS_MAX_NAMES,
        /*
         * Said out loud, because a partial sweep looks exactly like a small zone.
         *
         * Records that were not asked about would otherwise be marked gone by
         * dnsStore - reporting a change that did not happen, which is the worst
         * possible failure for a page whose job is to report changes.
         */
        'incomplete' => $ranOut,
    ];
}

/**
 * Which names to ask about, from every source that knows one.
 *
 * @return list<string>
 */
function dnsNamesToCheck(
    array $config,
    PDO $pdo,
    string $domain,
    string $dig,
    ?callable $runner = null
): array {
    $names = [$domain => true];

    /*
     * A zone transfer, if the nameserver will give one.
     *
     * On somebody else's zone this is refused, which is correct and expected.
     * On your own nameserver it is the only complete answer there is - every
     * other method is inference - so it is worth one attempt against each
     * authoritative server before falling back.
     */
    $tried = 0;
    foreach (dnsAuthoritative($dig, $domain, $runner) as $server) {
        if ($tried++ >= DNS_TRANSFER_SERVERS) {
            break;
        }
        foreach (dnsZoneTransfer($dig, $domain, $server, $runner) as $name) {
            $names[$name] = true;
        }
        if (count($names) > 1) {
            // One server answering is enough; the rest hold the same zone.
            break;
        }
    }

    /*
     * cPanel's own list, which is exact for this account.
     *
     * Reuses the same reader the treemap uses to put a globe on a document
     * root - the file says which domains this account serves, which is a fact
     * rather than a guess.
     */
    foreach (array_keys(cpanelDocumentRoots($config)) as $root) {
        foreach (cpanelDocumentRoots($config)[$root] as $served) {
            $served = strtolower(trim($served, '.'));
            if ($served === $domain || str_ends_with($served, '.' . $domain)) {
                $names[$served] = true;
            }
        }
    }

    // Whatever was found before, so a name that stops resolving is still asked
    // about - that is precisely the change worth reporting.
    try {
        $stmt = $pdo->prepare('SELECT DISTINCT name FROM dns_records WHERE domain = ?');
        $stmt->execute([$domain]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $known) {
            $names[strtolower((string)$known)] = true;
        }
    } catch (Throwable) {
        // No table yet.
    }

    /*
     * A short list of names that exist on almost every zone.
     *
     * Not a brute-force wordlist - eight names, all of them things a mail or web
     * host creates by default. Anything larger would be scanning somebody's
     * zone, which is slow, rude and mostly returns nothing.
     */
    foreach (['www', 'mail', 'ftp', 'webmail', 'cpanel', 'autodiscover', 'ns1', 'ns2'] as $common) {
        $names[$common . '.' . $domain] = true;
    }

    $out = array_keys($names);
    sort($out);
    return $out;
}

/** The nameservers a domain says are authoritative for it. */
function dnsAuthoritative(string $dig, string $domain, ?callable $runner = null): array
{
    $out = [];
    foreach (dnsQueryRecords($dig, $domain, 'NS', $runner) as $record) {
        $server = strtolower(trim((string)$record['value'], '.'));
        if ($server !== '') {
            $out[] = $server;
        }
    }
    return $out;
}

/**
 * A zone transfer, which is refused far more often than it succeeds.
 *
 * A refusal is the correct configuration for a public nameserver and is not
 * reported as an error - it simply means this method contributed nothing and
 * the others carry the load.
 *
 * @return list<string>
 */
function dnsZoneTransfer(string $dig, string $domain, string $server, ?callable $runner = null): array
{
    if (!preg_match('/^[a-z0-9.-]{1,253}$/i', $server)) {
        return [];
    }

    /*
     * Wrapped in `timeout`, because dig's own +time does not bound this.
     *
     * A zone transfer is TCP, and +time governs the UDP query timeout. Against
     * a server that accepts the connection and then says nothing - which is
     * what a nameserver set to ignore rather than refuse transfers does - dig
     * simply sits there, and it hung the entire sweep.
     */
    $limit = whichBinary('timeout');
    $command = sprintf(
        '%s%s +noall +answer +tries=1 +time=' . DNS_QUERY_TIME . ' @%s %s AXFR 2>&1',
        $limit === null ? '' : escapeshellarg($limit) . ' ' . DNS_TRANSFER_TIMEOUT . ' ',
        escapeshellarg($dig),
        escapeshellarg($server),
        escapeshellarg($domain)
    );

    $out = $runner === null ? shellRead($command) : $runner($command);
    if ($out === null || stripos($out, 'transfer failed') !== false
        || stripos($out, 'communications error') !== false) {
        return [];
    }

    $names = [];
    foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 4) {
            continue;
        }
        $name = strtolower(rtrim($parts[0], '.'));
        if ($name !== '' && ($name === $domain || str_ends_with($name, '.' . $domain))) {
            $names[$name] = true;
        }
    }

    return array_keys($names);
}

/**
 * Whether a name exists at all.
 *
 * NXDOMAIN is a definite no from the zone's own nameserver and is the answer
 * for most of the common names on most zones. NOERROR with no records means the
 * name exists but has nothing of that type - which is a yes, because another
 * type may well be there.
 *
 * Returns null when nothing came back, and null is treated as "ask anyway":
 * a resolver that timed out has told us nothing, and skipping a name on that
 * basis would silently drop records that are really there.
 */
function dnsNameExists(string $dig, string $name, ?callable $runner = null): ?bool
{
    if (!preg_match('/^[a-z0-9._-]{1,253}$/i', $name)) {
        return false;
    }

    $command = sprintf(
        '%s +noall +comments +tries=1 +time=' . DNS_QUERY_TIME . ' %s A 2>&1',
        escapeshellarg($dig),
        escapeshellarg($name)
    );

    $out = $runner === null ? shellRead($command) : $runner($command);
    if ($out === null || trim($out) === '') {
        return null;
    }

    if (preg_match('/status:\s*NXDOMAIN/i', $out)) {
        return false;
    }
    if (preg_match('/status:\s*NOERROR/i', $out)) {
        return true;
    }

    return null;
}

/**
 * One name and type, as records.
 *
 * @return list<array{name: string, type: string, value: string, ttl: int}>
 */
function dnsQueryRecords(string $dig, string $name, string $type, ?callable $runner = null): array
{
    if (!preg_match('/^[a-z0-9._-]{1,253}$/i', $name) || !preg_match('/^[A-Z]{1,10}$/', $type)) {
        return [];
    }

    $command = sprintf(
        '%s +noall +answer +tries=1 +time=' . DNS_QUERY_TIME . ' %s %s 2>&1',
        escapeshellarg($dig),
        escapeshellarg($name),
        escapeshellarg($type)
    );

    $out = $runner === null ? shellRead($command) : $runner($command);
    if ($out === null) {
        return [];
    }

    $records = [];
    foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, ';')) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 5) {
            continue;
        }

        [$answerName, $ttl, , $answerType] = $parts;
        $value = implode(' ', array_slice($parts, 4));

        /*
         * A CNAME answer to an A query is reported as the CNAME it is.
         *
         * dig follows the chain and returns both, and recording the final A
         * under the alias would claim the name has an address record when what
         * it actually has is a pointer somewhere else - a distinction that
         * matters the day the target moves.
         */
        $records[] = [
            'name' => strtolower(rtrim($answerName, '.')),
            'type' => strtoupper($answerType),
            'value' => trim($value),
            'ttl' => (int)$ttl,
        ];
    }

    return $records;
}

/**
 * Stores what was seen, and returns what changed.
 *
 * The change list is the point of the whole exercise. A record appearing, a
 * value changing and a record vanishing are three different events and each is
 * reported as itself.
 *
 * @return list<array{change: string, name: string, type: string, value: string, was: ?string}>
 */
function dnsStore(
    PDO $pdo,
    string $domain,
    array $records,
    int $at,
    bool $complete = true
): array {
    $changes = [];

    $before = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT name, type, value, ttl FROM dns_records WHERE domain = ? AND gone_at IS NULL'
        );
        $stmt->execute([$domain]);
        foreach ($stmt->fetchAll() as $row) {
            $before[$row['name'] . '|' . $row['type'] . '|' . $row['value']] = $row;
        }
    } catch (Throwable) {
        return $changes;
    }

    $now = [];
    foreach ($records as $record) {
        $now[$record['name'] . '|' . $record['type'] . '|' . $record['value']] = $record;
    }

    $insert = $pdo->prepare(
        'INSERT INTO dns_records (domain, name, type, value, ttl, first_seen, last_seen)
         VALUES (:d, :n, :t, :v, :ttl, :at, :at)
         ON CONFLICT(domain, name, type, value)
         DO UPDATE SET last_seen = :at, ttl = :ttl, gone_at = NULL'
    );

    foreach ($now as $key => $record) {
        $insert->execute([
            ':d' => $domain, ':n' => $record['name'], ':t' => $record['type'],
            ':v' => $record['value'], ':ttl' => $record['ttl'], ':at' => $at,
        ]);

        if (!isset($before[$key])) {
            $changes[] = [
                'change' => 'added', 'name' => $record['name'], 'type' => $record['type'],
                'value' => $record['value'], 'was' => null, 'at' => $at,
            ];
        }
    }

    /*
     * Anything that was there and is not now.
     *
     * Marked gone rather than deleted: "the MX record disappeared last Tuesday"
     * is the question somebody asks a week later, and a row that was removed
     * cannot answer it.
     */
    $gone = $pdo->prepare(
        'UPDATE dns_records SET gone_at = ?
          WHERE domain = ? AND name = ? AND type = ? AND value = ? AND gone_at IS NULL'
    );

    foreach ($complete ? $before : [] as $key => $row) {
        if (isset($now[$key])) {
            continue;
        }
        $gone->execute([$at, $domain, $row['name'], $row['type'], $row['value']]);
        $changes[] = [
            'change' => 'gone', 'name' => $row['name'], 'type' => $row['type'],
            'value' => $row['value'], 'was' => null, 'at' => $at,
        ];
    }

    foreach ($changes as $change) {
        $pdo->prepare(
            'INSERT INTO dns_changes (domain, at, change, name, type, value)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $domain, $at, $change['change'], $change['name'], $change['type'], $change['value'],
        ]);
    }

    return $changes;
}

/**
 * What a domain currently publishes, grouped by what each group is for.
 *
 * Grouped because a zone read as a flat list of forty rows hides the structure
 * that matters: six records are the mail configuration and belong together, and
 * a missing SPF is only obvious next to the MX records it should accompany.
 *
 * @return array<string, mixed>
 */
function dnsInventory(PDO $pdo, string $domain): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM dns_records WHERE domain = ? ORDER BY name, type, value'
        );
        $stmt->execute([$domain]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        return ['groups' => [], 'names' => [], 'rows' => []];
    }

    $groups = [];
    foreach ($rows as $row) {
        $group = dnsRecordGroup((string)$row['type'], (string)$row['value']);
        $row['group'] = $group;
        $row['gone'] = $row['gone_at'] !== null;
        $groups[$group][] = $row;
    }

    $names = [];
    foreach ($rows as $row) {
        if ($row['gone_at'] !== null) {
            continue;
        }
        $names[(string)$row['name']] = true;
    }

    return [
        'groups' => $groups,
        'names' => array_keys($names),
        'rows' => $rows,
        'group_notes' => dnsGroupNotes(),
    ];
}

/**
 * Which part of a zone's job a record belongs to.
 *
 * TXT records are split by what they actually are, because a zone's TXT records
 * are a grab bag: SPF and DMARC are mail configuration and belong with the MX
 * records, a domain-verification token is nothing to do with mail, and lumping
 * them together makes the mail group unreadable.
 */
function dnsRecordGroup(string $type, string $value): string
{
    $lower = strtolower($value);

    if (in_array($type, ['MX'], true)) {
        return 'mail';
    }
    if ($type === 'TXT') {
        if (str_contains($lower, 'v=spf1') || str_contains($lower, 'v=dmarc1')
            || str_contains($lower, 'domainkey') || str_contains($lower, 'v=dkim1')) {
            return 'mail';
        }
        if (preg_match('/(verification|site-verification|_acme|challenge)/', $lower)) {
            return 'proof';
        }
        return 'text';
    }
    if (in_array($type, ['NS', 'SOA'], true)) {
        return 'delegation';
    }
    if ($type === 'CAA') {
        return 'certificates';
    }
    if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
        return 'addresses';
    }

    return 'other';
}

/** @return array<string, string> */
function dnsGroupNotes(): array
{
    return [
        'addresses' => 'Where the names point. A CNAME is a pointer at another name rather '
            . 'than an address, which matters the day its target moves.',
        'mail' => 'Everything that decides whether mail arrives: the servers that accept it, '
            . 'and the SPF, DKIM and DMARC records that decide whether anyone believes it came '
            . 'from you. A missing SPF is only obvious next to the MX records it belongs with.',
        'delegation' => 'Which nameservers are in charge, and the serial that says when the '
            . 'zone last changed.',
        'certificates' => 'CAA records name the authorities allowed to issue certificates for '
            . 'this domain. An unexpected one here is worth asking about.',
        'proof' => 'Verification tokens - proving to somebody that you control this domain. '
            . 'Harmless, and they accumulate for ever unless somebody tidies them.',
        'text' => 'Other TXT records.',
        'other' => 'Everything else.',
    ];
}

/**
 * What has changed recently, newest first.
 *
 * @return list<array>
 */
function dnsRecentChanges(PDO $pdo, ?string $domain, int $limit = 100): array
{
    try {
        if ($domain === null) {
            $stmt = $pdo->prepare(
                'SELECT * FROM dns_changes ORDER BY at DESC, id DESC LIMIT ?'
            );
            $stmt->execute([$limit]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM dns_changes WHERE domain = ? ORDER BY at DESC, id DESC LIMIT ?'
            );
            $stmt->execute([$domain, $limit]);
        }
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}
