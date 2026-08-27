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
