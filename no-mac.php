<?php

declare(strict_types=1);

$root = $argv[1] ?? __DIR__;

function deleteDirectory(string $path): void
{
    $items = scandir($path);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $item;

        if (is_dir($child) && !is_link($child)) {
            deleteDirectory($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
}

function cleanMacFiles(string $directory): void
{
    $items = scandir($directory);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path) && !is_link($path)) {
            if ($item === '__MACOSX') {
                echo "Deleting directory: {$path}\n";
                deleteDirectory($path);
                continue;
            }

            cleanMacFiles($path);
            continue;
        }

        if (str_starts_with($item, '._')) {
            echo "Deleting file: {$path}\n";
            unlink($path);
        }
    }
}

if (!is_dir($root)) {
    fwrite(STDERR, "Directory does not exist: {$root}\n");
    exit(1);
}

echo "Cleaning: {$root}\n";

cleanMacFiles($root);

echo "Done.\n";