# HostScope workspace

This repository is being separated into independently deployable HostScope
projects.

| project | role |
| --- | --- |
| [Full client](projects/client-hostscope/) | Customer dashboard, collection, alerts, local storage, and fleet control. |
| [Remote client](projects/client-hostscope-remote/) | Future minimal collector, replica, or key-custody deployment. |
| [Commercial site](projects/commercial-site/) | Product, documentation, subscriptions, and upgrades. |
| [Commercial marketplace](projects/commercial-marketplace/) | Reviewed commercial catalog and purchases. |
| [Commercial updates](projects/commercial-updates/) | Signed downloads and version-check service. |
| [Commercial services](projects/commercial-services/) | Optional AI proxy, key custody, and ciphertext replica services. |

Each project owns its own deployment and credentials. No client installation
depends on a commercial service by default.

The recommended VPS topology, rebrand-safe naming rules, customer encryption
choices, and commercial-service boundaries are recorded in
[projects/DIRECTION.md](projects/DIRECTION.md).
