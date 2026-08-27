# HostScope marketplace plan

## Scope

HostScope’s marketplace is a catalog of optional, locally installed extension
packages: integrations, alert-rule packs, dashboards, and guided-operation
packs. It is not an app store that downloads or runs arbitrary code from the
browser.

## Trust model

- Packages are discovered from a configured local directory only.
- Every package declares a stable id, version, publisher, summary, license,
  supported HostScope version range, and requested capabilities.
- Administrators review and explicitly enable each package.
- Package capabilities are allowlisted and shown before enablement.
- Remote discovery, payment, telemetry, automatic updates, and executable
  package hooks remain disabled until signed-package verification and rollback
  are implemented.

## Delivery sequence

1. [ ] Manifest schema, strict validation, and bounded local catalog discovery.
2. [ ] Database-backed enablement state, audit records, and capability review.
3. [ ] Administrator catalog UI with search, detail, enable/disable, and safe
   unavailable/error states.
4. [ ] First data-only package type: alert-rule recommendations.
5. [ ] Signed package verification, compatibility checks, install/rollback,
   and release provenance before any remote package source.
6. [ ] Publisher workflow, commercial terms, moderation, payments, and hosted
   marketplace service only after legal and business-owner decisions.

## Completion evidence

Each delivery item requires a server-side authorization test, malformed
manifest coverage, capability-denial coverage, migration/upgrade coverage, and
documentation that describes actual behavior rather than planned behavior.
