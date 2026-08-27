# Encryption decisions to review later

This is the operator/product-owner checklist for encryption. It separates
decisions that HostScope can safely default from decisions that must remain
changeable in the website and database-backed settings.

## Current safe defaults

| decision | initial value | change it in | why |
| --- | --- | --- | --- |
| protection | off | Setup → Encryption | Encryption must be explicit. |
| protected stores | configuration, setup, account | Setup → Encryption | These normally hold the most sensitive credentials and history. |
| archive method | local sealed copies | Encryption package settings | Works on shared hosting, VPSs, containers, and dedicated hosts. |
| local custody | private file outside web root | Encryption package settings | Simple, portable, and does not require a third party. |
| copy policy | daily, retain 30 days | Encryption package settings | A useful starting point; it is not an automatic deletion promise until retention automation is installed. |
| live-store protection | operator-managed volume where available | Setup → Encryption | Protects SQLite, WAL, logs, and config together without changing application queries. |
| remote replication | off | Fleet Encryption → Replicas | A replica should never be created without a deliberately enrolled peer. |
| commercial services | off | Marketplace → installed package | A customer deployment must never depend on HostScope-operated services. |

These are seeded settings, not hard-coded policy. The only bootstrap values
that remain in `lib/config.php` are the local setup-store path and enough
information to open the settings service. Once the settings migration is
complete, package policy, provider configuration, replicas, retention, and
service endpoints belong in the setup database and are changed through the
authenticated website/API.

## Decisions requiring later review

| topic | review when | configuration owner | concern |
| --- | --- | --- | --- |
| Default protected stores | before a public release | product settings | Selecting every optional store can surprise a small deployment; selecting too little leaves secrets exposed. |
| Retention and deletion | before enabling automatic pruning | encryption package | Delete only after a recovery test and required replica acknowledgements; never remove the last verified copy. |
| Key recovery | before fleet custody is enabled | custodian package | Decide who may recover, how many custodians are required, and what happens when one is lost. Do not copy one complete key to every peer. |
| Key expiry | before an expiring service plan | custody provider | Expiry only prevents a new unwrap. It cannot revoke a copied plaintext database, sealed copy plus key, or a key already cached by a compromised process. |
| SQLCipher support | per supported PHP/OS build | SQLCipher package | The package must prove WAL, backup, migration, crash recovery, and performance behavior. It must not fall back to plaintext SQLite. |
| MySQL/MariaDB backend | when multi-node query/write needs outweigh SQLite simplicity | storage package | It is a storage backend, not an encryption feature. It adds credentials, migrations, backups, and server operations. |
| Redis | when an explicit cache/queue use case exists | cache package | Redis is not durable encrypted metric storage by default; an exposed Redis service is a serious data-disclosure risk. |
| Commercial key hosting | before pricing or customer onboarding | commercial-services/key-custody package | Keep key custody and ciphertext replication as separate tenant-isolated roles; establish recovery, deletion, audit, and incident terms. |
| Product/domain naming | before public launch or rebrand | commercial-site deployment settings | Domains and labels must not change encrypted artifact identifiers, peer identities, signing keys, or stored customer data. |

## Service and API design decisions

Use one versioned service namespace, independent of a provisional domain:

```
{configured service base}/v1/{service}/{tenant-or-fleet}/{resource}
```

The first service names should be `key-custody`, `replica-relay`, and
`entitlements`. A client stores a configured base URL and pinned enrollment
identity, never a literal `hostscope.clewismoten.com` URL. On the provisional
VPS, those services can initially live beneath separate paths, for example
`/services/key-custody/`; moving each to its own domain later must preserve the
same versioned API and peer identity.

Fleet API requests need mutual TLS or an equivalent enrolled-client credential,
request timestamp, nonce, scoped role, payload hash, and replay protection.
Transport credentials, package-download credentials, and data-encryption keys
must be distinct. A replica endpoint accepts ciphertext and metadata only. A
custody endpoint receives a wrapped data key and returns a short-lived unwrap
authorization only after policy evaluation; it does not accept metric data.

## Package choices without overload

The website should present a single **Protection** page. It first asks what the
administrator wants to protect and then shows only installed compatible
packages. Advanced provider, SQLCipher, storage, and fleet controls stay hidden
until their package is installed and enabled. Each package supplies a short
capability summary, prerequisites, impact, recovery consequence, and a
connection test; it may not silently change another package's settings.

Recommended package families:

- `sealed-copies` — local encrypted artifacts and recovery verification.
- `sqlcipher-encrypted-sqlite` — live encrypted SQLite where a compatible PDO
  driver is installed.
- `fleet-custody` and `fleet-replica` — self-managed peer custody and
  ciphertext replication.
- `kms-*` — optional third-party or HostScope key-provider adapters.
- `storage-mysql` / `storage-mariadb` — optional transactional storage
  backends.
- `cache-redis` — optional bounded cache/queue integration, never a default
  database replacement.

Every executable package gets a private data directory or database registered
with the package runtime. It cannot read another package's data, the core
configuration, or key material unless the administrator grants that specific
capability. Core retains the enrollment, authorization, audit, package
signature, settings, and secret-reference interfaces; packages retain their
implementation and private schema migrations.
