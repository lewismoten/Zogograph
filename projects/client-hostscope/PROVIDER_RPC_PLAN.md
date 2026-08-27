# Isolated provider RPC plan

This is the next implementation boundary after the sandboxed, no-secret
`capability` probe. It is intentionally separate from package installation and
capability grants. A signed package is not allowed to choose an RPC operation
at runtime; the manifest and core both have to recognize it.

## Version 1 operation set

| Operation | Signed capability | Data the worker may receive | Data it must not receive |
| --- | --- | --- | --- |
| `capability` | none | package id and an invocation id | settings, secrets, database paths, core handles |
| `seal_envelope` | `key_provider` | fresh per-artifact data key and provider public information | local KEK, management/billing credential, other package data |
| `store_ciphertext` | `ciphertext_replica` + `network_client` | already-sealed artifact bytes/digest and scoped client credential | plaintext store, artifact data key, recovery key |
| `sqlcipher_preflight` | `live_database_encryption` + `storage_backend` | a disposable, core-created test database path in the package mount | selected live database, setup database, production key |

The first operation remains `capability` only. Add each later operation only
after its request and response schema, audit event, recovery behavior, and
sandbox mount are implemented and tested. This keeps clients from granting a
broad “provider” permission whose effects are unclear.

## Core-owned invocation rules

Core must create a one-time invocation id, write an audit intent, verify the
installed manifest/fingerprint/revocation/grants immediately before launch,
and impose a small request, response, CPU, memory, and wall-clock budget. It
must reject a response unless the invocation id, package id, operation, and
schema all match the request. The worker never chooses a core file path or a
secret reference to resolve.

Secret references stay in core. A request schema can name a specific signed
settings field as secret-bearing; only then may core resolve that exact
package-scoped `secret_ref:` into the worker's short-lived standard input. The
secret must be absent from audit entries, process arguments, environment,
worker output, package private SQLite, and error text.

## Deployment decision

Use a one-shot container/service process by default. It is simpler for a small
deployment, prevents long-lived secret caches, and makes the mounted package
release and egress policy visible in the deployment definition. A persistent
worker is a later opt-in optimization only after it has per-invocation memory
clearing, authentication, restart, and crash-recovery evidence.

The core process should launch an argument-vector command supplied by a
deployment-owned configuration file, not a browser setting. That command is
security-sensitive host policy, comparable to a service account or container
profile; letting a website form change it would turn a configuration compromise
into arbitrary command execution.

## Provider rollout

1. **SQLCipher preflight:** create and destroy only a disposable database in
   the package mount. Prove driver version, encrypted reopen, WAL behavior,
   backup/restore, and wrong-key refusal before a user can select a live store.
2. **Ciphertext relay:** permit only sealed artifact upload and digest/receipt
   validation. It is safe to introduce before remote key custody because it
   never needs plaintext or a data key.
3. **Remote key custody:** enroll only a client credential, fetch public
   wrapping information, and store a sealed data-key envelope. Keep management
   and billing credentials outside the client package entirely.
4. **Live SQLCipher migration:** require a verified sealed recovery artifact,
   a dry-run database, explicit stopped-writer acknowledgement, successful
   encrypted reopen, and an operator recovery drill before replacing a live
   store.

Each operation stays optional. The supported low-cost default remains local
SQLite with owner-only files and verified sealed copies; no remote service or
container runtime is required for that baseline.
