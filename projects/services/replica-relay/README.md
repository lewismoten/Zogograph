# HostScope replica relay

This optional commercial service stores verified **ciphertext only** for an
enrolled customer. It has no custody key, key-encryption key, unwrap operation,
or access to a customer database's plaintext. Keep it independently deployed
from `../key-custody/`; using the same host, credentials, database, or backup
boundary would defeat that separation.

The reference deployment uses private files for ciphertext and SQLite for
tenant metadata, replay nonces, and audit events. That is a low-cost starting
point, not a claim that SQLite is suitable for unlimited artifacts. Set
`maximum_artifact_bytes` to the service-plan and VPS capacity, put both private
paths outside the document root, and use object storage only through a later
adapter that preserves the same tenant isolation and digest verification.

Set `maximum_tenant_bytes` and `maximum_tenant_artifacts` as well. The reference
service enforces both limits in the same immediate SQLite transaction that
records a ciphertext upload, so concurrent clients cannot both spend the last
available storage or artifact unit. The sample's 20 GiB / 2,000-artifact
ceiling is a conservative VPS default; a later billing adapter can apply
tenant-specific limits without changing the ciphertext protocol.

The deployed API enforces that its SQLite directory and ciphertext directory
are absolute, non-symlinked private paths outside its web root. It creates them
with owner-only permissions, including SQLite WAL/SHM companion files; a
relative or web-root path is refused instead of silently storing ciphertext
beside the application. Private permissions prevent accidental local reads but
are not a substitute for encrypted volume storage when the service metadata
also requires live at-rest encryption.

## Quick deployment

Copy `config.sample.php` to `config.php`, choose private absolute paths, deploy
behind HTTPS using [`deploy/nginx.conf.example`](deploy/nginx.conf.example),
and create the first enrollment:

```bash
php scripts/provision-tenant.php "Example customer"
```

Save the printed management token separately from the printed enrolled-client
bearer token and Ed25519 signing private key. The client pair is for exactly
one deployment and signs every upload/download request. The management token
lists metadata and deletes an artifact; it cannot download ciphertext. Neither
credential is a key-custody credential and neither grants any unwrap access.

`GET /v1/health` is the unauthenticated deployment probe. It opens the private
SQLite service store and returns only service liveness/version metadata; it
does not reveal tenants, ciphertext metadata, credentials, or capacity data.

`PUT /v1/artifacts/{artifactId}` accepts raw ciphertext, requires an exact
SHA-256 in `X-HostScope-Ciphertext-SHA256`, verifies it before recording
metadata, streams it to a private temporary file under the configured size
ceiling, and atomically replaces the ciphertext file. A signed client can later
`GET` that artifact; the relay rehashes it before delivery. Management
may list metadata with `GET /v1/artifacts` or delete one artifact. Deletion is
irreversible at this relay. The management request must carry
`{"confirm_recovery":true}` after the operator verifies another recovery
copy; the relay cannot make that recovery decision on the customer's behalf.

The request signature covers method, path, timestamp, nonce, and SHA-256 of the
exact binary body. The relay accepts timestamps within five minutes and stores
nonces for ten minutes. It rejects replay, changed content, cross-tenant paths,
symlinks, and oversized payloads. See `openapi.json` for the versioned contract.

An enrolled deployment can use signed `GET /v1/client/entitlement` to see only
its artifact count, ciphertext-byte usage, configured limits, and whether it
can upload another artifact. This is not a management, download, deletion, or
billing endpoint; it exists so a client can explain a capacity refusal before
transferring ciphertext.

The full client has no executable relay provider yet; this service is therefore
not selectable by an end user until the isolated package runtime and the relay
package's retry/coverage/deletion protocol are complete. It is intentionally
safe to deploy, inspect, and test independently now.
