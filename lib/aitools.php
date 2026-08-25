<?php
declare(strict_types=1);

/*
 * What the assistant is allowed to do, described so that it does the right one.
 *
 * A tool description is a specification the model reads once and then acts on
 * for the rest of the conversation, and a vague one produces a confident wrong
 * call. So each description says three things explicitly: what the tool
 * returns, when to use it, and - the part usually left out - when *not* to.
 * "Only for network problems" written down is worth more than any amount of
 * cleverness in the prompt.
 *
 * The hard rule of this file: the model never writes SQL, never names a table
 * or a column, and never supplies a fragment of a query. Every tool is a
 * predefined statement with typed, bounded, enumerated parameters bound to it.
 * A model that could pass a string into a query would eventually pass one that
 * was not what anybody meant - not from malice, just from being a language
 * model - and there is no version of that which ends well on a database that
 * holds a machine's history.
 *
 * Three kinds of tool, and the difference matters at every layer:
 *
 *   server   read-only questions answered here, from the databases. Safe, fast,
 *            and the majority.
 *   browser  things only the page can do - move the user to another view, take
 *            a screenshot. These leave the server, wait for the browser, and
 *            come back.
 *   ask      anything that changes something. None are enabled by default and
 *            each one asks a person first.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/spans.php';
require_once __DIR__ . '/actions.php';

/**
 * Every tool, with its schema and how it runs.
 *
 * `where` is server, browser or ask. `permission` is whether a person must
 * agree before each use. `group` is what the settings page uses to let somebody
 * turn a whole category off.
 *
 * @return array<string, array<string, mixed>>
 */
function aiToolCatalogue(): array
{
    return [

        /* ------------------------------------------------- what is wrong -- */

        'get_current_status' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'The current state of the machine in one call.',
            'description' =>
                "Returns the most recent reading of everything this dashboard measures: "
                . "disk space used and free, inode use, CPU percentage, memory percentage, "
                . "load average, uptime, and when each collector last ran.\n\n"
                . "USE THIS FIRST for almost any problem. It is one cheap call and it "
                . "usually tells you which direction to look, which saves three or four "
                . "more specific calls.\n\n"
                . "DO NOT use this to look at history - it returns a single moment, the "
                . "most recent one. For 'when did this start' or 'is this getting worse', "
                . "use get_measurement_history instead.\n\n"
                . "DO NOT call this more than once in a conversation unless several "
                . "minutes have passed or the user says something changed. The readings "
                . "are collected every few minutes and calling it again immediately "
                . "returns the same numbers.",
            'parameters' => [],
        ],

        'get_measurement_history' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'How one measurement has moved over time.',
            'description' =>
                "Returns a series of readings for ONE measurement over a period, "
                . "summarised as a handful of points with the minimum, maximum and "
                . "average of each. Use it to answer 'when did this start', 'is it "
                . "getting worse', or 'was it like this last week'.\n\n"
                . "DO NOT use this to find out the current value - get_current_status is "
                . "one call and gives you all of them.\n\n"
                . "Pick the shortest period that answers the question. Asking for 30 days "
                . "when the user is describing something that started this morning "
                . "produces a flat line and tells you nothing.",
            'parameters' => [
                'measurement' => [
                    'type' => 'string',
                    'enum' => ['disk_used_percent', 'inodes_used_percent', 'cpu_percent',
                               'memory_percent', 'load_per_core', 'disk_free_bytes'],
                    'required' => true,
                    'description' =>
                        'Which measurement. disk_used_percent and inodes_used_percent are '
                        . 'percentages of the filesystem the account writes to. cpu_percent '
                        . 'is of this ACCOUNT\'S allowance, not of the machine - inside '
                        . 'CageFS it can sit at 100 while the machine is idle, which is '
                        . 'what being throttled looks like and is not the same as the '
                        . 'server being busy. load_per_core is the load average divided by '
                        . 'the core count and expressed as a percentage, so 100 means one '
                        . 'runnable process per core.',
                ],
                'hours' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 8760,
                    'required' => true,
                    'description' => 'How far back to look, in hours. 24 for "today", 168 '
                        . 'for a week, 720 for a month.',
                ],
            ],
        ],

        'get_recent_alerts' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'Alerts this dashboard has raised.',
            'description' =>
                "Returns alerts raised by the monitor itself, newest first, with when, how "
                . "severe, and what they said.\n\n"
                . "USE THIS EARLY. The monitor may already have noticed and named the "
                . "problem the user is describing, in which case the alert's own text is a "
                . "better starting point than anything you would work out from raw "
                . "readings.\n\n"
                . "These are the monitor's own conclusions, NOT log lines from the server "
                . "or the application. For messages written by PHP, Apache or the "
                . "application, use get_recent_log_lines.",
            'parameters' => [
                'hours' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 2160,
                    'required' => false, 'default' => 168,
                    'description' => 'How far back to look, in hours. Defaults to a week.',
                ],
                'severity' => [
                    'type' => 'string',
                    'enum' => ['any', 'info', 'warning', 'error', 'critical'],
                    'required' => false, 'default' => 'any',
                    'description' => 'Only alerts at this level. Use "any" unless the user '
                        . 'has asked about severity specifically.',
                ],
                'limit' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 50,
                    'required' => false, 'default' => 20,
                    'description' => 'How many to return, newest first.',
                ],
            ],
        ],

        'get_recent_log_lines' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'Recent lines from the server and application logs.',
            'description' =>
                "Returns the newest lines across every log file this account can read - "
                . "PHP error logs, Apache error and access logs, and the dashboard's own "
                . "log - merged into one time-ordered list. Each line comes back already "
                . "split into its timestamp, severity, what wrote it, and the message, "
                . "with any stack trace attached to the error it belongs to.\n\n"
                . "USE THIS when the user describes something failing, a page erroring, a "
                . "500, a blank page, or anything where software rather than capacity is "
                . "the suspect.\n\n"
                . "DO NOT use this to investigate a full disk or high CPU unless the logs "
                . "are what is filling the disk. Capacity problems are answered by "
                . "get_current_status and find_largest_paths.\n\n"
                . "Filter by level when the user is describing a failure: unfiltered, the "
                . "twenty newest lines on a busy server are usually twenty access-log "
                . "entries and tell you nothing.",
            'parameters' => [
                'level' => [
                    'type' => 'string',
                    'enum' => ['any', 'warning', 'error', 'critical'],
                    'required' => false, 'default' => 'any',
                    'description' => 'Only lines at this level or worse. "error" is the '
                        . 'usual choice when investigating a failure. Lines that announce '
                        . 'no level at all - most access-log entries - are excluded by '
                        . 'anything other than "any".',
                ],
                'limit' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 60,
                    'required' => false, 'default' => 25,
                    'description' => 'How many lines to return, newest first.',
                ],
                'contains' => [
                    'type' => 'string', 'maxLength' => 120,
                    'required' => false,
                    'description' =>
                        'Plain text that must appear somewhere in the line. This is a '
                        . 'literal substring match, NOT a pattern - regular expression '
                        . 'syntax is matched as literal characters. Use it to follow up a '
                        . 'specific filename, function or error the user has quoted. Leave '
                        . 'it out to see everything.',
                ],
            ],
        ],

        'get_repeated_log_lines' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'Which log messages keep coming back.',
            'description' =>
                "Groups recent log lines by shape - numbers, addresses and paths replaced "
                . "with placeholders - and returns the most frequent, with a count and an "
                . "example of each.\n\n"
                . "USE THIS when a log is growing quickly, when a disk is filling with "
                . "logs, or when the user says something is 'flooding' or 'spamming'. Ten "
                . "thousand lines differing only in a request id are ONE event with a "
                . "count of ten thousand, and that is the only presentation of it anybody "
                . "can act on.\n\n"
                . "DO NOT use this to read a specific error. It deliberately destroys the "
                . "details that make one line different from another - use "
                . "get_recent_log_lines for that.",
            'parameters' => [
                'limit' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 25,
                    'required' => false, 'default' => 10,
                    'description' => 'How many distinct shapes to return, most frequent first.',
                ],
            ],
        ],

        'find_largest_paths' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'The largest and fastest-growing files and directories.',
            'description' =>
                "Returns the biggest paths under the watched directory, or the ones that "
                . "have grown most since the previous scan, with their sizes and rates.\n\n"
                . "USE THIS ONLY FOR DISK SPACE PROBLEMS - a disk filling up, a disk "
                . "already full, or a question about what is using the space. Sort by "
                . "'growth' rather than 'size' when the question is 'what changed', "
                . "because the largest directory on a server is usually the largest "
                . "directory on that server every day and is not news.\n\n"
                . "DO NOT use this for inode exhaustion. Running out of inodes is about "
                . "the NUMBER of files, not their size, and the directory responsible is "
                . "often tiny - use find_crowded_directories.\n\n"
                . "DO NOT use this for CPU, memory or network problems. It reads a stored "
                . "scan and has nothing to say about any of them.\n\n"
                . "The figures come from the last completed scan, which may be hours old. "
                . "The reply says when it ran; say so to the user rather than presenting "
                . "stale sizes as current.",
            'parameters' => [
                'sort_by' => [
                    'type' => 'string', 'enum' => ['size', 'growth'],
                    'required' => true,
                    'description' => '"size" for the biggest, "growth" for what has grown '
                        . 'most since the previous scan. Use "growth" for "what changed" '
                        . 'and "size" for "what is using all the space".',
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => ['any', 'logs', 'backups', 'cache', 'temp', 'trash',
                               'media', 'code', 'other'],
                    'required' => false, 'default' => 'any',
                    'description' => 'Narrow to one kind of file. Use "logs" when a log is '
                        . 'suspected, "backups" when a backup job is suspected.',
                ],
                'limit' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 40,
                    'required' => false, 'default' => 15,
                    'description' => 'How many paths to return.',
                ],
            ],
        ],

        'find_crowded_directories' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'Directories holding the most files.',
            'description' =>
                "Returns directories by how many FILES they contain, not by size.\n\n"
                . "USE THIS ONLY FOR INODE PROBLEMS - 'no space left on device' when df "
                . "shows free space, a failure to create a file on a disk that is not "
                . "full, or an explicit inode warning. The answer is nearly always a "
                . "session, cache or mail-queue directory holding hundreds of thousands of "
                . "tiny files, and it will not appear anywhere in a list sorted by size.\n\n"
                . "DO NOT use this for ordinary 'the disk is full' questions.",
            'parameters' => [
                'limit' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 30,
                    'required' => false, 'default' => 15,
                    'description' => 'How many directories to return, most files first.',
                ],
            ],
        ],

        'get_busiest_processes' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'What is running and what it is using.',
            'description' =>
                "Returns the processes recorded at the last collection, with their process "
                . "id, owner, CPU and memory use, how long each has been running, and its "
                . "command line.\n\n"
                . "USE THIS FOR CPU OR MEMORY PROBLEMS ONLY. The two things worth looking "
                . "for are a process using far more than the others, and a process that "
                . "has been running far longer than it should have - a request handler "
                . "alive for six hours is stuck, whatever its CPU says.\n\n"
                . "DO NOT use this for disk space or network problems.\n\n"
                . "This is a snapshot from the last collector run, not a live view, so a "
                . "process that has since exited may still be listed and one that started "
                . "since will not be. Say so if the timing matters.",
            'parameters' => [
                'sort_by' => [
                    'type' => 'string', 'enum' => ['cpu', 'memory', 'age'],
                    'required' => true,
                    'description' => 'Which column to sort by, largest first. Use "age" to '
                        . 'find stuck processes.',
                ],
                'limit' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 30,
                    'required' => false, 'default' => 12,
                    'description' => 'How many processes to return.',
                ],
            ],
        ],

        'get_site_check_results' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'Whether the watched addresses are answering.',
            'description' =>
                "Returns the configured URL checks with their most recent result, response "
                . "time, and how often each has failed recently.\n\n"
                . "USE THIS WHEN THE USER SAYS THE SITE IS DOWN, SLOW, OR RETURNING "
                . "ERRORS. These are the only measurements here taken from outside the "
                . "machine: every internal reading can be perfectly healthy while the site "
                . "returns 500, and this is the one thing that would notice.\n\n"
                . "DO NOT use this for problems the user has described as internal - a "
                . "full disk, a slow database, a cron that stopped.\n\n"
                . "If it returns no checks at all, say so: it means nobody has configured "
                . "one, not that the site is fine.",
            'parameters' => [
                'hours' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 720,
                    'required' => false, 'default' => 24,
                    'description' => 'How far back to summarise failures.',
                ],
            ],
        ],

        'get_network_summary' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'Ports, connections and recent traffic.',
            'description' =>
                "Returns which ports are listening, how many established connections there "
                . "are grouped by the address at the other end, and a summary of the most "
                . "recent packet capture if one has been taken.\n\n"
                . "USE THIS ONLY FOR NETWORK PROBLEMS: unexpected traffic, a suspected "
                . "flood or scan, a service that should be listening and is not, or a "
                . "question about who is connecting.\n\n"
                . "DO NOT use this for a site that is down - that is "
                . "get_site_check_results. DO NOT use it for slowness unless the user has "
                . "specifically mentioned bandwidth or connections; a slow site is far "
                . "more often disk, CPU or the application.\n\n"
                . "One address holding hundreds of connections is the shape worth "
                . "reporting; a long list of addresses with one each is ordinary web "
                . "traffic.",
            'parameters' => [],
        ],

        'get_collector_health' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'Whether the monitoring itself is working.',
            'description' =>
                "Returns when each collector last ran, how often it usually runs, and "
                . "whether it is late.\n\n"
                . "USE THIS BEFORE CONCLUDING THAT ANYTHING IS FINE. Every chart on this "
                . "dashboard goes quiet when a collector stops, and quiet looks exactly "
                . "like healthy. If the user says 'it looks fine but something is wrong', "
                . "check this first - the answer is often that the readings stopped three "
                . "days ago.\n\n"
                . "Also use it when readings look impossibly flat or a gap appears in a "
                . "chart.",
            'parameters' => [],
        ],

        'get_recent_changes' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'Software that changed underneath.',
            'description' =>
                "Returns changes noticed in installed software - the kernel, PHP, OpenSSL, "
                . "the C library, and the programs this dashboard watches - with what each "
                . "was and what it became.\n\n"
                . "USE THIS WHENEVER THE USER SAYS SOMETHING WORKED BEFORE AND DOES NOT "
                . "NOW. It is the single most common cause and the least visible: a "
                . "package update at three in the morning explains a great deal of "
                . "otherwise inexplicable behaviour.\n\n"
                . "The times are when the change was NOTICED, not when it happened - this "
                . "is recorded on a probe rather than continuously. Say so rather than "
                . "asserting a change happened at a particular minute.",
            'parameters' => [
                'hours' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 8760,
                    'required' => false, 'default' => 336,
                    'description' => 'How far back to look. Defaults to a fortnight.',
                ],
            ],
        ],

        'get_action_history' => [
            'group' => 'diagnostic',
            'where' => 'server',
            'permission' => false,
            'summary' => 'What has been done through this dashboard.',
            'description' =>
                "Returns the audit log of actions taken through this dashboard: files and "
                . "directories deleted, scans started, processes stopped, settings changed, "
                . "sign-ins.\n\n"
                . "USE THIS when something has disappeared, when the user asks 'did I "
                . "delete that', or when a change needs to be traced to a moment.\n\n"
                . "DO NOT use this to look for errors - it records deliberate actions, not "
                . "failures. Failures are in get_recent_log_lines.",
            'parameters' => [
                'hours' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 2160,
                    'required' => false, 'default' => 168,
                    'description' => 'How far back to look.',
                ],
                'limit' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 40,
                    'required' => false, 'default' => 20,
                    'description' => 'How many entries, newest first.',
                ],
            ],
        ],

        /* ---------------------------------------------------- the browser -- */

        'open_page' => [
            'group' => 'navigation',
            'where' => 'browser',
            'permission' => false,
            'summary' => 'move you to a page of this dashboard.',
            'description' =>
                "Opens one of this dashboard's pages in the user's browser. The chat stays "
                . "open and the conversation continues - this does not interrupt anything, "
                . "it just changes what is behind the panel.\n\n"
                . "USE THIS when you have found something and the user should see it for "
                . "themselves, or when the page has a control they need - a button to "
                . "empty a directory, a form to add a check. Say why you are taking them "
                . "there in the same message.\n\n"
                . "DO NOT use this to gather information. It shows the user a page; it "
                . "does not tell YOU anything. The diagnostic tools are how you find "
                . "things out, and calling this instead wastes a turn and moves the user "
                . "somewhere for no reason.\n\n"
                . "DO NOT call it more than once per message. Two navigations in a row "
                . "means the user never sees the first.",
            'parameters' => [
                'page' => [
                    'type' => 'string',
                    'enum' => [],
                    'required' => true,
                    'description' => 'Which page to open. The list is fixed; anything not '
                        . 'in it is refused.',
                ],
                'hours' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 8760,
                    'required' => false,
                    'description' => 'Set the page\'s time range to this many hours, so the '
                        . 'user arrives looking at the period being discussed rather than '
                        . 'the default.',
                ],
                'why' => [
                    'type' => 'string', 'maxLength' => 200, 'required' => true,
                    'description' => 'One short sentence shown to the user explaining why '
                        . 'they are being taken here. Write it for them, not for yourself: '
                        . '"so you can see which directories grew" rather than "navigating '
                        . 'to storage".',
                ],
            ],
        ],

        'take_screenshot' => [
            'group' => 'navigation',
            'where' => 'browser',
            'permission' => true,
            'summary' => 'take a picture of what you can see.',
            'description' =>
                "Captures an image of the page the user currently has open and returns it "
                . "to you.\n\n"
                . "USE THIS SPARINGLY and only when the shape of something matters more "
                . "than its numbers: a chart the user is describing in words, a layout "
                . "problem, or a value they can see and you cannot find. Images cost far "
                . "more tokens than text - a screenshot is worth roughly a thousand words "
                . "of data you could have fetched precisely.\n\n"
                . "DO NOT use this to read data that a diagnostic tool returns. If you "
                . "want the disk figures, call get_current_status; a picture of them is "
                . "worse in every way.\n\n"
                . "DO NOT use it as a first step. Find out what you can from the data "
                . "first, and reach for this when the user's description and the data "
                . "disagree.\n\n"
                . "The user is asked before each capture and may refuse.",
            'parameters' => [
                'why' => [
                    'type' => 'string', 'maxLength' => 200, 'required' => true,
                    'description' => 'What you expect to see and why the data cannot tell '
                        . 'you. This is shown to the user when asking permission, so it '
                        . 'has to be a real reason they can judge.',
                ],
            ],
        ],
    ];
}

/**
 * The catalogue with the pages filled in.
 *
 * `open_page` can only name a view that exists, and the list of views lives in
 * the interface rather than here - so it is injected rather than duplicated,
 * which is the difference between one list and two that drift apart.
 */
function aiTools(array $views): array
{
    $catalogue = aiToolCatalogue();

    $catalogue['open_page']['parameters']['page']['enum'] = array_keys($views);
    $catalogue['open_page']['parameters']['page']['description'] .=
        "\n\nThe pages, and what each is for:\n"
        . implode("\n", array_map(
            fn(string $key, string $what): string => sprintf('  %s - %s', $key, $what),
            array_keys($views),
            array_values($views)
        ));

    return $catalogue;
}

/**
 * The tools actually offered, after the operator's settings.
 *
 * A tool switched off is not described to the model at all, rather than being
 * described and then refused. A model told about a tool it cannot use will keep
 * trying it, explain its absence to the user as a fault, and waste turns.
 *
 * @return array<string, array<string, mixed>>
 */
function aiEnabledTools(array $config, array $views): array
{
    $settings = $config['ai']['tools'] ?? [];
    $out = [];

    foreach (aiTools($views) as $name => $tool) {
        $mine = $settings[$name] ?? [];

        // Enabled unless explicitly turned off, except for anything that acts
        // rather than reads, which is off unless explicitly turned on.
        $on = array_key_exists('enabled', $mine)
            ? (bool)$mine['enabled']
            : $tool['where'] !== 'ask';

        if (!$on) {
            continue;
        }

        $tool['permission'] = array_key_exists('permission', $mine)
            ? (bool)$mine['permission']
            : (bool)$tool['permission'];

        $out[$name] = $tool;
    }

    return $out;
}

/**
 * The tool list in the shape a chat completions API expects.
 *
 * @return list<array<string, mixed>>
 */
function aiToolSchemas(array $tools): array
{
    $out = [];

    foreach ($tools as $name => $tool) {
        $properties = [];
        $required = [];

        foreach ($tool['parameters'] as $key => $spec) {
            $property = [
                'type' => $spec['type'],
                'description' => $spec['description'],
            ];

            foreach (['enum', 'minimum', 'maximum', 'maxLength'] as $extra) {
                if (isset($spec[$extra]) && $spec[$extra] !== []) {
                    $property[$extra] = $spec[$extra];
                }
            }

            $properties[$key] = $property;
            if (!empty($spec['required'])) {
                $required[] = $key;
            }
        }

        $out[] = [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $tool['description'],
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object)$properties,
                    'required' => $required,
                    /*
                     * No extra keys, ever.
                     *
                     * A model that invents a parameter is a model that has
                     * misunderstood the tool, and accepting the call anyway
                     * hides that until the answer turns out to be wrong.
                     */
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    return $out;
}

/**
 * Checks and coerces what the model passed, against the tool's own schema.
 *
 * Every value that reaches a query goes through here first. Types are coerced
 * where that is unambiguous - a model returning "24" for an integer is
 * extremely common and refusing it would be pedantry - and refused where it is
 * not.
 *
 * @return array{ok: bool, values: array<string, mixed>, why: string}
 */
function aiCheckArguments(array $tool, array $given): array
{
    $values = [];

    foreach ($given as $key => $value) {
        if (!isset($tool['parameters'][$key])) {
            return ['ok' => false, 'values' => [], 'why' => sprintf(
                'There is no parameter called "%s" on this tool. The parameters are: %s.',
                $key,
                implode(', ', array_keys($tool['parameters'])) ?: 'none'
            )];
        }
    }

    foreach ($tool['parameters'] as $key => $spec) {
        $missing = !array_key_exists($key, $given)
            || $given[$key] === null
            || $given[$key] === '';

        if ($missing) {
            if (!empty($spec['required'])) {
                return ['ok' => false, 'values' => [], 'why' => sprintf(
                    'The parameter "%s" is required and was not given.', $key
                )];
            }
            if (array_key_exists('default', $spec)) {
                $values[$key] = $spec['default'];
            }
            continue;
        }

        $value = $given[$key];

        if ($spec['type'] === 'integer') {
            if (!is_numeric($value) || (int)$value != $value) {
                return ['ok' => false, 'values' => [], 'why' => sprintf(
                    'The parameter "%s" must be a whole number; "%s" is not.',
                    $key,
                    is_scalar($value) ? (string)$value : gettype($value)
                )];
            }
            $number = (int)$value;

            /*
             * Clamped rather than refused.
             *
             * A model asking for 100000 rows has misjudged the scale, not made
             * an error worth a round trip - and the reply says what it was
             * given, so the misjudgement is visible rather than silent.
             */
            if (isset($spec['minimum'])) {
                $number = max((int)$spec['minimum'], $number);
            }
            if (isset($spec['maximum'])) {
                $number = min((int)$spec['maximum'], $number);
            }
            $values[$key] = $number;
            continue;
        }

        if (!is_string($value)) {
            return ['ok' => false, 'values' => [], 'why' => sprintf(
                'The parameter "%s" must be a string.', $key
            )];
        }

        if (!empty($spec['enum']) && !in_array($value, $spec['enum'], true)) {
            return ['ok' => false, 'values' => [], 'why' => sprintf(
                'The parameter "%s" must be one of: %s. "%s" is not.',
                $key,
                implode(', ', $spec['enum']),
                mb_substr($value, 0, 60)
            )];
        }

        if (isset($spec['maxLength']) && mb_strlen($value) > (int)$spec['maxLength']) {
            $value = mb_substr($value, 0, (int)$spec['maxLength']);
        }

        $values[$key] = $value;
    }

    return ['ok' => true, 'values' => $values, 'why' => ''];
}

/**
 * The pages the assistant may send somebody to, and what each is for.
 *
 * Written here rather than taken from the interface's own labels, because a
 * navigation label - "Over time" - is written for somebody who already knows
 * where they are, and a model choosing between fifty of them needs a sentence.
 *
 * @return array<string, string>
 */
function aiViews(): array
{
    return [
        'overview' => 'gauges for disk, inodes, CPU, memory and load, plus what the '
            . 'dashboard currently thinks is wrong',
        'charts' => 'those same five measurements plotted over the selected period',
        'storage' => 'disk use by category, the last scan, and a Scan now button',
        'storage-files' => 'the largest files and directories, and what has grown',
        'storage-links' => 'symlinks that are broken, looping, or leave the account',
        'storage-io' => 'how hard the disks are working',
        'log-stream' => 'the newest lines from every log at once, and repeated messages',
        'host-disk' => 'every filesystem on the machine and how full each is',
        'cpu' => 'processor use, for the account and for the machine',
        'host' => 'memory: what is using it and where it goes',
        'processes' => 'what is running, and what it is using',
        'network' => 'listening ports, connections, and packet captures',
        'net-urls' => 'checks that fetch addresses from outside and record the answer',
        'net-rdns' => 'names for addresses seen in captures and logs',
        'domains' => 'domain names being watched, and when each expires',
        'uptime' => 'when the machine was up and when each collector was reporting',
        'alerts' => 'every alert raised, with the evidence captured at the time',
        'capabilities' => 'which programs are installed and usable from this account',
        'sys-changed' => 'software that changed underneath - kernel, PHP, OpenSSL',
        'sys-account' => 'what the dashboard is storing and what it will grow to',
        'sys-retention' => 'how long each kind of record is kept',
        'sys-problems' => "the dashboard's own error log",
        'sys-notes' => 'notes people have written against pages and charts',
        'schedule' => 'when the collectors run and what they collect',
        'email' => 'alert email settings, severities and thresholds',
        'backups' => 'backups found on the machine and how old each is',
        'calendar' => 'renewals, expiries and events that are coming up',
    ];
}
