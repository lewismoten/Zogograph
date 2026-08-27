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

    /** Credential-free endpoint identity probe for the isolated setup flow. */
    public function preflight(string $serviceBaseUrl, ?string $tlsSpkiPin = null): array
    {
        KeyCustodyHttpClient::healthEndpoint($serviceBaseUrl, $tlsSpkiPin);
        return ['id' => 'hostscope-key-custody', 'state' => 'endpoint-ready',
            'reason' => 'The configured custody endpoint returned the expected service identity and API version. No enrollment credential or key material was sent.'];
    }
}
