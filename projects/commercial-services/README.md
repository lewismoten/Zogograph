# Commercial services

Optional HostScope-operated services live here. They are separate from the
public site and are never required for local collection, dashboard access, or
local encrypted copies.

## Candidate services

- AI proxy — applies account policy and forwards explicitly permitted requests
  to a selected model provider.
- Key custody — authorizes short-lived unwrap operations for encrypted
  artifacts; it stores wrapped key material, never plaintext metrics.
- Fleet replica relay — stores and verifies ciphertext artifacts for enrolled
  customers without receiving a usable data key.

Every service needs its own API, tenant isolation, retention policy, audit
trail, incident response, and availability model. The client-side protocol is
versioned and optional; a failure here must not stop local collection.

Key custody and replica storage must remain distinct roles by default. A hosted
service must not receive both a decryptable artifact and the material needed to
unwrap it.
