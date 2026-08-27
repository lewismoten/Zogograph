# HostScope marketplace plan

## Scope

HostScope’s marketplace is a catalog of optional, locally installed extension
packages: integrations, alert-rule packs, dashboards, and guided-operation
packs. It is not an app store that downloads or runs arbitrary code from the
browser.

## Trust model

- Packages are discovered from a configured local directory only.
- Every package declares a stable id, version, publisher, summary, license,
  and requested capabilities.
- Administrators review and explicitly enable each package.
- Package capabilities are allowlisted and shown before enablement.
- Remote discovery, payment, telemetry, automatic updates, and executable
  package hooks remain disabled until signed-package verification and rollback
  are implemented.

## Delivery sequence

1. [x] Manifest schema, strict validation, and bounded local catalog discovery.
2. [x] Database-backed enablement state, audit records, and capability review.
3. [x] Administrator catalog UI with package detail, enable/disable, and safe
   unavailable/error states.
4. [x] First data-only package type: alert-rule recommendations.
5. [ ] Signed package verification, compatibility checks, install/rollback,
   and release provenance before any remote package source.
6. [ ] Publisher workflow, commercial terms, moderation, payments, and hosted
   marketplace service only after legal and business-owner decisions.

## Completion evidence

Each delivery item requires a server-side authorization test, malformed
manifest coverage, capability-denial coverage, migration/upgrade coverage, and
documentation that describes actual behavior rather than planned behavior.

### In progress: package provenance

Manifests may carry an Ed25519 signature. When a signature is present,
HostScope verifies it against a locally configured publisher key and refuses to
enable a malformed, untrusted, or invalidly signed package. Unsigned packages
remain local-only administrator-reviewed manifests. Compatibility ranges,
package installation/rollback, release provenance, and every remote source are
still unimplemented.

## Current package format

Set `marketplace.directory` in `lib/config.php` (it defaults to the local
`marketplace/` directory), then add one `<package-id>.json` file per package.
Only `alert_recommendations` is currently accepted. A recommendation is shown
for administrator review only; enabling it neither changes an alert rule nor
executes package code.

```json
{
  "id": "uptime-guidance",
  "version": "1.0.0",
  "name": "Uptime guidance",
  "publisher": "Example publisher",
  "summary": "Advice for reviewing unexpected restarts.",
  "license": "License identifier or terms",
  "capabilities": ["alert_recommendations"],
  "recommendations": [
    {
      "rule": "host_uptime",
      "severity": "warning",
      "reason": "Review unexpected restarts."
    }
  ]
}
```
