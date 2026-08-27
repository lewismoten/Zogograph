# HostScope key-custody service

This is the optional commercial remote key-hosting service. It is not a
dependency of local sealed copies, self-managed fleet custody, collection, or
the dashboard. Its first implementation may use a private SQLite database per
service deployment; a managed SQL backend is an operational upgrade, not a
customer-visible requirement.

The service stores key metadata and wrapped data-encryption-key envelopes. It
does not accept metric databases, sealed artifacts, or raw customer data. The
replica relay is a different service and must not share the unwrap path or a
combined tenant credential.

See `openapi.json` for the API contract. The companion client integration will
be the optional `hostscope-key-custody` package. Until it is installed and
enabled, HostScope uses the local key file or self-managed fleet options.

## Security baseline

- Require HTTPS and enrolled client authentication (mTLS preferred; a
  short-lived scoped client assertion is the fallback).
- Use a distinct account-management session/token for billing and key-policy
  management; never reuse it for collector or replica traffic.
- Require every enrolled-client request to include a timestamp within five
  minutes and a one-time nonce; the service retains used nonces for ten minutes
  to reject replayed unwrap or envelope requests.
- Return key identifiers and policy only to management endpoints. The enrolled
  client unwrap endpoint may return the requested key over HTTPS after scoped
  authorization; it never exposes a key through billing or management APIs.
- Log every enrollment, policy, authorization, rotation, disablement, and
  billing state change without recording plaintext keys, envelopes, or tokens.
- Default to disabled remote custody, one tenant per account, 15-minute unwrap
  authorization maximum, no persisted client-side unwrapped-key cache, and a
  30-day audit-event minimum.

The commercial website sets plans, prices, quotas, invoices, payment-provider
references, and cancellation/retention policy. The custody API reports those
states but does not receive card or bank details.

## Current reference API

Copy `config.sample.php` to `config.php`, create a private Base64 256-bit
`master_key_file`, and provision the first tenant with the store/administration
workflow. `api.php` currently implements key list/create, client unwrap,
subscription read, and configured billing portal/checkout session links. It is
a low-cost SQLite reference service; deploy it behind HTTPS and an authenticated
reverse proxy before exposing it publicly. mTLS enrollment, envelope storage,
key rotation, request replay protection, and payment-provider integration are
the next service increments.
