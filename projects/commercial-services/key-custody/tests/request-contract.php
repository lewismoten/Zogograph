<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/request.php';
function requestExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
requestExpect(keyCustodyRequestFields('{"name":"primary"}', ['name'], ['name']) === ['name' => 'primary'], 'valid closed request was rejected');
foreach ([['[]', 'JSON object'], ['{"name":"primary","extra":true}', 'unsupported'], ['{}', 'missing required'], ['not-json', 'Syntax error']] as [$raw, $message]) {
    try { keyCustodyRequestFields($raw, ['name'], ['name']); throw new RuntimeException('invalid request was accepted'); }
    catch (InvalidArgumentException|JsonException $error) { requestExpect(str_contains($error->getMessage(), $message), 'invalid request did not return the expected refusal'); }
}
echo "key custody request contract checks passed\n";
