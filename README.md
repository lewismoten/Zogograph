# Disk Usage Monitor

A PHP collector and a static dashboard that watch a home directory and tell you
before it fills up.

It answers three questions: **what is happening now**, **what is growing**, and
**where do I go to fix it** — and it emails you when something jumps, because a
monitor you have to remember to look at is a monitor that tells you afterwards.

## Files

- `cron.php` — the collector. Command line only; `.htaccess` denies it over HTTP.
- `api.php` — the only thing the dashboard talks to.
- `index.html` / `index.css` / `index.js` — the dashboard.
- `lib/` — config, database, collection, alerting, auth, masking, actions.
- `data/` — the SQLite database. Created automatically, never served.

## What it records

Two kinds of reading, on two clocks, because they cost wildly different amounts.

**Vitals** — disk bytes, inodes, load average, memory, CPU. A handful of file
reads, so a minute apart is reasonable. CPU and memory come from `/proc`; on a
host that does not expose it those columns are simply empty and everything else
still works.

**Scans** — walk the tree, size everything, record the biggest files, the
biggest directories, the fullest directories by file count, and the totals for
trash / logs / temp. Minutes of IO on a large account, so hourly at most.

## Install

```bash
mkdir -p ~/public_html/stats.example.com
# everything except data/ and lib/config.php
cp -r cron.php api.php index.html index.css index.js lib .htaccess \
      ~/public_html/stats.example.com/
```

Create the config:

```bash
cd ~/public_html/stats.example.com
cp lib/config.sample.php lib/config.php
php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'   # password_hash
php -r 'echo bin2hex(random_bytes(32)), "\n";'                          # mask_secret
```

Edit `lib/config.php`: set `scan_root`, `timezone`, the two secrets above, and
the alert thresholds and recipient.

Take the first readings:

```bash
php cron.php --vitals
php cron.php --scan
```

Then open the site. `data/stats.db` is created on the first run.

## Cron

Two lines, because the two jobs run on different clocks:

```cron
# Vitals every minute, and evaluate the alert rules with them.
* * * * * /usr/local/bin/php ~/public_html/stats.example.com/cron.php --vitals --alerts --quiet

# A full scan every hour, then alerts again now the per-path numbers have moved.
7 * * * * /usr/local/bin/php ~/public_html/stats.example.com/cron.php --scan --alerts --prune --quiet
```

`--quiet` says nothing unless something goes wrong, so cron only mails you about
real failures. Run `which php` to find the CLI binary; it is often not the one
the web server uses.

Vitals every minute is roughly half a megabyte of database a week before
pruning. `--prune` keeps the last two weeks at full resolution, thins everything
older to one reading an hour, and drops anything past about a year.

## Alerts

Two kinds of rule, both configured in `lib/config.php`.

**Thresholds** catch the state you must not be in: free space below a floor in
bytes or percent, inodes nearly exhausted.

**Jumps** catch what is going to put you there, and are the ones worth having: a
category or a single path growing by more than a set amount between scans, or a
directory gaining thousands of files. A threshold tells you when it is nearly
too late; a jump tells you which log file to go and look at.

There is also a **projection**: free space is fitted over the last day of
readings, and if the trend runs out within `projected_full_hours` you hear about
it while there is still time to act.

Everything raised in one run becomes one email, and each rule is rate limited by
`cooldown_seconds`. A disk filling up trips several rules at once, and five
separate messages about one event is how people learn to ignore them.

A directory is not blamed for its child's growth: one runaway log would
otherwise alert for itself, for its directory, and for the home directory.

## Signed in and signed out

The dashboard is readable without a password. Sizes, counts, growth, trends and
alerts are all visible — that is what makes it worth leaving open on a second
monitor.

What signing in adds is **the names**, and the ability to **delete**.

Signed out, every path is replaced by a stable pseudonym that keeps its shape —
`.log file C24823 (depth 9)` — so you can still reason about what is happening
without any filename being disclosed. The masking happens on the server and the
real string never enters the response, so there is nothing in the page to
bypass. The pseudonym is a keyed hash: without the key, somebody who suspects a
path exists cannot confirm it by hashing their guess.

If you would rather nobody sees it at all, `.htaccess` has a commented-out basic
auth block. The app's own login still works behind it.

## Deleting

Only from a signed-in session, only over POST, only with a CSRF token, and only
for paths that survive every one of these:

- resolve, via `realpath`, to somewhere inside `scan_root`
- fall in a category listed in `deletable_categories` (trash and temp by default)
- are not a symlink, and are not reached through one
- are not the monitor's own database

Everything is decided against the *resolved* path, because
`trash/../../../etc/passwd` is inside the scan root right up until you resolve
it. Every attempt is written to the `actions` table **including the refusals** —
a run of refusals is what an attempt to escape the scan root looks like, and
that table is the only place it would show.

`Empty trash` and `Empty temp` remove the files in a category and leave the
directories alone.

## Live updates

The dashboard refreshes every 30 seconds. The **Live** button switches it to
every 10 seconds and makes each poll take a *fresh* reading rather than
re-reading the last one — the collector's own clock is a minute at best, and the
moment you want ten-second resolution is the moment something is going wrong.
Polling stops while the tab is hidden.

## Schema

One SQLite file, normalised on `paths`:

| table | what it holds |
| --- | --- |
| `paths` | every path once: id, string, file or dir, category |
| `vitals` | one row per cheap reading |
| `scans` | one row per walk of the tree |
| `scan_totals` | trash / logs / temp bytes and file counts per scan |
| `path_sizes` | one path's size and file count at one scan |
| `alerts` | what was raised, and whether the email went |
| `actions` | every delete attempted, and what happened |

A path is written once and referred to by id everywhere else. A home directory
has the same few thousand interesting paths in it every hour, so stored inline
the strings would be most of the database within a month — and `path_sizes`
keyed by `path_id` makes "how has this one file grown" an index range scan
rather than a walk of every sample.

Ad-hoc questions are just SQL:

```bash
sqlite3 data/stats.db "
  SELECT p.path, MAX(ps.bytes) - MIN(ps.bytes) AS grew
  FROM path_sizes ps JOIN paths p ON p.id = ps.path_id
  GROUP BY p.id ORDER BY grew DESC LIMIT 10;"
```

## What is counted

A file is assigned to at most one category, so the totals mean something next to
each other. Directory names win over extensions.

- Trash: under a directory named `.trash` or `trash`
- Logs: under `log` or `logs`, or a `*.log` anywhere
- Temp: under `tmp`, `temp` or `temporary`, or a `*.tmp` / `*.temp` anywhere

Only the part of the path **below `scan_root`** is examined. Whatever the
account happens to live under is not the user's doing — a home directory at
`/var/tmp/sites/example` would otherwise have every file in it classed as
temporary, and `Empty temp` would offer to delete the lot.

The monitor's own `data/` directory is excluded, and symlinks are never
followed.

## Security

- `lib/config.php` holds the password hash and the mask secret. It is
  gitignored, and `lib/.htaccess` denies the whole directory.
- `data/.htaccess` denies the database. Serving it would hand over every path,
  every size and the password hash in one request.
- Sessions are `HttpOnly`, `SameSite=Strict`, `Secure` over HTTPS, and time out
  after `session_lifetime`.
- `.htaccess` redirects to HTTPS and sets a content security policy. A login
  sends a password; never over plain HTTP.
- Even masked, the sizes describe a private machine, so nothing here is
  cacheable by a shared proxy.

## Configuration

Everything lives in the array returned by `lib/config.php`: scan root, timezone,
database path, the two secrets, session lifetime, all the alert thresholds and
the recipient, the category rules, the excluded paths, how many offenders to
keep per scan, and which categories may be deleted.

## Upgrading from the JSON version

The old collector wrote a tree of JSON files under `data/`. Nothing reads them
any more; the SQLite database is created alongside and starts from empty. Delete
`data/index.json` and the year directories once you are happy the new collector
is running.
