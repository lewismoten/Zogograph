# Commercial services

Optional HostScope-operated services live here. They are separate from the
public site and are never required for local collection, dashboard access, or
local encrypted copies.

The recommended provisional VPS paths, future domain migration rule, and
service separation policy are in [`../DIRECTION.md`](../DIRECTION.md).

## Candidate services

- AI proxy — applies account policy and forwards explicitly permitted requests
  to a selected model provider.
- Key custody — authorizes short-lived unwrap operations for encrypted
  artifacts; it stores wrapped key material, never plaintext metrics.
- Fleet replica relay — stores and verifies ciphertext artifacts for enrolled
  customers without receiving a usable data key. The low-cost reference
  implementation lives in [`replica-relay/`](replica-relay/).

Every service needs its own API, tenant isolation, retention policy, audit
trail, incident response, and availability model. The client-side protocol is
versioned and optional; a failure here must not stop local collection.

Key custody and replica storage must remain distinct roles by default. A hosted
service must not receive both a decryptable artifact and the material needed to
unwrap it.

## Recommended launch order

Do not launch HostScope-operated key custody with the first commercial service
release. Begin, if needed, with an optional ciphertext-only replica relay after
tenant isolation, rate limits, retention/deletion controls, verification,
support escalation, and incident response are proven. A relay must not receive
a data-encryption key or unwrap authorization.

Reconsider optional key custody only after self-managed fleet custody has passed
recovery and outage drills, the service has independent security review,
short-lived authorization and revocation work, and clear recovery/deletion and
privacy terms exist. The service's base URL, price, retention, and availability
policy must be commercial settings, never compiled into a client package.
