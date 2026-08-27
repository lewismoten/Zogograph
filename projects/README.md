# HostScope projects

This repository is a workspace for independently deployable HostScope
projects. The complete customer deployment now lives in `client-hostscope/`.

- `commercial-site/` — public product, documentation, account, subscription,
  and upgrade experience.
- `commercial-marketplace/` — reviewed commercial catalog and purchase flow.
- `commercial-updates/` — signed release downloads and version-check API.
- `commercial-services/` — optional hosted services such as AI proxy and key
  custody; never required by a self-managed client.
- `client-hostscope/` — full customer deployment: dashboard, collection,
  alerts, local storage, and fleet control.
- `client-hostscope-remote/` — future barebones collector/replica deployment,
  separate from the full dashboard client.

Each project owns its deployment boundary, credentials, release process, and
documentation. Shared protocols must be versioned rather than importing
production code across these folders. The root `data/` directory remains only
as a protected transition location for an existing ignored local database; it
must be migrated only after its writers have stopped.

See [DIRECTION.md](DIRECTION.md) for the recommended initial VPS paths,
separation boundaries, rebrand-safe endpoint rules, and encryption launch
policy.
