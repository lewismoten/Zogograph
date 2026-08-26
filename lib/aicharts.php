<?php
declare(strict_types=1);

/*
 * Every chart, named so that three parties can talk about the same one.
 *
 * The person looking at it, the model reasoning about it, and the code drawing
 * it all needed a shared word. Without one, a conversation goes "the memory
 * graph" - and there are four - or the model asks for a screenshot of the whole
 * page because it has no way to name the part it wants.
 *
 * So each chart has an id, a name, the page it lives on, and a list of the
 * measurements it plots. From that one definition comes:
 *
 *   the label      shown on the chart itself, on hover, with its range
 *   the data tool  which returns the numbers behind it
 *   the image tool which returns a picture of it, for the rare case where the
 *                  numbers genuinely cannot answer the question
 *   the chat card  which draws it inside the conversation
 *
 * The order matters. Numbers first, always: a chart is a rendering of data the
 * model can have directly, and asking for a picture of it instead is asking for
 * a worse copy at fifty times the cost. The image is for when somebody says
 * "there's a spike here" and points - the shape of a thing, not its values.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/spans.php';

/**
 * The charts, by id.
 *
 * `series` names the measurements. Each is [key, label, unit] where key is one
 * of the fixed expressions in aiChartExpression - never a column name from
 * anywhere but this file.
 *
 * @return array<string, array<string, mixed>>
 */
function aiChartCatalogue(): array
{
    return [
        'roomChart' => [
            'name' => 'Room left',
            'view' => 'charts',
            'what' => 'Free disk space and free inodes over time. Either can run out on '
                . 'its own - a disk with free gigabytes and no inodes still refuses to '
                . 'create a file.',
            'series' => [
                ['disk_free_bytes', 'Disk free', 'bytes'],
                ['inodes_free', 'Inodes free', 'count'],
            ],
        ],
        'cpuChart' => [
            'name' => 'CPU and memory',
            'view' => 'charts',
            'what' => "Processor and memory use as a share of what this ACCOUNT may use, "
                . "not of the machine. Inside CageFS this can sit at 100 while the machine "
                . "is idle, which is what being throttled looks like.",
            'series' => [
                ['cpu_percent', 'CPU', 'percent'],
                ['mem_used_percent', 'Memory', 'percent'],
            ],
        ],
        'ioChart' => [
            'name' => 'Disk I/O',
            'view' => 'charts',
            'what' => 'Bytes actually reaching a disk, not bytes served from cache. Read '
                . 'and written separately.',
            'series' => [
                ['io_read_bytes', 'Read', 'bytes'],
                ['io_write_bytes', 'Written', 'bytes'],
            ],
        ],
        'loadChart' => [
            'name' => 'Load average',
            'view' => 'charts',
            'what' => 'How many processes wanted to run at once. Not a percentage - '
                . 'compare it to the core count. At one per core the machine is busy; '
                . 'above that, work is queueing.',
            'series' => [
                ['load1', 'Load, one minute', 'count'],
                ['load_per_core', 'Per core', 'percent'],
            ],
        ],
        'netChart' => [
            'name' => 'Network',
            'view' => 'charts',
            'what' => 'Bytes a second in and out, summed over the real interfaces. '
                . 'Loopback is not counted.',
            'series' => [
                ['net_in_bytes', 'Arriving', 'bytes'],
                ['net_out_bytes', 'Leaving', 'bytes'],
            ],
        ],
        'diskUsedChart' => [
            'name' => 'How full the disk is',
            'view' => 'charts',
            'what' => 'Disk and inode use as percentages. The same readings as Room left, '
                . 'the other way up - this is the one to use when the question is "how '
                . 'close to full", and Room left when it is "how much is there".',
            'series' => [
                ['disk_used_percent', 'Disk used', 'percent'],
                ['inodes_used_percent', 'Inodes used', 'percent'],
            ],
        ],
    ];
}

/**
 * How one measurement is calculated, as SQL over the vitals table.
 *
 * Every expression here is a literal written in this file and selected by a key
 * that has already been checked against the catalogue. Nothing the model says
 * ever becomes part of one.
 */
function aiChartExpression(string $key): ?string
{
    return [
        'disk_free_bytes' => 'CAST("disk_free_bytes" AS REAL)',
        'inodes_free' => 'CAST("inodes_free" AS REAL)',
        'disk_used_percent' =>
            'CASE WHEN "disk_total_bytes" > 0
                  THEN (CAST("disk_used_bytes" AS REAL) / "disk_total_bytes") * 100 END',
        'inodes_used_percent' =>
            'CASE WHEN "inodes_total" > 0
                  THEN (CAST("inodes_used" AS REAL) / "inodes_total") * 100 END',
        'cpu_percent' => 'CAST("cpu_percent" AS REAL)',
        'mem_used_percent' => 'CAST("mem_used_percent" AS REAL)',
        'load1' => 'CAST("load1" AS REAL)',
        'load_per_core' =>
            'CASE WHEN "cpu_cores" > 0
                  THEN (CAST("load1" AS REAL) / "cpu_cores") * 100 END',
        'io_read_bytes' => 'CAST("io_read_bytes" AS REAL)',
        'io_write_bytes' => 'CAST("io_write_bytes" AS REAL)',
        'net_in_bytes' => 'CAST("net_in_bytes" AS REAL)',
        'net_out_bytes' => 'CAST("net_out_bytes" AS REAL)',
    ][$key] ?? null;
}

/**
 * The numbers behind one chart, bucketed.
 *
 * Bucketed because a month of readings is eight thousand numbers, which would
 * fill a context window and say less than twenty would. Each bucket carries its
 * lowest and highest as well as its average: the average of an hour containing
 * a two-minute spike hides the spike, and the spike is the thing being looked
 * for.
 *
 * @return array<string, mixed>
 */
function aiChartData(PDO $pdo, string $chartId, int $hours): array
{
    $catalogue = aiChartCatalogue();

    if (!isset($catalogue[$chartId])) {
        return ['error' => sprintf(
            'There is no chart called "%s". The charts are: %s.',
            $chartId,
            implode(', ', array_keys($catalogue))
        )];
    }

    $chart = $catalogue[$chartId];
    $since = time() - ($hours * SPAN_HOUR);
    $bucket = max(60, (int)floor(($hours * SPAN_HOUR) / 24));

    $out = [
        'chart' => $chartId,
        'name' => $chart['name'],
        'shown_on' => $chart['view'],
        'page' => $chart['view'],
        'what' => $chart['what'],
        'period' => 'the last ' . plainSpan($hours * SPAN_HOUR, true),
        'bucket' => plainSpan($bucket),
        // The browser formats its axis from this; every series on one chart
        // shares a unit, so the first one is the chart's.
        'units' => $chart['series'][0][2] ?? '',
        'series' => [],
    ];

    foreach ($chart['series'] as [$key, $label, $unit]) {
        $expression = aiChartExpression($key);
        if ($expression === null) {
            continue;
        }

        $stmt = $pdo->prepare(sprintf(
            'SELECT MIN(taken_at) AS from_at, MAX(taken_at) AS to_at, COUNT(*) AS readings,
                    MIN(%1$s) AS low, MAX(%1$s) AS high, AVG(%1$s) AS mean,
                    (taken_at / :bucket) AS slot
               FROM vitals
              WHERE taken_at >= :since AND %1$s IS NOT NULL
              GROUP BY slot ORDER BY slot',
            $expression
        ));
        $stmt->execute([':since' => $since, ':bucket' => $bucket]);
        $rows = $stmt->fetchAll();

        /*
         * Two forms of the same moment, and both are needed.
         *
         * `when` is for the model, which cannot do anything sensible with an
         * epoch second. `at` is the epoch second, for the browser, which draws
         * the chart from this same answer and cannot plot a string - it was
         * getting only the string, so every chart the assistant drew in the
         * conversation came out empty while the numbers underneath it were
         * perfectly good.
         */
        $points = array_map(fn(array $row): array => [
            'at' => (int)$row['from_at'],
            'when' => date('Y-m-d H:i', (int)$row['from_at']),
            'low' => round((float)$row['low'], 2),
            'high' => round((float)$row['high'], 2),
            'average' => round((float)$row['mean'], 2),
        ], $rows);

        $out['series'][] = [
            'measurement' => $key,
            'label' => $label,
            'unit' => $unit,
            'points' => $points,
            'moved_by' => count($points) < 2
                ? null
                : round($points[count($points) - 1]['average'] - $points[0]['average'], 2),
        ];
    }

    $any = array_filter($out['series'], fn(array $series): bool => $series['points'] !== []);

    if ($any === []) {
        $out['note'] = 'No readings in that period for anything this chart plots. Either '
            . 'the collector was not running - check get_collector_health - or the period '
            . 'is older than the retention settings keep.';
        return $out;
    }

    $out['note'] = 'These are the numbers the chart is drawn from. Only ask for a picture '
        . 'of it if the person is describing something about its SHAPE that these cannot '
        . 'tell you.';

    return $out;
}
