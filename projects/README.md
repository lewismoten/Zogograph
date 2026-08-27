# HostScope projects

This repository currently contains the deployable HostScope client at its root.
The folders below establish the project boundaries for the product family
without disrupting that deployment during the transition.

- `commercial-site/` — public product, documentation, account, subscription,
  and upgrade experience.
- `commercial-marketplace/` — reviewed commercial catalog and purchase flow.
- `commercial-updates/` — signed release downloads and version-check API.
- `commercial-services/` — optional hosted services such as AI proxy and key
  custody; never required by a self-managed client.
- `client-hostscope/` — the full customer deployment, represented by the
  current root application until it is moved in a dedicated migration.
- `client-hostscope-remote/` — a future barebones remote collector/replica
  deployment, separate from the full dashboard client.

Each project owns its own deployment boundary, credentials, release process,
and documentation. Shared protocols must be versioned rather than importing
production code across these folders.
