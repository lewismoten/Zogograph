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

        /*
         * Searching for an animation, which is a different thing from
         * generating a picture and is priced, limited and named differently.
         *
         * `search_key` was `giphy_key`. The old name is still read so nobody's
         * configuration breaks, but the field is not named after one company:
         * there are three services with the same shape, the endpoint is a
         * setting, and hard-coding a brand into a config key is how an install
         * ends up unable to switch.
         */
        'reactions' => (bool)($set['reactions'] ?? false),
        'search_service' => isset(aiReactionServices()[$set['search_service'] ?? ''])
            ? (string)$set['search_service']
            : 'tenor',
        'search_key' => (string)($set['search_key'] ?? $set['giphy_key'] ?? ''),
        'search_base' => (string)($set['search_base'] ?? $set['giphy_base'] ?? ''),
        /*
         * Searches an hour. A hundred is the free allowance these services
         * publish; it can be set lower and not higher, because a ceiling a
         * setting can lift past the provider's own protects nothing.
         */
        'searches_per_hour' => max(0, min(100, (int)($set['searches_per_hour'] ?? 100))),
        'rating' => in_array($set['rating'] ?? 'g', ['g', 'pg', 'pg-13'], true)
            ? (string)($set['rating'] ?? 'g') : 'g',

        /*
         * What generated pictures cost, for the running total.
         *
         * DeepAI sells a monthly plan with an allowance and then charges for
         * credits past it, so a per-image price is only true once the allowance
         * is gone. Both are recorded and the arithmetic is shown rather than
         * hidden: while there is allowance left the marginal cost is nothing,
         * and after it the per-image price applies. Guessing either would
         * produce a total that looks authoritative and is not.
         */
        'monthly_cents' => max(0, (float)($set['monthly_cents'] ?? 0)),
        'included_per_month' => max(0, (int)($set['included_per_month'] ?? 0)),
        'price_standard' => max(0, (float)($set['price_standard'] ?? 0)),
        'price_hd' => max(0, (float)($set['price_hd'] ?? 0)),
        'price_genius' => max(0, (float)($set['price_genius'] ?? 0)),

        // Overridable so the paths that leave this machine can be exercised
        // against a stub; there is one real address for each in practice.
        'base' => (string)($set['base'] ?? ''),

        // Kept so an install configured before the rename still works.
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

    /*
     * Generated pictures only.
     *
     * Searching for an animation and generating an image share nothing except
     * that both produce a picture: one is a read against a free quota, the
     * other is a bill. Counting them together meant a handful of reactions
     * could use up the budget for the thing that actually costs money.
     */
    try {
        $stmt = $ai->prepare(
            "SELECT COUNT(*) FROM ai_images WHERE at >= ? AND kind != 'reaction'"
        );
        $stmt->execute([time() - SPAN_HOUR]);
        $used = (int)$stmt->fetchColumn();
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
 * Whether another animation search may be made this hour.
 *
 * Its own budget, far larger than the picture one, because the two cost
 * different things. A hundred an hour is what these services publish as a free
 * allowance; going past it gets the key throttled, which breaks the feature for
 * everybody using this install rather than costing anybody money.
 *
 * @return array{used: int, allowed: int, ok: bool, why: string}
 */
function aiSearchBudget(PDO $ai, array $settings): array
{
    $allowed = (int)$settings['searches_per_hour'];

    try {
        $stmt = $ai->prepare(
            "SELECT COUNT(*) FROM ai_images WHERE at >= ? AND kind = 'reaction'"
        );
        $stmt->execute([time() - SPAN_HOUR]);
        $used = (int)$stmt->fetchColumn();
    } catch (Throwable) {
        $used = 0;
    }

    if ($allowed <= 0) {
        return ['used' => $used, 'allowed' => 0, 'ok' => false,
                'why' => 'reaction searches are switched off'];
    }

    if ($used >= $allowed) {
        return ['used' => $used, 'allowed' => $allowed, 'ok' => false, 'why' => sprintf(
            'that is %d searches this hour, which is the limit. It resets as the hour '
            . 'rolls forward.',
            $used
        )];
    }

    return ['used' => $used, 'allowed' => $allowed, 'ok' => true, 'why' => ''];
}

/**
 * What one generated picture costs, in millicents.
 *
 * The awkward part of this, and it is worth writing down rather than papering
 * over: the plan is a monthly fee with an allowance, and credits afterwards. So
 * a picture's marginal cost is zero until the allowance runs out and the
 * per-image price after that, and neither figure alone is the truth.
 *
 * What is NOT done here is spreading the monthly fee across the images made -
 * that would make the first picture of the month cost five dollars and the
 * hundredth cost nothing, which is arithmetic nobody can act on. The monthly
 * fee is reported separately, as a monthly fee.
 */
function aiImageCost(PDO $ai, array $settings, string $quality): int
{
    $price = match ($quality) {
        'hd' => (float)$settings['price_hd'],
        'genius' => (float)$settings['price_genius'],
        default => (float)$settings['price_standard'],
    };

    if ($price <= 0) {
        return 0;
    }

    $included = (int)$settings['included_per_month'];
    if ($included <= 0) {
        return (int)round($price * 1000);
    }

    /*
     * Inside the allowance costs nothing extra, which is the honest answer.
     *
     * Counted over the calendar month because that is how the plan is sold.
     */
    try {
        $stmt = $ai->prepare(
            "SELECT COUNT(*) FROM ai_images WHERE at >= ? AND kind != 'reaction' AND error IS NULL"
        );
        $stmt->execute([(int)mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'))]);
        $thisMonth = (int)$stmt->fetchColumn();
    } catch (Throwable) {
        $thisMonth = 0;
    }

    return $thisMonth < $included ? 0 : (int)round($price * 1000);
}

/**
 * Keeps the bytes of a generated picture.
 *
 * Only generated ones. An animation found by a search is somebody else's file
 * sitting on their service, and copying it here would be storing their content
 * rather than ours; the link is enough for something nobody paid for.
 *
 * The reason to keep the bytes at all is that a provider URL expires. A picture
 * that was paid for and is a 404 a fortnight later is a record of nothing, and
 * "review them later in full size" is not possible from a dead link.
 *
 * @return array{ok: bool, bytes?: int, why?: string}
 */
function aiKeepAsset(PDO $ai, int $imageId, string $url, ?callable $fetch = null): array
{
    if ($url === '') {
        return ['ok' => false, 'why' => 'there was no picture to keep'];
    }

    $got = $fetch !== null ? $fetch($url) : aiFetchBytes($url);

    if (!$got['ok']) {
        return ['ok' => false, 'why' => $got['why']];
    }

    $size = @getimagesizefromstring($got['body']);
    if ($size === false) {
        return ['ok' => false, 'why' => 'what came back was not an image'];
    }

    $ai->prepare(
        'INSERT INTO ai_assets (image_id, at, mime, width, height, bytes, body)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $imageId, time(), (string)($size['mime'] ?? 'application/octet-stream'),
        (int)$size[0], (int)$size[1], strlen($got['body']), $got['body'],
    ]);

    return ['ok' => true, 'bytes' => strlen($got['body'])];
}

/** Largest generated image kept. Past this the link is all there is. */
const AI_ASSET_MAX = 12 * 1024 * 1024;

/** Fetches an image, refusing anything that is not one or is too large. */
function aiFetchBytes(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'why' => 'this PHP has no curl'];
    }
    if (!str_starts_with($url, 'https://')) {
        return ['ok' => false, 'why' => 'only https addresses are fetched'];
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        /*
         * Stopped mid-download rather than checked afterwards.
         *
         * A Content-Length is a claim by somebody else's server; the only limit
         * that holds is one that counts the bytes as they arrive.
         */
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($handle, $expect, $got): int =>
            $got > AI_ASSET_MAX ? 1 : 0,
    ]);

    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'why' => $error !== '' ? $error : 'nothing came back'];
    }
    if ($status >= 400) {
        return ['ok' => false, 'why' => 'the picture host answered ' . $status];
    }
    if (strlen($body) > AI_ASSET_MAX) {
        return ['ok' => false, 'why' => 'that picture is larger than this keeps'];
    }

    return ['ok' => true, 'body' => $body];
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
 * The animation services this can search, and how each one answers.
 *
 * Three of them, and the endpoint is a field rather than a constant, because
 * which one an install can use is not something this code can know: they have
 * different terms, different regions, and one of them may be the only one an
 * employer will approve. The differences between their responses are small
 * enough to describe in a table and large enough that guessing would break.
 *
 * `path` is appended to the base. `pick` is told the decoded body and returns
 * the first usable animation, or null.
 */
function aiReactionServices(): array
{
    return [
        'tenor' => [
            'label' => 'Tenor',
            'base' => 'https://tenor.googleapis.com/v2',
            'path' => '/search',
            'note' => 'Google runs it, and both Google and the service it grew out of '
                . 'support it. A key comes from the Google Cloud console.',
            'query' => fn(array $set, string $mood): array => [
                'key' => $set['search_key'],
                'q' => $mood,
                'limit' => 1,
                'media_filter' => 'tinygif',
                'contentfilter' => $set['rating'] === 'g' ? 'high' : 'medium',
            ],
            'pick' => function (array $body): ?array {
                $first = $body['results'][0] ?? null;
                if (!is_array($first)) {
                    return null;
                }
                $media = $first['media_formats'] ?? [];
                $url = $media['tinygif']['url'] ?? $media['gif']['url'] ?? '';
                return $url === '' ? null : [
                    'url' => (string)$url,
                    'title' => (string)($first['content_description'] ?? ''),
                ];
            },
        ],

        'klipy' => [
            'label' => 'Klipy',
            'base' => 'https://api.klipy.com/api/v1',
            'path' => '/gifs/search',
            'note' => 'A smaller service with its own catalogue.',
            'query' => fn(array $set, string $mood): array => [
                'q' => $mood,
                'per_page' => 1,
                'content_filter' => $set['rating'],
            ],
            'pick' => function (array $body): ?array {
                $first = $body['data'][0] ?? $body['result'][0] ?? null;
                if (!is_array($first)) {
                    return null;
                }
                $url = $first['file']['sm']['gif']['url']
                    ?? $first['url']
                    ?? '';
                return $url === '' ? null : [
                    'url' => (string)$url,
                    'title' => (string)($first['title'] ?? ''),
                ];
            },
        ],

        'legacy' => [
            'label' => 'The older search service',
            'base' => 'https://api.giphy.com/v1',
            'path' => '/gifs/search',
            'note' => 'The one most keys are already for.',
            'query' => fn(array $set, string $mood): array => [
                'api_key' => $set['search_key'],
                'q' => $mood,
                'limit' => 1,
                'rating' => $set['rating'],
                'lang' => 'en',
            ],
            'pick' => function (array $body): ?array {
                $first = $body['data'][0] ?? null;
                if (!is_array($first)) {
                    return null;
                }
                $url = $first['images']['downsized']['url']
                    ?? $first['images']['original']['url']
                    ?? '';
                return $url === '' ? null : [
                    'url' => (string)$url,
                    'title' => (string)($first['title'] ?? ''),
                ];
            },
        ],
    ];
}

/**
 * Finds one animation for a mood.
 *
 * Rate limited separately from the picture budget, and much more loosely: a
 * search is a cheap read against somebody else's quota, where generating an
 * image is a bill. The default ceiling is the free tier most of these publish;
 * it can be set lower and not higher, because a limit that a setting can raise
 * past the service's own is not protecting anything.
 *
 * @return array{ok: bool, url?: string, title?: string, source?: string, error?: string}
 */
function aiFindReaction(array $settings, string $mood, ?callable $ask = null): array
{
    if (!$settings['reactions'] || $settings['search_key'] === '') {
        return ['ok' => false, 'error' => 'reactions are not set up'];
    }

    $services = aiReactionServices();
    $key = $settings['search_service'];

    if (!isset($services[$key])) {
        return ['ok' => false, 'error' => 'no search service is chosen'];
    }

    $service = $services[$key];
    $base = rtrim($settings['search_base'] ?: $service['base'], '/');
    $url = $base . $service['path'] . '?' . http_build_query(
        ($service['query'])($settings, mb_substr($mood, 0, 60))
    );

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
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'the search did not answer'];
    }

    $found = ($service['pick'])($decoded);
    if ($found === null) {
        return ['ok' => false, 'error' => 'nothing matched that'];
    }

    return [
        'ok' => true,
        'url' => $found['url'],
        'title' => $found['title'],
        'source' => $service['label'],
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
    ?string $error,
    int $millicents = 0
): int {
    $ai->prepare(
        'INSERT INTO ai_images (session_id, at, kind, prompt, style, url, error, millicents)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $session, time(), $kind, mb_substr($prompt, 0, 1000), $style, $url, $error,
        $millicents,
    ]);

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
