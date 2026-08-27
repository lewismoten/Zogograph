<?php
declare(strict_types=1);

function manageExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
/** @return array{int,string,string} */
function manageRun(array $arguments): array
{
    $pipes = []; $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../scripts/manage-remote.php'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('could not run custody management CLI');
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $error];
}

[$status, , $error] = manageRun(['--base-url', 'https://custody.example/v1', '--token-file', 'relative.token', 'keys']);
manageExpect($status === 1 && str_contains($error, 'management token file must be an absolute'), 'management CLI did not parse documented space-separated options safely');
[$status, , $error] = manageRun(['--base-url=not-a-url', '--token-file=/secure/token', 'keys']);
manageExpect($status === 1 && str_contains($error, 'management base URL must be an unambiguous HTTPS URL'), 'management CLI did not parse equals-form options safely');
$source = file_get_contents(__DIR__ . '/../scripts/manage-remote.php');
manageExpect($source !== false && str_contains($source, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($source, 'CURLOPT_SSL_VERIFYHOST => 2')
    && str_contains($source, 'CURLOPT_PINNEDPUBLICKEY') && str_contains($source, "'Authorization: Bearer ' . \$token"),
    'management CLI does not retain its required secure transport boundary');
echo "key custody management CLI checks passed\n";
