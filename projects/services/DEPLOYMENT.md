# Deploying Zogograph services

This repository packages the separately isolated key-custody and replica-relay
reference services for `https://services.zogograph.com/`. They must be routed
to distinct application paths, system users, databases, credentials, and
rollback procedures; one service must never deploy into the other’s persistent
store.

Configure repository-specific `DEPLOY_HOST`, optional `DEPLOY_PORT`,
`DEPLOY_USER`, absolute `DEPLOY_PATH`, `DEPLOY_URL`, `DEPLOY_KNOWN_HOSTS`, and
secret `DEPLOY_SSH_PRIVATE_KEY`. `DEPLOY_PATH` is validated before deployment.
The workflow deploys only the generated `dist/` artifact and deliberately
excludes `.env`, configuration files, SQLite data, uploads, and secrets.

Run `npm ci`, `npm test`, and `npm run build`; then confirm the configured
HTTPS health endpoint responds. Each extracted service repository should keep
the same `DEPLOY_*` names with its own repository-specific values.
