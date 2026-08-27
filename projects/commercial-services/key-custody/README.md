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

- Require HTTPS and enrolled-client authentication. The reference service
  requires both a client bearer credential and an Ed25519 signature over the
  method, path, timestamp, nonce, and exact body hash. mTLS is the planned
  deployment upgrade for high-assurance environments.
- Use a distinct account-management session/token for billing and key-policy
  management; never reuse it for collector or replica traffic.
- Require every enrolled-client request to include a timestamp within five
  minutes, a one-time nonce, and the signature; the service retains used
  nonces for ten minutes to reject replayed unwrap or envelope requests.
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
`master_key_file`, and provision the first tenant:

```bash
php scripts/master-key.php /secure/hostscope-key-custody/master.key
php scripts/provision-tenant.php "Example customer"
```

Set the generated absolute path in `config.php`. Keep the printed management
token, client token, and client signing private key separately; the service
retains only token hashes and the signing public key and cannot show the token
or private key again. `api.php` currently implements key list/create, client unwrap,
subscription read, and configured billing portal/checkout session links. It is
a low-cost SQLite reference service; deploy it behind HTTPS and an authenticated
reverse proxy before exposing it publicly. Envelope storage, key rotation, and
timestamp/nonce replay protection with signed client requests are included.
mTLS enrollment and payment-provider integration remain the next service
increments.

If an enrolled client is replaced or its credentials might have been exposed,
an account-management client can `POST /v1/client-credentials/rotate`. The
response contains a new client bearer token and Base64 Ed25519 private key once
and immediately revokes all previous enrolled-client credentials for that
tenant. Update the client package's two `secret_ref:` values together before
retiring the old deployment.

On Apache, the included `.htaccess` routes `/v1/...` requests to `api.php` and
denies direct configuration/database files. Nginx and other servers must apply
the equivalent route and direct-file-denial rules in their own configuration.
