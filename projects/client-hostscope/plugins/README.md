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

Package settings may refer to credentials only as package-scoped `secret_ref:`
values. Core encrypts the referenced value in `setup.db` using a key file kept
outside the web root; it never returns the plaintext through the settings API.
The same local key as sealed copies is the simple default, while a separate
0600 key file is available for administrators who require separate custody.
