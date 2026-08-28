# Deploying Zogograph Market

The target is `https://market.zogograph.com/`. Configure repository-specific
`DEPLOY_HOST`, optional `DEPLOY_PORT`, `DEPLOY_USER`, absolute `DEPLOY_PATH`,
`DEPLOY_URL`, `DEPLOY_KNOWN_HOSTS`, and secret `DEPLOY_SSH_PRIVATE_KEY`.

The workflow builds and deploys only `dist/`, validates that the artifact is
non-empty, and performs an HTTPS smoke test. A future catalog or entitlement
database must be excluded from ordinary synchronization and have its own
migration and backup process.
