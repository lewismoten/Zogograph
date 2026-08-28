# Continuous deployment

The Forgejo workflows in the repository root deploy this application with
`rsync`, without replacing the live configuration or SQLite stores. They are
safe for the existing installation after the following Forgejo settings exist.

## Forgejo settings

Repository **variables**:

| Variable | Value |
| --- | --- |
| `DEPLOY_HOST` | SSH hostname or IP address |
| `DEPLOY_PORT` | SSH port; leave empty for `22` |
| `DEPLOY_USER` | restricted deployment user |
| `DEPLOY_PATH` | absolute web-root path for this HostScope install |
| `DEPLOY_URL` | public HTTPS URL for the install, without a trailing slash |
| `DEPLOY_KNOWN_HOSTS` | exact `known_hosts` line for the server's SSH host key |
| `SNAPSHOT_PATH` | absolute private path for snapshots, outside the web root and deploy path |

Repository **secret**:

| Secret | Value |
| --- | --- |
| `DEPLOY_SSH_PRIVATE_KEY` | private key for the deployment user; its public key belongs in that user’s `authorized_keys` |

The deployment user needs read/write access to both deployment and snapshot
paths, and the server needs `bash`, `rsync`, and `sqlite3`. Do not make either
the deployment path or snapshot path world-readable.

Get the known-host entry from a trusted administrative connection, for example:

```sh
ssh-keyscan -H your-server.example
```

Verify the reported fingerprint by a separate trusted channel before saving it
as a variable. Do not disable host-key checking in the workflow.

## Deploying

`client-hostscope-deploy.yml` runs on pushes to `develop` and the current
`feature-dashboard-professional-polish` development branch when they change
`projects/client-hostscope/`. It can also be run manually from any selected
ref. Change the `push.branches` list when the development branch changes.

Each run:

1. creates a release staging directory, excluding `data/`, `lib/config.php`,
   tests, deployment examples, and documentation;
2. writes a public `build.json` containing the commit, ref, run ID/number,
   creation time, version, repository, application, and part;
3. takes a remote snapshot before altering the site;
4. uses `rsync --delete` for application files while explicitly preserving
   `data/`, `lib/config.php`, and `.well-known/` on the server; and
5. fetches `build.json` over HTTPS to confirm the deployed commit.

SQLite files below `data/` are captured with SQLite’s online `.backup` command,
not copied together with their WAL files. Snapshot IDs are printed in the job
output. Historical files below `data/migration-backups/` are deliberately not
opened as live databases. The workflow only finds live databases below `data/`;
if a configuration points at a database outside the deploy path, back that
store up separately.

## Restoring a snapshot

Before restoring, pause the account and host collector cron jobs so they do not
write while files are replaced. In Forgejo, run **Restore Client HostScope
Snapshot**, enter the snapshot ID, and type exactly:

```text
restore-hostscope-snapshot
```

The restore job snapshots the current site first, restores application and
non-database data files, then restores each SQLite database from its consistent
backup and removes its stale WAL/SHM/journal companions. The pre-restore
snapshot ID is printed in the job output, so a mistaken restore is itself
reversible. Re-enable collectors only after the job succeeds and the site
loads normally.
