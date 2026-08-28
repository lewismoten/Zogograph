<?php
declare(strict_types=1);

/* The browser and AI tools do not benefit from binary floating-point noise.
 * Keep the wire contract compact; byte counters and integer identifiers stay
 * integers, while measurements use at most five decimal places. */

const API_FLOAT_DECIMALS = 5;

function apiCompactValue(mixed $value): mixed
{
    if (is_float($value)) {
        $rounded = round($value, API_FLOAT_DECIMALS);
        return $rounded == 0.0 ? 0 : $rounded;
    }
    if (!is_array($value)) return $value;
    foreach ($value as $key => $item) $value[$key] = apiCompactValue($item);
    return $value;
}

function apiJson(mixed $value): string
{
    return json_encode(apiCompactValue($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
