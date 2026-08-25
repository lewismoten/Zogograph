<?php
declare(strict_types=1);

/*
 * The conversation, as a state machine.
 *
 * PHP has no background worker here, so a turn cannot be a single long request
 * that runs to completion - a model deciding it needs four tools would hold one
 * connection open for a minute with nothing to show for it. Instead the
 * conversation is a row whose state advances one step per request, and the
 * browser polls it and drives the next step when one is due.
 *
 * That falls out well rather than being a compromise. The states are exactly
 * the things a person watching wants to see:
 *
 *   idle                nothing is happening; say something
 *   thinking            a step is due - the browser will ask for one
 *   waiting_browser     the model asked for something only the page can do
 *   waiting_permission  the model asked for something a person must allow
 *   stopped             somebody stopped it
 *   ended               a limit was reached
 *   failed              the model or the network gave up
 *
 * Closing the chat window does not touch any of this. A session outlives the
 * panel, because somebody following a suggestion to look at another page has
 * not finished the conversation, and making them start again would throw away
 * everything the model had worked out.
 */

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/aidb.php';
require_once __DIR__ . '/aitools.php';
require_once __DIR__ . '/airun.php';

/**
 * What the model is told about where it is, before anything else.
 *
 * Deliberately specific about the things it cannot know and would otherwise
 * assume: that CPU here means the account's allowance, that scans are
 * intermittent, that the collectors can stop. A model reasoning from the wrong
 * defaults gives confident answers that are wrong in ways nobody catches.
 */
function aiSystemPrompt(array $config, array $tools): string
{
    $root = (string)($config['scan_root'] ?? 'the watched directory');
    $host = php_uname('n');

    $lines = [
        "You are the troubleshooting assistant inside Hostscope, a monitoring dashboard "
        . "for a single web-hosting account and the machine it runs on.",
        "",
        "You are talking to the person who runs this account. They can see the dashboard; "
        . "you cannot, except through the tools.",
        "",
        "THE MACHINE",
        "  Hostname: {$host}",
        "  Watched directory: {$root}",
        "  Time here: " . date('Y-m-d H:i:s T'),
        "",
        "THINGS THAT ARE TRUE HERE AND ARE NOT TRUE EVERYWHERE",
        "  - This is usually a shared host with CageFS. CPU and memory percentages from "
        . "the account's own readings are percentages of what THIS ACCOUNT may use, not "
        . "of the machine. An account at 100% CPU on an idle machine is being throttled, "
        . "which is a real problem but a different one from a busy server.",
        "  - Disk figures come from periodic scans, not continuously. A scan may be hours "
        . "old. Every tool that returns scan data says when it ran; pass that on rather "
        . "than presenting a stale size as current.",
        "  - There are two collectors, one for the account and one run by root. Either can "
        . "stop, and when one stops its charts go quiet - which looks exactly like "
        . "healthy. If something seems impossibly fine, check get_collector_health.",
        "  - Some tools return nothing because a feature is not set up rather than because "
        . "there is no problem. Say which; they are opposite conclusions.",
        "",
        "HOW TO WORK",
        "  - Start with get_current_status and get_recent_alerts unless the user's "
        . "description points somewhere specific. They are cheap and usually narrow it "
        . "down.",
        "  - Read each tool's description before choosing it. Several say explicitly when "
        . "NOT to use them, and those are the cases where using them produces a confident "
        . "wrong answer.",
        "  - Prefer two precise tools over one broad one. Prefer no tool at all if the "
        . "user has asked something you can answer from what you already have.",
        "  - When something changed, check get_recent_changes. A package update explains "
        . "an enormous share of 'it worked yesterday'.",
        "",
        "HOW TO ANSWER",
        "  - Plain sentences. No headings, no bullet lists unless there are genuinely "
        . "several parallel items.",
        "  - Say what you measured and what you concluded, separately. 'The disk is 96% "
        . "full and the logs directory grew 4 GB since yesterday' is a measurement; "
        . "'something is writing to error_log in a loop' is a conclusion, and it should be "
        . "marked as one.",
        "  - Never state a cause you have not measured. If you have a suspicion, say it is "
        . "a suspicion and say what would confirm it.",
        "  - If a tool returned nothing, say so rather than filling the gap.",
        "  - Do not suggest running shell commands unless asked. This person may not have "
        . "shell access, and the dashboard can usually do it.",
        "",
        "TOOLS AVAILABLE TO YOU RIGHT NOW: " . (
            $tools === [] ? 'none' : implode(', ', array_keys($tools))
        ),
    ];

    if ($tools === []) {
        $lines[] = "";
        $lines[] = "All tools are switched off, so you can only reason from what the user "
            . "tells you. Say so if you are asked something that needs data.";
    }

    return implode("\n", $lines);
}

/** Starts a conversation. */
function aiSessionStart(array $config, PDO $ai, string $who, string $from = ''): int
{
    $settings = aiSettings($config);

    $ai->prepare(
        'INSERT INTO ai_sessions
            (started_at, last_at, state, model, started_by, opened_from,
             max_seconds, max_millicents, max_turns)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        time(), time(), 'idle', $settings['model'], $who, $from,
        $settings['max_seconds'],
        // Cents to millicents, so the limit and the running total are the same
        // unit and no conversion happens at comparison time.
        (int)round($settings['max_cents'] * 1000),
        $settings['max_turns'],
    ]);

    return (int)$ai->lastInsertId();
}

/** One session as a row, or null. */
function aiSession(PDO $ai, int $id): ?array
{
    $stmt = $ai->prepare('SELECT * FROM ai_sessions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** The newest session that has not finished, if there is one. */
function aiOpenSession(PDO $ai): ?array
{
    $row = $ai->query(
        "SELECT * FROM ai_sessions
          WHERE state NOT IN ('stopped', 'ended', 'failed')
          ORDER BY id DESC LIMIT 1"
    )->fetch();

    return $row === false ? null : $row;
}

function aiSetState(PDO $ai, int $id, string $state, ?string $reason = null): void
{
    $ended = in_array($state, ['stopped', 'ended', 'failed'], true);

    $ai->prepare(
        'UPDATE ai_sessions SET state = ?, last_at = ?, stop_reason = COALESCE(?, stop_reason),
                ended_at = CASE WHEN ? = 1 THEN ? ELSE ended_at END
          WHERE id = ?'
    )->execute([$state, time(), $reason, $ended ? 1 : 0, time(), $id]);
}

/** Adds a message to the transcript. */
function aiAddMessage(
    PDO $ai,
    int $session,
    string $role,
    ?string $content,
    array $extra = []
): int {
    $ai->prepare(
        'INSERT INTO ai_messages (session_id, at, role, content, tool_name, tool_id,
                                  tool_args, visible)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $session, time(), $role, $content,
        $extra['tool_name'] ?? null,
        $extra['tool_id'] ?? null,
        isset($extra['tool_args']) ? json_encode($extra['tool_args']) : null,
        array_key_exists('visible', $extra) ? (int)$extra['visible'] : 1,
    ]);

    return (int)$ai->lastInsertId();
}

/**
 * Whether this session may keep going.
 *
 * Checked before every step rather than after, so a limit stops the next call
 * rather than being noticed once the money is spent.
 *
 * @return array{ok: bool, why: string}
 */
function aiWithinLimits(array $session): array
{
    $ran = time() - (int)$session['started_at'];

    if ((int)$session['max_seconds'] > 0 && $ran > (int)$session['max_seconds']) {
        return ['ok' => false, 'why' => sprintf(
            'This session has been running for %s, which is the limit set in Settings.',
            plainSpan($ran, true)
        )];
    }

    if ((int)$session['max_millicents'] > 0
        && (int)$session['spent_millicents'] >= (int)$session['max_millicents']) {
        return ['ok' => false, 'why' => sprintf(
            'This session has spent about %s, which is the limit set in Settings.',
            aiMoney((int)$session['spent_millicents'])
        )];
    }

    if ((int)$session['max_turns'] > 0 && (int)$session['turns'] >= (int)$session['max_turns']) {
        return ['ok' => false, 'why' => sprintf(
            'This session has taken %d turns, which is the limit set in Settings.',
            (int)$session['turns']
        )];
    }

    return ['ok' => true, 'why' => ''];
}

/**
 * The transcript, in the shape the model expects.
 *
 * Rebuilt from the database on every step rather than held anywhere. The
 * database is the conversation - there is no other copy - which is what makes
 * closing the window harmless and what makes the whole thing auditable.
 *
 * @return list<array<string, mixed>>
 */
function aiTranscript(PDO $ai, int $session): array
{
    $stmt = $ai->prepare(
        'SELECT role, content, tool_name, tool_id, tool_args FROM ai_messages
          WHERE session_id = ? ORDER BY id'
    );
    $stmt->execute([$session]);

    $out = [];
    $pendingCalls = [];

    foreach ($stmt->fetchAll() as $row) {
        $role = (string)$row['role'];

        if ($role === 'tool_call') {
            /*
             * Several tool calls in one assistant turn are one message.
             *
             * Every provider requires that shape, and storing them as separate
             * rows is what makes the transcript readable - so they are
             * collected here and flushed when the next non-call row arrives.
             */
            $pendingCalls[] = [
                'id' => (string)$row['tool_id'],
                'type' => 'function',
                'function' => [
                    'name' => (string)$row['tool_name'],
                    'arguments' => (string)($row['tool_args'] ?? '{}'),
                ],
            ];
            continue;
        }

        if ($pendingCalls !== []) {
            $out[] = [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => $pendingCalls,
            ];
            $pendingCalls = [];
        }

        if ($role === 'tool') {
            $out[] = [
                'role' => 'tool',
                'tool_call_id' => (string)$row['tool_id'],
                'content' => (string)($row['content'] ?? ''),
            ];
            continue;
        }

        if ($role === 'user' || $role === 'assistant') {
            $out[] = ['role' => $role, 'content' => (string)($row['content'] ?? '')];
        }
    }

    if ($pendingCalls !== []) {
        $out[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => $pendingCalls];
    }

    return $out;
}

/**
 * Advances the conversation by one step.
 *
 * Exactly one request to the model, and then whatever server-side tools it
 * asked for. Anything needing a person or a browser leaves the session parked
 * in a waiting state and returns; the next step happens when that is answered.
 *
 * @return array<string, mixed> the session's new state, for the caller to return
 */
function aiStep(
    array $config,
    PDO $ai,
    PDO $pdo,
    int $id,
    array $views,
    ?callable $ask = null
): array {
    $session = aiSession($ai, $id);
    if ($session === null) {
        throw new ActionRefused('no such session');
    }

    if ($session['state'] !== 'thinking') {
        return aiSessionView($config, $ai, $id, $views);
    }

    $limits = aiWithinLimits($session);
    if (!$limits['ok']) {
        aiAddMessage($ai, $id, 'assistant', $limits['why'] . ' Start a new one to carry on.');
        aiSetState($ai, $id, 'ended', $limits['why']);
        return aiSessionView($config, $ai, $id, $views);
    }

    $settings = aiSettings($config);
    $tools = aiEnabledTools($config, $views);
    $schemas = aiToolSchemas($tools);

    /*
     * The one call that leaves this machine, behind a seam.
     *
     * Passed in rather than called directly so the whole loop - dispatch,
     * permission, the browser round trip, the accounting - can be exercised
     * against a scripted model with no network. Same shape as the fetcher
     * urlWatchRunDue takes, for the same reason.
     */
    $ask ??= 'aiAsk';

    $answer = $ask(
        $settings,
        aiTranscript($ai, $id),
        $schemas,
        aiSystemPrompt($config, $tools)
    );

    $millicents = $answer['ok']
        ? aiCost($settings, $answer['tokens_in'] ?? 0, $answer['tokens_out'] ?? 0,
            $answer['tokens_cached'] ?? 0)
        : 0;

    /*
     * Recorded before anything is done with it.
     *
     * Including on failure: a request that was sent and refused still left this
     * machine, and "what exactly was sent" has to be answerable without
     * qualification afterwards.
     */
    $ai->prepare(
        'INSERT INTO ai_calls (session_id, at, ms, model, tokens_in, tokens_out,
                               tokens_cached, millicents, request, response, error, http_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id, time(), (int)($answer['ms'] ?? 0), $settings['model'],
        (int)($answer['tokens_in'] ?? 0), (int)($answer['tokens_out'] ?? 0),
        (int)($answer['tokens_cached'] ?? 0), $millicents,
        json_encode($answer['request'] ?? null, JSON_PARTIAL_OUTPUT_ON_ERROR),
        json_encode($answer['response'] ?? null, JSON_PARTIAL_OUTPUT_ON_ERROR),
        $answer['error'] ?? null,
        (int)($answer['status'] ?? 0),
    ]);
    $callId = (int)$ai->lastInsertId();

    $ai->prepare(
        'UPDATE ai_sessions SET turns = turns + 1, last_at = ?,
                tokens_in = tokens_in + ?, tokens_out = tokens_out + ?,
                tokens_cached = tokens_cached + ?, spent_millicents = spent_millicents + ?
          WHERE id = ?'
    )->execute([
        time(), (int)($answer['tokens_in'] ?? 0), (int)($answer['tokens_out'] ?? 0),
        (int)($answer['tokens_cached'] ?? 0), $millicents, $id,
    ]);

    if (!$answer['ok']) {
        aiAddMessage($ai, $id, 'assistant',
            'I could not reach the model: ' . $answer['error']);
        aiSetState($ai, $id, 'failed', $answer['error']);
        return aiSessionView($config, $ai, $id, $views);
    }

    $text = trim((string)($answer['text'] ?? ''));
    $calls = $answer['tool_calls'] ?? [];

    if ($text !== '') {
        aiAddMessage($ai, $id, 'assistant', $text);
    }

    if ($calls === []) {
        aiSetState($ai, $id, 'idle');
        return aiSessionView($config, $ai, $id, $views);
    }

    return aiHandleCalls($config, $ai, $pdo, $id, $callId, $calls, $tools, $views);
}

/**
 * Deals with the tools the model asked for.
 *
 * Server tools run immediately. Anything needing a person or a browser is
 * recorded and the session parks. A call that fails validation is answered with
 * the reason rather than being dropped, because a model told what it got wrong
 * fixes it on the next turn and one told nothing repeats it.
 */
function aiHandleCalls(
    array $config,
    PDO $ai,
    PDO $pdo,
    int $id,
    int $callId,
    array $calls,
    array $tools,
    array $views
): array {
    $parked = false;
    $toRun = [];

    /*
     * Two passes: every call is recorded before any is run.
     *
     * A model that asked for two tools in one turn made ONE turn, and writing
     * each call immediately followed by its result splits it into two in the
     * transcript - the model then reads its own history as though it had gone
     * back and forth when it had not. Recording all the calls first keeps them
     * in a single assistant message, which is also the shape every provider
     * expects when several results follow.
     */
    foreach ($calls as $call) {
        $name = (string)$call['name'];
        $arguments = is_array($call['arguments']) ? $call['arguments'] : [];

        aiAddMessage($ai, $id, 'tool_call', null, [
            'tool_name' => $name,
            'tool_id' => (string)$call['id'],
            'tool_args' => $arguments,
        ]);

        $toRun[] = [$name, $arguments, (string)$call['id']];
    }

    foreach ($toRun as [$name, $arguments, $toolId]) {
        if (!isset($tools[$name])) {
            /*
             * An invented tool is answered, not ignored.
             *
             * A model whose call vanishes will try it again; one told the tool
             * does not exist and which ones do will pick a real one next turn.
             */
            aiRecordToolResult($ai, $id, $toolId, $name, [
                'error' => sprintf(
                    'There is no tool called "%s" available to you. The tools you have are: '
                    . '%s. Use one of those, or answer without a tool.',
                    $name,
                    implode(', ', array_keys($tools))
                ),
            ]);
            continue;
        }

        $tool = $tools[$name];
        $checked = aiCheckArguments($tool, $arguments);

        if (!$checked['ok']) {
            aiRecordToolResult($ai, $id, $toolId, $name, [
                'error' => $checked['why'] . ' Call it again with the parameters corrected.',
            ]);
            continue;
        }

        $needsPermission = (bool)$tool['permission'];
        $where = (string)$tool['where'];

        $ai->prepare(
            'INSERT INTO ai_tool_runs (session_id, call_id, at, tool, tool_id, arguments,
                                       runs_where, state, needs_permission)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $callId, time(), $name, $toolId,
            json_encode($checked['values']), $where,
            $needsPermission ? 'proposed' : 'allowed',
            $needsPermission ? 1 : 0,
        ]);
        $runId = (int)$ai->lastInsertId();

        if ($needsPermission) {
            $parked = 'waiting_permission';
            continue;
        }

        if ($where === 'browser') {
            $parked = $parked ?: 'waiting_browser';
            continue;
        }

        aiRunAndRecord($config, $ai, $pdo, $id, $runId, $name, $checked['values'], $toolId);
    }

    aiSetState($ai, $id, $parked ?: 'thinking');
    return aiSessionView($config, $ai, $id, $views);
}

/** Runs a server tool and writes both the run row and the model's result message. */
function aiRunAndRecord(
    array $config,
    PDO $ai,
    PDO $pdo,
    int $session,
    int $runId,
    string $name,
    array $values,
    string $toolId
): void {
    $started = microtime(true);
    $result = aiRunTool($name, $values, $config, $pdo);
    $ms = (int)round((microtime(true) - $started) * 1000);

    $ai->prepare(
        'UPDATE ai_tool_runs SET state = ?, settled_at = ?, result = ?, ms = ?, error = ?
          WHERE id = ?'
    )->execute([
        isset($result['error']) ? 'failed' : 'done',
        time(),
        aiEncodeResult($result),
        $ms,
        $result['error'] ?? null,
        $runId,
    ]);

    aiRecordToolResult($ai, $session, $toolId, $name, $result);
}

/** The tool's answer, as a message the model will read on the next step. */
function aiRecordToolResult(PDO $ai, int $session, string $toolId, string $name, array $result): void
{
    aiAddMessage($ai, $session, 'tool', aiEncodeResult($result), [
        'tool_name' => $name,
        'tool_id' => $toolId,
    ]);
}

/**
 * Everything the browser needs to draw the conversation.
 *
 * Deliberately one call. The panel polls this several times a second while a
 * turn is running, and a design needing three round trips to know what to show
 * would triple that.
 *
 * @return array<string, mixed>
 */
function aiSessionView(array $config, PDO $ai, int $id, array $views): array
{
    $session = aiSession($ai, $id);
    if ($session === null) {
        return ['exists' => false];
    }

    $settings = aiSettings($config);

    $messages = $ai->prepare(
        'SELECT id, at, role, content, tool_name, tool_args FROM ai_messages
          WHERE session_id = ? ORDER BY id'
    );
    $messages->execute([$id]);

    $shown = [];
    foreach ($messages->fetchAll() as $row) {
        $role = (string)$row['role'];

        /*
         * Tool results are summarised, not shown.
         *
         * A reply can be twelve kilobytes of JSON, and putting that in a chat
         * panel buries the conversation it belongs to. What somebody wants to
         * see is that a tool ran and which one - the whole result is on the
         * session's own page for anybody who wants it.
         */
        if ($role === 'tool') {
            continue;
        }

        if ($role === 'tool_call') {
            $shown[] = [
                'id' => (int)$row['id'],
                'at' => (int)$row['at'],
                'role' => 'tool_call',
                'tool' => (string)$row['tool_name'],
                'arguments' => json_decode((string)($row['tool_args'] ?? '{}'), true) ?: [],
            ];
            continue;
        }

        $shown[] = [
            'id' => (int)$row['id'],
            'at' => (int)$row['at'],
            'role' => $role,
            'content' => (string)($row['content'] ?? ''),
        ];
    }

    $runs = $ai->prepare(
        'SELECT id, tool, arguments, runs_where, state, needs_permission, ms, error
           FROM ai_tool_runs WHERE session_id = ? ORDER BY id'
    );
    $runs->execute([$id]);
    $toolRuns = $runs->fetchAll();

    $waiting = array_values(array_filter($toolRuns, fn(array $run): bool =>
        $run['state'] === 'proposed'));

    $forBrowser = array_values(array_filter($toolRuns, fn(array $run): bool =>
        $run['state'] === 'allowed' && $run['runs_where'] === 'browser'));

    $all = aiTools($views);

    return [
        'exists' => true,
        'id' => (int)$session['id'],
        'state' => (string)$session['state'],
        'stop_reason' => $session['stop_reason'],
        'started_at' => (int)$session['started_at'],
        'model' => (string)$session['model'],
        'turns' => (int)$session['turns'],
        'messages' => $shown,
        'tokens' => [
            'in' => (int)$session['tokens_in'],
            'out' => (int)$session['tokens_out'],
            'cached' => (int)$session['tokens_cached'],
        ],
        'spent' => [
            'millicents' => (int)$session['spent_millicents'],
            'said' => aiMoney((int)$session['spent_millicents']),
            /*
             * Whether the figure means anything.
             *
             * Without prices configured the cost is zero, which is not "free" -
             * it is "not known". Saying which stops somebody reading a zero as
             * a reassurance.
             */
            'known' => $settings['price_in'] > 0 || $settings['price_out'] > 0,
        ],
        'limits' => [
            'max_seconds' => (int)$session['max_seconds'],
            'max_millicents' => (int)$session['max_millicents'],
            'max_turns' => (int)$session['max_turns'],
            'ran_for' => time() - (int)$session['started_at'],
        ],
        // The one the browser has to run next, if any.
        'browser_tool' => $forBrowser === [] ? null : aiToolRunForBrowser($forBrowser[0], $all),
        'permission' => $waiting === [] ? null : aiToolRunForBrowser($waiting[0], $all),
        'tool_runs' => array_map(fn(array $run): array => [
            'id' => (int)$run['id'],
            'tool' => (string)$run['tool'],
            'summary' => $all[$run['tool']]['summary'] ?? '',
            'state' => (string)$run['state'],
            'ms' => (int)$run['ms'],
            'error' => $run['error'],
        ], $toolRuns),
    ];
}

/** One pending tool run, with what the browser needs to act on it. */
function aiToolRunForBrowser(array $run, array $catalogue): array
{
    $arguments = json_decode((string)$run['arguments'], true) ?: [];

    return [
        'id' => (int)$run['id'],
        'tool' => (string)$run['tool'],
        'summary' => $catalogue[$run['tool']]['summary'] ?? '',
        'arguments' => $arguments,
        'why' => (string)($arguments['why'] ?? ''),
        'needs_permission' => (bool)$run['needs_permission'],
    ];
}
