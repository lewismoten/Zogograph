# Zogograph workspace

This repository contains Zogograph rebrand, migration, and shared-planning
records. The deployable projects have been extracted into sibling repositories.

| project | role |
| --- | --- |
| [Client](../client/) | Customer dashboard, collection, alerts, local storage, and fleet control. |
| [Remote client](../client-remote/) | Restricted collector or ciphertext-replica deployment. |
| [Public site](../site/) | Product, documentation, subscriptions, and upgrades. |
| [Zogograph Market](../market/) | Reviewed commercial catalog and purchases. |
| [Updates](../updates/) | Signed downloads and version-check service. |
| [Services](../services/) | Optional AI proxy, key custody, and ciphertext replica services. |

Each project owns its own deployment and credentials. No client installation
depends on a commercial service by default.

The recommended VPS topology, rebrand-safe naming rules, customer encryption
choices, and commercial-service boundaries are recorded in
[projects/DIRECTION.md](projects/DIRECTION.md).

See [Zogograph rebrand and migration](REBRAND_MIGRATION.md) for public-domain
targets, renamed project directories, preserved compatibility identifiers, and
administrator actions.

For the operational implementation direction—what is usable now, what remains
optional, and where it is configured—see
[projects/IMPLEMENTATION_DIRECTION.md](projects/IMPLEMENTATION_DIRECTION.md).
