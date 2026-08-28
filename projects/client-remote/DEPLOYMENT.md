# Deploying Zogograph Remote demo

Deploy this public, non-administrative demonstration to
`https://demo-remote.zogograph.com/` with repository-specific `DEPLOY_HOST`,
optional `DEPLOY_PORT`, `DEPLOY_USER`, absolute `DEPLOY_PATH`, `DEPLOY_URL`,
`DEPLOY_KNOWN_HOSTS`, and secret `DEPLOY_SSH_PRIVATE_KEY`.

The workflow deploys only `dist/` and smoke-tests HTTPS. A future remote
collector service must keep its private configuration, enrollment credentials,
and local stores outside this public static deployment.
