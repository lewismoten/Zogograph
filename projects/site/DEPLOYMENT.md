# Deploying the Zogograph public site

The production target is `https://zogograph.com/`. Configure a redirect from
`https://www.zogograph.com/` at the web server or CDN; this static artifact
does not set domain redirects itself.

## Repository settings

| name | purpose |
| --- | --- |
| `DEPLOY_HOST` | SSH host for this site only |
| `DEPLOY_PORT` | optional SSH port, default `22` |
| `DEPLOY_USER` | restricted deployment user |
| `DEPLOY_PATH` | absolute document-root path for this site only |
| `DEPLOY_URL` | canonical HTTPS URL used for smoke testing |
| `DEPLOY_KNOWN_HOSTS` | pinned SSH host key entry |
| `DEPLOY_SSH_PRIVATE_KEY` | deployment-user private key secret |

The workflow builds `dist/`, verifies it is non-empty, deploys only that
directory, and fetches the configured HTTPS URL afterwards. It rejects missing
or unsafe deployment paths. This project has no runtime database, uploads, or
environment file to preserve.
