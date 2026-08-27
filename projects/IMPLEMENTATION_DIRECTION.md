# HostScope implementation direction

This is the practical handoff for the current HostScope direction. It is the
short source of truth for what a customer can use now, which choices remain
optional, and where an operator or product owner changes those choices later.
It uses the current HostScope name and provisional VPS URL as replaceable
deployment content, not protocol values.

Read [DIRECTION.md](DIRECTION.md) for service boundaries and
[DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) for the deployment gates.
The detailed client design is in
[client-hostscope/ENCRYPTION_AND_FLEET_PLAN.md](client-hostscope/ENCRYPTION_AND_FLEET_PLAN.md).

## Recommended customer starting point

The full `client-hostscope/` deployment is self-contained. A customer should
be able to install it, collect locally, and use local protection without an
account on any HostScope commercial service.

1. Start with SQLite and HTTPS.
2. Protect the bootstrap configuration, `setup.db`, and `stats.db` using
   daily verified sealed copies retained for 30 days.
3. Keep the local encryption key in an owner-only file outside the web root
   and keep one separately protected offline recovery copy.
4. Run a recovery drill before calling the protection policy ready.
5. Add `host.db` and `ai.db` only when those stores exist and their contents
   warrant the extra protected-copy size and recovery responsibility.

This protects copied databases, snapshots, and backup media. It does not make
a live SQLite database encrypted while HostScope is running. For that distinct
need, the operator may rely on host/volume encryption or install the optional
SQLCipher package on a specifically tested platform.

## Customer choices without configuration overload

The website presents a single **Protection** flow. It asks for scope, live
store protection, key custody, and recovery policy in that order. It displays
only capabilities supplied by installed compatible packages.

| customer choice | initial presentation | available today | later optional package direction |
| --- | --- | --- | --- |
| stores to protect | configuration, setup, and account preselected; host and assistant offered when present | yes | package-owned stores may add their own explicit selection |
| protection of copies | local AES-256-GCM sealed copies | yes | ciphertext replica relay |
| protection of a live SQLite store | host/VM/managed-volume statement | yes, as an operator-recorded policy | SQLCipher after an exact platform preflight and release approval |
| recovery-key custody | local private key file | yes | manual unlock, self-managed custody, external KMS/HSM, or hosted custody |
| storage alternatives | keep SQLite as the normal choice | no backend migration implied | MySQL/MariaDB for a demonstrated storage need; Redis only as a bounded cache or queue |

Installing a package exposes its reviewed metadata and settings; it does not
enable encryption, send data, or grant it secrets. The current isolated worker
can run only signed, deployment-attested, no-secret diagnostics. A successful
diagnostic is prerequisite evidence, never proof that a provider is active or
that a live store is encrypted.

## Configuration authority

All normal choices are authenticated, database-backed settings. They are not
release constants, source edits, browser storage, URLs, or command arguments.

| decision | authoritative configuration location | owner | special rule |
| --- | --- | --- | --- |
| client store scope, copy schedule, retention, drills | client `setup.db`, through Setup/Protection UI and API | customer administrator | never prune the newest verified artifact |
| package endpoint, policy, and private state | signed package schema plus package-private database | customer administrator | a package cannot read another package's state |
| enrolled-client bearer/signing material | encrypted package secret references | customer administrator | plaintext is never returned to the browser or normal settings API |
| public name, domain, pricing, support links, plan labels | commercial-site and marketplace administration databases | commercial operator | changing these does not change package, artifact, or key IDs |
| service entitlement and capacity | commercial billing control plane | commercial operator | a billing token cannot manage or unwrap customer keys |
| service master keys and service backups | private service deployment and offline recovery procedure | service operator | never share them with the commercial site or marketplace |

`lib/config.php` is the limited exception: it contains only the private paths
needed to locate and open client settings, never encryption material or
long-lived provider credentials. Treat it as a protected configuration artifact
and include it in recovery testing.

## Commercial topology

The provisional VPS can route separately deployed projects at these paths:

| project | provisional path | purpose |
| --- | --- | --- |
| `commercial-site/` | `/` | product, documentation, account, subscription, and upgrade links |
| `commercial-marketplace/` | `/marketplace/` | package review, catalogue, publisher workflow, and purchases |
| `commercial-updates/` | `/updates/` | signed manual download and version-check service |
| `commercial-services/key-custody/` | `/services/key-custody/` | optional tenant-isolated wrapped-key custody |
| `commercial-services/replica-relay/` | `/services/replica-relay/` | optional ciphertext-only artifact relay |
| `commercial-services/ai-proxy/` | `/services/ai-proxy/` | future opt-in model proxy |

Each deployment has an independent OS account where practical, database,
configuration, private storage, backup set, logs, deploy credential, and
release pipeline. A reverse-proxy path is routing, not an authorization or
secret-sharing boundary. A future domain migration changes the configured base
URL and enrollment migration record while preserving versioned API semantics
and stable opaque identities.

## Decisions that need a later owner review

- Confirm the published SQLCipher PHP/PDO/OS image matrix before allowing live
  SQLite encryption; test WAL, migration, backup, restore, crash recovery, and
  performance for every supported image.
- Define recovery ownership, availability objectives, rotation, deletion,
  incident response, and customer terms before offering hosted custody.
- Define replica count, legal hold, retention tiers, and deletion evidence by
  customer plan before automatic remote replication or deletion.
- Define marketplace publisher verification, security review, vulnerability
  response, pricing, tax, refunds, and payout rules before commercial launch.
- Approve the final product name, domains, and endpoint-identity migration
  procedure before a rebrand; do not encode them in ciphertext or key formats.

Until those records are approved, the safe default is local sealed copies,
local recovery custody, and no commercial-service dependency.
