# HostScope ciphertext replica relay package

This optional package sends only already-sealed artifact ciphertext to the
separate HostScope replica relay. It is disabled by default and is not a
requirement for local sealed copies, local recovery keys, or key custody.

Settings require an HTTPS relay base URL, the opaque tenant identifier, and
two encrypted package `secret_ref:` values: the enrolled-client bearer and its
Base64 Ed25519 private signing key. Never put a custody credential, management
token, billing token, plaintext database, or encryption key in these settings.
The adapter accepts only the generated `tok_…` bearer syntax, so whitespace or
header-like content is rejected before it can enter an HTTP request.

The transport streams one sealed artifact from disk, calculates SHA-256, signs
the method/path/timestamp/nonce/digest, verifies TLS and hostname, and follows
no redirects. It has no upload method for a live SQLite database or a plaintext
export. The relay may receive ciphertext, but never an unwrap authorization.

The package remains a design preview until the isolated runtime can enforce its
capabilities and the core replication ledger can prove upload, remote digest
verification, retry, retention, deletion acknowledgement, and recovery.
