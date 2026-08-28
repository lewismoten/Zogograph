<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/api-json.php';

function apiJsonExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$value = apiCompactValue([
    'integer' => 922337203685477580,
    'normal' => 0.9999999123,
    'nested' => ['small' => 0.00000099123, 'negative' => -12.123456789],
]);
apiJsonExpect($value['integer'] === 922337203685477580, 'integer counter changed at the API boundary');
apiJsonExpect($value['normal'] === 1.0, 'float was not rounded to five decimal places');
apiJsonExpect($value['nested']['small'] === 0, 'sub-five-decimal float was not compacted');
apiJsonExpect($value['nested']['negative'] === -12.12346, 'nested float was not rounded');
$json = apiJson(['number' => 0.123456789]);
apiJsonExpect($json === '{"number":0.12346}', 'wire JSON retained excess floating-point precision');
$invalid = apiJson(['text' => "bad\xB1text"]);
apiJsonExpect(str_contains($invalid, '\\ufffd'), 'invalid UTF-8 invalidated the API response');
echo "API JSON checks passed\n";
