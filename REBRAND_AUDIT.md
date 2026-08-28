# Zogograph rebrand audit

This is the source-level audit record for the rename from HostScope to
Zogograph. It is intentionally a classification, not a global replacement
list.

| occurrence class | examples | disposition |
| --- | --- | --- |
| Public branding | page titles, visible navigation, setup headers, public-site metadata, project README headings | Rename to Zogograph. |
| Internal safe-to-change naming | workspace directory names and release-artifact labels | Rename with updated workflow/document references. |
| Compatibility identifiers | `hostscope-*` plugin IDs, package IDs, browser-storage keys, database values, enrollment credentials, artifact/signing IDs | Preserve until a tested migration or compatibility alias exists. |
| Historical material | migration snapshots, audit records, existing installed manifests, prior release metadata | Preserve unchanged. |
| Deployment examples | `/etc/hostscope`, `/srv/hostscope`, prior domains | Do not edit an active installation by source rename; publish new Zogograph examples and migrate explicitly. |

## Current compatibility decisions

- Browser-storage keys retain their `hostscope` namespace to retain local user
  preferences and dismissals.
- Existing marketplace/plugin IDs retain their names because they are stored,
  signed, and/or externally referenced.
- Existing SQLite database names, configuration keys, encryption key paths,
  peer identities, update channels, and cookie/session behavior are not renamed
  by a UI rebrand.
- New deployment documentation uses Zogograph domains and project directories.
  Existing deployment paths are valid until an administrator moves them using a
  backup-verified migration.

## Remaining public-surface review

Public text in the primary client is being converted deliberately by surface:
dashboard shell, setup, documentation, reports, notifications, and package
documentation. Technical protocol text may retain HostScope when it refers to
an existing compatibility identifier rather than the public product name.
