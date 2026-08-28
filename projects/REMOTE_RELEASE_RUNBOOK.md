# Signed remote-client release runbook

This runbook joins the separately deployed `client-remote/` and `updates/`
projects. It publishes no credentials, enrollment material, metrics, databases,
or private signing keys.

## 1. Build the immutable remote archive

In `client-remote/`, run the verified build, then create a ZIP whose entries
are the contents of `dist/`, not a parent `dist/` directory. Do not include
`src/setup.php`, a private remote configuration, any key, or a local database.

```sh
npm test && npm run build
(cd dist && zip -r ../remote-core-1.2.3.zip .)
```

Upload the ZIP to its final HTTPS download URL before signing its manifest.
The URL must not contain a credential, query, fragment, or redirect.

## 2. Sign and publish the update manifest offline

In `updates/`, use the owner-only base64 Ed25519 secret-key file. The signer
computes the ZIP's SHA-256 and emits `zogograph.remote-release.v1`; the static
updates build copies it only when the reviewed file exists.

```sh
ZOGOGRAPH_RELEASE_SIGNING_KEY_FILE=/private/zogograph-release.key \
ZOGOGRAPH_RELEASE_KEY_ID=release-2026 \
php bin/sign-remote-release.php 1.2.3 ../client-remote/remote-core-1.2.3.zip \
  https://updates.zogograph.com/releases/remote-core-1.2.3.zip \
  > src/remote-release.json
npm test && npm run build
```

Derive the corresponding **public** key for the remote node; never copy the
secret key to it:

```sh
ZOGOGRAPH_RELEASE_SIGNING_KEY_FILE=/private/zogograph-release.key \
  php bin/sign-remote-release.php --public-key
```

## 3. Configure the remote node deliberately

In the remote node's protected Setup page, enter the deployed
`remote-release.json` HTTPS URL and the derived public key. Choose one update
policy:

| Policy | Remote updater behavior |
| --- | --- |
| Manual | Verifies the release but does not download or switch it. |
| Download | Stages a verified ZIP for an administrator to apply. |
| Automatic | Stages, verifies, extracts an immutable release, then atomically switches `current`. |

The remote's private updater requires an owner-only configuration file and a
separate release root. Its web server serves `RELEASE_ROOT/current`, never a
mutable release directory. Test the configured signed manifest with `check`
before scheduling the policy-aware `run` action.

```sh
ZOGOGRAPH_REMOTE_CONFIG=/srv/zogograph-remote/.private/config.json \
  php /opt/zogograph-remote/bin/remote-update.php check
```

An update failure must leave the current release and local collection intact.
Do not reuse controller, OpenID, enrollment, custody, or marketplace keys as
an update signing key.
