# Zogograph projects

This directory retains shared Zogograph planning and migration records. The
deployable projects now live in sibling repositories; each owns its manifest,
lockfile, environment example, build/test commands, deployment workflow, and
deployment documentation.

- `../site/` — public product, documentation, account, subscription,
  and upgrade experience.
- `../market/` — reviewed commercial catalog and purchase flow.
- `../updates/` — signed release downloads and version-check API.
- `../services/` — optional hosted services such as AI proxy and key
  custody; never required by a self-managed client.
- `../client/` — full customer deployment: dashboard, collection,
  alerts, local storage, and fleet control.
- `../client-remote/` — future barebones collector/replica deployment,
  separate from the full dashboard client.

Each project owns its deployment boundary, credentials, release process, and
documentation. Shared protocols must be versioned rather than importing
production code across these folders. The root `data/` directory remains only
as a protected transition location for an existing ignored local database; it
must be migrated only after its writers have stopped.

See [DIRECTION.md](DIRECTION.md) for the recommended initial VPS paths,
separation boundaries, rebrand-safe endpoint rules, and encryption launch
policy. [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) is the corresponding
operator-ready handoff: per-project boundaries, database/configuration
ownership, customer protection defaults, and launch gates.

[IMPLEMENTATION_DIRECTION.md](IMPLEMENTATION_DIRECTION.md) is the concise
current-state handoff: recommended customer defaults, optional package choices,
configuration authority, and decisions that require later owner review.
