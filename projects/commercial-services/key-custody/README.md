# HostScope key-custody service

This is the optional commercial remote key-hosting service. It is not a
dependency of local sealed copies, self-managed fleet custody, collection, or
the dashboard. Its first implementation may use a private SQLite database per
service deployment; a managed SQL backend is an operational upgrade, not a
customer-visible requirement.

The service stores key metadata and wrapped per-artifact data-key envelopes. It
does not accept metric databases, sealed artifacts, or raw customer data. The
replica relay is a different service and must not share the unwrap path or a
combined tenant credential.

The custody API enforces that its SQLite metadata directory is an absolute,
non-symlinked private path outside the deployed web root. It creates the
directory, database, and SQLite WAL/SHM companion files with owner-only
permissions; a relative or web-served database location is refused rather than
treated as a convenience default. This filesystem hardening limits accidental
local disclosure but is not itself live database encryption; use encrypted
volume storage or a later verified database-encryption deployment when that is
required.

### Existing symmetric records

Service databases created before the public-wrapping update retain their old
wrapped symmetric custody keys and existing envelopes for recovery only. They
are shown by `GET /v1/keys` as `legacy_symmetric`; no public wrapping key is
available and new envelopes must not be added to them. Rotate the key, seal
each artifact's fresh data key with the replacement's public key, verify
recovery, then delete old envelopes and retire the legacy key. This preserves
recovery during upgrade without claiming that the old design has the new
asymmetric property.

Every API JSON request is capped at 2 MiB before parsing. This safely leaves
room for the 1 MiB wrapped-envelope limit and its Base64 encoding while refusing
oversized management or client requests before they consume unbounded PHP
memory.

See `openapi.json` for the API contract. The companion client integration will
be the optional `hostscope-key-custody` package. Until it is installed and
enabled, HostScope uses the local key file or self-managed fleet options.

`GET /v1/health` is the unauthenticated deployment probe. It opens the private
SQLite service store and returns only service liveness/version metadata; it
does not reveal tenants, plans, keys, envelopes, credentials, or artifacts.

The API returns `no-store`, anti-framing, no-referrer, no-sniff, and restrictive
browser-permission headers. When PHP sees HTTPS it also returns HSTS; keep HSTS
at the TLS reverse proxy too, and do not enable it for a hostname that is not
ready to serve HTTPS on every covered subdomain.

For the provisional VPS path, use
[`deploy/nginx.conf.example`](deploy/nginx.conf.example) inside the HTTPS
server block. It is important to forward `Authorization` and set `HTTPS=on`
for PHP when TLS terminates at Nginx; otherwise the service correctly refuses
requests rather than silently accepting insecure traffic. The example also
rejects bodies above 2 MiB at the proxy before PHP reads them and repeats the
API response-header baseline. Review the HSTS `includeSubDomains` scope before
using it on a shared hostname.

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
- An enrolled client obtains an active key's X25519 **public** wrapping key,
  creates a fresh 256-bit data key locally, and seals that data key into one
  artifact envelope. The service retains the matching private half encrypted
  under its private service master key. The unwrap endpoint may return only the
  named artifact data key over HTTPS after a scoped signed request; it never
  exposes a private wrapping key through billing or management APIs.
- Before recording an envelope, the service transiently opens it with that
  private wrapping half and refuses anything other than a 256-bit data key.
  The plaintext result is neither persisted nor written to the audit log. This
  prevents malformed recovery records from being accepted merely because they
  have the right ciphertext length.
- Log every enrollment, policy, authorization, rotation, disablement, and
  billing state change without recording plaintext keys, envelopes, or tokens.
- Default to disabled remote custody, one tenant per account, 15-minute unwrap
  authorization maximum, no persisted client-side unwrapped-key cache, and a
  30-day audit-event minimum.

The commercial website sets plans, prices, quotas, invoices, payment-provider
references, and cancellation/retention policy. The custody API reports those
states but does not receive card or bank details.

Only a current active subscription can create a new custody key or store a new
envelope. If its configured renewal time passes, new writes pause until the
billing control plane updates the entitlement. An inactive or overdue
subscription does not erase existing envelopes or automatically block their
recovery: billing must not become a way to hold an operator's encrypted data
hostage. Retention/deletion terms remain explicit commercial policy and must
provide notice and recovery export procedures.

Each subscription also has an `envelope_quota`, initially 1,000. It limits new
named artifact envelopes per tenant, not re-saving an existing envelope and not
recovery of an existing artifact. This keeps the low-cost SQLite deployment
bounded without using a billing failure to strand a customer's data. The
commercial billing control plane can adjust it alongside the key quota. The
customer's `GET /v1/subscription` response includes `envelope_used`, so an
account UI can show remaining capacity before an upload is rejected. The same
management response includes `key_used` and `can_create_keys`, so the operator
can distinguish a plan limit from a key-creation failure without exposing key
material.

Each tenant also defaults to **60 recovery-key releases per UTC hour**. This is
a small abuse-control ceiling for a lost or malfunctioning enrolled client, not
an availability entitlement: it applies even when a subscription is overdue or
cancelled, then resets automatically at the next hour. The billing control
plane may set `unwrap_quota_per_hour` from 1 to 100,000 for a documented
recovery workload. Do not set it to an unrealistically low value; it can delay
bulk recovery, but it must never be used to permanently deny existing envelopes.

An enrolled deployment can instead use signed `GET /v1/client/subscription`.
That minimal view reports only envelope usage, envelope quota, subscription
state, and whether another envelope can be stored. It is deliberately not a
billing or account-management API and does not reveal key metadata, recovery
material, plan-change history, or any management capability.

Quota decisions and the corresponding key or envelope write share an immediate
SQLite write lock. Concurrent clients therefore cannot both consume the last
available quota unit; one succeeds and later requests observe the committed
usage. This keeps the initial single-service SQLite deployment dependable
without requiring a separate quota or cache service.

## Current reference API

Copy `config.sample.php` to `config.php`, create a private Base64 256-bit
`master_key_file`, and provision the first tenant:

```bash
php scripts/master-key.php /secure/hostscope-key-custody/master.key
php scripts/provision-tenant.php "Example customer"
```

Set the generated absolute path in `config.php`; keep the SQLite database in a
private directory outside the deployed service web root (the sample uses
`/var/lib/hostscope-key-custody/key-custody.db`). Keep the printed management
token, client token, and client signing private key separately; the service
retains only token hashes and the signing public key and cannot show the token
or private key again. `api.php` currently implements key list/create, signed
public-wrapping-key read, client artifact-data-key unwrap,
subscription status plus pending change-request read/withdrawal, and configured
billing portal/checkout session links. It is a low-cost SQLite reference
service; deploy it behind HTTPS and an authenticated reverse proxy before
exposing it publicly. Envelope storage, key rotation, and
timestamp/nonce replay protection with signed client requests are included.
mTLS enrollment and payment-provider integration remain the next service
increments.

Create a consistent metadata backup with no extra database service:

```bash
php scripts/backup-metadata.php /secure/hostscope-key-custody/backups/metadata-20260827.db
```

The command refuses an existing, symbolic-link, or web-root destination and
prints a SHA-256 value for the resulting private SQLite file. It deliberately
does **not** copy `master_key_file`; maintain a separately protected offline
master-key recovery copy and test restoring both paths together.

### Service master-key rotation

This is distinct from customer custody-key rotation. It rewraps the service's
stored custody private keys, not customer artifacts or their data keys. Keep
the API workers stopped throughout the operation: the command takes an
exclusive SQLite transaction, but it cannot prevent a separately running PHP
worker from opening a record with the old configured master key after the
transaction commits.

Create a new owner-only 256-bit key file, keep the old key available, then run
the rotation with a new private metadata-backup destination:

```bash
php scripts/master-key.php /secure/hostscope-key-custody/master-2027.key
php scripts/rotate-master-key.php \
  --old-key /secure/hostscope-key-custody/master.key \
  --new-key /secure/hostscope-key-custody/master-2027.key \
  --backup /secure/hostscope-key-custody/backups/before-master-rotation.db \
  --confirm
```

The command decrypts every service-held custody wrapping key with the old
master key, encrypts and decrypts it again with the replacement inside one
exclusive transaction, then records an audit event for each affected tenant.
Only after it succeeds, update `master_key_file` to the replacement path while
the service remains stopped, start the service, and perform artifact recovery
checks. Retain both the old master key and the verified pre-rotation metadata
backup until recovery with the replacement has been proven. This intentional
maintenance window keeps the initial SQLite service simple; a future HA
provider can supply a staged multi-master-key migration without downtime.

### Optional commercial billing control plane

Customer management tokens can request a plan change and open the configured
billing portal. A payment provider or commercial-site backend applies the
result through a different, opt-in endpoint; it must never use a customer
token or enrolled-client credential. Create a separate private token file:

```bash
php scripts/billing-token.php /secure/hostscope-key-custody/billing.token
```

Set that absolute path as `billing_service_token_file` in `config.php`, then
have only the commercial billing backend call:

```text
PATCH /v1/internal/subscriptions/{tenantId}
Authorization: Bearer <contents of billing.token>
{"plan":"starter","state":"active","key_quota":2,"envelope_quota":1000,"renews_at":1767225600}
```

This endpoint changes only plan, state, quota, renewal metadata, and pending
request state. Its token is rejected by all key, envelope, audit, and unwrap
operations. Leave the token path empty when using the SQLite reference service
without a commercial billing backend; the endpoint then returns `503` rather
than exposing a partially configured integration.

Account-management clients can read their own recent `/v1/audit` events. The
service returns only bounded tenant-local operational metadata—never plaintext
keys, envelopes, tokens, or private signing material—so a commercial website
can explain key, enrollment, and subscription changes without becoming a key
recovery channel.

### Low-friction management CLI

For a small self-operated deployment, use the included CLI instead of putting
a management token in a browser URL, shell history, or client configuration.
Put the one-time management token in an absolute `0600` file outside the web
root, then use a normal HTTPS endpoint:

```bash
php scripts/manage-remote.php \
  --base-url https://custody.example/v1 \
  health

php scripts/manage-remote.php \
  --base-url https://custody.example/v1 \
  --token-file /secure/hostscope-key-custody/management.token \
  keys

php scripts/manage-remote.php \
  --base-url https://custody.example/v1 \
  --token-file /secure/hostscope-key-custody/management.token \
  create-key primary 300
```

It supports key policy and staged rotation, envelope inventory/deletion,
subscription requests, and configured billing portal/checkout links. The
command verifies the TLS certificate and hostname, follows no redirects, and
never prints the input token. `--tls-spki-pin sha256//...` is available for an
advanced pinned-identity deployment; leave it unset for normal CA validation
and update it through a tested migration before rotating the certificate key.
Run `php scripts/manage-remote.php` with no arguments for the full command
list. It includes tenant key policy, envelope review, enrolled-client
creation/revocation, one-time client/management credential rotation, bounded
audit history, subscriptions, and billing links. One-time credential commands
print secrets only in their successful response, so use a private terminal and
place the values directly in an encrypted secret reference. This management
token remains separate from every enrolled-client, billing-control, and
recovery-key credential.

An account-management client may delete one named envelope with
`DELETE /v1/keys/{keyId}/envelopes/{artifactId}` with JSON
`{"confirm_recovery":true}`. The management CLI requires the corresponding
`delete-envelope KEY_ID ARTIFACT_ID --confirm-recovery` flag. This does not
delete the custody key or any other artifact envelope. It is irreversible from
the service's perspective: verify another recovery path before making the
request.
`GET /v1/keys` includes each key's `envelope_count`, so an operator can see
which key still protects retained artifacts before attempting a staged rotation,
retirement, or disablement.

The reference API can recommend a short in-memory cache duration, but it
cannot revoke key material after a client has received it. Keep the client
cache at zero by default, rotate a suspected key, and use an HSM/KMS-backed
provider package when an environment needs stronger non-exportable-key policy.
The service caps a requested recommendation at the key's configured
authorization TTL; this is a cache ceiling, not a claim that previously
returned material expires or is remotely revocable.

Use one enrolled-client credential per customer deployment. Account management
can `GET` or `POST /v1/client-credentials` to list metadata or create a new
one-time bearer/signing-key pair with a clear deployment label, and can revoke only one old deployment with
`DELETE /v1/client-credentials/{credentialId}`. If credentials might be
widely exposed, `POST /v1/client-credentials/rotate` creates one replacement
pair and immediately revokes **all** existing enrolled-client credentials for
that tenant. Update a client package's two `secret_ref:` values together before
retiring that deployment.

If a customer management token is exposed, use it once to call
`POST /v1/management-credentials/rotate`. The response contains a replacement
management token once and revokes every prior management token for that tenant.
Record the replacement in the account-management system before closing the
current session; neither a client credential nor the commercial billing token
can perform this recovery action.

Key rotation is staged to preserve recovery. `PATCH /v1/keys/{keyId}` with
`{"action":"rotate"}` creates a replacement and marks the old key
`rotating`; it remains able to unwrap its existing registered envelopes. Create
replacement envelopes, prove recovery, and delete the old envelopes. Only then
use `{"action":"retire"}`. The service refuses to retire or disable a key
that still has envelopes, because doing so would silently strand customer
artifacts.

For a routine policy change, use the same endpoint with
`{"action":"update_policy","authorization_ttl_seconds":120}` and an
optional display `name`. This changes only the maximum cache recommendation
for future unwraps; it neither re-encrypts data nor invalidates a key already
received by a client.

On Apache, the included `.htaccess` routes `/v1/...` requests to `api.php` and
denies direct configuration/database files. Nginx and other servers must apply
the equivalent route and direct-file-denial rules in their own configuration.
