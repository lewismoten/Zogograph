#!/usr/bin/env php
<?php
declare(strict_types=1);

/* CLI-only, explicit recovery for the sealed bootstrap configuration. It
 * parses recovered PHP without including it, backs up the current bootstrap,
 * then atomically replaces it. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This recovery command is CLI-only.\n"); }
if ($argc !== 6 || $argv[1] !== '--sealed' || $argv[3] !== '--key' || $argv[5] !== '--confirm') {
    fwrite(STDERR, "Usage: php scripts/restore-config.php --sealed /secure/config.sealed --key /secure/hostscope.key --confirm\n");
    exit(64);
}
require_once __DIR__ . '/../lib/encryption.php';
$root = dirname(__DIR__); $target = $root . '/lib/config.php'; $source = realpath($argv[2]);
if ($source === false || is_link($argv[2]) || !is_file($source) || is_link($target) || !is_dir(dirname($target)) || !is_writable(dirname($target))) {
    fwrite(STDERR, "The sealed source or bootstrap configuration location is unavailable or unsafe.\n"); exit(1);
}
$recovery = $root . '/data/recovery';
if (is_link($recovery) || (!is_dir($recovery) && !mkdir($recovery, 0700, true)) || !is_dir($recovery)) {
    fwrite(STDERR, "The private recovery directory is unavailable or unsafe.\n"); exit(1);
}
@chmod($recovery, 0700);
$plain = $recovery . '/config-restore-' . bin2hex(random_bytes(8)) . '.php';
$temporary = $target . '.restore-' . bin2hex(random_bytes(8));
try {
    encryptionOpenFile($source, $plain, encryptionReadLocalKey($argv[4], $root));
    encryptionVerifyRecoveredStore($plain, 'config');
    if (is_file($target)) {
        $backup = $recovery . '/config-before-restore-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.php';
        if (!copy($target, $backup)) throw new RuntimeException('could not save the current bootstrap configuration');
        @chmod($backup, 0600);
    } else { $backup = null; }
    if (!copy($plain, $temporary)) throw new RuntimeException('could not stage recovered bootstrap configuration');
    @chmod($temporary, 0600);
    if (!rename($temporary, $target)) throw new RuntimeException('could not atomically replace bootstrap configuration');
    fwrite(STDOUT, 'Restored bootstrap configuration' . ($backup === null ? '.' : "; saved prior copy to {$backup}.") . "\n");
} catch (Throwable $error) {
    @unlink($temporary); fwrite(STDERR, 'Restore refused: ' . $error->getMessage() . "\n"); exit(1);
} finally { @unlink($plain); }
