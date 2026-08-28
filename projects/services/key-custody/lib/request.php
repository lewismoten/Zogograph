<?php
declare(strict_types=1);

/** Decodes a bounded body after the HTTP entry point has enforced its size. */
function keyCustodyRequestObject(string $raw): array
{
    $value = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    if (!is_object($value) || !($value instanceof stdClass)) throw new InvalidArgumentException('JSON object body is required');
    return get_object_vars($value);
}

/** Enforces the OpenAPI closed-object contract before a handler reads fields. */
function keyCustodyRequestFields(string $raw, array $allowed, array $required = []): array
{
    $body = keyCustodyRequestObject($raw);
    if (array_diff(array_keys($body), $allowed) !== []) throw new InvalidArgumentException('request contains unsupported fields');
    foreach ($required as $field) if (!array_key_exists($field, $body)) throw new InvalidArgumentException('request is missing required fields');
    return $body;
}
