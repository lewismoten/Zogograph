# Disk Usage Monitor

A PHP collector and a static dashboard that watch a home directory and tell you
before it fills up.

It answers three questions: **what is happening now**, **what is growing**, and
**where do I go to fix it** — and it emails you when something jumps, because a
monitor you have to remember to look at is a monitor that tells you afterwards.

## Files

- `cron.php` — the collector. Command line only; `.htaccess` denies it over HTTP.
- `setup.php` — the config wizard, in a browser or at a prompt.
- `health.php` — a diagnostic page that depends on nothing and works when the
  rest does not.
- `api.php` — the only thing the dashboard talks to.
- `index.html` / `index.css` / `index.js` — the dashboard.
- `lib/` — config, database, collection, alerting, auth, masking, actions.
- `data/` — the SQLite database. Created automatically, never served.

## What it records

Two kinds of reading, on two clocks, because they cost wildly different amounts.

**Vitals** — disk bytes, inodes, load average, memory, CPU, and network bytes
in and out. A handful of file reads, so a minute apart is reasonable. CPU,
memory and network come from `/proc`; on a host that does not expose it those
columns are simply empty and everything else still works.

CPU and network are stored as their raw cumulative counters as well as a
computed rate, because a rate only exists as a difference between two moments.
Keeping the counters means a gap in collection produces an honest average over
the gap instead of a wrong instant, and a counter that goes backwards (a
reboot) is discarded rather than reported as a spectacular negative.

**Scans** — walk the tree, size everything, record the biggest files, the
biggest directories, the fullest directories by file count, and the totals for
trash / logs / temp. Minutes of IO on a large account, so hourly at most.

## Install

```bash
mkdir -p ~/public_html/stats.example.com
# everything except data/ and lib/config.php
cp -r cron.php api.php setup.php index.html index.css index.js lib .htaccess \
      ~/public_html/stats.example.com/
```

Then open `setup.php` in a browser and fill the form in. It writes
`lib/config.php` for you, hashes the password and generates the mask secret.

The same wizard runs at a prompt if you prefer:

```bash
php setup.php --write
```

Either way, **delete `setup.php` once you are done**.

Take the first readings:

```bash
php cron.php --vitals
php cron.php --scan
```

Then open the site. `data/stats.db` is created on the first run.

## The wizard

`setup.php` asks the questions, overlays the answers on
`lib/config.sample.php`, and produces a finished config. Both front ends read
the same field definitions from `lib/configure.php`, so the browser and the
prompt cannot drift apart.

Who is allowed to write `lib/config.php`:

| state | what happens |
| --- | --- |
| the file is missing | anybody may write it — this is the install, and there is no password yet to ask for |
| the file exists | you must enter the password already in it |
| the file exists, sign-in was never set up | nobody — there is no secret to prove, so paste it yourself |

Anyone who cannot write still gets the finished text to paste into the file.
Having a file to edit is its own authorisation.

Entering the current password **unlocks** the form for fifteen minutes and
fills it with the settings the system is actually running — without that the
form starts from the sample's defaults, and adjusting one threshold would
quietly reset your scan root and alert address along with it. The unlock uses
its own short-lived cookie, unrelated to the dashboard's session.

Two things are never shown to somebody who has not entered the password: the
existing password hash, and the existing mask secret. Text generated for an
unauthorised visitor carries a **new** mask secret, and using it renames every
masked path on the dashboard. Unlocked, the existing secret is kept unless you
tick the box to rotate it.

Sizes can be written the way people say them — `5G`, `500M`, or a plain number
of bytes.

The install window is a real window: between uploading these files and
finishing setup, whoever reaches the page first chooses the password. Do it
straight away, then delete `setup.php`. If you would rather keep the file,
`.htaccess` has a commented-out block that denies it.

## Cron

The **Schedule** panel on the dashboard writes these lines for you once you are
signed in — pick how often each job runs and press the button. See
[Scheduling from the dashboard](#scheduling-from-the-dashboard) for what it will
and will not touch.

By hand it is two lines, because the two jobs run on different clocks:

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

## Scheduling from the dashboard

A crontab line is a command the machine runs as you, on a timer, so letting a
browser put text into one is remote code execution with extra steps. This does
not do that. **No field on the page becomes part of a command.**

- The schedule comes from a fixed list of presets; anything not on the list is
  refused.
- The PHP binary is picked from binaries actually found on disk. This matters
  beyond safety: the PHP the web server runs is often not the CLI one, and a
  cron job running the wrong PHP fails in a way that is tedious to diagnose.
- The minute and hour are cast to integers and clamped to 0–59 and 0–23.
- The command itself is assembled from constants, with both paths quoted.

**The rest of your crontab is left alone.** The dashboard's lines live between
two marker comments; everything outside them is copied through in order.
Removing the schedule takes the markers with it, so nothing is left behind.

The crontab is written by handing `crontab` a temporary file rather than piping
into it — a pipe means building a shell command that contains the content, and
the content is the one thing that must never be inside a command string.

If `shell_exec` is disabled or the host has no `crontab` (both common on managed
hosting), the panel says so and you add the lines through your control panel
instead. It will never write a fresh crontab because it could not read the
existing one.

## Reading log files

Signed in, a log file in the offenders table gets a **View** button: the last N
lines, paging backwards, with search.

The reader never reads the file. It seeks to the end and walks backwards in
blocks, so showing the last two hundred lines of a ten gigabyte `error_log`
costs the same as for a ten kilobyte one — measured at **65 KB read, 0.7 ms,
2 MB of memory** against a 126 MB file. That is the whole reason it exists: the
file that fills a disk is exactly the file you cannot open.

Search cannot be free in the same way, since a match may be anywhere, so it is
bounded instead — it scans backwards through 64 MB and **says when it stopped
early** rather than quietly reporting "no matches" for a file it only partly
looked at.

Only categories in `viewable_categories` (logs and errors by default) can be
read. That is deliberately a different list from `deletable_categories`: being
allowed to look at a file and being allowed to remove it are different
permissions, and the things worth reading are not the things worth deleting.

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

**Send a test email** on the Schedule panel sends the current status report to
the configured address — the same report a real alert carries, built by the same
function, because a test that exercises a different code path tells you nothing
about the one that matters. It always goes to the address in the config and
never to one in the request: a monitor that emails its file listing to whoever
asks is an open relay with a disk inventory attached.

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

## Reading the charts

**Room left** puts disk space and inodes together, because they are the same
question: either can run out on its own, and a disk with free gigabytes and no
inodes still refuses to create a file. In **Absolute** they cannot share a scale
— bytes and a count have nothing in common — so inodes move to the right-hand
axis and get a dashed line. In **Percent free** they share one 0–100% axis and
can be read against each other directly. The **Show** control at the top
switches between the two.

Absolute is the mode that shows a slope; percent is the mode that shows how
close to the edge you are. A nearly-full disk looks flat in percent and
obviously falling in bytes, which is why both exist.

**Load average is not a percentage.** It is how many processes wanted to run at
once, so it only means something against the core count: 4.00 is half busy on
eight cores and twice oversubscribed on two. The card shows it as a share of the
cores it has, and the collector records the core count alongside it.

Axis ticks land on round numbers — 1, 2 or 5 times a power of ten — and carry
enough decimals for neighbouring labels to differ. The unit is chosen by how big
the numbers are and the decimals by how far apart they are, which are two
different questions: 72 GiB of space moving by 50 MiB an hour reads as
`71.94 GiB`, not as `73671 MiB`.

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

Problems go to `data/error.log` rather than a table, so that a database that
cannot be opened is still able to report why.

Reading a log and editing the crontab do not write to the database. Deleting
does, and always has, including the refusals.

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

- Errors: any file actually **named** `error_log`, `php_errorlog` or `error.log`
- Trash: under a directory named `.trash` or `trash`
- Logs: under `log` or `logs`, or a `*.log` anywhere
- Temp: under `tmp`, `temp` or `temporary`, or a `*.tmp` / `*.temp` anywhere

Whole filenames (`category_files`) win over both directory and extension rules,
and that ordering is the point of them. Apache and cPanel drop an `error_log`
into every directory that has ever thrown a warning, and any one of them can
quietly reach ten gigabytes. Counted as logs they vanish into a total; counted
on their own you can see them, and the per-path history says which one is
growing.

The category list is derived from the rules, so adding one to
`category_dirs`, `category_files` or `category_extensions` makes it appear in
the scan totals, the chart, the offenders filter and the wizard's deletable
list without touching any of them.

Only the part of the path **below `scan_root`** is examined. Whatever the
account happens to live under is not the user's doing — a home directory at
`/var/tmp/sites/example` would otherwise have every file in it classed as
temporary, and `Empty temp` would offer to delete the lot.

The monitor's own `data/` directory is excluded, and symlinks are never
followed.

## When something goes wrong

Open **`health.php`**. It requires nothing, includes nothing and never touches
the database or the config, so it runs on an install that is otherwise entirely
broken — which is the only time it is worth having. It checks that every file is
present and actually parses (a truncated upload looks identical to a healthy
file until PHP compiles it), that PHP is new enough, that SQLite is there, and
that `data/` is writable. It reports states and never paths, because it answers
to anyone who can reach it.

If everything there is green, the fault is at runtime. Every failed request now
returns a **reference** instead of a blank page:

```json
{"error":"Something went wrong on the server.","reference":"c5767186"}
```

Find that reference in `data/error.log`, or on the dashboard under **Recent
problems** once you are signed in. The message itself is never sent to the
browser — it names file paths and sometimes SQL, and the API answers to people
who are not signed in.

### Why there is no error_log to read

Because many hosts do not configure one. `health.php` says whether yours has,
and this project writes its own to `data/error.log` regardless, at a known path,
denied by `data/.htaccess`.

The boundary is installed before anything that can fail, which matters more than
it sounds: the previous version wrapped the request in a try/catch, and a fatal
during `require` happens before the try block exists. What escaped was either a
blank 500 or — with `display_errors` on — HTML warnings printed into the middle
of a JSON body with a 200 status on top. Three separate handlers now cover
warnings, uncaught exceptions and fatals, and all three end in a JSON body with
the right status code.

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
