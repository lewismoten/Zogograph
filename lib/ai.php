<?php
declare(strict_types=1);

/*
 * Talking to a model, and keeping the bill.
 *
 * The provider is configurable because there is no good reason for a monitoring
 * tool to have an opinion about which one somebody uses, and because the
 * request shape is the same everywhere that matters: OpenAI's chat completions
 * format with tool calling, which Anthropic, Google, Groq, Together, Mistral,
 * Ollama and llama.cpp all either speak natively or expose a compatible
 * endpoint for.
 *
 * Money is counted in millicents - thousandths of a cent, as integers. Prices
 * are quoted per million tokens and a session can be hundreds of calls, and a
 * budget enforced against a float that drifts by a fraction each turn is a
 * budget that stops working somewhere past the third decimal place.
 *
 * Everything sent and everything received is written down before anything is
 * done with it. This is a tool that reads a machine's logs and hands them to
 * somebody else's service, and "what exactly was sent" has to be answerable
 * afterwards without qualification.
 */

require_once __DIR__ . '/spans.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/aidb.php';

/** How long one request to the model may take before it is abandoned. */
const AI_TIMEOUT = 120;

/** How many tool-calling rounds one user message may cause. */
const AI_MAX_STEPS = 12;

/**
 * The providers this knows how to talk to.
 *
 * `base` is the default endpoint; anything speaking the same protocol can be
 * pointed at by overriding it, which is how a local model is used.
 */
function aiProviders(): array
{
    return [
        'openai' => [
            'label' => 'OpenAI',
            'base' => 'https://api.openai.com/v1',
            'auth' => 'bearer',
            'note' => 'Also the shape used by Groq, Together, Fireworks, DeepSeek, '
                . 'OpenRouter and most others. Point the address at theirs.',
            'models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1'],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'base' => 'https://api.anthropic.com/v1',
            'auth' => 'x-api-key',
            'note' => 'Uses its own message format, which this translates to and from.',
            'models' => ['claude-sonnet-4-5', 'claude-haiku-4-5', 'claude-opus-4-5'],
        ],
        'compatible' => [
            'label' => 'Anything OpenAI-compatible',
            'base' => 'http://127.0.0.1:11434/v1',
            'auth' => 'bearer',
            'note' => 'Ollama, llama.cpp, LM Studio, vLLM, or a gateway of your own. '
                . 'A local model needs no key and nothing leaves the machine, which for '
                . 'a tool that reads your logs is worth considering.',
            'models' => [],
        ],
    ];
}

/**
 * The assistant's settings, with the defaults filled in.
 *
 * @return array<string, mixed>
 */
function aiSettings(array $config): array
{
    $set = $config['ai'] ?? [];
    $provider = (string)($set['provider'] ?? 'openai');
    $providers = aiProviders();

    if (!isset($providers[$provider])) {
        $provider = 'openai';
    }

    return [
        'enabled' => (bool)($set['enabled'] ?? false),
        'provider' => $provider,
        'base_url' => trim((string)($set['base_url'] ?? '')) ?: $providers[$provider]['base'],
        'model' => trim((string)($set['model'] ?? '')) ?: ($providers[$provider]['models'][0] ?? ''),
        // Never returned to the browser; see aiSettingsView.
        'api_key' => (string)($set['api_key'] ?? ''),

        /*
         * Prices per million tokens, in cents.
         *
         * Optional: without them everything still works and the session simply
         * reports token counts rather than money. A wrong price is worse than
         * no price, so nothing is guessed from the model name.
         */
        'price_in' => (float)($set['price_in'] ?? 0),
        'price_out' => (float)($set['price_out'] ?? 0),
        'price_cached' => (float)($set['price_cached'] ?? 0),

        // Limits on one session. Zero means no limit of that kind.
        'max_seconds' => max(0, (int)($set['max_seconds'] ?? 3600)),
        'max_cents' => max(0, (float)($set['max_cents'] ?? 50)),
        'max_turns' => max(0, (int)($set['max_turns'] ?? 40)),

        'tools' => $set['tools'] ?? [],
    ];
}

/** Whether there is enough configured to try. */
function aiReady(array $config): array
{
    $set = aiSettings($config);

    if (!$set['enabled']) {
        return ['ok' => false, 'why' => 'The assistant is switched off in Settings.'];
    }
    if ($set['model'] === '') {
        return ['ok' => false, 'why' => 'No model has been chosen in Settings.'];
    }
    if ($set['api_key'] === '' && $set['provider'] !== 'compatible') {
        return ['ok' => false, 'why' => 'No API key has been set in Settings.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'why' => 'This PHP has no curl, so it cannot reach a model.'];
    }

    return ['ok' => true, 'why' => ''];
}

/**
 * Turns tokens into money, in millicents.
 *
 * Cached input is billed separately by every provider that offers it, usually
 * at a tenth of the normal rate, and on a conversation that re-sends a long
 * system prompt every turn that is most of the bill. Counted separately or the
 * estimate is wrong by a factor.
 */
function aiCost(array $settings, int $in, int $out, int $cached): int
{
    $perMillionToMillicents = fn(float $cents): float => $cents * 1000 / 1000000;

    $total =
        $in * $perMillionToMillicents($settings['price_in'])
        + $out * $perMillionToMillicents($settings['price_out'])
        + $cached * $perMillionToMillicents($settings['price_cached']);

    return (int)round($total);
}

/** Millicents as something to put on screen. */
function aiMoney(int $millicents, string $symbol = '$'): string
{
    if ($millicents === 0) {
        return $symbol . '0.00';
    }

    $cents = $millicents / 1000;

    /*
     * Sub-cent totals said as such rather than rounded to zero.
     *
     * A session that cost four tenths of a cent showing "$0.00" reads as "this
     * is free", which is the wrong impression to leave somebody with when they
     * are about to run fifty of them.
     */
    if ($cents < 1) {
        return sprintf('under %s0.01', $symbol);
    }

    return $symbol . number_format($cents / 100, 2);
}

/**
 * One request to the model.
 *
 * Returns the raw decoded response alongside what it cost, with errors as
 * values rather than exceptions - a provider being down is something the
 * conversation should say out loud, not something that ends the request with a
 * stack trace.
 *
 * @return array<string, mixed>
 */
function aiAsk(array $settings, array $messages, array $tools, ?string $system = null): array
{
    $started = microtime(true);
    $provider = $settings['provider'];

    [$url, $headers, $body] = $provider === 'anthropic'
        ? aiAnthropicRequest($settings, $messages, $tools, $system)
        : aiOpenAiRequest($settings, $messages, $tools, $system);

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => AI_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 15,
        // A model endpoint is somebody else's service over the internet; the
        // certificate check is the only thing standing between the API key and
        // whoever is on the path.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $problem = curl_error($handle);
    curl_close($handle);

    $ms = (int)round((microtime(true) - $started) * 1000);

    if ($raw === false) {
        return [
            'ok' => false,
            'error' => $problem !== '' ? $problem : 'the request failed',
            'status' => $status,
            'ms' => $ms,
            'request' => $body,
            'response' => null,
        ];
    }

    $decoded = json_decode((string)$raw, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => 'the model answered with something that is not JSON',
            'status' => $status,
            'ms' => $ms,
            'request' => $body,
            'response' => ['raw' => mb_substr((string)$raw, 0, 2000)],
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'error' => aiErrorText($decoded, $status),
            'status' => $status,
            'ms' => $ms,
            'request' => $body,
            'response' => $decoded,
        ];
    }

    $parsed = $provider === 'anthropic'
        ? aiAnthropicReply($decoded)
        : aiOpenAiReply($decoded);

    return $parsed + [
        'ok' => true,
        'error' => null,
        'status' => $status,
        'ms' => $ms,
        'request' => $body,
        'response' => $decoded,
    ];
}

/** Whatever the provider called the problem, in one line. */
function aiErrorText(array $decoded, int $status): string
{
    foreach ([['error', 'message'], ['error', 'type'], ['message'], ['detail']] as $path) {
        $at = $decoded;
        foreach ($path as $key) {
            if (!is_array($at) || !isset($at[$key])) {
                $at = null;
                break;
            }
            $at = $at[$key];
        }
        if (is_string($at) && $at !== '') {
            return sprintf('%s (HTTP %d)', $at, $status);
        }
    }

    return sprintf('the model refused the request (HTTP %d)', $status);
}

/** @return array{0: string, 1: list<string>, 2: array} */
function aiOpenAiRequest(array $settings, array $messages, array $tools, ?string $system): array
{
    $body = [
        'model' => $settings['model'],
        'messages' => $system === null
            ? $messages
            : array_merge([['role' => 'system', 'content' => $system]], $messages),
    ];

    if ($tools !== []) {
        $body['tools'] = $tools;
        $body['tool_choice'] = 'auto';
    }

    $headers = ['Content-Type: application/json'];
    if ($settings['api_key'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $settings['api_key'];
    }

    return [rtrim($settings['base_url'], '/') . '/chat/completions', $headers, $body];
}

/**
 * The reply, in the shape the rest of this code works in.
 *
 * @return array<string, mixed>
 */
function aiOpenAiReply(array $decoded): array
{
    $choice = $decoded['choices'][0]['message'] ?? [];
    $usage = $decoded['usage'] ?? [];

    $calls = [];
    foreach ($choice['tool_calls'] ?? [] as $call) {
        $arguments = $call['function']['arguments'] ?? '{}';
        $calls[] = [
            'id' => (string)($call['id'] ?? uniqid('call_', true)),
            'name' => (string)($call['function']['name'] ?? ''),
            /*
             * Arguments arrive as a JSON string, and sometimes an invalid one.
             *
             * A model that produced malformed JSON has made a mistake worth
             * telling it about rather than crashing over - so it becomes an
             * empty set here and the checker refuses it with a message the
             * model can act on.
             */
            'arguments' => is_array($arguments)
                ? $arguments
                : (json_decode((string)$arguments, true) ?: []),
        ];
    }

    return [
        'text' => (string)($choice['content'] ?? ''),
        'tool_calls' => $calls,
        'finish' => (string)($decoded['choices'][0]['finish_reason'] ?? ''),
        'tokens_in' => (int)($usage['prompt_tokens'] ?? 0),
        'tokens_out' => (int)($usage['completion_tokens'] ?? 0),
        'tokens_cached' => (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
    ];
}

/** @return array{0: string, 1: list<string>, 2: array} */
function aiAnthropicRequest(array $settings, array $messages, array $tools, ?string $system): array
{
    /*
     * Anthropic keeps the system prompt out of the message list and names tool
     * results differently. Translated here rather than storing two shapes.
     */
    $out = [];
    foreach ($messages as $message) {
        if (($message['role'] ?? '') === 'tool') {
            $out[] = ['role' => 'user', 'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => (string)($message['tool_call_id'] ?? ''),
                'content' => (string)($message['content'] ?? ''),
            ]]];
            continue;
        }

        if (($message['role'] ?? '') === 'assistant' && !empty($message['tool_calls'])) {
            $blocks = [];
            if (($message['content'] ?? '') !== '') {
                $blocks[] = ['type' => 'text', 'text' => (string)$message['content']];
            }
            foreach ($message['tool_calls'] as $call) {
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => (string)$call['id'],
                    'name' => (string)$call['function']['name'],
                    'input' => json_decode((string)$call['function']['arguments'], true) ?: (object)[],
                ];
            }
            $out[] = ['role' => 'assistant', 'content' => $blocks];
            continue;
        }

        $out[] = ['role' => $message['role'], 'content' => (string)($message['content'] ?? '')];
    }

    $body = [
        'model' => $settings['model'],
        'max_tokens' => 4096,
        'messages' => $out,
    ];

    if ($system !== null) {
        $body['system'] = $system;
    }

    if ($tools !== []) {
        $body['tools'] = array_map(fn(array $tool): array => [
            'name' => $tool['function']['name'],
            'description' => $tool['function']['description'],
            'input_schema' => $tool['function']['parameters'],
        ], $tools);
    }

    return [
        rtrim($settings['base_url'], '/') . '/messages',
        [
            'Content-Type: application/json',
            'x-api-key: ' . $settings['api_key'],
            'anthropic-version: 2023-06-01',
        ],
        $body,
    ];
}

/** @return array<string, mixed> */
function aiAnthropicReply(array $decoded): array
{
    $text = '';
    $calls = [];

    foreach ($decoded['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= (string)($block['text'] ?? '');
        }
        if (($block['type'] ?? '') === 'tool_use') {
            $calls[] = [
                'id' => (string)($block['id'] ?? uniqid('call_', true)),
                'name' => (string)($block['name'] ?? ''),
                'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
            ];
        }
    }

    $usage = $decoded['usage'] ?? [];

    return [
        'text' => $text,
        'tool_calls' => $calls,
        'finish' => (string)($decoded['stop_reason'] ?? ''),
        'tokens_in' => (int)($usage['input_tokens'] ?? 0),
        'tokens_out' => (int)($usage['output_tokens'] ?? 0),
        'tokens_cached' => (int)($usage['cache_read_input_tokens'] ?? 0),
    ];
}
