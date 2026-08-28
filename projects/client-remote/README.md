# Zogograph remote client

This future minimal Zogograph deployment is for a machine that supplies collection,
ciphertext replication, or key-custody duties without exposing the full
dashboard and administration surface.

It should contain only the collectors, enrolled-peer protocol, encrypted
artifact handling, health endpoint, and narrowly scoped configuration required
for its selected role. It must not inherit browser administration, destructive
actions, marketplace management, or AI features by default.

The remote client is a separate package boundary, not a configuration switch in
the full client. Shared wire formats and enrollment rules belong in a versioned
protocol package once the deployable split begins.

## Local development

Run `npm ci`, `npm test`, and `npm run build`, then `npm start` to serve the
artifact at `http://127.0.0.1:4174`. Its configured production base URL is
`https://demo-remote.zogograph.com/`; remote endpoints remain environment
configuration, never hard-coded demonstration credentials.

## Encryption baseline

Start every remote node with one explicit role and no recovery material beyond
what that role requires:

| role | may retain | must never receive by default |
| --- | --- | --- |
| collector | its local collection configuration and locally selected protected stores | commercial billing credentials, another node's private key, or a full dashboard account database |
| ciphertext replica | authenticated sealed artifacts, their hashes, and availability metadata | plaintext databases, artifact data keys, custody private keys, or unwrap authority |
| self-managed custodian | its own narrowly scoped wrapped-key or authorization material and audit metadata | customer metrics, replica ciphertext unless the operator explicitly accepts reduced separation, or billing credentials |

The recommended first remote role is **ciphertext replica only**. Its storage
directory and SQLite metadata database must be private, absolute,
non-symlinked paths outside the web root; it validates the ciphertext hash but
does not decrypt the artifact. Key custody remains a separate enrolled role so
one stolen remote node does not contain both a recoverable artifact and the
means to open it.

When a remote node eventually offers local encrypted copies, use the same
simple customer default as the full client: selected stores, a private local
key outside the web root, daily verified sealed copies, 30-day retention, and
a non-destructive recovery drill before reporting protection ready. Fleet,
commercial custody, external KMS, and live SQLCipher storage stay opt-in
provider choices rather than implicit dependencies.
