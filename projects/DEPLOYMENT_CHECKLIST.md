# Recommended deployment checklist

This is the initial deployment direction for Zogograph. It is intentionally
conservative: the full customer client works without a Zogograph-operated
service, encryption is opt-in, and each public or commercial role has an
independent security boundary.

Read [DIRECTION.md](DIRECTION.md) first for the product decisions and
rebrand-safe endpoint rules. This checklist turns those decisions into an
operator handoff; it is not a claim that every optional service is ready to
offer publicly.

## Deployments to create

Deploy each row with its own operating-system account where possible, private
configuration file, database, artifact directory, logs, backups, deployment
credential, and release pipeline. Do not use a reverse-proxy path as a reason
to share secrets or writable directories.

| project | provisional route | first responsibility | database and secrets |
| --- | --- | --- | --- |
| `site/` | `https://zogograph.com/` | product pages, documentation, account entry, subscription/upgrade links | separate site administration database; payment-provider references only, never raw payment data or customer recovery keys |
| `marketplace/` | `https://market.zogograph.com/` | reviewed package catalogue, publisher submissions, purchases | separate marketplace database; package-review and publisher credentials only |
| `updates/` | `https://updates.zogograph.com/` | signed manual downloads and version-check API | release metadata database and offline-protected signing key; no billing or custody data |
| `services/replica-relay/` | `https://services.zogograph.com/replica-relay/` | optional encrypted-artifact relay | separate tenant metadata database and private ciphertext-only artifact directory; no unwrap authority |
| `services/key-custody/` | `https://services.zogograph.com/key-custody/` | optional wrapped-key envelope custody | separate tenant database and private master-key directory; no customer databases, replica artifacts, or payment data |
| `services/ai-proxy/` | `https://services.zogograph.com/ai-proxy/` | future opt-in model-provider proxy | separate policy/audit database and provider-secret store; never customer encryption keys |
| `client/` | `https://demo.zogograph.com/` | complete collector, dashboard, setup, and local protection | customer-owned setup/account/host/assistant stores and local key material as selected by its administrator |
| `client-remote/` | `https://demo-remote.zogograph.com/` | future minimal collector, replica, or custodian role | only the stores and credentials required by its declared role; no full dashboard by default |

The listed URL paths are deployment settings for the current VPS. They can
move to separate domains later. Clients must retain a configured service base
URL and pinned enrollment identity; package IDs, artifact IDs, signing-key IDs,
and protocol versions must not contain the provisional hostname.

## Customer protection defaults

Start the full client with no active encryption until an administrator makes an
informed choice on the **Protection** page. The recommended first activation
is:

1. Protect the bootstrap configuration, `setup.db`, and the account database
   (`stats.db`). Offer `host.db` and `ai.db` when present, but require explicit
   confirmation because they can increase backup size and sensitivity.
2. Use verified local AES-256-GCM sealed copies on a daily schedule, retaining
   30 days. Store the local key outside the document root with an independently
   protected recovery copy.
3. Require a recovery drill before reporting the policy ready. Keep the newest
   verified copy for every selected store regardless of retention settings.
4. Explain that sealed copies protect backup media and copied files, not a
   running SQLite database. For live at-rest protection, offer an
   operator-managed encrypted volume or the SQLCipher package only when its
   platform checks pass.

Remote replicas, hosted custody, external KMS/HSM, SQLCipher, MySQL/MariaDB,
and Redis stay hidden until the administrator installs a compatible package.
Redis is a future cache/queue option, never the normal durable metric database.

## Where settings belong

Customer-facing choices must be stored in authenticated, database-backed
settings and exposed by the website/API. They must not be release constants.

| setting class | owner and location | bootstrap exception |
| --- | --- | --- |
| selected stores, schedule, retention, recovery-drill interval | client encryption settings in `setup.db` | none |
| package provider URL, replication policy, optional storage backend | that package's schema-validated settings and private package database | none |
| provider enrollment credentials and keys | encrypted package secret references or a deliberately selected custody provider | never place in normal configuration, URLs, browser storage, or logs |
| public labels, domains, support links, prices, plans, and supported-package matrix | site/marketplace administration databases | no client protocol or artifact changes required |
| hosted-service capacity and entitlement | commercial billing control plane; services consume a narrow entitlement | billing credentials never grant client-key unwraps |

`lib/config.php` remains a small private bootstrap file only: paths and the
minimum required to open the client settings. It must be sealed as a protected
artifact and restored only through the guarded recovery procedure in
[`client/SETUP.md`](client/SETUP.md). Do not grow it into a
general secret store.

## Required launch gates

- TLS, restrictive filesystem permissions, non-web-readable data/key paths,
  separate backups, and a documented incident contact exist for every public
  deployment.
- A customer client can be installed and protected locally without a commercial
  account, hosted key service, marketplace, or remote relay.
- Marketplace packages are signed, reviewed, capability-scoped, and revocable.
  Do not execute package PHP until the isolated runtime and permission boundary
  are complete.
- The ordinary package diagnostic launcher is secret-free and network-free. A
  custody enrollment-readiness check, if deployed, uses a separately reviewed
  launcher whose egress policy permits only the normalized configured custody
  origin. Require fresh endpoint-identity evidence, explicit `key_provider`
  and `network_client` approval, and encrypted package-secret references before
  it receives an enrolled-client pair. This check may read subscription
  readiness only; it cannot store an envelope, unwrap a key, or access an
  artifact/database path.
- The update service publishes a signed manifest and validates release-file
  hashes before a client accepts an update.
- The replica relay proves it stores ciphertext only. The custody service proves
  it cannot receive customer metric databases or payment-card data. These two
  roles never share a database, key directory, or service credential.
- Custody SQLite metadata has a tested backup/restore procedure, while its
  private service master key has a separately protected offline recovery copy.
  Do not place both in the same ordinary backup archive. Replica-relay backups
  include its metadata and ciphertext artifacts together, but remain
  ciphertext-only and never receive a custody key.
- Before offering live SQLite encryption on an image, SQLCipher must pass WAL,
  migration, backup, restore, crash-recovery, and performance tests on that
  exact PHP/PDO build.
- Before enabling automatic artifact deletion, verify recovery exercises,
  alerting, retention rules, and replica deletion acknowledgements.
- Before enabling remote envelope upload or recovery, complete the separate
  artifact-contract release gate: preserve the verified local envelope, record
  digest-only remote evidence, test storage and isolated unwrap recovery, and
  document outage, rotation, deletion, and customer recovery behavior.

## Decisions to record before public launch

Keep these in administration records so they can change without editing source:

- final product name, domains, endpoint migration process, and support URLs;
- supported operating-system/PHP/SQLCipher image matrix and review date;
- retention tiers, legal hold, recovery exercise interval, and replica count
  per customer plan;
- hosted custody availability target, authorization lifetime, rotation period,
  recovery escalation, incident response, privacy, and deletion terms;
- marketplace publisher verification, review policy, refunds, tax, revenue
  share, package vulnerability response, and any payment-provider integration.

Detailed encryption rationale and release gates remain in
[`client/ENCRYPTION_REVIEW.md`](client/ENCRYPTION_REVIEW.md)
and
[`client/ENCRYPTION_AND_FLEET_PLAN.md`](client/ENCRYPTION_AND_FLEET_PLAN.md).
