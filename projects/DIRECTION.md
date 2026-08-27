# HostScope product and deployment direction

This is the decision record for the separated HostScope projects. It gives a
safe launch direction while keeping customer-facing choices in authenticated,
database-backed settings instead of source code. It deliberately uses
**HostScope** and the current provisional URL as replaceable labels. The
companion [IMPLEMENTATION_DIRECTION.md](IMPLEMENTATION_DIRECTION.md) records
which parts are usable now versus deliberately staged.

## Recommended initial topology

Run the commercial projects as separate deployments behind one VPS reverse
proxy. Until separate domains are wanted, use independent path prefixes under
the provisional public origin:

| deployment | provisional public location | role | must not share |
| --- | --- | --- | --- |
| commercial site | `https://hostscope.clewismoten.com/` | product pages, documentation, account entry, subscription and upgrade links | service databases and service secrets |
| marketplace | `/marketplace/` | reviewed package catalogue, purchase records and submission workflow | client package runtime and custody keys |
| updates | `/updates/` | signed download distribution and version-check API | marketplace payment data and client credentials |
| key custody | `/services/key-custody/` | tenant-isolated wrapped-key envelopes and unwrap authorization | replicas, metrics, payment information, and customer databases |
| future AI proxy | `/services/ai-proxy/` | opt-in model-provider policy and request forwarding | custody data and marketplace credentials |
| future replica relay | `/services/replica-relay/` | encrypted artifact storage and verification | usable data keys or unwrap authority |

Each row gets its own environment configuration, database, filesystem account
where practical, backup policy, log stream, deploy credential, and release
pipeline. A reverse-proxy path is routing only: it is not a security boundary.
Separate domains may replace these paths later without changing the versioned
API or customer enrollment identity.

The full client remains independently deployable on customer infrastructure.
The future remote client is a deliberately smaller collector, ciphertext
replica, or self-managed custodian; it must not need the full dashboard or any
commercial service to collect locally.

## Naming and endpoint policy

Do not embed `hostscope.clewismoten.com`, a product name, or a path prefix in
the client protocol, encrypted artifact identifier, signing-key identifier, or
peer identity. The current URL is a deployment setting only.

- Public site labels, domains, support URLs, pricing copy, and logo assets are
  commercial-site content settings.
- Package publishers and product package names use stable opaque identifiers;
  display names can change during a rebrand.
- A client stores an administrator-configured service base URL and a pinned
  enrollment identity. Changing an endpoint requires an authenticated,
  audited re-enrollment or explicitly approved identity migration.
- APIs remain under a versioned namespace. Moving a service to a new hostname
  preserves request semantics and the identity-pinning model.

This makes a future rebrand a content and deployment migration rather than a
key rotation or data migration.

## Encryption choices presented to a customer

Present one **Protection** page, not a list of cryptography products. It should
ask the following in order and reveal only compatible, installed packages:

1. **What should be protected?** Offer configuration, setup database, account
   database (`stats.db`), host database, and assistant database. Preselect the
   configuration, setup, and account stores; leave optional host/assistant
   stores selected only when they exist and the administrator confirms them.
2. **When must live data be protected?** Offer the host/VM/managed-volume
   option when the operator already has it, or a verified SQLCipher package
   only on a tested platform. Clearly label local sealed copies as backup
   protection rather than live-database encryption.
3. **Who controls recovery keys?** Start with a local private key outside the
   web root. Show manual unlock, self-managed fleet custody, and compatible
   third-party/hosted providers only when their packages are installed.
4. **How should recovery work?** Ask for schedule, retention, and an optional
   encrypted ciphertext replica policy. Require a recovery test before policy
   activation and never delete the newest verified copy for a protected store.

Encryption remains off until an administrator activates it. No option may
silently upload a key, contact a service, or claim live at-rest encryption it
cannot verify.

## Initial technical and commercial policy

| concern | initial direction | review before changing |
| --- | --- | --- |
| Local protection | Verified AES-GCM sealed copies, daily, retaining 30 days; key file outside the document root with an offline recovery copy. | Enable automatic deletion only after recovery exercises and monitoring are operating. |
| Live SQLite | Host/VM volume encryption where the operator manages it; SQLCipher only through a tested optional package. | Expand the tested OS/PHP/PDO matrix only after WAL, migration, backup, crash-recovery, and performance tests. |
| Key custody | Local custody first. Hosted custody is optional and disabled by default. | Launch/enable it only with independent review, tenant isolation, recovery drills, incident response, and customer terms. |
| Provider enrollment | Verify an HTTPS service identity before accepting an enrolled-client credential; normal CA/hostname validation is default and SPKI pinning is optional. | Change the connection-test contract only with a reviewed endpoint-migration and certificate-key rotation procedure. |
| Remote copies | Disabled by default; replica relays receive ciphertext only. | Recommend two independent replicas only after enrollment, deletion acknowledgements, and outage tests exist. |
| Database alternatives | Keep SQLite as the normal deployment. MySQL/MariaDB and Redis are optional packages, not encryption choices. | Add MySQL/MariaDB only for a demonstrated multi-node storage need; use Redis only as a TLS-authenticated cache or queue with no secrets by default. |
| Package execution | Trusted signed packages with explicit capability grants and private package data. | Do not load package code until isolation, secret references, revocation, migrations, and audit evidence are complete. |
| Billing | Commercial site owns plans and payment-provider references; custody only consumes a narrowly scoped entitlement state. | Integrate a payment provider only after tax, refund, support, privacy, and cancellation/retention terms are approved. |

The configuration database and `setup.db` deserve special attention because
they can contain credentials, signing material, service endpoint settings, and
recovery policy. A sealed configuration artifact is therefore a default
selection. It is still not a substitute for keeping the bootstrap configuration
minimal, private, and free of plaintext long-lived secrets.

## Boundaries that must remain true

- A service that stores ciphertext replicas must not authorize key unwraps.
- A service that authorizes unwraps must not receive metric databases or
  payment-card data.
- A package never receives another package's private database, core settings,
  or a raw secret merely because it is installed.
- A local customer installation remains usable without marketplace, updates,
  AI proxy, replica relay, or hosted key custody.
- Backups, migrations, exports, SQLite WAL/SHM companions, and configuration
  artifacts are assessed together; encrypting only a `*.db` file is not a
  complete at-rest protection claim.

## Decisions to revisit later

Keep these as administration records, not source edits:

- approved public product name, domains, and service URL migration procedure;
- supported operating-system/PHP/SQLCipher image matrix and review expiry;
- retention tiers, legal hold, deletion evidence, recovery exercise interval,
  and replica count by customer plan;
- custody availability target, authorization lifetime, key-rotation period,
  incident contacts, and customer recovery escalation process;
- marketplace review rules, publisher identity verification, revenue share,
  refunds, tax, and package vulnerability response;
- whether a customer can bring its own KMS/HSM, and which provider packages
  have been tested and supported.

## What can be enabled now

The customer client can use local, verified sealed copies with a local key file
outside the web root. The administrator selects stores and schedule through
the Protection interface, records recovery evidence, and can periodically
rehash and authenticate retained artifacts without leaving decrypted copies on
disk. This is the recommended first deployment on a VPS, shared host, or
single machine.

The following are intentionally visible only as future, optional package
directions: live SQLCipher conversion, fleet custody, ciphertext relays,
external KMS/HSM providers, MySQL/MariaDB storage, and Redis caching. An
installed executable package can be reviewed, granted only its declared
capabilities, configured using its signed schema, given a package-scoped secret
reference, and revoked. It cannot perform normal package execution; the only
exception is a deployment-attested diagnostic worker. Its ordinary probes have
no secrets. A separately configured custody launcher may make a single
endpoint-restricted subscription-readiness request with the enrolled client
pair, only after a fresh identity probe and explicit grant. It cannot upload an
envelope, unwrap a key, or receive an artifact/database path. This preserves
customer choice without claiming that an untested provider is already
protecting live data.

`commercial-services/replica-relay/` is now a separately deployable,
ciphertext-only reference service with isolated tenant enrollment, SQLite
metadata, private artifact files, and a signed API. It is not yet an end-user
client capability: no full-client relay package may upload to it until the
isolated runtime, retry/coverage ledger, recovery verification, and deletion
acknowledgement protocol are implemented and tested.

The key-custody service is a separate reference deployment, not a default
customer dependency. If it is offered later, keep its base URL, plan/price,
retention, recovery contacts, and supported-package matrix in commercial
database-backed settings. A rebrand or domain migration then changes content
and endpoint configuration, never an artifact identity or key format.

Its three credentials remain separate: a customer management token for key
policy and subscription requests, an enrolled-client bearer plus Ed25519
signing key for a named envelope operation, and an optional private commercial
billing-control token that can apply entitlement state only. None substitutes
for another, and none should be stored as normal client configuration. The
client's future provider package receives only the enrolled-client pair through
encrypted package secret references.

Before a provider package stores that enrolled-client pair, the Protection UI
must run its unauthenticated endpoint probe and display the returned service
identity and API version. The probe has no bearer, request signature, or key
material. Reject an unexpected identity rather than treating a reachable URL
as a trusted service. The initial UI should leave an SPKI pin blank unless the
administrator has an approved certificate-key rotation process; CA and hostname
verification remain required whether or not a pin is used.

The detailed encryption design and implementation state live in
[`client-hostscope/ENCRYPTION_AND_FLEET_PLAN.md`](client-hostscope/ENCRYPTION_AND_FLEET_PLAN.md).
The operator-facing review checklist is
[`client-hostscope/ENCRYPTION_REVIEW.md`](client-hostscope/ENCRYPTION_REVIEW.md).
The concise set of adopted defaults and future configuration locations is
[`client-hostscope/ENCRYPTION_DECISIONS.md`](client-hostscope/ENCRYPTION_DECISIONS.md).
The deployment handoff and launch gates are collected in
[`DEPLOYMENT_CHECKLIST.md`](DEPLOYMENT_CHECKLIST.md).
