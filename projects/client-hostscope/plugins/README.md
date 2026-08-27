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
changed manifest—is shown as an integrity failure and blocks a new enablement.
Older installed packages that predate the manifest-fingerprint migration are
also fail-closed; reinstall their reviewed signed release to establish new
evidence. Never treat a manifest comment or a browser status alone as evidence
that altered package code is safe.
