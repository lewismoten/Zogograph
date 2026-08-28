# Zogograph workspace

This repository is being separated into independently deployable Zogograph
projects.

| project | role |
| --- | --- |
| [Client](projects/client/) | Customer dashboard, collection, alerts, local storage, and fleet control. |
| [Remote client](projects/client-remote/) | Future minimal collector, replica, or key-custody deployment. |
| [Public site](projects/site/) | Product, documentation, subscriptions, and upgrades. |
| [Zogograph Market](projects/marketplace/) | Reviewed commercial catalog and purchases. |
| [Updates](projects/updates/) | Signed downloads and version-check service. |
| [Services](projects/services/) | Optional AI proxy, key custody, and ciphertext replica services. |

Each project owns its own deployment and credentials. No client installation
depends on a commercial service by default.

The recommended VPS topology, rebrand-safe naming rules, customer encryption
choices, and commercial-service boundaries are recorded in
[projects/DIRECTION.md](projects/DIRECTION.md).

For the operational implementation direction—what is usable now, what remains
optional, and where it is configured—see
[projects/IMPLEMENTATION_DIRECTION.md](projects/IMPLEMENTATION_DIRECTION.md).
