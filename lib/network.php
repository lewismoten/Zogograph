<?php
declare(strict_types=1);

/*
 * What is talking to this machine, and on which ports.
 *
 * The gap this fills: every other panel here measures a resource being consumed
 * - disk, memory, CPU - and none of them can tell you *why*. A machine pinned at
 * 100% CPU because it is answering four thousand DNS queries a second looks
 * exactly like a machine pinned at 100% CPU for any other reason, and the
 * difference is only visible on the wire.
 *
 * Three levels, in increasing cost and increasing intrusiveness:
 *
 *   listening sockets   free, read from /proc or ss. What is open, and what is
 *                       holding it open. Answers "is that port meant to be there".
 *   established peers   free, same source. Who is connected right now, which is
 *                       enough to spot one address holding a thousand sockets.
 *   a packet sample     expensive and root-only. A bounded tcpdump run,
 *                       summarised into counts. Answers "where is the traffic
 *                       actually coming from" when the first two do not.
 *
 * The capture records counts and endpoints and nothing else. No payloads are
 * kept, ever - a monitor that quietly writes the contents of other people's
 * traffic into a SQLite file on a shared host would be a far worse problem than
 * whatever it was pointed at.
 */

require_once __DIR__ . '/collect.php';

/** Longest a requested capture may run, whatever is asked for. */
const CAPTURE_MAX_SECONDS = 60;

/** And a packet ceiling, so a busy link cannot make it run long anyway. */
const CAPTURE_MAX_PACKETS = 20000;

/** How many distinct peers, ports and names to keep from one capture. */
const CAPTURE_KEEP = 40;

/**
 * The network interfaces and what has crossed them since boot.
 *
 * Loopback is reported but marked, rather than dropped: a machine doing a
 * gigabyte a second over `lo` is telling you something real about a local
 * database, and it is also the reason a naive total looks impossible.
 *
 * @return list<array>
 */
function networkInterfaces(string $procRoot = '/proc'): array
{
    $raw = @file_get_contents($procRoot . '/net/dev');
    if ($raw === false) {
        return [];
    }

    $out = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $rest] = explode(':', $line, 2);
        $name = trim($name);
        $parts = preg_split('/\s+/', trim($rest)) ?: [];
        if (count($parts) < 16) {
            continue;
        }

        $out[] = [
            'name' => $name,
            'loopback' => $name === 'lo',
            'rx_bytes' => (int)$parts[0],
            'rx_packets' => (int)$parts[1],
            'rx_errors' => (int)$parts[2],
            'rx_dropped' => (int)$parts[3],
            'tx_bytes' => (int)$parts[8],
            'tx_packets' => (int)$parts[9],
            'tx_errors' => (int)$parts[10],
            'tx_dropped' => (int)$parts[11],
            'up' => interfaceIsUp($name),
            'speed_mbit' => interfaceSpeed($name),
        ];
    }

    usort($out, fn(array $a, array $b): int =>
        ($a['loopback'] <=> $b['loopback']) ?: ($b['rx_bytes'] + $b['tx_bytes'] <=> $a['rx_bytes'] + $a['tx_bytes']));

    return $out;
}

function interfaceIsUp(string $name): ?bool
{
    $state = @file_get_contents('/sys/class/net/' . $name . '/operstate');
    if ($state === false) {
        return null;
    }
    return trim($state) === 'up';
}

/**
 * Link speed in megabits, where the driver reports one.
 *
 * Virtual interfaces and most containers report nothing, and a wrong number
 * here would make "we are saturating the link" a claim rather than a reading -
 * so absence is reported as absence.
 */
function interfaceSpeed(string $name): ?int
{
    $speed = @file_get_contents('/sys/class/net/' . $name . '/speed');
    if ($speed === false) {
        return null;
    }
    $value = (int)trim($speed);
    return $value > 0 ? $value : null;
}

/**
 * Every socket in a listening state, with whatever holds it.
 *
 * `ss` first because it names the process; the /proc/net files as a fallback
 * because they are always there. The fallback cannot attribute a socket to a
 * process without walking the fd directory of every process, which is expensive
 * and, from an unprivileged account, mostly refused - so it reports the socket
 * without an owner rather than guessing.
 *
 * @return array{rows: list<array>, source: string, reason: string}
 */
function listeningSockets(): array
{
    $out = shellRead('ss -lntupH 2>/dev/null');
    if ($out !== null) {
        return ['rows' => parseSsListening($out), 'source' => 'ss', 'reason' => ''];
    }

    $rows = [];
    foreach (['tcp' => '/proc/net/tcp', 'tcp6' => '/proc/net/tcp6',
              'udp' => '/proc/net/udp', 'udp6' => '/proc/net/udp6'] as $kind => $file) {
        foreach (parseProcNet($file, str_starts_with($kind, 'tcp')) as $row) {
            $rows[] = $row + ['protocol' => $kind, 'process' => null, 'pid' => null];
        }
    }

    return [
        'rows' => dedupePorts($rows),
        'source' => 'proc',
        'reason' => $rows === []
            ? 'Neither ss nor /proc/net could be read here.'
            : 'Read from /proc/net, which does not say which process holds each socket. '
                . 'Install iproute2, or run the host collector as root, for that.',
    ];
}

function parseSsListening(string $out): array
{
    $rows = [];

    foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 5) {
            continue;
        }

        // netid state recv-q send-q local peer [users:(("name",pid=N,fd=N))]
        $protocol = $parts[0];
        $local = $parts[4];

        /*
         * Split on the last colon, not the first.
         *
         * An IPv6 listener is written `[::]:443` or `*:443`, and splitting on
         * the first colon turns `::` into an address of `` and a port of `:`.
         */
        $at = strrpos($local, ':');
        if ($at === false) {
            continue;
        }
        $address = substr($local, 0, $at);
        $port = (int)substr($local, $at + 1);
        if ($port <= 0) {
            continue;
        }

        $process = null;
        $pid = null;
        if (preg_match('/users:\(\("([^"]+)",pid=(\d+)/', $line, $m)) {
            $process = $m[1];
            $pid = (int)$m[2];
        }

        $rows[] = [
            'protocol' => $protocol,
            'address' => trim($address, '[]'),
            'port' => $port,
            'process' => $process,
            'pid' => $pid,
        ];
    }

    return dedupePorts($rows);
}

/**
 * One row per protocol and port, with the addresses folded together.
 *
 * A daemon bound to both stacks appears four times in `ss` - v4 and v6, each
 * possibly on several addresses - and four identical rows for one service reads
 * as four services. What matters is "port 53 is open, held by named, on
 * everything", so that is what is reported.
 */
function dedupePorts(array $rows): array
{
    $byKey = [];

    foreach ($rows as $row) {
        /*
         * The family comes from the address, not from the protocol name.
         *
         * `ss` writes "tcp" for both stacks and only the address says which -
         * `[::]:22` against `0.0.0.0:22`. Reading it off the protocol worked
         * against /proc/net/tcp6, where the name carries it, and quietly
         * reported every IPv6 listener as IPv4 everywhere else.
         */
        $family = str_contains((string)$row['address'], ':') ? 'v6' : 'v4';
        $base = rtrim($row['protocol'], '6');
        $key = $base . ':' . $row['port'];

        if (!isset($byKey[$key])) {
            $byKey[$key] = [
                'protocol' => $base,
                'port' => $row['port'],
                'addresses' => [],
                'families' => [],
                'process' => $row['process'],
                'pid' => $row['pid'],
            ];
        }

        $byKey[$key]['addresses'][$row['address']] = true;
        $byKey[$key]['families'][$family] = true;
        $byKey[$key]['process'] ??= $row['process'];
        $byKey[$key]['pid'] ??= $row['pid'];
    }

    $out = [];
    foreach ($byKey as $entry) {
        $addresses = array_keys($entry['addresses']);
        $out[] = [
            'protocol' => $entry['protocol'],
            'port' => $entry['port'],
            'addresses' => $addresses,
            'families' => array_keys($entry['families']),
            /*
             * Whether it is reachable from off the machine.
             *
             * A service on 127.0.0.1 is a different thing from the same service
             * on 0.0.0.0, and the distinction is the whole of "should this be
             * open" - one is internal plumbing, the other is exposed to whoever
             * can route to this host.
             */
            'public' => portIsPublic($addresses),
            'process' => $entry['process'],
            'pid' => $entry['pid'],
            'service' => portService($entry['port'], $entry['protocol']),
        ];
    }

    usort($out, fn(array $a, array $b): int => $a['port'] <=> $b['port']);
    return $out;
}

function portIsPublic(array $addresses): bool
{
    foreach ($addresses as $address) {
        $address = trim($address, '[]');
        if ($address === '0.0.0.0' || $address === '*' || $address === '::' || $address === '') {
            return true;
        }
        if (!str_starts_with($address, '127.') && $address !== '::1') {
            return true;
        }
    }
    return false;
}

/**
 * Two hex fields per line of /proc/net/tcp, little-endian.
 *
 * State 0A is LISTEN for TCP. UDP has no listening state - a bound socket is
 * simply state 07 - so for UDP anything bound with no peer is reported.
 */
function parseProcNet(string $file, bool $isTcp): array
{
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return [];
    }

    $rows = [];
    foreach (array_slice(preg_split('/\R/', trim($raw)) ?: [], 1) as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 4) {
            continue;
        }

        $state = strtoupper($parts[3]);
        if ($isTcp ? $state !== '0A' : $state !== '07') {
            continue;
        }

        [$hexAddress, $hexPort] = array_pad(explode(':', $parts[1]), 2, '');
        $port = (int)hexdec($hexPort);
        if ($port <= 0) {
            continue;
        }

        $rows[] = ['address' => hexToAddress($hexAddress), 'port' => $port];
    }

    return $rows;
}

function hexToAddress(string $hex): string
{
    if (strlen($hex) === 8) {
        // Little-endian: 0100007F is 127.0.0.1.
        $bytes = array_reverse(str_split($hex, 2));
        return implode('.', array_map('hexdec', $bytes));
    }

    if (strlen($hex) === 32) {
        // Each 32-bit word is little-endian; the words themselves are in order.
        $words = str_split($hex, 8);
        $parts = [];
        foreach ($words as $word) {
            $reversed = implode('', array_reverse(str_split($word, 2)));
            $parts[] = substr($reversed, 0, 4);
            $parts[] = substr($reversed, 4, 4);
        }
        $packed = @inet_pton(implode(':', $parts));
        return $packed === false ? implode(':', $parts) : (string)inet_ntop($packed);
    }

    return $hex;
}

/**
 * What usually answers on a port.
 *
 * Deliberately phrased as "usually" throughout. A port number is a convention,
 * not a fact about what is listening - anything may bind 443 - so this names the
 * expectation and the process column names the reality. Where they disagree,
 * that disagreement is the interesting thing, and stating the port's service as
 * though it were established would hide exactly that case.
 */
function portService(int $port, string $protocol = 'tcp'): array
{
    static $known = [
        20 => ['FTP data', 'The data channel for a classic FTP transfer.'],
        21 => ['FTP', 'Unencrypted by default. Usually replaced by SFTP.'],
        22 => ['SSH', 'Shell access and SFTP. The most probed port on the internet.'],
        25 => ['SMTP', 'Mail between servers. An open one gets abused within hours.'],
        53 => ['DNS', 'Name service. Both UDP and TCP; UDP carries almost all of it, '
            . 'and an authoritative server here will see far more traffic than anything else.'],
        80 => ['HTTP', 'Web, unencrypted. Usually a redirect to 443.'],
        110 => ['POP3', 'Mail collection, legacy.'],
        123 => ['NTP', 'Clock synchronisation. Amplification-attack bait if open to the world.'],
        143 => ['IMAP', 'Mail collection.'],
        443 => ['HTTPS', 'Web, encrypted. The busiest port on most web hosts.'],
        465 => ['SMTPS', 'Mail submission over TLS.'],
        587 => ['Mail submission', 'What mail clients use to send.'],
        993 => ['IMAPS', 'IMAP over TLS.'],
        995 => ['POP3S', 'POP3 over TLS.'],
        3306 => ['MySQL / MariaDB', 'Should almost never be reachable from off the machine.'],
        5432 => ['PostgreSQL', 'Should almost never be reachable from off the machine.'],
        6379 => ['Redis', 'No authentication by default. Public exposure is a compromise.'],
        8000 => ['HTTP alternate', 'Often a development or application server.'],
        8080 => ['HTTP alternate', 'Often a proxy, an app server, or a control panel.'],
        8443 => ['HTTPS alternate', 'Often a control panel.'],
        9000 => ['PHP-FPM or app server', 'FastCGI, usually local only.'],
        11211 => ['Memcached', 'No authentication. A famous amplification vector when public.'],
        27017 => ['MongoDB', 'Should almost never be reachable from off the machine.'],
        // cPanel and WHM, which is what this is most likely running on.
        2077 => ['cPanel WebDisk', ''],
        2078 => ['cPanel WebDisk (TLS)', ''],
        2082 => ['cPanel', 'Control panel, unencrypted.'],
        2083 => ['cPanel (TLS)', 'Control panel.'],
        2086 => ['WHM', 'Server administration, unencrypted.'],
        2087 => ['WHM (TLS)', 'Server administration.'],
        2095 => ['Webmail', ''],
        2096 => ['Webmail (TLS)', ''],
        3000 => ['Application server', 'Node, Rails and Forgejo all default here.'],
        // Common self-hosted things.
        8006 => ['Proxmox', ''],
        9090 => ['Cockpit or Prometheus', ''],
    ];

    if (isset($known[$port])) {
        return ['name' => $known[$port][0], 'note' => $known[$port][1], 'known' => true];
    }

    if ($port >= 49152) {
        return [
            'name' => 'ephemeral range',
            'note' => 'Ports above 49151 are handed out to outgoing connections. A listener '
                . 'here is unusual and worth identifying.',
            'known' => false,
        ];
    }

    return ['name' => 'unrecognised', 'note' => '', 'known' => false];
}

/**
 * Who is connected right now, grouped by peer.
 *
 * Grouped because the useful shape is "one address holds nine hundred sockets",
 * which a flat list of nine hundred rows states and does not show. Local
 * addresses are grouped by port instead, since that is what says which service
 * is carrying the load.
 *
 * @return array{peers: list<array>, ports: list<array>, total: int, states: array<string,int>, source: string, reason: string}
 */
function connectionSummary(): array
{
    $out = shellRead('ss -Htn state connected 2>/dev/null');
    if ($out === null) {
        return [
            'peers' => [], 'ports' => [], 'total' => 0, 'states' => [],
            'source' => 'none',
            'reason' => 'ss could not be run here, so established connections cannot be listed. '
                . '/proc/net/tcp has the same information but not the process behind it.',
        ];
    }

    $peers = [];
    $ports = [];
    $total = 0;

    foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 4) {
            continue;
        }

        $local = $parts[count($parts) - 2];
        $peer = $parts[count($parts) - 1];

        $localPort = (int)substr($local, (int)strrpos($local, ':') + 1);
        $peerAddress = trim(substr($peer, 0, (int)strrpos($peer, ':')), '[]');

        if ($localPort <= 0 || $peerAddress === '') {
            continue;
        }

        $total++;
        $peers[$peerAddress] = ($peers[$peerAddress] ?? 0) + 1;
        $ports[$localPort] = ($ports[$localPort] ?? 0) + 1;
    }

    arsort($peers);
    arsort($ports);

    return [
        'peers' => array_map(
            fn(string $address, int $count): array => [
                'address' => $address,
                'connections' => $count,
                'private' => addressIsPrivate($address),
            ],
            array_keys(array_slice($peers, 0, CAPTURE_KEEP, true)),
            array_values(array_slice($peers, 0, CAPTURE_KEEP, true))
        ),
        'ports' => array_map(
            fn(int $port, int $count): array => [
                'port' => $port,
                'connections' => $count,
                'service' => portService($port),
            ],
            array_keys(array_slice($ports, 0, CAPTURE_KEEP, true)),
            array_values(array_slice($ports, 0, CAPTURE_KEEP, true))
        ),
        'total' => $total,
        'states' => [],
        'source' => 'ss',
        'reason' => '',
    ];
}

/** Whether an address is one nobody routes, so a busy one is a neighbour. */
function addressIsPrivate(string $address): bool
{
    if (str_contains($address, ':')) {
        return str_starts_with($address, 'fe80') || str_starts_with($address, 'fc')
            || str_starts_with($address, 'fd') || $address === '::1';
    }

    return str_starts_with($address, '10.')
        || str_starts_with($address, '192.168.')
        || str_starts_with($address, '127.')
        || (bool)preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $address);
}

/* ------------------------------------------------------- packet sampling -- */

/**
 * A bounded look at what is actually on the wire.
 *
 * This is the tool of last resort and it is treated as one. Everything above is
 * free and continuous; this costs a real capture on a live interface, needs
 * root, and is therefore run only when somebody asks for it and only for as
 * long as they asked.
 *
 * Four bounds, because any one of them can fail on a busy link:
 *
 *   timeout     wall clock, enforced outside tcpdump by `timeout`
 *   -c          a packet ceiling, so a gigabit of DNS cannot make it run long
 *   -s          a snap length just past the headers we parse
 *   no -w       nothing is written to disk; the output is parsed and discarded
 *
 * What is kept is counts: how many packets, between which addresses, on which
 * ports, and for DNS the names asked for. No payload is stored. A monitor that
 * quietly wrote other people's traffic into a file on a shared host would be a
 * far worse problem than whatever it was pointed at, and "we only kept the
 * interesting part" is what everyone says before the leak.
 *
 * @return array<string, mixed>
 */
function captureTraffic(
    string $interface,
    int $seconds,
    string $filter = '',
    ?callable $runner = null
): array {
    $seconds = max(5, min(CAPTURE_MAX_SECONDS, $seconds));

    if (!preg_match('/^[A-Za-z0-9._:-]{1,32}$/', $interface)) {
        return captureFailure('That is not a valid interface name.');
    }

    $expression = captureFilter($filter);
    if ($expression === null) {
        return captureFailure(
            'That capture filter is not one of the ones offered. Filters are chosen from a '
            . 'fixed list rather than typed, because a tcpdump expression is passed to a '
            . 'program running as root and there is no safe way to accept an arbitrary one.'
        );
    }

    $tcpdump = whichBinary('tcpdump');
    if ($tcpdump === null) {
        return captureFailure('tcpdump is not installed, or is not on this PATH.');
    }

    $timeout = whichBinary('timeout');
    $command = sprintf(
        '%s%s -nn -q -l -s 96 -c %d -i %s %s 2>&1',
        $timeout === null ? '' : escapeshellarg($timeout) . ' ' . ($seconds + 2) . ' ',
        escapeshellarg($tcpdump),
        CAPTURE_MAX_PACKETS,
        escapeshellarg($interface),
        $expression['argument'] === '' ? '' : escapeshellarg($expression['argument'])
    );

    $started = microtime(true);
    $out = shellRead($command);
    $elapsed = round(microtime(true) - $started, 1);

    if ($out === null) {
        return captureFailure('tcpdump produced no output. It needs root, and the interface '
            . 'has to exist and be up.');
    }

    /*
     * tcpdump's own complaints look like output, so they are checked for.
     *
     * "You don't have permission" and "No such device" both arrive on stderr,
     * which is folded into the same stream - and an empty summary from a failed
     * command reads as "no traffic", which is the opposite of the truth.
     */
    foreach (['permission' => 'This needs root: tcpdump could not open the interface.',
              'No such device' => 'There is no interface by that name on this machine.',
              'not found' => 'tcpdump is not installed here.'] as $needle => $said) {
        if (stripos($out, $needle) !== false && substr_count($out, "\n") < 5) {
            return captureFailure($said);
        }
    }

    $summary = summariseCapture($out);
    $summary['ok'] = true;
    $summary['reason'] = '';
    $summary['interface'] = $interface;
    $summary['seconds'] = $elapsed;
    $summary['requested_seconds'] = $seconds;
    $summary['filter'] = $expression['key'];
    $summary['filter_label'] = $expression['label'];
    $summary['hit_packet_limit'] = $summary['packets'] >= CAPTURE_MAX_PACKETS;

    return $summary;
}

function captureFailure(string $reason): array
{
    return [
        'ok' => false, 'reason' => $reason, 'packets' => 0, 'bytes' => 0,
        'peers' => [], 'ports' => [], 'names' => [], 'protocols' => [],
        'seconds' => 0, 'interface' => '', 'filter' => '', 'filter_label' => '',
        'hit_packet_limit' => false, 'requested_seconds' => 0,
    ];
}

/**
 * The capture filters on offer, and what each is for.
 *
 * A fixed list rather than a text box. The expression is handed to a program
 * running as root, and while escapeshellarg would stop a shell escape, tcpdump's
 * own filter language can itself open files (`-F`) and is not something to
 * accept from a web form. Every one of these is a question somebody actually
 * asks when a machine is being hammered.
 *
 * @return array{key: string, label: string, argument: string, about: string}|null
 */
function captureFilter(string $key): ?array
{
    $filters = captureFilters();
    foreach ($filters as $filter) {
        if ($filter['key'] === $key || ($key === '' && $filter['key'] === 'all')) {
            return $filter;
        }
    }
    return null;
}

/** @return list<array{key: string, label: string, argument: string, about: string}> */
function captureFilters(): array
{
    return [
        [
            'key' => 'all',
            'label' => 'Everything',
            'argument' => '',
            'about' => 'Every packet crossing the interface. The right place to start when '
                . 'you do not yet know what is busy.',
        ],
        [
            'key' => 'dns',
            'label' => 'DNS (port 53)',
            'argument' => 'port 53',
            'about' => 'Both UDP and TCP. If this machine answers for a zone, this is very '
                . 'often the busiest thing on it — and the one nothing else on this site '
                . 'would show you.',
        ],
        [
            'key' => 'dns-udp',
            'label' => 'DNS over UDP only',
            'argument' => 'udp port 53',
            'about' => 'Almost all real query traffic. Separating it from TCP tells a flood '
                . 'of ordinary queries apart from zone transfers.',
        ],
        [
            'key' => 'https',
            'label' => 'HTTPS (port 443)',
            'argument' => 'tcp port 443',
            'about' => 'Web traffic in both directions.',
        ],
        [
            'key' => 'https-syn',
            'label' => 'New HTTPS connections only',
            'argument' => 'tcp dst port 443 and tcp[tcpflags] & tcp-syn != 0',
            'about' => 'Only the opening packet of each connection, so the count is '
                . '"how many connections are being attempted" rather than "how much data". '
                . 'This is the shape a flood makes.',
        ],
        [
            'key' => 'mail',
            'label' => 'Mail (25, 465, 587)',
            'argument' => 'port 25 or port 465 or port 587',
            'about' => 'A compromised account sending spam shows up here long before it '
                . 'shows up in the disk figures.',
        ],
        [
            'key' => 'web',
            'label' => 'Web (80 and 443)',
            'argument' => 'port 80 or port 443',
            'about' => 'Both web ports together.',
        ],
    ];
}

/**
 * Counts out of tcpdump's one-line-per-packet output.
 *
 * Parsed with deliberately loose patterns. tcpdump's format varies by version,
 * by protocol and by whether `-q` is in effect, and a strict parser that
 * silently matched nothing would report a quiet network on a machine falling
 * over. Anything unrecognised is counted as a packet and not attributed, and
 * the unattributed count is reported so the arithmetic can be checked.
 */
function summariseCapture(string $out): array
{
    $peers = [];
    $ports = [];
    $names = [];
    $protocols = [];
    $packets = 0;
    $bytes = 0;
    $unparsed = 0;

    foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, 'tcpdump:') || str_starts_with($line, 'listening on')) {
            continue;
        }

        $packets++;

        // "10.0.0.1.54321 > 10.0.0.2.53: 12345+ A? example.com. (29)"
        if (!preg_match('/\s(\S+?)\.(\d+) > (\S+?)\.(\d+):/', $line, $m)) {
            $unparsed++;
            continue;
        }

        [, $source, $sourcePort, $destination, $destinationPort] = $m;

        $peers[$source] = ($peers[$source] ?? 0) + 1;
        $peers[$destination] = ($peers[$destination] ?? 0) + 1;

        /*
         * The lower port is counted, not both.
         *
         * One side of every conversation is an ephemeral port picked at random,
         * and counting those produces a list of thousands of ports each seen
         * once. The low port is the service, which is the question.
         */
        $port = min((int)$sourcePort, (int)$destinationPort);
        $ports[$port] = ($ports[$port] ?? 0) + 1;

        if (preg_match('/\((\d+)\)\s*$/', $line, $size)) {
            $bytes += (int)$size[1];
        }

        if (preg_match('/\b(UDP|tcp|ICMP|IP6)\b/i', $line, $protocol)) {
            $key = strtolower($protocol[1]);
            $protocols[$key] = ($protocols[$key] ?? 0) + 1;
        }

        /*
         * The name asked for, when this is a DNS query.
         *
         * This is the one field worth keeping beyond a count: "four thousand
         * queries" is a symptom and "four thousand queries for one zone that is
         * not ours" is a diagnosis. It is a hostname from a query header, not
         * payload from anybody's session.
         */
        if (preg_match('/\s(?:A|AAAA|NS|MX|TXT|SOA|PTR|CNAME|SRV|ANY)\?\s+([A-Za-z0-9._-]+)/', $line, $q)) {
            $name = strtolower(rtrim($q[1], '.'));
            if ($name !== '') {
                $names[$name] = ($names[$name] ?? 0) + 1;
            }
        }
    }

    arsort($peers);
    arsort($ports);
    arsort($names);
    arsort($protocols);

    $top = fn(array $counts, string $key): array => array_map(
        fn($k, int $v): array => [$key => $k, 'packets' => $v],
        array_keys(array_slice($counts, 0, CAPTURE_KEEP, true)),
        array_values(array_slice($counts, 0, CAPTURE_KEEP, true))
    );

    $portRows = $top($ports, 'port');
    foreach ($portRows as &$row) {
        $row['service'] = portService((int)$row['port']);
    }
    unset($row);

    $peerRows = $top($peers, 'address');
    foreach ($peerRows as &$peer) {
        $peer['private'] = addressIsPrivate((string)$peer['address']);
    }
    unset($peer);

    return [
        'packets' => $packets,
        'bytes' => $bytes,
        'unparsed' => $unparsed,
        'peers' => $peerRows,
        'ports' => $portRows,
        'names' => $top($names, 'name'),
        'protocols' => $protocols,
        'distinct_peers' => count($peers),
        'distinct_names' => count($names),
    ];
}

/** An absolute path to a binary, searched rather than assumed. */
function whichBinary(string $name): ?string
{
    if (!preg_match('/^[a-z0-9_-]{1,32}$/i', $name)) {
        return null;
    }

    foreach (['/usr/sbin/', '/sbin/', '/usr/bin/', '/bin/', '/usr/local/sbin/', '/usr/local/bin/'] as $dir) {
        if (@is_executable($dir . $name)) {
            return $dir . $name;
        }
    }

    $found = shellRead('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
    $found = $found === null ? '' : trim($found);
    return $found !== '' && @is_executable($found) ? $found : null;
}

/* ------------------------------------------------------------------ DNS -- */

/**
 * Whether name service on this machine actually answers, and how fast.
 *
 * Worth having separately from the packet counts because they answer different
 * questions. The capture says how much DNS traffic there is; this says whether
 * any of it is being answered correctly - and a nameserver that is busy *and*
 * answering is a completely different situation from one that is busy and
 * timing out.
 *
 * Every query is bounded and asked once. This is a diagnostic, not a monitor:
 * running it on a schedule would add this machine's own traffic to the thing
 * being measured.
 *
 * @param list<array{name: string, type: string, server: ?string, why: string}> $queries
 * @return array{available: bool, reason: string, rows: list<array>}
 */
function dnsChecks(array $queries, ?callable $runner = null): array
{
    $dig = whichBinary('dig');
    if ($dig === null && $runner === null) {
        return [
            'available' => false,
            'rows' => [],
            'reason' => 'dig is not installed here. On a Red Hat system it is in bind-utils; '
                . 'on Debian, dnsutils. Without it this cannot ask a nameserver anything.',
        ];
    }

    $rows = [];
    foreach (array_slice($queries, 0, 12) as $query) {
        $rows[] = dnsQuery($dig ?? 'dig', $query, $runner);
    }

    return ['available' => true, 'rows' => $rows, 'reason' => ''];
}

/**
 * One lookup, timed, with the answer and the failure told apart.
 *
 * Four outcomes worth distinguishing, and they are frequently confused with
 * each other: an answer, a valid "there is no such name", a server that refused
 * to answer, and no reply at all. Only the last means the nameserver is down,
 * and reporting all four as "failed" is how somebody spends an afternoon
 * restarting a daemon that was working.
 */
function dnsQuery(string $dig, array $query, ?callable $runner = null): array
{
    $name = (string)($query['name'] ?? '');
    $type = strtoupper((string)($query['type'] ?? 'A'));
    $server = $query['server'] ?? null;

    $row = [
        'name' => $name,
        'type' => $type,
        'server' => $server,
        'why' => (string)($query['why'] ?? ''),
        'ok' => false,
        'status' => 'unknown',
        'answers' => [],
        'ms' => null,
        'note' => '',
    ];

    if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/', $name)
        || !preg_match('/^[A-Z]{1,10}$/', $type)
        || ($server !== null && !preg_match('/^[A-Za-z0-9._:-]{1,253}$/', (string)$server))) {
        $row['note'] = 'That is not a name this will look up.';
        return $row;
    }

    $command = sprintf(
        '%s +tries=1 +time=3 +noall +comments +answer %s %s %s 2>&1',
        escapeshellarg($dig),
        $server === null ? '' : escapeshellarg('@' . $server),
        escapeshellarg($name),
        escapeshellarg($type)
    );

    $started = microtime(true);
    $out = $runner === null ? shellRead($command) : $runner($command);
    $row['ms'] = (int)round((microtime(true) - $started) * 1000);

    if ($out === null || trim($out) === '') {
        $row['status'] = 'no reply';
        $row['note'] = 'Nothing came back at all. The server is unreachable, or a firewall '
            . 'is dropping the query.';
        return $row;
    }

    if (preg_match('/status:\s*([A-Z]+)/', $out, $m)) {
        $row['status'] = strtolower($m[1]);
    } elseif (str_contains($out, 'connection timed out') || str_contains($out, 'no servers could be reached')) {
        $row['status'] = 'no reply';
    }

    foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, ';')) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) >= 5) {
            $row['answers'][] = [
                'ttl' => (int)$parts[1],
                'type' => $parts[3],
                'value' => implode(' ', array_slice($parts, 4)),
            ];
        }
    }

    $row['ok'] = $row['status'] === 'noerror' && $row['answers'] !== [];
    $row['note'] = match ($row['status']) {
        'noerror' => $row['answers'] === []
            ? 'The server answered and knows the name, but has no record of this type.'
            : '',
        'nxdomain' => 'The server answered: there is no such name. That is a working '
            . 'nameserver giving a correct negative answer, not a failure.',
        'refused' => 'The server refused. It is running and reachable, and does not consider '
            . 'itself responsible for this name — usually a zone that is not configured, or '
            . 'recursion turned off for outside queries.',
        'servfail' => 'The server tried and failed. For a recursive query that often means '
            . 'upstream is broken; for an authoritative one, the zone failed to load.',
        'no reply' => $row['note'],
        default => '',
    };

    return $row;
}

/**
 * The lookups worth making by default, built from what this machine claims.
 *
 * Its own hostname against the local resolver, and against itself if it is
 * listening on 53 - because "does my nameserver answer for my own zone" is the
 * first question, and the difference between the two answers is the second.
 *
 * @return list<array{name: string, type: string, server: ?string, why: string}>
 */
function defaultDnsQueries(array $config, array $listening = []): array
{
    $host = php_uname('n');
    $servesDns = false;
    foreach ($listening as $row) {
        if ((int)($row['port'] ?? 0) === 53) {
            $servesDns = true;
            break;
        }
    }

    $queries = [
        [
            'name' => $host,
            'type' => 'A',
            'server' => null,
            'why' => 'This machine\'s own name, asked the way anything else on it would ask.',
        ],
        [
            'name' => 'example.com',
            'type' => 'A',
            'server' => null,
            'why' => 'A name that has nothing to do with this machine, so a failure here is '
                . 'the resolver rather than the zone.',
        ],
    ];

    if ($servesDns) {
        $queries[] = [
            'name' => $host,
            'type' => 'A',
            'server' => '127.0.0.1',
            'why' => 'The same question asked of the nameserver running here. If this differs '
                . 'from the first answer, this machine and the rest of the world disagree '
                . 'about where it is.',
        ];
        $queries[] = [
            'name' => 'example.com',
            'type' => 'A',
            'server' => '127.0.0.1',
            'why' => 'Whether the nameserver here will recurse for a name it is not '
                . 'authoritative for. An authoritative-only server refusing this is correct '
                . 'and is not a fault.',
        ];
    }

    foreach ((array)($config['network']['dns_names'] ?? []) as $extra) {
        $queries[] = [
            'name' => (string)$extra,
            'type' => 'A',
            'server' => null,
            'why' => 'From network.dns_names in the config.',
        ];
    }

    return $queries;
}

/* -------------------------------------------------------------- services -- */

/**
 * Every service unit the machine knows about, running or not.
 *
 * Distinct from the watched-daemon panel, which asks "are these particular
 * things alive". This is the whole list, and it exists for the other question:
 * what is installed, what is enabled, and what has failed - including things
 * nobody thought to watch, which is where the answer usually is.
 *
 * `failed` and `masked` are called out because they are the two states that
 * are silently wrong. A failed unit uses no memory and no CPU, so it is absent
 * from every other panel here rather than standing out in one; a masked unit
 * cannot be started at all, which is a five-minute mystery if you do not know.
 *
 * @return array{available: bool, reason: string, rows: list<array>, summary: array<string,int>}
 */
function serviceUnits(): array
{
    $systemctl = whichBinary('systemctl');
    if ($systemctl === null) {
        return [
            'available' => false, 'rows' => [], 'summary' => [],
            'reason' => 'systemctl is not on this machine, so there are no systemd units to '
                . 'list. On a container or an older init this is expected.',
        ];
    }

    $listed = shellRead(escapeshellarg($systemctl)
        . ' list-units --type=service --all --no-pager --no-legend --plain 2>/dev/null');
    $files = shellRead(escapeshellarg($systemctl)
        . ' list-unit-files --type=service --no-pager --no-legend --plain 2>/dev/null');

    if ($listed === null && $files === null) {
        return [
            'available' => false, 'rows' => [], 'summary' => [],
            'reason' => 'systemctl is installed but returned nothing. Inside a container '
                . 'without a running systemd this is what happens.',
        ];
    }

    $rows = [];

    foreach (preg_split('/\R/', trim((string)$listed)) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line), 5) ?: [];
        if (count($parts) < 4 || !str_ends_with($parts[0], '.service')) {
            continue;
        }
        $name = substr($parts[0], 0, -8);
        $rows[$name] = [
            'name' => $name,
            'loaded' => $parts[1],
            'active' => $parts[2],
            'sub' => $parts[3],
            'description' => $parts[4] ?? '',
            'enabled' => null,
        ];
    }

    /*
     * The enablement state comes from a different command.
     *
     * `list-units` says what is running now; `list-unit-files` says what is set
     * to start at boot. A service that is running but disabled will vanish at
     * the next reboot, and one that is enabled but dead failed to start - two
     * findings that neither list produces on its own.
     */
    foreach (preg_split('/\R/', trim((string)$files)) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (count($parts) < 2 || !str_ends_with($parts[0], '.service')) {
            continue;
        }
        $name = substr($parts[0], 0, -8);
        if (str_contains($name, '@')) {
            // A template, not a unit. It has no state of its own.
            continue;
        }
        if (!isset($rows[$name])) {
            $rows[$name] = [
                'name' => $name, 'loaded' => 'not-loaded', 'active' => 'inactive',
                'sub' => 'dead', 'description' => '', 'enabled' => $parts[1],
            ];
            continue;
        }
        $rows[$name]['enabled'] = $parts[1];
    }

    $out = array_values($rows);
    foreach ($out as &$row) {
        $row['state'] = serviceState($row);
    }
    unset($row);

    /*
     * Sorted by how much attention each state deserves.
     *
     * A list of two hundred services alphabetically is a list nobody reads to
     * the bottom, and the three that failed are somewhere in the middle of it.
     */
    $rank = ['failed' => 0, 'masked' => 1, 'running-not-enabled' => 2,
             'enabled-not-running' => 3, 'running' => 4, 'stopped' => 5];
    usort($out, fn(array $a, array $b): int =>
        (($rank[$a['state']] ?? 9) <=> ($rank[$b['state']] ?? 9)) ?: strcmp($a['name'], $b['name']));

    $summary = [];
    foreach ($out as $row) {
        $summary[$row['state']] = ($summary[$row['state']] ?? 0) + 1;
    }

    return ['available' => true, 'reason' => '', 'rows' => $out, 'summary' => $summary];
}

/**
 * One word for what a unit's several state fields add up to.
 *
 * systemd reports loaded/active/sub/enabled separately and the interesting
 * conditions are combinations of them. Naming the combination is the whole
 * value: "running but not enabled" is a real finding and is invisible in any
 * one column.
 */
function serviceState(array $row): string
{
    $active = (string)($row['active'] ?? '');
    $enabled = (string)($row['enabled'] ?? '');

    if ($active === 'failed' || ($row['sub'] ?? '') === 'failed') {
        return 'failed';
    }
    if ($enabled === 'masked' || $row['loaded'] === 'masked') {
        return 'masked';
    }
    if ($active === 'active') {
        return $enabled === 'disabled' ? 'running-not-enabled' : 'running';
    }
    if ($enabled === 'enabled') {
        return 'enabled-not-running';
    }
    return 'stopped';
}

/** What each state means, for the interface to say rather than imply. */
function serviceStateNote(string $state): string
{
    return match ($state) {
        'failed' => 'Tried to run and could not. It uses no memory and no CPU, so it is '
            . 'absent from every other panel here rather than standing out in one.',
        'masked' => 'Deliberately blocked. It cannot be started at all until it is unmasked, '
            . 'which is a long mystery if you do not know it was done.',
        'running-not-enabled' => 'Running now, but not set to start at boot — it will be gone '
            . 'after the next restart.',
        'enabled-not-running' => 'Set to start at boot and is not running. Either it failed '
            . 'quietly or something stopped it.',
        'running' => 'Running, and set to start at boot.',
        'stopped' => 'Not running and not set to. Usually intentional.',
        default => '',
    };
}

/* --------------------------------------------------------------- storing -- */

/**
 * Writes one collection's network state beside the sample it belongs to.
 *
 * Everything here is cheap and free of side effects: reading /proc and asking
 * `ss` for a list. The capture is not part of this and never will be - it costs
 * a real measurement on a live interface and only happens when asked for.
 */
function storeNetwork(PDO $host, int $sampleId): array
{
    $counts = ['ports' => 0, 'interfaces' => 0, 'peers' => 0, 'units' => 0];

    $listening = listeningSockets();
    $insertPort = $host->prepare(
        'INSERT OR REPLACE INTO host_ports
            (sample_id, protocol, port, addresses, families, public, process, pid)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($listening['rows'] as $row) {
        $insertPort->execute([
            $sampleId, $row['protocol'], $row['port'],
            implode(' ', $row['addresses']), implode(' ', $row['families']),
            $row['public'] ? 1 : 0, $row['process'], $row['pid'],
        ]);
        $counts['ports']++;
    }

    $insertInterface = $host->prepare(
        'INSERT OR REPLACE INTO host_interfaces
            (sample_id, name, rx_bytes, tx_bytes, rx_packets, tx_packets,
             rx_errors, tx_errors, rx_dropped, tx_dropped, up, speed_mbit)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach (networkInterfaces() as $row) {
        $insertInterface->execute([
            $sampleId, $row['name'], $row['rx_bytes'], $row['tx_bytes'],
            $row['rx_packets'], $row['tx_packets'], $row['rx_errors'], $row['tx_errors'],
            $row['rx_dropped'], $row['tx_dropped'],
            $row['up'] === null ? null : ($row['up'] ? 1 : 0),
            $row['speed_mbit'],
        ]);
        $counts['interfaces']++;
    }

    $connections = connectionSummary();
    $insertPeer = $host->prepare(
        'INSERT OR REPLACE INTO host_peers (sample_id, address, connections, private)
         VALUES (?, ?, ?, ?)'
    );
    foreach (array_slice($connections['peers'], 0, CAPTURE_KEEP) as $peer) {
        $insertPeer->execute([
            $sampleId, $peer['address'], $peer['connections'], $peer['private'] ? 1 : 0,
        ]);
        $counts['peers']++;
    }

    $units = serviceUnits();
    $insertUnit = $host->prepare(
        'INSERT OR REPLACE INTO host_units
            (sample_id, name, state, active, sub, enabled, description)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($units['rows'] as $row) {
        $insertUnit->execute([
            $sampleId, $row['name'], $row['state'], $row['active'],
            $row['sub'], $row['enabled'], $row['description'],
        ]);
        $counts['units']++;
    }

    return $counts;
}

/** Writes one capture and the counts it produced. */
function storeCapture(PDO $host, array $capture): int
{
    $host->prepare(
        'INSERT INTO host_captures
            (taken_at, interface, filter, seconds, packets, bytes, unparsed,
             distinct_peers, distinct_names, hit_limit, ok, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        time(), $capture['interface'], $capture['filter'], $capture['seconds'],
        $capture['packets'], $capture['bytes'], $capture['unparsed'] ?? 0,
        $capture['distinct_peers'] ?? 0, $capture['distinct_names'] ?? 0,
        $capture['hit_packet_limit'] ? 1 : 0, $capture['ok'] ? 1 : 0, $capture['reason'],
    ]);

    $id = (int)$host->lastInsertId();

    $insert = $host->prepare(
        'INSERT OR REPLACE INTO host_capture_rows (capture_id, kind, label, packets, private)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach (['peers' => 'address', 'ports' => 'port', 'names' => 'name'] as $kind => $field) {
        foreach ($capture[$kind] ?? [] as $row) {
            $insert->execute([
                $id, $kind, (string)$row[$field], (int)$row['packets'],
                (int)($row['private'] ?? 0),
            ]);
        }
    }

    return $id;
}

/**
 * The capture task name for a given interface and filter.
 *
 * Encoded into the task key so two different requests are two different tasks -
 * asking for DNS on eth0 and then everything on eth1 should run both, not
 * overwrite one with the other.
 */
function captureTaskName(string $interface, string $filter): string
{
    return 'capture:' . $interface . ':' . $filter;
}

/** Reads a task name back into its parts, or null if it is not one. */
function parseCaptureTask(string $task): ?array
{
    if (!str_starts_with($task, 'capture:')) {
        return null;
    }
    $parts = explode(':', $task, 3);
    if (count($parts) !== 3) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $parts[1]) || captureFilter($parts[2]) === null) {
        return null;
    }
    return ['interface' => $parts[1], 'filter' => $parts[2]];
}
