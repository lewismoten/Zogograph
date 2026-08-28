# Zogograph rebrand and migration

HostScope is now **Zogograph**: a platform that observes activity, records the
evidence, and helps explain what it means. The public canonical site is
`https://zogograph.com/`; deployment targets are configured per project rather
than hard-coded into clients.

## Project directories and targets

| previous workspace directory | Zogograph directory | intended deployment target |
| --- | --- | --- |
| `client-hostscope/` | `client/` | `demo.zogograph.com` |
| `client-hostscope-remote/` | `client-remote/` | `demo-remote.zogograph.com` |
| `commercial-marketplace/` | `marketplace/` | `market.zogograph.com` |
| `commercial-services/` | `services/` | `services.zogograph.com` |
| `commercial-site/` | `site/` | `zogograph.com` / `www.zogograph.com` |
| `commercial-updates/` | `updates/` | `updates.zogograph.com` |

Each directory is being prepared for its own repository. `DEPLOY_*` names stay
the shared deployment-variable contract, but every repository owns its own
values, secrets, destination, and smoke test.

## Compatibility policy

The public product name changes now. The following are compatibility-sensitive
and are intentionally preserved until a documented, tested migration exists:

- `hostscope-*` plugin IDs, package manifests, signing subjects, and package
  records;
- database tables, stored values, encryption artifact IDs, key identifiers,
  peer identities, API routes, cookies, and browser-storage keys;
- existing deployment snapshot layouts and configured filesystem paths;
- customer-defined agents, their instructions, conversations, alerts, and
  historical evidence.

Old application URLs and update endpoints need redirects or aliases during a
separately announced migration period; no client protocol is changed merely by
changing a public domain or label.

## Administrator actions

1. Set each repository's `DEPLOY_*` variables for its own destination only.
2. Point public DNS and TLS at the new subdomains when their applications and
   smoke tests are ready.
3. Preserve existing client configuration and databases in place during a
   deployment-path migration; use the client snapshot/restore workflow.
4. Reinstall or reapprove a plugin only when its manifest version changes.
   Existing plugin IDs remain valid.

## Plugin authors

Use **Zogograph** in public names and documentation. Do not rename an already
published plugin ID, signing key ID, compatibility identifier, or update
channel. Publish a new version and document any new public endpoint instead.

## Agents

The UI refers generically to assistants and agents. Built-in agents—📡 Mr.
Radar, 🛠 Duty, and 🌱 Ada—retain their names. Mr. Radar may use “Oh no, not
again!” only when evidence proves recurrence, and must follow it with the
evidence and next useful step.
