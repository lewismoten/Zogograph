# Encryption and fleet custody plan

## Status

This is a product and implementation plan. HostScope does **not** currently
encrypt SQLite files or operate a key service. Encryption remains off until an
administrator explicitly enables it in setup. No third-party key service is a
default or prerequisite.

## Goal and threat boundary

The baseline promise is narrow and testable: a copied encrypted HostScope
artifact cannot be read without a currently authorized key. It protects lost
disks, copied database files, snapshots, and backup media. It does not protect
an attacker who controls a running HostScope process after it has obtained an
active key; that is a host-compromise problem and needs normal account, process,
and operating-system hardening as well.

The implementation must protect the database, its SQLite `-wal` and `-shm`
files, migration snapshots, exported copies, and encrypted-replica manifests
as one unit. Encrypting only a `*.db` file while leaving its WAL beside it would
be a data disclosure bug.

## Data classes

| store | content | default encryption choice when enabled |
| --- | --- | --- |
| `setup.db` | complete configuration, authentication material, integration credentials, and signing keys | selected by default |
| `stats.db` | paths, measurements, alert/audit records, and account operational history | selected by default |
| `host.db` | host-wide users, processes, paths, and system evidence | selected by default when host collection is enabled |
| `ai.db` | assistant transcripts, tool evidence, memory, and generated assets | selected by default when the assistant is enabled |
| `lib/config.php` | bootstrap paths; legacy installations can contain more | protected as a sealed configuration artifact, not a SQLite target |
| logs and exports | may contain paths, errors, or query data | opt-in encrypted archive/export; do not silently claim they are database encryption |

The setup page must make these choices explicit. It must never silently enable
encryption, upload a key, or contact another HostScope server.

## Architecture decisions

### 1. Core owns the safety contracts; packages provide encryption methods

HostScope core needs versioned artifact metadata, key identifiers,
algorithm-agility rules, authorization, auditing, recovery evidence, package
signatures, a settings/secrets interface, and a key-provider interface. These
are compatibility and data-recovery contracts, so they cannot be delegated to
a marketplace add-on. The cipher implementation, encrypted storage engine,
custody adapter, and replica transport are packages loaded only after an
administrator installs and enables them.

Each sealed artifact uses a fresh random data-encryption key (DEK). The DEK
encrypts the artifact with an authenticated cipher. The DEK is then wrapped by
one or more key-encryption keys (KEKs) held by the chosen provider. A manifest
records the artifact version, protected database type, key identifier, wrapped
DEKs, cipher parameters, authenticated hash, coverage range, and signatures.
Secrets and keys are never placed in the account database that they unlock.

Use PHP's maintained cryptographic extension when available, with a single
well-tested core implementation and deterministic test vectors. Do not invent
an encryption algorithm, use unauthenticated encryption, derive a key directly
from a short password, or put a key in `.htaccess`.

### 2. Full live-SQLite encryption is a storage-engine package

The current `pdo_sqlite` driver opens normal SQLite files. It cannot make an
encrypted SQLite database behave as a live WAL database. Therefore:

- Do not write normal data to an unencrypted spool and call the later copy
  “database encryption”; that spool remains an at-rest disclosure.
- Do not offer table, row, or whole-file checkboxes until their precise storage
  guarantees are implemented and tested.
- A true encrypted live `stats.db`/`host.db`/`ai.db` needs the
  `sqlcipher-encrypted-sqlite` package plus a supported encrypted SQLite engine
  distribution (for example a SQLCipher-compatible PDO build), or volume
  encryption supplied by the operator.
- Core reports an installed package's verified capability, not merely its
  setting: `none`, `sealed-copies`, `operator-managed-volume`, or
  `encrypted-sqlite`. An operator-managed-volume selection is a statement of
  local policy, not proof that the hosting platform encrypts a disk.

`sealed-copies` is the first portable core mode: it makes consistent SQLite
snapshots, seals them, verifies them, records the result, and only then offers
the copies for local or fleet retention. The live database remains plainly
labelled as unencrypted. This improves backup and remote-copy protection without
misrepresenting the live database's state.

### 3. Field encryption is limited to bounded secret records

Field encryption may be added for settings and selected credentials after the
sealed-artifact primitive exists. It is appropriate for values that are read by
key, rarely queried, and never needed for SQLite indexes, sort order, or range
queries. It is not an appropriate first implementation for metrics, paths,
vitals, scans, or fleet queries: encrypting those columns would break indexes,
WAL locality, aggregation, and dashboard reads unless the application is
redesigned around encrypted blobs.

When field encryption is offered, each field declaration must state its search,
sort, index, replication, and migration behavior. It is an advanced option, not
a generic “encrypt this table” control.

## Key custody modes

The setup interface exposes only modes that the current server can validate.
Every mode displays its recovery consequence before activation.

| mode | key location | suitable use | limitation |
| --- | --- | --- | --- |
| Manual unlock | operator supplies an unlock secret after restart | highest separation for an unattended key | collection and dashboard cannot start until unlocked |
| Local protected file | outside document root, owner-readable only | simple single-server deployments | a host/account compromise can read the key |
| Host-managed encrypted volume | operating system or hosting platform | transparent protection for live SQLite, WAL, logs, and configuration | key custody is outside HostScope; availability follows the host |
| HostScope fleet custodian | one or more explicitly enrolled HostScope peers hold wrapped-DEK material | self-managed remote custody and failover | requires mutually authenticated key protocol, cache policy, and recovery quorum |
| External provider | optional marketplace/plugin integration | organizations already using a compatible KMS/HSM | never a default and never required for core encryption |

`.htaccess` prevents HTTP delivery; it is not encryption and does not protect a
local account, backup, shell, or filesystem reader. A local key file belongs
outside the document root. A configured shared path is allowed only after
canonical-path, ownership, mode, and symlink-policy checks; it must never be a
blind `data/` symlink. A key stored beside the encrypted object is useful only
as accidental-exposure protection, not as separated custody.

## Key expiry, caching, rotation, and recovery

Key expiry cannot make an already copied plaintext database unreadable, and it
cannot revoke a copied key. It can limit future access to sealed artifacts:

1. A provider issues a short-lived authorization to unwrap a named DEK.
2. HostScope caches the unwrapped DEK only in process memory, with a bounded
   TTL. The setup choice states whether collectors may keep running after that
   TTL.
3. When a key version expires, the provider refuses new unwraps. Removing all
   wrapping material for a DEK is crypto-erasure for artifacts that have no
   cached/plaintext copy.
4. Rotation first adds a new wrapped-DEK envelope and verifies recovery with the
   new key. It never destroys the old envelope until the required recovery
   period and all replica acknowledgements are recorded.
5. Re-encrypting data under a new DEK creates and verifies a new sealed copy;
   it is not the same operation as rewrapping the existing DEK.

The application must never persist an unwrapped DEK in `setup.db`, a PHP
session, browser storage, an audit row, a URL, cron arguments, or a log. Loss
of the only key is permanent loss of the encrypted data, so setup requires a
verified recovery path before encryption can be marked ready.

## Fleet roles and replication

A HostScope installation may separately act as:

- **owner** — produces encrypted artifacts for its own data;
- **replica** — stores ciphertext and availability metadata only; and
- **custodian** — authorizes DEK unwraps or stores a wrapped recovery envelope.

No role implicitly grants another. A replica must not receive a usable DEK;
otherwise stealing one replica defeats the purpose. A custodian need not retain
metrics. One peer can fill multiple roles only after the operator acknowledges
the reduced separation.

The existing signed vital transport is a useful foundation for peer identity,
but it is not sufficient for database replication or key custody. New protocol
versions need enrollment, scoped roles, transport keys distinct from data keys,
payload signatures, replay protection, content hashes, resumable chunk uploads,
and explicit retention/deletion acknowledgements.

For every artifact, the owner maintains a private replica ledger containing:

- owner and artifact identifiers, database kind, generation, and coverage;
- ciphertext hash, byte count, DEK/key version identifiers, and creation time;
- requested replication count and eligible peers;
- each peer's upload, verification, retention, and deletion acknowledgement;
- last usable coverage date for each owner/database/peer; and
- recovery-envelope and key-rotation acknowledgement state.

The fleet dashboard may show “which peers have a verified copy” and the latest
coverage time without downloading the artifact. It must show a replica as
healthy only after the remote peer verifies the ciphertext hash. The requested
replication count is a per-database policy, bounded by the enrolled peers and
edition capacity; zero means local-only.

Initial product policy: replication is off by default. Recommend two encrypted
remote replicas only when at least three independently administered peers are
available; the owner does not count as a remote replica. Automatic retention
must preserve at least one verified recoverable copy and every copy required by
the configured recovery and replica policy.

Multi-custodian threshold recovery (for example, two of three custodians) is a
separate cryptographic feature. Do not approximate it by copying the complete
key to three servers. It needs a reviewed threshold/envelope design, a defined
loss/rotation procedure, and interoperability tests before it is exposed.

## Setup flow

1. **Inventory** — discover the account, setup, host, and assistant stores;
   show their current protection state and companions/backups.
2. **Choose scope** — select whole stores, sealed exports, and optionally
   declared secret fields. Default remains `none`.
3. **Choose custody** — configure and test a local, manual, fleet, or optional
   provider-backed key method. No network call occurs unless fleet/provider is
   chosen.
4. **Recovery proof** — create a small test artifact, decrypt it through every
   required recovery path, and record only success/failure evidence.
5. **Prepare** — make consistent copies, seal them to a new destination, verify
   hash and decryption, and keep original data untouched.
6. **Activate** — enable the selected protection policy only after every chosen
   store has a verified initial artifact and, when requested, enough verified
   replicas. Activation is audited and reversible only while a recovery path
   exists.
7. **Operate** — periodically seal, verify, replicate, monitor expiry, and
   rotate. Failures never silently delete the last verified copy.

## Core versus marketplace boundary

Core provides the safety format, operator interface, artifact ledger, package
authorization, recovery verification, and stable provider/storage APIs. It
must work with no third party. Settings live in the setup database behind the
authenticated website/API after bootstrap; packages may have a private database
and migrations but do not get broad access to core or other-package data.

Marketplace packages provide encryption methods: sealed copies, SQLCipher,
self-managed fleet custody, ciphertext replicas, cloud KMS/HSM adapters,
commercial HostScope key hosting, storage backends, and optional caches. The
current marketplace intentionally accepts data-only manifests, so it must be
upgraded to a signed isolated runtime with capability grants, secret references,
per-package storage, revocation, compatibility tests, and audit logging before
any of these packages can execute. Commercial key hosting remains optional and
must not become a hidden dependency of local encryption.

## Delivery order and evidence

1. Add the signed isolated marketplace runtime, package settings/secrets store,
   capability grants, private package migrations, and audit/revocation model.
2. Move the current sealed-copy implementation behind a built-in disabled
   `sealed-copies` package; add setup inventory, recovery proof, restore,
   retention, status, and alerting.
3. Add encryption state and artifact-ledger migrations that support packages
   without exposing plaintext keys or package-private settings.
4. Implement the `fleet-replica` package for scoped ciphertext storage and
   verified coverage ledger entries; test outage, retry, replay, tampering,
   partial upload, and replica deletion.
5. Implement `fleet-custody` with short-lived unwrap authorization and bounded
   in-memory cache; test expiry, failover, revocation, rotation, and recovery.
6. Implement the scaffolded `sqlcipher-encrypted-sqlite` package. Do not
   declare live database encryption until WAL, backup, migration, crash
   recovery, and performance guards pass.
7. Add optional provider and storage packages only after the stable interfaces
   are tested. See `ENCRYPTION_REVIEW.md` for the defaults and later decisions.

Every stage needs tests proving that a ciphertext-only copy cannot be read with
no key, that tampering is detected, that keys never appear in logs/exported
diagnostics, that an interrupted conversion retains the original readable data,
and that a key-loss/recovery scenario behaves exactly as the UI states.
