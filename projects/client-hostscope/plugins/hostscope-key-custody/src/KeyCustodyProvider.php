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

    /**
     * Confirms only that one enrolled client can read its minimal envelope
     * entitlement. It never creates a key/envelope or requests key material.
     * Credentials are supplied for this one short-lived sandbox invocation.
     */
    public function enrollmentCheck(string $serviceBaseUrl, string $clientCredential, string $clientSigningKey, ?string $tlsSpkiPin = null): array
    {
        $entitlement = (new KeyCustodyHttpClient($serviceBaseUrl, $clientCredential, $clientSigningKey, $tlsSpkiPin))->envelopeEntitlement();
        return ['id' => 'hostscope-key-custody', 'state' => 'enrollment-ready',
            'reason' => $entitlement['can_store_envelopes']
                ? 'The enrolled client credential is accepted and may store a future wrapped data-key envelope. No envelope or key was requested.'
                : 'The enrolled client credential is accepted, but its current subscription or envelope quota does not permit a new envelope. No envelope or key was requested.'];
    }
}
