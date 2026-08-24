<?php
declare(strict_types=1);

/*
 * Symlinks that eat things.
 *
 * The scanner steps over every symlink it meets, deliberately - following them
 * double counts at best and walks out of the scan root at worst. The
 * consequence is that a bad link is invisible to this tool while still being
 * the thing that ruins everything else: a link pointing at one of its own
 * ancestors is an infinitely deep directory to anything that *does* follow
 * links, and backup jobs, `du`, `tar` and web crawlers all do. That is how a
 * disk fills up with one file copied ten thousand times.
 *
 * Four things are worth reporting:
 *
 *   loop      the chain of links comes back to a link it already passed
 *   ancestor  it resolves to a directory containing the link itself, so
 *             anything that follows it recurses until it runs out of something
 *   broken    it points at nothing, which is untidy rather than dangerous
 *   outside   it leaves the watched directory, so sizes measured here are not
 *             the sizes something following it would see
 *
 * The walk never follows a link. It reads where each one points and reasons
 * about the path, which is the only safe way to inspect a loop.
 */

require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/collect.php';

/** Longest chain of links followed before calling it a loop regardless. */
const SYMLINK_MAX_HOPS = 40;

/**
 * Walks the tree looking for symlinks that will hurt something.
 *
 * Bounded by wall clock rather than by entries, because the thing that varies
 * between accounts is how slow the disk is, not how many files there are. It
 * says when it stopped early: a check that quietly gave up and reported
 * "nothing wrong" would be worse than not having one.
 *
 * @return array{
 *   problems: list<array>, links: int, dirs: int,
 *   complete: bool, seconds: float
 * }
 */
function findSymlinkProblems(array $config, float $budgetSeconds = 10.0): array
{
    $root = realpath((string)$config['scan_root']);
    if ($root === false) {
        throw new ActionRefused('the watched directory does not resolve');
    }

    $excluded = array_map('normalisePath', $config['scan']['exclude_paths'] ?? []);
    $deadline = microtime(true) + $budgetSeconds;
    $started = microtime(true);

    $state = ['links' => 0, 'dirs' => 0, 'problems' => [], 'complete' => true];
    walkForLinks($root, $root, $excluded, $deadline, $state);

    // Worst first: a loop will break a backup tonight, a broken link never will.
    $rank = ['loop' => 0, 'ancestor' => 1, 'outside' => 2, 'broken' => 3];
    usort(
        $state['problems'],
        fn(array $a, array $b): int => ($rank[$a['kind']] ?? 9) <=> ($rank[$b['kind']] ?? 9)
    );

    return [
        'problems' => array_slice($state['problems'], 0, 200),
        'links' => $state['links'],
        'dirs' => $state['dirs'],
        'complete' => $state['complete'],
        'seconds' => round(microtime(true) - $started, 2),
    ];
}

function walkForLinks(
    string $dir,
    string $root,
    array $excluded,
    float $deadline,
    array &$state,
    int $depth = 0
): void {
    if ($depth > 40 || isExcludedPath($dir, $excluded)) {
        return;
    }
    if (microtime(true) > $deadline) {
        $state['complete'] = false;
        return;
    }

    $handle = @opendir($dir);
    if ($handle === false) {
        return;
    }

    $state['dirs']++;

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $entry;

        if (is_link($full)) {
            $state['links']++;
            $problem = inspectLink($full, $root);
            if ($problem !== null) {
                $state['problems'][] = $problem;
            }
            // Never descend through one. Following the link is the mistake this
            // whole file exists to warn other programs about.
            continue;
        }

        if (is_dir($full)) {
            walkForLinks($full, $root, $excluded, $deadline, $state, $depth + 1);
            if (!$state['complete']) {
                break;
            }
        }
    }

    closedir($handle);
}

/**
 * What is wrong with one symlink, or null if nothing is.
 *
 * @return array{kind: string, path: string, target: string, hops: int, note: string}|null
 */
function inspectLink(string $link, string $root): ?array
{
    $immediate = @readlink($link);
    if ($immediate === false) {
        return [
            'kind' => 'broken',
            'path' => $link,
            'target' => '',
            'hops' => 0,
            'note' => 'The link cannot be read at all.',
        ];
    }

    $chain = followLink($link);
    $target = $chain['target'];

    if ($chain['loop']) {
        return [
            'kind' => 'loop',
            'path' => $link,
            'target' => $target,
            'hops' => $chain['hops'],
            'note' => $chain['hops'] >= SYMLINK_MAX_HOPS
                ? 'The chain of links never ends. Anything following it will give up or hang.'
                : 'The links point at each other in a circle.',
        ];
    }

    /*
     * A link into one of its own ancestors.
     *
     * The dangerous one, and the one that looks perfectly innocent in a
     * listing: `photos/all -> ..` is one line and an infinitely deep tree.
     */
    $inside = rtrim($target, '/') . '/';
    if ($target !== '' && str_starts_with($link . '/', $inside)) {
        return [
            'kind' => 'ancestor',
            'path' => $link,
            'target' => $target,
            'hops' => $chain['hops'],
            'note' => 'Points at a directory that contains the link itself, so anything '
                . 'following symlinks will descend for ever.',
        ];
    }

    if (!$chain['exists']) {
        return [
            'kind' => 'broken',
            'path' => $link,
            'target' => $target,
            'hops' => $chain['hops'],
            'note' => 'Points at something that is not there.',
        ];
    }

    if (!str_starts_with($target . '/', rtrim($root, '/') . '/')) {
        return [
            'kind' => 'outside',
            'path' => $link,
            'target' => $target,
            'hops' => $chain['hops'],
            'note' => 'Leaves the watched directory. Sizes measured here will not match '
                . 'what a program following this link would see.',
        ];
    }

    return null;
}

/**
 * Follows a chain of links by reading them, never by opening them.
 *
 * `realpath` would do this in one call and returns false for a loop, a broken
 * link and a permissions problem alike - three different answers flattened into
 * one, and this function has to tell them apart. Reading hop by hop also gives
 * the length of the chain, which is what distinguishes a tidy two-link circle
 * from a runaway.
 *
 * @return array{target: string, loop: bool, exists: bool, hops: int}
 */
function followLink(string $link): array
{
    $seen = [];
    $current = $link;
    $hops = 0;

    while ($hops < SYMLINK_MAX_HOPS) {
        if (isset($seen[$current])) {
            return ['target' => $current, 'loop' => true, 'exists' => false, 'hops' => $hops];
        }
        $seen[$current] = true;

        if (!is_link($current)) {
            // The end of the chain: a real file, a real directory, or nothing.
            return [
                'target' => $current,
                'loop' => false,
                'exists' => file_exists($current),
                'hops' => $hops,
            ];
        }

        $next = @readlink($current);
        if ($next === false) {
            return ['target' => $current, 'loop' => false, 'exists' => false, 'hops' => $hops];
        }

        // A relative target is relative to the directory the link sits in.
        $current = $next[0] === '/' ? $next : dirname($current) . '/' . $next;
        $current = tidyPath($current);
        $hops++;
    }

    return ['target' => $current, 'loop' => true, 'exists' => false, 'hops' => $hops];
}

/**
 * Resolves `.` and `..` in a path as text.
 *
 * Textually, because `realpath` needs the path to exist and half the paths
 * reaching this function are the broken ones. It is not a substitute for
 * realpath in general - it cannot know about symlinks in the middle of a path -
 * but each hop here is resolved one at a time, so there are none left to know
 * about.
 */
function tidyPath(string $path): string
{
    $absolute = str_starts_with($path, '/');
    $out = [];

    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $part;
    }

    return ($absolute ? '/' : '') . implode('/', $out);
}
