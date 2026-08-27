<?php
declare(strict_types=1);

function secretScriptExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
/** @return array{int,string,string} */
function secretScriptRun(string $script, string $path): array
{
    $pipes = []; $process = proc_open([PHP_BINARY, $script, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('could not run custody secret setup script');
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $error];
}

$root = sys_get_temp_dir() . '/hostscope-custody-secret-' . bin2hex(random_bytes(4)); mkdir($root, 0700, true);
try {
    foreach (['master-key.php' => 'master.key', 'billing-token.php' => 'billing.token'] as $script => $name) {
        $path = $root . '/' . $name;
        [$status] = secretScriptRun(__DIR__ . '/../scripts/' . $script, $path);
        secretScriptExpect($status === 0 && is_file($path) && !is_link($path) && (fileperms($path) & 0077) === 0,
            $script . ' did not create an owner-only regular secret file');
        $first = file_get_contents($path);
        [$status] = secretScriptRun(__DIR__ . '/../scripts/' . $script, $path);
        secretScriptExpect($status === 1 && file_get_contents($path) === $first, $script . ' replaced an existing secret file');
        @unlink($path);
    }
    echo "key custody secret setup script checks passed\n";
} finally { @rmdir($root); }
