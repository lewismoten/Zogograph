<?php
declare(strict_types=1);

/*
 * Everything that can honestly be said about one path.
 *
 * The treemap answers "where is the space" and then stops at a coloured
 * rectangle. This is the other half: you have found the rectangle, and now you
 * want to know what it is, how long it has been getting bigger, whether it is
 * safe to remove and whether you can look inside it before you decide.
 *
 * Three sources, and they are kept apart on purpose:
 *
 *   the filesystem   stat, and for a directory a bounded walk. Live, exact,
 *                    and the only thing that knows about right now.
 *   the database     this path's recorded sizes, if it was ever interesting
 *                    enough to record. Historical, sparse, and absent for most
 *                    paths - which is reported rather than filled in with a
 *                    flat line.
 *   the config       whether it may be deleted or read, which is policy and
 *                    not a property of the file at all.
 *
 * Nothing here deletes anything or reads a file's contents. Both are separate
 * actions with their own checks; this only reports whether they would be
 * allowed, so the interface can offer the button rather than guess.
 */

require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/collect.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/treemap.php';

/** How long a directory inspection may spend measuring before it reports a bound. */
const INSPECT_WALK_BUDGET = 3.0;

/** The largest file this will read the head of to identify an image. */
const INSPECT_IMAGE_MAX = 64 * 1024 * 1024;

/** How many recorded sizes to hand back for the history chart. */
const INSPECT_HISTORY_ROWS = 400;

/**
 * The full report on one path.
 *
 * @return array<string, mixed>
 */
function inspectPath(array $config, PDO $pdo, string $requested): array
{
    $real = resolveInspectable($config, $requested);
    $root = realpath((string)$config['scan_root']) ?: '';

    $isLink = is_link($real);
    $isDir = !$isLink && is_dir($real);

    $facts = statFacts($real, $isLink);
    $what = classifyFile($real);

    $out = [
        'path' => $real,
        'name' => basename($real) ?: $real,
        'parent' => dirname($real),
        'kind' => $isLink ? 'link' : ($isDir ? 'dir' : 'file'),
        'inside_account' => $root !== '' && str_starts_with($real . '/', rtrim($root, '/') . '/'),
        'facts' => $facts,
        'type' => $isDir ? null : $what['type'],
        'extension' => $isDir ? null : $what['extension'],
        'category' => categoryOf($real . ($isDir ? '/x' : ''), $config['scan'], $root),
    ];

    /*
     * Blocks rather than apparent size, alongside it.
     *
     * A sparse file reports a size df has never heard of, and a small file
     * still occupies a whole block. Showing both is the only way the number
     * here can be reconciled with the number on the disk.
     */
    $stat = $isLink ? @lstat($real) : @stat($real);
    $out['allocated_bytes'] = $stat === false ? null : (int)($stat['blocks'] ?? 0) * 512;
    $out['inode'] = $stat === false ? null : (int)($stat['ino'] ?? 0);
    $out['device'] = $stat === false ? null : (int)($stat['dev'] ?? 0);

    if ($isLink) {
        $target = @readlink($real);
        $out['link_target'] = $target === false ? null : $target;
        $out['link_resolves'] = $target !== false && file_exists($real);
    }

    if ($isDir) {
        $out['contents'] = directoryContents($config, $real);
    } else {
        $out['image'] = imageFacts($real);
        $out['text'] = !$isLink && looksLikeText($real);
    }

    $out['history'] = pathHistoryRows($pdo, $real);
    $out['permissions'] = permissionFacts($config, $pdo, $real, $isDir, $isLink);

    return $out;
}

/**
 * The path, if this account has any business looking at it.
 *
 * Wider than the delete and read rules - describing a file is not the same act
 * as removing it or printing it - but not unbounded, which is where this
 * started. Reporting the size, owner and permissions of any path on the machine
 * makes this a general-purpose stat oracle, and nothing in the interface needs
 * one: every path it can offer comes from the account's own tree or from the
 * host logs the administrator named. So those are the two places it will look.
 *
 * A symlink is described as itself rather than as its target, and the boundary
 * is tested against the *resolved* parent, so a link cannot be used to nominate
 * a file outside the account and have it described under an innocuous name.
 */
function resolveInspectable(array $config, string $requested): string
{
    if ($requested === '' || str_contains($requested, "\0")) {
        throw new ActionRefused('empty or malformed path');
    }

    /*
     * A symlink is described as itself, not as what it points at.
     *
     * realpath would silently substitute the target - so a link into /etc
     * would be reported under its innocuous name, with the target's size and
     * the target's owner, and nothing on screen would say a link was involved.
     */
    $parent = realpath(dirname($requested));
    if ($parent === false) {
        throw new ActionRefused('no such path');
    }
    $real = rtrim($parent, '/') . '/' . basename($requested);

    if (!file_exists($real) && !is_link($real)) {
        throw new ActionRefused('no such path');
    }

    $dataDir = realpath(dirname((string)$config['database']));
    if ($dataDir !== false && str_starts_with($real . '/', $dataDir . '/')) {
        throw new ActionRefused('that is the monitor\'s own data');
    }

    $root = realpath((string)$config['scan_root']);
    if ($root !== false && str_starts_with($real . '/', rtrim($root, '/') . '/')) {
        return $real;
    }

    $hostLogs = array_values(array_filter(
        array_map('realpath', $config['host_log_paths'] ?? []),
        fn(mixed $p): bool => is_string($p)
    ));
    if (in_array($real, $hostLogs, true)) {
        return $real;
    }

    throw new ActionRefused(
        'that path is outside this account and is not one of the host logs named in '
        . 'host_log_paths. Files that only root can see are reported by the host '
        . 'collector, which records them; this account cannot examine them directly.'
    );
}

/**
 * What is directly inside a directory, and how big the whole of it is.
 *
 * The recursive total is bounded by the clock and says when it gave up, for the
 * same reason the treemap does: a lower bound presented as a measurement is how
 * the thing being hunted gets dismissed.
 */
function directoryContents(array $config, string $dir): array
{
    $excluded = array_map('normalisePath', $config['scan']['exclude_paths'] ?? []);
    $total = walkSize($dir, $excluded, microtime(true) + INSPECT_WALK_BUDGET);

    $entries = ['dirs' => 0, 'files' => 0, 'links' => 0];
    $biggest = [];
    $types = [];

    $handle = @opendir($dir);
    if ($handle !== false) {
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;

            if (is_link($full)) {
                $entries['links']++;
                continue;
            }
            if (is_dir($full)) {
                $entries['dirs']++;
                continue;
            }

            $entries['files']++;
            $size = (int)(@filesize($full) ?: 0);
            $biggest[$entry] = $size;

            $type = fileTypeOf($full);
            $types[$type] = ($types[$type] ?? 0) + $size;
        }
        closedir($handle);
    }

    arsort($biggest);
    arsort($types);

    return [
        'bytes' => $total['bytes'],
        'files' => $total['files'],
        'complete' => $total['complete'],
        'entries' => $entries,
        'biggest' => array_map(
            fn(string $name, int $bytes): array => ['name' => $name, 'bytes' => $bytes],
            array_keys(array_slice($biggest, 0, 8, true)),
            array_values(array_slice($biggest, 0, 8, true))
        ),
        'types' => array_map(
            fn(string $type, int $bytes): array => ['type' => $type, 'bytes' => $bytes],
            array_keys($types),
            array_values($types)
        ),
    ];
}

/**
 * Whether this is an image, decided by its first bytes rather than its name.
 *
 * A `.png` is a claim made by whoever named the file. The signature is a fact
 * about its contents, and it is the only safe basis for deciding to show
 * something as a picture: rendering a file as an image because of its extension
 * is how a mislabelled thing gets handed to a decoder that was not expecting it.
 *
 * Dimensions come from getimagesize, which parses only the header.
 *
 * @return array{is_image: bool, format: ?string, width: ?int, height: ?int, reason: string}
 */
function imageFacts(string $path): array
{
    $none = ['is_image' => false, 'format' => null, 'width' => null, 'height' => null, 'reason' => ''];

    $size = @filesize($path);
    if ($size === false || $size < 8) {
        return $none + ['reason' => 'too small to carry a signature'];
    }
    if ($size > INSPECT_IMAGE_MAX) {
        return array_merge($none, ['reason' => 'larger than this will read for a preview']);
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return array_merge($none, ['reason' => 'not readable by this user']);
    }
    $head = (string)fread($handle, 16);
    fclose($handle);

    $format = imageSignature($head);
    if ($format === null) {
        return array_merge($none, ['reason' => 'the first bytes are not an image signature']);
    }

    $facts = ['is_image' => true, 'format' => $format, 'width' => null, 'height' => null, 'reason' => ''];

    if (function_exists('getimagesize')) {
        $measured = @getimagesize($path);
        if (is_array($measured)) {
            $facts['width'] = (int)$measured[0];
            $facts['height'] = (int)$measured[1];
        }
    }

    return $facts;
}

/**
 * The format named by a file's opening bytes, or null.
 *
 * Only the formats a browser will render without help. A TIFF or a PSD is an
 * image and cannot be shown as one, so recognising it here would produce a
 * broken picture rather than a useful answer.
 */
function imageSignature(string $head): ?string
{
    if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
        return 'png';
    }
    if (str_starts_with($head, "GIF87a") || str_starts_with($head, "GIF89a")) {
        return 'gif';
    }
    if (str_starts_with($head, "\xff\xd8\xff")) {
        return 'jpeg';
    }
    if (str_starts_with($head, "BM")) {
        return 'bmp';
    }
    // RIFF....WEBP
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
        return 'webp';
    }
    // An SVG is text, and text is not identified by a signature. Left out
    // deliberately: it is a document that can carry script, not a bitmap.
    return null;
}

/** The mime type to serve a recognised image as. */
function imageMime(string $format): string
{
    return match ($format) {
        'png' => 'image/png',
        'gif' => 'image/gif',
        'jpeg' => 'image/jpeg',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

/**
 * This path's recorded sizes, if it has any.
 *
 * Most paths have none - only the interesting ones are written each scan - and
 * that is reported as an absence rather than smoothed into an empty chart.
 */
function pathHistoryRows(PDO $pdo, string $path): array
{
    $stmt = $pdo->prepare('SELECT id, category FROM paths WHERE path = ?');
    $stmt->execute([$path]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['recorded' => false, 'path_id' => null, 'rows' => []];
    }

    $sizes = $pdo->prepare(
        'SELECT s.started_at, ps.bytes, ps.files, ps.links
           FROM path_sizes ps JOIN scans s ON s.id = ps.scan_id
          WHERE ps.path_id = ? AND s.finished_at IS NOT NULL
          ORDER BY s.started_at DESC LIMIT ' . INSPECT_HISTORY_ROWS
    );
    $sizes->execute([(int)$row['id']]);
    $rows = array_reverse($sizes->fetchAll());

    return [
        'recorded' => true,
        'path_id' => (int)$row['id'],
        'category' => $row['category'],
        'rows' => $rows,
    ];
}

/**
 * What the interface may offer for this path, and why not when it may not.
 *
 * The reasons matter more than the flags. "Delete is unavailable" invites the
 * user to go and find another way; "this is outside the categories the config
 * allows deleting from" tells them which line to change if they disagree.
 */
function permissionFacts(
    array $config,
    PDO $pdo,
    string $path,
    bool $isDir,
    bool $isLink
): array {
    $out = [
        'delete' => false, 'delete_reason' => '',
        'view' => false, 'view_reason' => '',
    ];

    if ($isLink) {
        $out['delete_reason'] = 'This is a symlink. Removing it would not free any space, '
            . 'and following it to delete what it points at is not something this will do.';
        $out['view_reason'] = 'This is a symlink.';
        return $out;
    }

    try {
        resolveDeletable($config, $path);
        $out['delete'] = true;
    } catch (ActionRefused $e) {
        $out['delete_reason'] = ucfirst($e->getMessage()) . '.';
    }

    if ($isDir) {
        $out['view_reason'] = 'A directory has no contents to read.';
        return $out;
    }

    try {
        resolveViewable($config, $path);
        $out['view'] = true;
    } catch (ActionRefused $e) {
        $out['view_reason'] = ucfirst($e->getMessage()) . '.';
    }

    return $out;
}
