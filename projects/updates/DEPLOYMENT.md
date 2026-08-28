# Deploying Zogograph Updates

Deploy to `https://updates.zogograph.com/` using repository-specific
`DEPLOY_HOST`, optional `DEPLOY_PORT`, `DEPLOY_USER`, absolute `DEPLOY_PATH`,
`DEPLOY_URL`, `DEPLOY_KNOWN_HOSTS`, and `DEPLOY_SSH_PRIVATE_KEY`.

The workflow deploys only `dist/` and smoke-tests the HTTPS endpoint. Release
publication must add a signed manifest and verified downloads as one reviewed
artifact set. Never replace signature or checksum validation with a branding
redirect. Legacy update endpoints need redirects or aliases during their
documented migration period.
