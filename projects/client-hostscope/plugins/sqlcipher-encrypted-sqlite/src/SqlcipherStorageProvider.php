<?php
declare(strict_types=1);

namespace HostScope\Plugin\SqlcipherEncryptedSqlite;

/**
 * Package-runtime entry point contract preview. The marketplace runtime does
 * not load executable packages yet, so activation fails closed if invoked.
 */
final class SqlcipherStorageProvider
{
    /** @return array{id: string, state: string, reason: string} */
    public function capability(): array
    {
        return [
            'id' => 'sqlcipher-encrypted-sqlite',
            'state' => 'unavailable',
            'reason' => 'The isolated HostScope package runtime is not installed.',
        ];
    }

    public function activate(): never
    {
        throw new \RuntimeException($this->capability()['reason']);
    }
}
