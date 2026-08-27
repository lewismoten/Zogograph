# HostScope executable packages

This directory holds source packages that require the future isolated HostScope
package runtime. It is separate from `data/marketplace/`, whose current JSON
catalog is deliberately data-only.

Every executable package must declare its capabilities, settings schema,
compatibility requirements, private-data/migration namespace, and entry point.
The runtime must verify a package signature and compatibility, obtain explicit
administrator grants, create its private storage, and audit enablement before
loading the entry point. A package must not be loaded directly by PHP include
paths or by copying its files into the web tree.

When declared, the signed `settings_schema` is enforced before settings are
stored. HostScope supports the small JSON Schema subset needed by packages:
object properties/required fields, `additionalProperties`, scalar and array
types, enums, string length/patterns, numeric limits, and array item limits.
Keep package schemas within that subset so an administrator gets a clear
configuration error instead of silently accepting an unsupported rule.

Packages may declare an ordered `data.migrations` list of signed
`migrations/NNN-name.sql` files. Core re-verifies installed hashes before it
applies those files transactionally to that package's private SQLite database
and records each applied migration. The declarative runner rejects attachment,
pragma, vacuum, extension-loading, and transaction-control statements; it is
not a way to run package PHP or reach core/other-package data.

Package settings may refer to credentials only as package-scoped `secret_ref:`
values. Core encrypts the referenced value in `setup.db` using a key file kept
outside the web root; it never returns the plaintext through the settings API.
The same local key as sealed copies is the simple default, while a separate
0600 key file is available for administrators who require separate custody.

The package-runtime dashboard rehashes installed immutable files and compares
the installed manifest with its install-time fingerprint before it reports a
package as usable. A changed, missing, extra, or symlinked package file—or a
changed manifest—is shown as an integrity failure, blocks a new enablement, and
raises the **Installed package integrity needs attention** alert during normal
alert evaluation. The alert verifies hashes and metadata only; it never loads
or executes the altered package.
Older installed packages that predate the manifest-fingerprint migration are
also fail-closed; reinstall their reviewed signed release to establish new
evidence. Never treat a manifest comment or a browser status alone as evidence
that altered package code is safe.

## Bundled design-preview providers

- `sqlcipher-encrypted-sqlite` — live encrypted SQLite only on a verified
  SQLCipher/PDO platform; never falls back to plaintext SQLite.
- `hostscope-key-custody` — optional remote wrapped-key custody using an
  enrolled client credential, separate from management and billing tokens.
- `hostscope-replica-relay` — optional signed transfer of already-sealed
  ciphertext to a separate relay; it never sends a plaintext store or key.

These packages are intentionally unavailable until the isolated runtime can
enforce their capabilities, settings/secret boundaries, and audit trail.
The deployment and JSON-RPC boundary for that runtime is specified in
[`ISOLATED_RUNTIME.md`](../ISOLATED_RUNTIME.md). It deliberately requires a
real OS/container sandbox; PHP-only restrictions are not considered execution
isolation.

The bundled worker currently supports only an isolated, no-secret capability
probe. A deployment may opt in to launching that probe through a package-
specific, manifest-pinned sandbox command; it is diagnostic only and package
grants do not authorize arbitrary provider operations.

The **Package runtime** page performs a core-owned, non-executing platform
check before presenting a package as ready. It checks declared PHP version and
extension requirements, detects a genuine SQLCipher PDO driver where one is
requested, and reports that isolated execution is still unavailable. This is a
setup explanation only: a passing platform check never loads package code,
opens a provider connection, or activates a protection claim.
