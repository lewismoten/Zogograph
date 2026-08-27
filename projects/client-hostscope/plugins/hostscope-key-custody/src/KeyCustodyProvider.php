<?php
declare(strict_types=1);

namespace HostScope\Plugin\KeyCustody;

final class KeyCustodyProvider
{
    /** @return array{id:string,state:string,reason:string} */
    public function capability(): array
    {
        return ['id' => 'hostscope-key-custody', 'state' => 'unavailable',
            'reason' => 'The isolated HostScope package runtime is not installed.'];
    }

    public function activate(): never
    {
        throw new \RuntimeException($this->capability()['reason']);
    }
}
