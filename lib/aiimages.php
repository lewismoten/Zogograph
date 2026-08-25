<?php
declare(strict_types=1);

/*
 * Pictures the assistant can make, and how often it may.
 *
 * Two quite different things share this file because they share a bill.
 *
 * A reaction - a small animated image expressing a mood - costs a search
 * against somebody's catalogue and nothing else. An generated image costs real
 * money per call and takes seconds. Both are entirely optional, both are off
 * until somebody configures a key, and both are rate-limited by the hour
 * because a model that finds a thing amusing will keep doing it.
 *
 * The self-portrait deserves a note. Letting the assistant regenerate its own
 * avatar is a novelty, and novelties in a monitoring tool have to be cheap and
 * bounded or they become the reason somebody turns the whole feature off. So it
 * shares the same hourly budget as everything else here, it is resized to 256
 * pixels before it is stored, and it can be switched off on its own.
 */

require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/spans.php';
require_once __DIR__ . '/aidb.php';

/**
 * The image styles on offer, and what each is for.
 *
 * DeepAI exposes these as separate endpoints rather than as a parameter, which
 * is why they are a list of names rather than a prompt suffix. The operator
 * chooses which the assistant may reach for; a model given twelve options will
 * use twelve, and most of them will be wrong for a monitoring dashboard.
 *
 * @return array<string, array{label: string, endpoint: string, what: string}>
 */
function aiImageStyles(): array
{
    return [
        'text2img' => [
            'label' => 'Plain',
            'endpoint' => 'text2img',
            'what' => 'No particular style. The one to use when the picture is meant to '
                . 'illustrate something rather than to be a joke.',
        ],
        'fantasy-world-generator' => [
            'label' => 'Fantasy world',
            'endpoint' => 'fantasy-world-generator',
            'what' => 'Sweeping, painterly, unreal landscapes.',
        ],
        'cyberpunk-portrait-generator' => [
            'label' => 'Cyberpunk portrait',
            'endpoint' => 'cyberpunk-portrait-generator',
            'what' => 'Neon-lit faces and machines. The obvious choice for a self-portrait.',
        ],
        'renaissance-painting-generator' => [
            'label' => 'Renaissance painting',
            'endpoint' => 'renaissance-painting-generator',
            'what' => 'Oil-painted gravity. Absurd applied to an ordinary subject.',
        ],
        'steampunk-generator' => [
            'label' => 'Steampunk',
            'endpoint' => 'steampunk-generator',
            'what' => 'Brass, valves and improbable machinery.',
        ],
        'future-architecture-generator' => [
            'label' => 'Underground cityscape',
            'endpoint' => 'future-architecture-generator',
            'what' => 'Vast built spaces. Good for anything about scale or capacity.',
        ],
        'anime-portrait-generator' => [
            'label' => 'Anime portrait',
            'endpoint' => 'anime-portrait-generator',
            'what' => 'Stylised characters.',
        ],
        'old-style-generator' => [
            'label' => 'Intergalactic battle',
            'endpoint' => 'old-style-generator',
            'what' => 'Painted science-fiction spectacle. Suits an urgent operational '
                . 'status rather more than the situation usually deserves.',
        ],
    ];
}

/** Shapes and sizes, which DeepAI takes as width and height. */
function aiImageShapes(): array
{
    return [
        'square' => ['label' => 'Square', 'width' => 512, 'height' => 512],
        'classic' => ['label' => 'Classic (4:3)', 'width' => 704, 'height' => 512],
        'wide' => ['label' => 'Wide (16:9)', 'width' => 896, 'height' => 512],
        'tall' => ['label' => 'Tall (3:4)', 'width' => 512, 'height' => 704],
    ];
}

function aiImageQualities(): array
{
    return [
        'standard' => ['label' => 'Standard', 'hd' => false,
                       'what' => 'One pass. Cheaper and quicker.'],
        'hd' => ['label' => 'HD', 'hd' => true,
                 'what' => 'Higher quality, and costs more per image.'],
    ];
}

/**
 * The image settings in force.
 *
 * @return array<string, mixed>
 */
function aiImageSettings(array $config): array
{
    $set = $config['ai']['images'] ?? [];
    $styles = aiImageStyles();

    $allowed = array_values(array_filter(
        (array)($set['styles'] ?? ['text2img']),
        fn(string $key): bool => isset($styles[$key])
    ));

    return [
        'enabled' => (bool)($set['enabled'] ?? false),
        'provider' => 'deepai',
        'api_key' => (string)($set['api_key'] ?? ''),
        'styles' => $allowed === [] ? ['text2img'] : $allowed,
        'quality' => isset(aiImageQualities()[$set['quality'] ?? ''])
            ? (string)$set['quality'] : 'standard',
        'shape' => isset(aiImageShapes()[$set['shape'] ?? ''])
            ? (string)$set['shape'] : 'square',
        /*
         * Per hour, across everything in this file.
         *
         * A model that finds a thing amusing will keep doing it, and each of
         * these costs money. Ten is generous for illustrating a diagnosis and
         * tight enough that a loop cannot run up a bill before anybody notices.
         */
        'per_hour' => max(0, (int)($set['per_hour'] ?? 10)),
        'self_portrait' => (bool)($set['self_portrait'] ?? false),
        'reactions' => (bool)($set['reactions'] ?? false),
        'giphy_key' => (string)($set['giphy_key'] ?? ''),
        'rating' => in_array($set['rating'] ?? 'g', ['g', 'pg', 'pg-13'], true)
            ? (string)($set['rating'] ?? 'g') : 'g',
        // Overridable so the paths that leave this machine can be exercised
        // against a stub; there is one real address for each in practice.
        'base' => (string)($set['base'] ?? ''),
        'giphy_base' => (string)($set['giphy_base'] ?? ''),
    ];
}

/**
 * How many pictures have been made in the last hour, and whether another may be.
 *
 * Counted from the record rather than held in memory, so restarting PHP does
 * not reset the budget - which on a shared host happens constantly.
 *
 * @return array{used: int, allowed: int, ok: bool, why: string}
 */
function aiImageBudget(PDO $ai, array $settings): array
{
    $allowed = (int)$settings['per_hour'];

    try {
        $used = (int)$ai->query(
            "SELECT COUNT(*) FROM ai_images WHERE at >= " . (time() - SPAN_HOUR)
        )->fetchColumn();
    } catch (Throwable) {
        $used = 0;
    }

    if ($allowed <= 0) {
        return ['used' => $used, 'allowed' => 0, 'ok' => false,
                'why' => 'picture-making is switched off'];
    }

    if ($used >= $allowed) {
        return ['used' => $used, 'allowed' => $allowed, 'ok' => false, 'why' => sprintf(
            'that is %d pictures this hour, which is the limit. It resets as the hour '
            . 'rolls forward.',
            $used
        )];
    }

    return ['used' => $used, 'allowed' => $allowed, 'ok' => true, 'why' => ''];
}

/**
 * Asks DeepAI for a picture.
 *
 * Returns the URL it produced rather than the bytes: the image lives on their
 * CDN, the browser fetches it directly, and this never has to hold a megabyte
 * of JPEG in memory to pass it along.
 *
 * @return array<string, mixed>
 */
function aiMakeImage(array $settings, string $prompt, string $style, ?callable $ask = null): array
{
    $styles = aiImageStyles();
    if (!isset($styles[$style]) || !in_array($style, $settings['styles'], true)) {
        return ['ok' => false, 'error' => 'that style is not one this install allows'];
    }

    if ($settings['api_key'] === '') {
        return ['ok' => false, 'error' => 'no DeepAI key is set'];
    }

    $shape = aiImageShapes()[$settings['shape']];
    $fields = [
        'text' => mb_substr($prompt, 0, 1000),
        'width' => (string)$shape['width'],
        'height' => (string)$shape['height'],
    ];

    if (aiImageQualities()[$settings['quality']]['hd']) {
        // DeepAI's name for it, and only accepted by some of the generators -
        // the ones that ignore it ignore it harmlessly.
        $fields['image_generator_version'] = 'hd';
    }

    /*
     * The address is configurable so this can be pointed at a stub.
     *
     * Not a feature anybody needs in production - DeepAI is at one address -
     * but the alternative is an untestable code path, and an untestable path
     * is one nobody has ever run.
     */
    $base = rtrim((string)($settings['base'] ?? 'https://api.deepai.org/api'), '/');
    $url = $base . '/' . $styles[$style]['endpoint'];

    if ($ask !== null) {
        return $ask($url, $fields, $settings['api_key']);
    }

    return aiPostForm($url, $fields, ['api-key: ' . $settings['api_key']], 90);
}

/**
 * A form post that returns decoded JSON, used by both services here.
 *
 * @return array<string, mixed>
 */
function aiPostForm(string $url, array $fields, array $headers, int $timeout = 30): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'this PHP has no curl'];
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $problem = curl_error($handle);
    curl_close($handle);

    if ($raw === false) {
        return ['ok' => false, 'error' => $problem !== '' ? $problem : 'the request failed'];
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'the service answered with something that is not JSON'];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'error' => (string)($decoded['err'] ?? $decoded['error'] ?? "HTTP {$status}"),
            'status' => $status,
        ];
    }

    return ['ok' => true, 'body' => $decoded, 'status' => $status];
}

/**
 * Finds a reaction image for a mood.
 *
 * A search rather than a generation: these are meant to be instant and free,
 * and generating one would cost seconds and money for something whose whole
 * point is being a throwaway aside.
 *
 * @return array<string, mixed>
 */
function aiFindReaction(array $settings, string $mood, ?callable $ask = null): array
{
    if (!$settings['reactions'] || $settings['giphy_key'] === '') {
        return ['ok' => false, 'error' => 'reactions are not set up'];
    }

    $base = rtrim((string)($settings['giphy_base'] ?? 'https://api.giphy.com/v1'), '/');
    $url = $base . '/gifs/search?' . http_build_query([
        'api_key' => $settings['giphy_key'],
        'q' => mb_substr($mood, 0, 60),
        'limit' => 1,
        // The rating matters: this appears unannounced in somebody's browser
        // at work, chosen by a language model from a search term it invented.
        'rating' => $settings['rating'],
        'lang' => 'en',
    ]);

    if ($ask !== null) {
        return $ask($url);
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'this PHP has no curl'];
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($handle);
    curl_close($handle);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || empty($decoded['data'][0])) {
        return ['ok' => false, 'error' => 'nothing matched that'];
    }

    $found = $decoded['data'][0];

    return [
        'ok' => true,
        'url' => (string)($found['images']['downsized']['url']
            ?? $found['images']['original']['url'] ?? ''),
        'title' => (string)($found['title'] ?? ''),
        'source' => 'GIPHY',
    ];
}

/** Records that a picture was made, for the budget and for the record. */
function aiRecordImage(
    PDO $ai,
    int $session,
    string $kind,
    string $prompt,
    string $style,
    ?string $url,
    ?string $error
): int {
    $ai->prepare(
        'INSERT INTO ai_images (session_id, at, kind, prompt, style, url, error)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$session, time(), $kind, mb_substr($prompt, 0, 1000), $style, $url, $error]);

    return (int)$ai->lastInsertId();
}

/** The assistant's current portrait, if it has made one. */
function aiPortrait(PDO $ai): ?array
{
    try {
        $row = $ai->query(
            "SELECT url, prompt, at FROM ai_images
              WHERE kind = 'portrait' AND url IS NOT NULL
              ORDER BY id DESC LIMIT 1"
        )->fetch();
    } catch (Throwable) {
        return null;
    }

    return $row === false ? null : [
        'url' => (string)$row['url'],
        'as' => (string)$row['prompt'],
        'at' => (int)$row['at'],
    ];
}
