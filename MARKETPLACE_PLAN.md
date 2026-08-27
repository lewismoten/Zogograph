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
  package hooks are not part of the local marketplace and remain disabled.

## Delivery sequence

1. [x] Manifest schema, strict validation, and bounded local catalog discovery.
2. [x] Database-backed enablement state, audit records, and capability review.
3. [x] Administrator catalog UI with package detail, enable/disable, and safe
   unavailable/error states.
4. [x] First data-only package type: alert-rule recommendations.
5. [x] Signed package verification, compatibility checks, install/rollback,
   and release provenance before any remote package source.
6. [ ] Publisher workflow, commercial terms, moderation, payments, and hosted
   marketplace service only after legal and business-owner decisions.

## Completion evidence

Each delivery item requires a server-side authorization test, malformed
manifest coverage, capability-denial coverage, migration/upgrade coverage, and
documentation that describes actual behavior rather than planned behavior.

### Implemented local provenance

Manifests may carry an Ed25519 signature. When a signature is present,
HostScope verifies it against a locally configured publisher key and refuses to
enable a malformed, untrusted, or invalidly signed package. Unsigned packages
remain local-only administrator-reviewed manifests. Compatibility ranges,
including PHP and database-schema bounds, are checked before enablement; a
changed package version requires fresh administrator approval. Package
installation and rollback are local-only, atomic, and leave the restored
revision disabled for review. Each replaced manifest is retained under a
content-derived SHA-256 revision id, and installation, approval, disablement,
and rollback are audit records. Remote sources remain unavailable.

## Current package format

Set `marketplace.directory` in `lib/config.php` (it defaults to the protected
`data/marketplace/` directory), then add one `<package-id>.json` file per package.
Only `alert_recommendations` is currently accepted. A recommendation is shown
for administrator review only; enabling it neither changes an alert rule nor
executes package code.

## Signatures and revisions

An unsigned manifest is permitted only as a locally reviewed package. To add
publisher provenance, configure the publisher's Base64 Ed25519 public key under
`marketplace.trusted_keys` and add this object to the manifest:

```json
"signature": {
  "key_id": "example-publisher",
  "algorithm": "ed25519",
  "value": "base64 detached signature"
}
```

The signature covers the manifest without `signature`, serialized as compact
JSON with object keys sorted lexically and array order preserved. A package
that supplies a signature but cannot verify is visible for diagnosis but cannot
be enabled. Use **Install local manifest** for an atomic write, and **History**
to restore a retained revision; neither operation enables the package.

```json
{
  "id": "uptime-guidance",
  "version": "1.0.0",
  "name": "Uptime guidance",
  "publisher": "Example publisher",
  "summary": "Advice for reviewing unexpected restarts.",
  "license": "License identifier or terms",
  "capabilities": ["alert_recommendations"],
  "compatibility": {"min_php": "8.1.0", "min_schema": 49},
  "recommendations": [
    {
      "rule": "host_uptime",
      "severity": "warning",
      "reason": "Review unexpected restarts."
    }
  ]
}
```
