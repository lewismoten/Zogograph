# Set up HostScope

HostScope is a dependency-free PHP site. You need a PHP-capable web host, a
scheduled-task facility, and access to the account whose home directory you
want to monitor. No Composer, Node.js, or external database server is needed.

## Before you begin

Make sure the host provides:

- PHP 8.1 or newer for both the web server and command line;
- PHP's PDO SQLite extension (`pdo_sqlite`);
- HTTPS for the site;
- a cron editor or shell access for the hosting account; and
- a web-server user that can write `data/` and, during initial setup, `lib/`.

HostScope stores its measurements and settings in SQLite files under `data/`.
That directory must be private: it must never be served as a downloadable web
directory. The included `.htaccess` files enforce this on Apache when
`AllowOverride` is enabled. For Nginx, start with
[`deploy/nginx.conf.example`](deploy/nginx.conf.example).

## 1. Deploy the application

Create the document root for the HostScope site, such as
`~/public_html/stats.example.com`, and upload the **complete release** there.
Keep the directory layout intact: in particular, `lib/`, `data/`, and their
included `.htaccess` files must remain in place.

Do not put an existing database or `lib/config.php` from another installation
into a new deployment unless you are deliberately migrating that installation.
Neither belongs in source control or a public download.

If you deploy through SSH, replace the example paths below with yours:

```bash
mkdir -p ~/public_html/stats.example.com
cd /path/to/hostscope-release
cp -R . ~/public_html/stats.example.com/
```

Set ownership and permissions so PHP can create files in `data/` and write the
initial bootstrap in `lib/`. Prefer making the web-server account the owner;
avoid world-writable permissions. After setup, `lib/config.php` is restricted
to its owner automatically.

For Apache, confirm that `.htaccess` is allowed for this document root and that
`mod_rewrite` is enabled so HTTP requests redirect to HTTPS. For Nginx, install
the supplied example's deny rules and configure a separate HTTP virtual host
that redirects to HTTPS.

## 2. Run the setup wizard immediately

Visit:

```
https://stats.example.com/setup.php
```

The page runs a preflight check. Resolve every error before continuing,
especially an unavailable SQLite extension or an unwritable `data/` directory.
Choose the scan root carefully: it defines the only tree the account collector
will examine and the boundary for file actions.

Complete the form, set an administrator password, and save. The wizard writes
the minimal `lib/config.php` bootstrap and stores the remaining settings in
`data/setup.db`.

Alternatively, from the deployed directory, run the same wizard in a terminal:

```bash
php setup.php --write
```

The wizard tells you which PHP binary it sees. Use the absolute path reported
by `which php` in cron if your host has more than one PHP version.

The initial setup page is intentionally open while no configuration exists, so
finish it as soon as the files are live. Once setup succeeds, remove
`setup.php` from the deployed site. If you retain it temporarily, deny web
access to it at the web-server level.

## 3. Take the first readings

Run these from the deployed directory as the hosting account:

```bash
php cron.php --vitals
php cron.php --scan
```

Then sign in at the site. The initial overview remains in setup mode until it
has a vital reading and a completed storage scan. If either command fails,
check `data/error.log` and open `health.php`; it is a standalone diagnostic
page that can identify incomplete or inconsistent uploads.

## 4. Schedule collection

Add these jobs in the hosting control panel's cron editor or with `crontab -e`.
Use absolute paths, and replace both placeholders with your own paths:

```cron
# Cheap system and account readings, plus alert evaluation.
* * * * * /absolute/path/to/php /absolute/path/to/hostscope/cron.php --vitals --alerts --quiet

# Full storage scan and retention cleanup.
7 * * * * /absolute/path/to/php /absolute/path/to/hostscope/cron.php --scan --alerts --prune --quiet
```

`--quiet` keeps successful cron runs silent and reports failures through cron's
normal error handling. The dashboard's **Schedule** panel can create account
cron entries on hosts that allow it; otherwise it shows the exact line to paste.

## Optional: host-wide collection

The normal collector monitors the account it runs as. To see host-wide process,
disk, and system information, enable **Also collect host-wide readings** in the
setup wizard and add this job to **root's** crontab, not the account crontab:

```cron
*/5 * * * * /absolute/path/to/php /absolute/path/to/hostscope/host-cron.php --collect --prune --quiet
```

Run the printed command once as root to verify it. Skip this step if you do not
administer the machine; HostScope continues to work with account-level data.

## Finish securely

- Confirm the dashboard is reachable only over HTTPS.
- Confirm a direct request to `data/`, `lib/`, `cron.php`, and `host-cron.php`
  is denied by the web server.
- Remove `setup.php` after setup. You may also remove `health.php` if you do
  not want a public diagnostic page.
- Back up `data/` privately. It contains the measurements, settings, and
  sensitive configuration state.

For operational behavior, alerts, upgrades, and recovery, see the detailed
reference in [README.md](README.md).

## Optional: encrypted database copies

HostScope starts with encryption disabled. In the setup wizard, each
administrator chooses the protection mode, the configuration/database stores to
protect, the key custody method, and a copy schedule and retention preference.
The first built-in mode creates verified encrypted **copies** of selected
SQLite databases and the bootstrap configuration file; it does not alter the
live database or its WAL journal. This is suitable for encrypted local archives
and the later fleet-replica workflow.

Create a 256-bit local key outside the document root, then keep an offline
recovery copy. Do not store it in `data/`, `lib/`, the web root, or alongside a
database export.

```bash
php scripts/encryption-key.php create /secure/hostscope/primary.key
```

In the setup wizard's **Encryption** section, select **Encrypted copies with a
local private key**, enter that absolute key-file path, and choose the stores
to include: `config`, `setup`, `account`, `host`, and/or `ai`. Missing optional
host and assistant databases are skipped. The artifact directory defaults to
the protected `data/sealed/` directory; set a private absolute path if you need
the copies elsewhere. Choose manual, daily, or weekly scheduling and a local
retention period; configure the matching job in your host scheduler. During an
explicit `--seal` run, HostScope prunes only old verified artifacts whose hash
still matches and never removes the newest verified artifact for a store. Set
retention to zero to retain every local encrypted copy.

If the host, VM, container volume, or managed database storage is already
encrypted at rest, select **My host/VM encrypts the selected live stores** and
choose the protected stores. This records the operator's policy but does not
attempt to verify host-level encryption or create HostScope copies.

Run a first copy manually, then schedule it separately from normal collection:

```bash
php cron.php --seal
```

Each new sealed artifact is fully decrypted into a short-lived private temporary
file before HostScope records it as verified. This is a recovery proof, so the
first run needs temporary free disk space approximately equal to the selected
source database/configuration file. A failed proof leaves the original live
data untouched and does not create a verified ledger record.

```cron
# Daily encrypted copies. This does nothing unless encryption.mode is selected.
19 3 * * * /absolute/path/to/php /absolute/path/to/hostscope/cron.php --seal --quiet
```

The key is never kept in HostScope configuration or its databases. A lost key
cannot be recovered from a sealed copy. See
[ENCRYPTION_AND_FLEET_PLAN.md](ENCRYPTION_AND_FLEET_PLAN.md) for the current
scope, limitations, rotation design, and planned self-managed fleet custody.

To inspect a copy during recovery, decrypt it to a new private file and remove
that plaintext file when you finish:

```bash
php scripts/encryption-key.php decrypt /secure/hostscope/primary.key account.sealed restored-account.db
```

To replace a key, create the replacement key, write a separate rotated copy,
verify that it decrypts, and only then retire the old sealed copy and key:

```bash
php scripts/encryption-key.php create /secure/hostscope/replacement.key
php scripts/encryption-key.php rotate /secure/hostscope/primary.key /secure/hostscope/replacement.key \
  account.sealed account-rotated.sealed account-rotation-2026-08-27
```

## Optional: signed executable packages

The normal marketplace remains data-only. Executable packages, including the
future encryption packages, are installed separately so a package cannot run
merely because a JSON manifest was copied into a catalog. A package must have a
trusted publisher key, a valid Ed25519 signature, and matching hashes for every
immutable file.

After adding the publisher public key through the protected package settings,
an administrator can inspect, install, and grant the package's declared
capabilities from the protected package page/API. The equivalent low-complexity
CLI workflow is:

```bash
php scripts/package-install.php install /absolute/path/to/signed-package
php scripts/package-install.php list
php scripts/package-install.php enable package-id capability[,capability...]
```

The capability list must exactly match the package's signed declaration. At
this stage installation and grants only register immutable files and a private
SQLite data directory; executable package code remains unavailable until the
isolated runtime is complete. This is intentional: it lets administrators
validate provenance and configuration without silently running new code.
