<?php
declare(strict_types=1);

/*
 * Deleting things.
 *
 * This is the only part of the monitor that cannot be undone, so it is the part
 * written most defensively. A request has to clear all of these before anything
 * is unlinked:
 *
 *   - a signed-in session
 *   - POST, with a CSRF token matching the session
 *   - a path that resolves, via realpath, to somewhere inside scan_root
 *   - a path in a category the config marks deletable
 *   - not a symlink, at any point along the way
 *   - not the monitor's own data directory
 *
 * The order matters: everything is decided against the *resolved* path, because
 * "/home/x/trash/../../../etc/passwd" is inside scan_root right up until the
 * moment you resolve it.
 *
 * Every attempt is written to the actions table, including the refusals. A run
 * of refusals is what an attempt to escape scan_root looks like, and it is the
 * only place it would ever show up.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/collect.php';

final class ActionRefused extends RuntimeException
{
}

/**
 * Resolves a requested path and refuses it if it is not somewhere we may touch.
 *
 * Returns the real path. Throws ActionRefused with a reason otherwise - the
 * reason is logged, but the caller should not hand every distinction back to an
 * unauthenticated client.
 */
function resolveDeletable(array $config, string $requested): string
{
    if ($requested === '' || str_contains($requested, "\0")) {
        throw new ActionRefused('empty or malformed path');
    }

    $real = realpath($requested);
    if ($real === false) {
        throw new ActionRefused('path does not exist');
    }

    $root = realpath((string)$config['scan_root']);
    if ($root === false) {
        throw new ActionRefused('scan root does not resolve');
    }

    // The trailing separator matters: without it "/home/regaldra-old" passes a
    // prefix test against "/home/regaldra".
    if ($real !== $root && !str_starts_with($real . '/', rtrim($root, '/') . '/')) {
        throw new ActionRefused('outside the scan root');
    }
    if ($real === $root) {
        throw new ActionRefused('refusing to delete the scan root itself');
    }

    // Never act through a link, and never act on one: both are ways to reach
    // somewhere the checks above just approved a different path for.
    if (is_link($requested) || is_link($real)) {
        throw new ActionRefused('symlinks are not deleted');
    }

    $dataDir = realpath(dirname((string)$config['database']));
    if ($dataDir !== false && ($real === $dataDir || str_starts_with($real . '/', $dataDir . '/'))) {
        throw new ActionRefused('refusing to delete the monitor\'s own data');
    }

    $category = categoryOf($real . (is_dir($real) ? '/x' : ''), $config['scan'], $root);
    $allowed = $config['deletable_categories'] ?? [];
    if ($category === null || !in_array($category, $allowed, true)) {
        throw new ActionRefused('not in a deletable category');
    }

    return $real;
}

/**
 * Deletes one file.
 *
 * Files only. Handing a recursive delete to a single path parameter is how a
 * one-character mistake removes a home directory; emptying a whole category is
 * a separate, named action below.
 */
function deleteFile(array $config, PDO $pdo, string $requested): array
{
    try {
        $real = resolveDeletable($config, $requested);
    } catch (ActionRefused $e) {
        logAction($pdo, 'delete_file', $requested, 'refused', 0, 0, $e->getMessage());
        throw $e;
    }

    if (is_dir($real)) {
        logAction($pdo, 'delete_file', $real, 'refused', 0, 0, 'is a directory');
        throw new ActionRefused('that is a directory; use empty instead');
    }

    $size = (int)(@filesize($real) ?: 0);
    if (!@unlink($real)) {
        logAction($pdo, 'delete_file', $real, 'failed', 0, 0, 'unlink returned false');
        throw new ActionRefused('could not delete the file');
    }

    logAction($pdo, 'delete_file', $real, 'ok', $size, 1);
    return ['bytes_freed' => $size, 'files_removed' => 1];
}

/**
 * Empties a whole category - trash, or temp.
 *
 * Walks the tree and removes the files that count against that category,
 * leaving the directories in place. Leaving them is deliberate: a trash
 * directory that removes itself is a surprise, and directories cost nothing.
 */
function emptyCategory(array $config, PDO $pdo, string $category): array
{
    $allowed = $config['deletable_categories'] ?? [];
    if (!in_array($category, $allowed, true)) {
        logAction($pdo, 'empty_category', $category, 'refused', 0, 0, 'category not deletable');
        throw new ActionRefused('that category cannot be emptied');
    }

    $root = realpath((string)$config['scan_root']);
    if ($root === false) {
        throw new ActionRefused('scan root does not resolve');
    }

    $excluded = array_map('normalisePath', $config['scan']['exclude_paths'] ?? []);
    $freed = 0;
    $removed = 0;
    $failed = 0;

    $files = [];
    collectCategoryFiles($root, $config['scan'], $category, $excluded, $root, $files);

    foreach ($files as $file => $size) {
        // Re-checked one at a time rather than trusted from the walk: the walk
        // and the delete are not the same instant, and this is the last point
        // at which a swapped path can be caught.
        try {
            $real = resolveDeletable($config, $file);
        } catch (ActionRefused) {
            $failed++;
            continue;
        }
        if (@unlink($real)) {
            $freed += $size;
            $removed++;
        } else {
            $failed++;
        }
    }

    logAction(
        $pdo,
        'empty_category',
        $category,
        $failed > 0 ? 'partial' : 'ok',
        $freed,
        $removed,
        $failed > 0 ? "$failed could not be removed" : null
    );

    return ['bytes_freed' => $freed, 'files_removed' => $removed, 'failed' => $failed];
}

/** Every file under $dir that counts against $category, with its size. */
function collectCategoryFiles(
    string $dir,
    array $rules,
    string $category,
    array $excluded,
    string $root,
    ?array &$out = null,
    int $depth = 0
): void {
    $out ??= [];
    if ($depth > 40 || isExcludedPath($dir, $excluded)) {
        return;
    }

    $handle = @opendir($dir);
    if ($handle === false) {
        return;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_link($full)) {
            continue;
        }
        if (is_dir($full)) {
            collectCategoryFiles($full, $rules, $category, $excluded, $root, $out, $depth + 1);
            continue;
        }
        if (categoryOf($full, $rules, $root) !== $category) {
            continue;
        }
        $size = @filesize($full);
        if ($size !== false) {
            $out[$full] = (int)$size;
        }
    }

    closedir($handle);
}

function logAction(
    PDO $pdo,
    string $action,
    ?string $target,
    string $outcome,
    int $bytes = 0,
    int $files = 0,
    ?string $detail = null
): void {
    $pdo->prepare(
        'INSERT INTO actions (at, action, target, outcome, bytes_freed, files_removed, detail, remote_addr)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        time(),
        $action,
        $target,
        $outcome,
        $bytes,
        $files,
        $detail,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
