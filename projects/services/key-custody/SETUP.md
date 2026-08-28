# Set up the Zogograph key-custody service

This is an optional remote key-hosting service. A customer does not need it to
use Zogograph’s local encrypted copies. Deploy it only when remote key custody
solves a real recovery or separation-of-duties need.

The recommended first deployment is one PHP service behind HTTPS, using its
private SQLite metadata store. It requires PHP 8.1+, PDO SQLite, OpenSSL,
libsodium, cURL, and a scheduled private backup. No external database, KMS, or
payment provider is needed for this baseline.

## 1. Keep service state private

Deploy the service code beneath the VPS application tree, but place all
writable or secret material outside the web root:

| item | recommended location | rule |
| --- | --- | --- |
| service code | `/srv/zogograph/services/key-custody` | deployed read-only by the PHP service account where practical |
| SQLite metadata | `/var/lib/zogograph-key-custody/key-custody.db` | absolute, non-symlinked private directory; never serve it over HTTP |
| service master key | `/secure/zogograph-key-custody/master.key` | absolute regular `0600` file, outside web root and normal metadata backup |
| metadata backups | `/secure/zogograph-key-custody/backups/` | private, separately retained; test alongside the master-key recovery copy |
| optional billing token | `/secure/zogograph-key-custody/billing.token` | separate `0600` file; its authority is subscription state only |

The service refuses a relative, symlinked, or web-served SQLite database path.
Private filesystem permissions reduce accidental disclosure, but they are not
live at-rest encryption. Use VPS/volume encryption when offline disk access is
in scope.

## 2. Create configuration and the master key

Copy `config.sample.php` to the unserved `config.php`, then create the first
master key:

```bash
php scripts/master-key.php /secure/zogograph-key-custody/master.key
```

Set the resulting absolute path as `master_key_file` in `config.php`. Keep one
offline recovery copy of this key, protected separately from the SQLite backup.
Losing both makes service-held wrapping keys unrecoverable; putting both in one
ordinary backup weakens the intended separation.

Set `require_https` to `true` for a public deployment. Configure a private
SQLite path; the sample `billing_portal_url` and `billing_service_token_file`
may remain blank for a no-cost, self-operated service.

## 3. Route only the versioned API through HTTPS

Add [`deploy/nginx.conf.example`](deploy/nginx.conf.example) to the HTTPS
server block for the provisional
`https://services.zogograph.com/key-custody/` path, or to a future
dedicated custody hostname. It forwards the `Authorization` header, marks PHP
requests as HTTPS, caps bodies at 2 MiB, and denies direct access to code,
configuration, data, scripts, and tests.

The route is deployment content. A future domain change must preserve the
`/v1` API and use the client’s endpoint-identity migration process; it must not
change custody key IDs, artifact IDs, or enrollment identities.

Verify the public, credential-free health endpoint before provisioning:

```bash
php scripts/manage-remote.php \
  --base-url https://services.zogograph.com/key-custody \
  health
```

It returns only service identity and API-version information. It does not
reveal tenants, plans, envelopes, tokens, or keys.

## 4. Provision one tenant and protect the one-time output

Run provisioning from a private terminal:

```bash
php scripts/provision-tenant.php "Example customer"
```

It prints three different one-time values. Store each in its own appropriate
private location before closing the terminal:

| value | purpose | never give it to |
| --- | --- | --- |
| management token | customer account, key policy, enrollment, audit, and subscription requests | Zogograph collector packages or billing control plane |
| enrolled-client token and Ed25519 signing key | one named Zogograph client deployment | browser settings, normal config, management console, or another client |
| service master key | service-side custody wrapping-key protection | customers, client packages, marketplace, billing, or replica services |

For a Zogograph client, first save the service URL and tenant ID, run its
credential-free endpoint test, then create two encrypted package-secret
references for the enrolled-client pair. The client package accepts only those
opaque references; it does not accept raw credentials in ordinary settings.

## 5. Manage keys and subscriptions safely

Put a management token in an absolute owner-only file, for example
`/secure/zogograph-key-custody/management.token`, then use the management CLI:

```bash
php scripts/manage-remote.php \
  --base-url https://services.zogograph.com/key-custody \
  --token-file /secure/zogograph-key-custody/management.token \
  create-key primary 300
```

The management CLI can list/create/manage keys, create or revoke one
enrolled-client credential per deployment, view audit metadata, and request a
plan change or cancellation. It follows no redirects, verifies TLS, and
accepts an optional SPKI pin for deployments that have a tested certificate-key
rotation procedure.

Subscription requests are safe to use without a payment provider: they remain
auditable pending requests. Add the optional billing control-plane token only
when a separate commercial backend is ready to apply entitlement changes. That
token can change plan/quota/renewal state but cannot list, create, rotate, or
unwrap custody keys.

## 6. Back up and rehearse recovery

Create a consistent metadata backup regularly:

```bash
php scripts/backup-metadata.php \
  /secure/zogograph-key-custody/backups/metadata-$(date +%F).db
```

Record the printed SHA-256 and test restoration using a non-production copy of
both the SQLite metadata and the corresponding master key. Do not test by
overwriting the live service. Before retiring or deleting customer envelopes,
require a verified alternate recovery path; the API and CLI both require an
explicit recovery confirmation for envelope deletion.

For service-master-key rotation, stop API workers, follow the staged procedure
in [README.md](README.md#service-master-key-rotation), update configuration
only after rewrapping succeeds, then prove recovery before retiring the old
master key or backup.

## What this service does not do

- It does not receive customer metric databases, configuration databases, or
  ciphertext replicas.
- It does not serve as a payment processor or store card/bank details.
- It does not make customer local encryption dependent on a commercial account.
- It does not magically revoke a data key already returned to a compromised
  running client; keep the default client cache at zero and maintain normal
  host hardening.

See [README.md](README.md) and [openapi.json](openapi.json) for the full
protocol and operational reference.
