# Disk Usage Monitor

A small PHP CLI collector plus a static HTML dashboard.

## Files

- `cron.php` — run this from cron.
- `index.html` — browser dashboard.
- `data/` — created automatically by the collector.

The collector writes:

```text
data/
├── index.json
├── 2026.json
└── 2026/
    ├── 08.json
    └── 08/
        ├── 23.json
        └── 23/
            └── 15-56.json
```

The JSON hierarchy is:

- `YYYY/MM/DD/HH-MM.json` — exact snapshot
- `YYYY/MM/DD.json` — daily rollup
- `YYYY/MM.json` — monthly rollup
- `YYYY.json` — yearly rollup
- `index.json` — available periods and snapshot locations

## Install

Create a directory that is web-accessible if you want to view the dashboard directly:

```bash
mkdir -p ~/public_html/stats.lewismoten.com
```

Copy these files there:

```text
cron.php
index.html
```

Protect the directory with authentication if it is reachable from the public internet.
The JSON contains file and directory names from your account.

Make the collector executable:

```bash
chmod 700 ~/public_html/stats.lewismoten.com/cron.php
```

Test it manually:

```bash
php ~/public_html/stats.lewismoten.com/cron.php
```

Then open:

```text
https://stats.lewismoten.com/
```

## Cron

Run once every day at 2:15 AM:

```cron
15 2 * * * /usr/local/bin/php /home/regaldra/public_html/stats.lewismoten.com/cron.php >> /home/regaldra/logs/stats.lewismoten.com.log 2>&1
```

Find the actual PHP CLI path first if needed:

```bash
which php
```

If it reports `/usr/bin/php`, use that instead.

The script is also safe to run more than once per day. Each run creates a snapshot named
with the local `HH-MM` time, while the daily/monthly/yearly rollups are regenerated.

## What is counted

A file is assigned to at most one category.

Directory matches take precedence:

- Trash: files under directories named `.trash` or `trash`
- Logs: files under directories named `log` or `logs`
- Temp: files under directories named `tmp`, `temp`, or `temporary`

Outside those directories:

- `*.log` is counted as Logs
- `*.tmp` and `*.temp` are counted as Temp

The monitor's own `data/` directory is excluded.

## Security

The dashboard is static, but its JSON data can expose:

- directory names
- file names
- disk capacity
- inode counts

Do not leave it publicly accessible unless that is intentional. Prefer password protection,
IP restrictions, VPN access, or another authenticated area.

## Configuration

Edit the `$config` array near the top of `cron.php` to change:

- scan root
- timezone
- number of top offenders
- category directory names
- category file extensions
- excluded paths
