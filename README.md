# Disk Usage Monitor

A PHP collector and a static dashboard that watch a home directory and tell you
before it fills up.

It answers three questions: **what is happening now**, **what is growing**, and
**where do I go to fix it** — and it emails you when something jumps, because a
monitor you have to remember to look at is a monitor that tells you afterwards.

## Files

- `cron.php` — the account collector. Command line only; `.htaccess` denies it
  over HTTP.
- `host-cron.php` — the host collector, run by root out of WHM's crontab. Sees
  what CageFS hides from the account. Command line only.
- `setup.php` — the config wizard, in a browser or at a prompt.
- `health.php` — a diagnostic page that depends on nothing and works when the
  rest does not.
- `api.php` — the only thing the dashboard talks to.
- `index.html` / `index.css` / `index.js` — the dashboard.
- `lib/` — config, database, collection, alerting, auth, masking, actions,
  log tailing, scheduling, processes, symlinks, and signalling.
- `data/` — the SQLite databases: `stats.db` from the account collector and
  `host.db` from the root one. Created automatically, never served.

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
cp -r cron.php host-cron.php api.php setup.php index.html index.css index.js \
      lib .htaccess ~/public_html/stats.example.com/
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

**If you have root on the machine**, there is a second collector. The wizard asks
about it — *Also collect host-wide readings* — and prints the crontab line when
it finishes, with your real paths already filled in. It has to go in **root's**
crontab rather than your account's, so it is the one step the wizard cannot do
for you:

```cron
*/5 * * * * /usr/local/bin/php ~/public_html/stats.example.com/host-cron.php --collect --prune --quiet
```

Without it the dashboard's *whole machine* panel shows that line and nothing
else. See [The host half](#the-host-half) for what it collects and why it needs
its own database. On CloudLinux it is the only way to find out what is using the
machine, because CageFS hides every other account's processes from yours.

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

There is a third line, and it belongs to **root** rather than to your account:

```cron
# Host-wide readings: other users' memory, kernel slab, root-owned logs.
*/5 * * * * /usr/local/bin/php ~/public_html/stats.example.com/host-cron.php --collect --prune --quiet
```

Five minutes rather than one: a reading walks every process in `/proc`, which is
not free on a busy box, and nothing it watches moves faster than that. The
dashboard's Schedule panel cannot write this one — it edits your account's
crontab, and this job is root's. Add it with `crontab -e` as root, or through
WHM's Terminal.

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

**Schedules are written as explicit lists, not as `4/6`.** The `start/step` form
is a Vixie extension: cronie accepts it, and cPanel's cron editor and several
other panels reject it — so a schedule written that way installs cleanly over
ssh and is refused through the interface most people on shared hosting actually
use. `0,4,8,12,16,20` is understood by every cron ever written, and reads
without knowing any syntax.

Every 4 hours from 4:07am gives:

```cron
7 0,4,8,12,16,20 * * * '/path/php' '/path/cron.php' --scan --alerts --prune --quiet
```

Intervals available: vitals every 1, 2, 5, 10, 15 or 30 minutes, or hourly;
scans every 1, 2, 3, 4, 6, 8 or 12 hours, or once a day.

**The minute and hour are when it first runs**, and a repeating schedule carries
on from there. Any value is accepted — a start outside one turn of the cycle
just names the same set from a different point, and **the set always contains
the time you asked for**. Asking for every 4 hours from 16 gives the same six
hours as from 4, both including 16:07 and 04:07. An earlier version clamped the
start into the cycle instead, which quietly turned "every 4 hours from 4am" into
a job that ran at 3am.

Fields a schedule ignores are greyed out: `every minute` uses neither, and
`hourly` uses only the minute.

### Which clock the times are in

Three clocks are in play and it is reasonable to assume they are one:

- **the browser's** — where you are reading this
- **PHP's `date.timezone`** — what the dashboard prints timestamps in
- **the system zone** — what the cron daemon reads schedule lines in

They need not agree. On the machine this was written on, PHP said `UTC` while
the system said `America/New_York`, so a line written for "2am" would have fired
four hours from when the config implied.

So the dashboard finds the zone **cron** will use — from `/etc/localtime`, then
`/etc/timezone`, then `TZ`, and a `CRON_TZ=` line in the crontab overrides all
of them — and converts the times into **your browser's** zone before showing
them:

> scan: 5:37 AM, 11:37 AM, 5:37 PM, 11:37 PM — 4× a day
> Times shown in yours (Asia/Kolkata); the crontab is read in the server's
> (America/New_York).

Up to four times are listed with a count of how many there are altogether, so a
six-hourly job shows all four and an hourly one shows the next four of twenty-
four. Anything more frequent than hourly is described rather than listed —
*"every 5 minutes, next at 4:30 AM"* — because a list of consecutive minutes
tells you nothing.

The conversion goes through the IANA zone name rather than a fixed offset, so
daylight saving and half-hour zones both come out right: the browser's own
timezone database does the work.

### Scan now

The **Scan now** button beside the offenders table runs one immediately. It is
spawned detached with its output discarded, because a scan is minutes of disk
work and running it inside the request would hit `max_execution_time` and leave
a half-written scan row behind. The dashboard polls every five seconds while one
is in flight and says so on the button.

A second scan is refused while one is running — two walks of the same tree
double the IO on a machine somebody is already worried about, and produce two
scan rows seconds apart that make every growth figure meaningless. A row left
unfinished by a killed process stops counting after an hour, so a crash cannot
block scanning for ever.

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

## What is using the machine

Signed in, a panel lists the running processes by CPU or by memory, and below it
the moments the machine was last struggling.

**Processes are grouped by name.** Eighty Chrome helpers using four gigabytes
between them is one fact; a row apiece is eighty rows, none of which look
alarming. The totals are sums, and the pid and command line kept are the
heaviest single process of the group — the one worth going to look at.

**How long it has been running** comes from `ps -eo etime`, which every platform
writes as `[[DD-]HH:]MM:SS` — readable and useless for arithmetic, so it is
parsed into seconds. (`etimes`, the seconds form, is procps only and absent on
BSD.) A group shows the age of its *oldest* member, and the newest beside it
when the two diverge sharply: a pool up for forty days whose newest member is
nine seconds old is restarting as fast as it dies, and that is a different fault
from one that has simply been running a long time. Neither number alone says so.
An age that cannot be read shows as `—` rather than as zero, which would read as
"just started" — the opposite of "not known", and exactly the sort of thing
somebody would act on.

**The recorded half is the point.** A live list is `top` with extra steps: you
have to be watching. This project exists to answer questions after the fact, so
whenever a reading crosses `record_when_cpu_above` or
`record_when_memory_above` the top few processes are written down. Something
pinned the CPU at four in the morning; in the morning there is an answer.

It only records while the machine is busy. A row per process per minute would be
a table growing faster than anything it could warn you about, and the three
minutes the CPU was pinned is small and is the only part anybody reads. `--prune`
drops old rows with everything else.

Signed-in only, like the log viewer, and for the same reason: a command line is
not a size. It carries paths, arguments, and often a password somebody put on a
command line despite being told not to. **Those are redacted** — `-pSECRET`,
`--token=…`, `auth=…` and friends become `[hidden]` before the row is stored or
sent, so this tool does not become a second place the password leaks from.

One wrinkle: names come from the command line's first word, because `ps`'s
`comm` column is a path that can contain spaces on BSD and so cannot be split on
reliably. An executable whose *path* contains a space therefore shows only the
part before it — vanishingly rare on a server, common in macOS application
bundles, so it looks worse on a laptop than where it will run.

## Stopping something

Signed in, every row of the live list carries **Ask** and **Force**, and they
are not two strengths of one button:

| | signal | what it means |
|---|---|---|
| Ask | SIGTERM | the process is told to go, and closes its files first |
| Force | SIGKILL | the kernel removes it; it cannot catch, decline or tidy up |

Force is the one that loses data, so it is styled as damage and says so in its
own confirmation. Ask reports honestly when a process ignored it — the normal
case for anything with a signal handler, and the reason both buttons exist.

**Grouped or one at a time.** The list is grouped by name by default, and a
group's buttons stop the whole group. Untick *Group by name* and every process
becomes its own row with its own buttons, which is how you stop one worker of a
pool and leave the rest running.

**Buttons that cannot work are dead before you press them.** Root's processes
and the account's own plumbing are drawn disabled with the reason as the
tooltip. A live-looking control that always answers with a refusal teaches
people to distrust the ones that work.

### Stopping by command line

`pkill -f`, for when the name is not specific enough to aim with — twelve
`webalizer` processes and one of them is chewing through one particular log:

    webalizer/bin/english.*git\.lewismoten\.com-ssl_log\.bkup

Two deliberate differences from the real `pkill`:

- **The pattern never reaches a shell.** It is matched in PHP against a process
  list this code read itself. Passing a string from a browser into
  `pkill -f '...'` is one metacharacter away from remote code execution, and no
  amount of quoting is worth betting the account on.
- **It previews first.** `pkill -9 -f` fails in exactly one way — the pattern was
  broader than the person writing it believed — and it fails silently, after the
  fact, on processes that are already gone. *Show matches* turns that into a
  decision. Protected matches appear struck through rather than hidden: a
  pattern that reaches `sshd` is a pattern about to be run again with one more
  character on the end, and that is worth seeing now.

### What it refuses, always

- **pid 1**, which is the machine.
- **Anything in the dashboard's own ancestry** — the web server, the shell above
  it, everything in between. Found by walking the parent chain rather than by
  matching names, so it holds whatever the server is called. Killing one of
  these would take down the request answering the click.
- **Other users' processes.**
- **Anything in `processes.protect`** — sshd, the shells, the mail and database
  daemons. Killing `sshd` from a web page locks you out of the machine you are
  trying to fix. Override that list freely; the three above are not opinions and
  cannot be overridden.
- **More than `processes.max_group` at once** (40), and any pattern matching over
  half of everything running. A regex one character broader than intended is the
  classic way to empty an account, and it always looks correct until it runs.

Set `processes.may_stop` to `false` to remove the capability entirely — worth
doing if the dashboard is reachable from anywhere a stolen session should not
have a kill switch.

### Why a pid is not a path

Deleting a file names the file: `/home/x/big.log` still means that file a minute
later. **A pid is a recycled number.** The browser shows one it read seconds ago,
and between the reading and the click that process can exit and the kernel can
hand its number to something else. Signalling a pid because of what a stale
table says is sitting on it is how a monitoring tool kills a database.

So stopping *one* process sends what the screen believed it was — the name, and
how long it had been running — and the server re-reads the process and refuses
if either has changed. A recycled pid always fails the age test, because the new
occupant is younger than the reading:

    pid 8214 has only been running 4 seconds, less than the 11.6 days on
    screen. The number was reused; refresh and look again.

Stopping a *group* or a *pattern* sends no pids at all — the name or the regex is
resolved to pids at the instant they are signalled, so there is no window for
them to go stale in.

Every attempt is written to the actions table, refusals included. A run of
refusals is what somebody probing the guards looks like, and it is the only
place that would ever show up.

## The host half

Everything above runs as the cPanel account. On a CloudLinux box that account is
inside CageFS, which gives it **its own `/proc` containing only its own
processes** — so the account collector can watch memory disappear and have
nothing to blame. The process holding two gigabytes belongs to another user and,
from inside the cage, does not exist. Neither do the kernel's slab caches,
another account's swap, or a 763 MB Apache log under `/etc` that costs exactly
as much disk as one in the home directory.

`host-cron.php` is the other half. It runs as root out of WHM's crontab:

    */5 * * * * /usr/local/bin/php /home/USER/public_html/stats/host-cron.php --collect --prune --quiet

Every five minutes rather than every minute: a reading walks every process in
`/proc`, which is not free on a busy box. The setup wizard prints this line with
your real paths in it, and so does the dashboard panel until the first reading
arrives — it is the one step neither can perform, because it needs a crontab
belonging to a user the dashboard is not running as.

It is refused over HTTP twice — by `.htaccess` and by the file itself, which
exits unless `PHP_SAPI` is `cli`. A root-privileged entry point reachable from a
browser is not something to leave to one layer. Run as a non-root user it still
works and says so, which matters because "I ran it and it found nothing
interesting" is otherwise hard to explain.

**A second database.** `data/host.db`, not the account's `stats.db`. Two writers
of different privilege sharing one write-ahead log is a trap: the first time
root creates a WAL the account cannot write, collection stops with a lock error
nobody thinks to look for. Separate files means the only thing they share is a
directory. Root is the single writer; the dashboard only ever reads, and never
migrates — a schema half-created by the web user in a file root expects to own
has no clean way out.

Set `host.owner` to the account the dashboard runs as. Root creates the file, so
without that only root can read it and the panel stays empty with no clue why.
It is set rather than guessed because guessing wrong gives either an unreadable
file or a world-readable one, and the second is worse: this database lists every
process on the machine with its command line. Ownership is re-applied on every
run, because SQLite recreates the `-wal` and `-shm` files as root and a WAL
database cannot be read without them.

The dashboard's schedule editor cannot manage this job — it edits the account's
crontab, and this one belongs to root.

### Why sum-of-RSS is a wild goose chase

The panel leads with this because it is the mistake the numbers invite. Adding
up the RSS column across processes gives a number bigger than the machine is
using — on the box this was written for, 4.96 GiB of RSS against 3.38 GiB
actually in use. Nothing is missing. **RSS counts every shared page once per
process**, so a hundred Apache workers each "own" the same copy of libc, and the
difference is an artefact of the counting.

**PSS** — proportional set size — is the figure that adds up, and no `ps` can
report it. It counts a process's **private pages in full** and **divides each
shared page** among the processes mapping it: one copy of libc shared by a
hundred workers contributes a hundredth to each, while everything that process
alone holds counts whole.

That distinction is worth stating carefully, because "proportional" invites the
idea that PSS is a measure of *sharing*. It is not. It is the effective physical
memory **attributable** to a process: shared pages are apportioned so that
summing PSS across every process gives the memory those processes actually
occupy, counted once. That is the whole reason it can be summed at all.

Attributable is not the same as reclaimable. Killing one of ten processes
sharing a page frees nothing of it — the page stays, and the other nine each see
their PSS *rise* as it is divided nine ways instead of ten. What a single exit
returns is only that process's private pages, which is a different figure again
(USS, which this does not collect). PSS answers "whose memory is this", not
"what would I get back".

It comes from `/proc/<pid>/smaps_rollup`, which the kernel builds by walking the
page tables: cheap per process, but there can be a thousand of them, so it is
read for the biggest sixty by RSS and the panel says so rather than presenting a
partial sum as a whole-machine total.

### Two different things called "shared"

Worth separating, because the word does double duty and the two quantities are
measured differently, from different files, for different purposes:

| | what it is | where it comes from |
|---|---|---|
| **Kernel shared memory / tmpfs** | tmpfs pages and System V segments — memory the kernel holds on behalf of no particular process | `Shmem` in `/proc/meminfo` |
| **Proportional (PSS)** | a process's private pages in full, plus its share of each page it maps alongside others | `/proc/<pid>/smaps_rollup` |

Neither is a subtotal of the other, and adding them together means nothing. They
are not quite disjoint either — a tmpfs page that some process maps is counted
in both, and one that nothing maps is counted only in `Shmem` — which is exactly
why the two need separate names rather than a shared word and a footnote. A
column headed "Shared" beside a card headed "Shared / tmpfs" invites a
comparison that does not hold.

### Services over time

The panel's most useful table, and the reason the host collector is worth
running on a schedule rather than once.

"Forgejo is using 1.8 GiB" cannot be acted on. It might be a leak three days old
or a working set that has been 1.8 GiB since the day it was installed, and those
call for opposite responses. The same fact with history attached answers itself:

| Service | Processes | Now | 24h ago | Change 24h | 7d ago | Change 7d |
|---|---|---|---|---|---|---|
| forgejo | 1 | 1.78 GiB | 1.73 GiB | +46.8 MiB | 1.52 GiB | **+263 MiB** |
| mariadbd | 1 | 1.07 GiB | 1.09 GiB | −13.6 MiB | 1.06 GiB | +19.2 MiB |
| httpd | 120 | 310 MiB | 307 MiB | +3.14 MiB | 293 MiB | +16.9 MiB |

Forgejo is leaking. MariaDB is not, and is nearly as large. Neither is visible
from a single reading.

**Grouped by executable, not by pid.** A pid is gone the moment a daemon
restarts, and a series keyed on one resets to zero every time something is
upgraded — precisely when comparing before and after matters most. The service
name survives that.

**Grouped first, then ranked.** The order is easy to get backwards and the
consequence is invisible. Taking the biggest *processes* and grouping whatever
survives loses every service made of many small workers: a hundred and twenty
httpd children at 20 MiB each is 2.4 GiB that never enters a top-25-processes
list, while one Forgejo at 1.8 GiB does. Ranking the groups puts them side by
side at their real weight.

**Compared on PSS.** Summing resident memory across a hundred workers counts one
shared copy of the runtime a hundred times, so the total moves with the worker
count rather than the memory — a service that forks ten more children appears to
have grown by a gigabyte when nothing was allocated.

**A missing comparison is blank, not zero.** If the collector was off, or the
service is new, the change column shows `—`. A zero would claim the service had
been measured and found stable, which on a fresh install would be a lie about
every row. The lookup also refuses a reading more than twelve hours from the
moment it wants: a collector switched off for a week would otherwise answer
"7 days ago" with a reading from three weeks back and report the gap as growth.

**Its own retention.** `host_services` does not hang off `host_samples` with a
cascade, unlike every other detail table. Those carry a process list and a slab
table and are pruned at a fortnight to keep the file small; a service row is a
name and five integers — roughly a megabyte a month — and its entire value is
being *older* than that. `host.keep_service_days` defaults to 90, so "and what
was it in June?" stays answerable after the detail behind it has gone.

### Swap is a state, not an event

The panel shows swap prominently and refuses to alarm about it, because the
number on its own means very little:

    Swap
    696 MiB / 1024 MiB
    68% allocated

**Linux does not pull pages back out of swap merely because RAM became free
again.** A page evicted during a busy hour last Tuesday stays evicted until
something touches it. So the figure is a high-water mark with no expiry, not a
measure of present distress — and a machine reporting two thirds of its swap
allocated is very often perfectly healthy. Colouring that red would train you to
ignore the colour, so the card stays neutral and says what it is:

> Old or cold pages left over from an earlier squeeze… a high-water mark, not a
> measure of now. No current shortage.

What *is* worth acting on is two things happening together: **swap climbing while
available memory falls.** Either alone is ordinary. Swap rising while
MemAvailable holds steady is the kernel moving cold pages out of the way, which
is it doing its job. MemAvailable falling while swap holds steady is cache being
claimed, also its job. Both at once means the machine has run out of cheap
options and is paying for memory with disk. Only then does the card turn amber:

> Swap is climbing (168 MiB/h) while available memory falls (1.23 GiB/h). Those
> two together are real pressure rather than leftover cold pages.

The estimate is the same shape as the disk projection — median of the steps,
gated on both halves of the window agreeing — because the failure mode is the
same: one burst of swapping during a nightly backup would otherwise read as a
trend for as long as it stayed in the window. There is one addition. A *direction*
is not a trend until it is big enough to matter: available memory wanders by tens
of megabytes on any busy machine, and on sign alone a drift of 27 MiB an hour
across 7.5 GiB counts as falling and pairs with any swap growth to report
pressure that is not there. Half a percent of RAM per hour is the bar.

The **Available** card beside it is `MemAvailable`, the kernel's own estimate of
what a new process could actually get. That rather than `MemFree`, which counts
reclaimable cache as used and makes every healthy machine look full.

### What it collects

- **The memory breakdown** — in use, available, reclaimable cache, slab, kernel
  shared memory, swap, and **other kernel/system memory**: `used` minus
  everything nameable (anonymous pages, swap-cached, unreclaimable slab, page
  tables, kernel stacks, per-cpu areas).

  That last one is shown deliberately rather than hidden or forced to zero,
  because **Linux does not reconcile exactly from a handful of `/proc` summary
  lines and never claimed to.** Vmalloc regions, DMA and driver buffers,
  hugepages and per-cpu areas are real allocations those lines do not break out,
  and the counters are not all sampled at the same instant either. A few hundred
  megabytes there is this machine's kernel, not a fault.

  It is called *other kernel/system memory* on the dashboard and `unaccounted`
  in the database. The internal name is the honest description of the
  arithmetic; the displayed one avoids implying a leak or a broken sum, which is
  what people read into "unaccounted". What is worth watching is the figure
  *growing* across days while nothing else does — then the memory has gone
  somewhere no per-process view will ever show, and knowing *that* is worth more
  than another process list.
- **Memory per user**, which is the column CageFS hides and usually the answer.
- **The biggest processes on the host**, with Pss, swap, threads and age.
- **Kernel slab caches**, sized the way `slabtop` does it — slabs × pages per
  slab × page size, not objects × object size. The two disagree, because a slab
  is whole pages and objects rarely divide evenly into them.
- **Shared memory** — System V segments and `/dev/shm`. A segment whose creator
  has exited still holds RAM, which is a leak with no owner to point at.
- **Files only root can stat**, from the `host.watch` globs. Defaults cover
  Apache's domlogs.
- **CloudLinux LVE faults**, where `lveinfo` exists. A process killed for hitting
  a limit leaves no other trace; a rising fault count is the evidence.

Everything is read from `/proc` directly rather than by shelling out to `ps`,
`free` or `slabtop` — those are not installed everywhere, their output differs
between procps and BSD userland in ways that are easy to miss, and none of them
can report Pss. It also means every parser takes the root of the tree it reads
as an argument, so they can be tested against captured fixtures rather than only
on the machine they are aimed at.

## Disk I/O

Collected on both sides, because the two see genuinely different things and
neither answer is complete alone.

### What the account can see

**The device**, from `/proc/diskstats`. Inside CageFS an account can usually
still read it, but what it reports is the *host's* disk — every account's
traffic together. It says the disk is busy; it never says you are the reason.

**Its own traffic**, summed from `/proc/<pid>/io` across every process the user
can see. Inside a cage those are its own and nothing else, which makes the sum
exactly "what this account is doing to the disk" — the number an account can act
on. Both are drawn on one chart, and the gap between the pair is the point:

    This account, read     ▁▁▃▁▁     0.4–3.5 MiB/s
    Device read            ▁▆█▆▁     2–28 MiB/s
    Device busy % (right)  ▁▆█▆▁     6–84%

`read_bytes`/`write_bytes` rather than `rchar`/`wchar`. The latter count every
byte handed to `read()` and `write()`, including the ones served from page cache
that never touched a disk — a process looping over a cached file reports
gigabytes of I/O while the disk sits idle. Writes have `cancelled_write_bytes`
subtracted, which is the accounting for dirty pages discarded before flush
(a file written and then deleted), so "wrote 4 GB" means four gigabytes actually
went to the platter.

### What only root can see

**Per device**: throughput, utilisation, queue depth and requests in flight.

**Stacked devices are listed but never summed.** LVM and software RAID sit on
top of real hardware: a write to `dm-0` is remapped onto `sda` and *both*
counters advance for the one write. Adding them together reports twice the
traffic that happened — the same mistake as counting a hard link twice, one
layer down. They still get a row, because "which volume is busy" is a real
question; they are just not additional bytes.

**Per service**: read and write rates alongside the memory columns, so the table
answers "what is writing" and "what is growing" in one place. A service's
counters restart when it does, so a negative difference is read as a restart and
reported as unknown rather than as a large negative rate.

**Pressure**, from `/proc/pressure/io` where the kernel has `CONFIG_PSI`.

On **RHEL 9 and CloudLinux 9 this is off by default**: the kernel is built with
`CONFIG_PSI=y` *and* `CONFIG_PSI_DEFAULT_DISABLED=y`, so a 5.14 kernel — well
past the 4.20 that introduced the feature — still has no `/proc/pressure`. Turn
it on with

    grubby --update-kernel=ALL --args="psi=1"

and reboot. Everything else about disk I/O works without it; only the stall
percentages are missing.

The reason is worked out by the **host collector**, not the dashboard, because
`/boot/config-$(uname -r)` is the file that settles it and the account cannot
read it. Note that a filtered `/proc` and a disabled `CONFIG_PSI` are
independent: on a real CloudLinux 9 box, enabling `psi=1` made
`/proc/pressure/io` readable *inside* the cage while `/proc/diskstats` stayed
hidden. Two absences, two causes — so the account-side check reports the
filtering it can prove and defers the kernel question rather than guessing at
it. It is recorded with each reading and shown in the host panel. The
Health panel says whether it is there and, when it is not, *which* of the
possible reasons applies — a kernel without it, a kernel with it disabled at
boot, or a `/proc` filtered by CageFS so the account cannot see a kernel feature
the machine does have. Note that the version is reported and never concluded
from: RHEL and therefore CloudLinux backported PSI into their 4.18 kernels, so
"4.18 is below 4.20" is exactly the wrong inference on the hosts this is aimed
at. This is
the better answer to "is the disk actually hurting anything", and utilisation is
a poor proxy for it: a device can sit at 100% pushing background writeback and
bother nobody. PSI measures the opposite thing — time spent *waiting*. `some` is
the share of time at least one task was blocked on I/O; `full` is the share when
nothing at all could run, which is what a machine grinding to a halt looks like
from the inside.

### Counters, not rates

Everything here is stored as the raw counter and turned into a rate against the
previous reading, the same way the CPU and network figures are. A rate only
exists as a difference between two moments, and storing it alone would make a
gap in collection indistinguishable from a quiet hour. Keeping the counters
means the difference can always be recovered over whatever interval actually
elapsed.

Counters restart at boot, so a difference that comes out negative means the
machine rebooted rather than that it un-read four gigabytes. That interval
reports no rate at all — an unknown, not a guess.

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

## Change, and telling a leak from a spike

The **Show** control has a third setting, **Change per reading**: instead of how
much room is left, the difference between one reading and the next. Below the
line is space being consumed, above it is space coming back. Fifty megabytes an
hour is invisible on an axis running to a terabyte and obvious the moment it is
drawn as a difference — and only the difference carries a sign, so a cleanup
that is working looks different from one that is not.

Two things make that chart readable:

- **The axis is scaled to the bulk of the readings, not the worst one.** A
  twenty-gigabyte upload is four hundred times a steady fifty-megabyte loss, so
  on a full-range axis the thing you are looking for is one pixel high. The
  extremes are trimmed off the scale, still drawn (clipped at the edge), and the
  largest single change is named underneath, so nothing is hidden.
- **Changes are totalled over a bucket that suits the range** — per reading up
  to six hours, per hour up to two days, per six hours up to a month, per day
  beyond. Totalled, not averaged: "this hour cost 1.2 GiB" is a quantity you can
  reason about, and the mean of the steps inside it is a smaller number that
  means nothing on its own.

### Why the projection is not a line of best fit

A least-squares fit was the first attempt and it is the wrong tool: one large
upload drags the line down hard enough to predict a full disk by Thursday, and
after a week of those nobody reads the warnings.

The distinction that matters is between a **step** and a **slope**. Space that
dropped once and stayed down is consumption that already happened. Space that
keeps dropping is a leak. So the window is cut in half and each half measured
on its own — a spike lands in one of them, a leak is in both — and three numbers
come out:

| | what it answers |
| --- | --- |
| `overall` | where the level actually went, spikes included |
| `median` | the middle step between readings, which one outlier cannot move |
| both halves | whether it is still going |

Only a fall in **both halves** counts as a trend, and the projection uses the
median step, so a leak that also happens to contain a spike is still projected
at the leak's rate. Tested against seven shapes:

| scenario | verdict |
| --- | --- |
| steady 1 GiB/h leak | sustained, 7.5 days |
| flat | steady |
| one 20 GiB upload | **spike — no alert** |
| upload near the end of the window | **spike — no alert** |
| leak *and* an upload | sustained, projected at the leak's 1 GiB/h |
| freeing up | steady |
| slow 100 MiB/h drift | sustained, 84 days |

The card says which it decided — `6.5 days · losing 1 GiB/h, and still falling`
versus `one-off · 20 GiB went at once, not a trend` — because a projection whose
reasoning you cannot see is a number people learn to ignore. Inodes get the same
treatment, and can raise their own alert.

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

**CPU and memory** follows the same switch, with one asymmetry: a percentage is
the only way to say "CPU", so that line never changes. Memory has both forms, so
in **Absolute** it becomes bytes consumed on the right-hand axis, with the total
installed named in the subtitle. `83%` is a number you have to already know the
machine to act on; `13.3 GiB of 16 GiB` is not. Consumed rather than available,
because that is the direction the question runs — memory is used *up* by
something, and a line that climbs as things get worse needs no explaining. It is
derived from the stored total and available rather than stored a third time,
which is one fewer way for the three to disagree.

**Load average is not a percentage.** It is how many processes wanted to run at
once, so it only means something against the core count: 4.00 is half busy on
eight cores and twice oversubscribed on two. The card shows it as a share of the
cores it has, and the collector records the core count alongside it.

Axis ticks land on round numbers — 1, 2 or 5 times a power of ten — and carry
enough decimals for neighbouring labels to differ. The unit is chosen by how big
the numbers are and the decimals by how far apart they are, which are two
different questions: 72 GiB of space moving by 50 MiB an hour reads as
`71.94 GiB`, not as `73671 MiB`.

Round **in the unit the label is printed in**, which is not the same as round in
tens. Five billion bytes is a perfectly tidy decimal step and renders as
`4.7 GiB`, so a byte axis chosen that way climbs 0.0, 4.7, 9.3, 14.0 GiB — an
axis you decode rather than read. The unit is therefore settled first and the
ticks are chosen within it, giving 0, 5, 10, 15 GiB. Percentages and load
averages are already their own unit, so nothing changes for them.

## Live updates

The dashboard refreshes every 30 seconds. The **Live** button switches it to
every 10 seconds and makes each poll take a *fresh* reading rather than
re-reading the last one — the collector's own clock is a minute at best, and the
moment you want ten-second resolution is the moment something is going wrong.
Polling stops while the tab is hidden.

## Schema

**A shipped migration is never edited.** Adding a column to the `CREATE TABLE`
in step 1 fixes new installs and silently leaves every existing one behind:
those databases recorded step 1 as done long ago and will never run it again.
It surfaces later, on the one machine that matters, as

    Failed: SQLSTATE[HY000]: General error: 1 table host_files has no column named device

New columns go in a new numbered step, *and* in the `CREATE TABLE` above it. The
`ALTER TABLE … ADD COLUMN` guard makes the step a no-op where the column already
exists, so both paths land in the same place — a fresh install builds it in one
statement, an existing one alters into it.



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

## One file, several names

A hard link is not a copy. Two directory entries can point at one inode, sharing
a single allocation on disk, and `ls -l` gives it away only in the link count:

    -rw-r----- 2 root regaldra 199M ... domlogs/git.lewismoten.com-ssl_log
    -rw-r----- 2 root regaldra 199M ... domlogs/regaldra/git.lewismoten.com-ssl_log

Apache does exactly this — the same log published flat and again under a
per-account directory. Both names report 199 MiB because both describe the same
199 MiB. **Added up as two files, that is 398 MiB of disk that does not exist.**

The error is worse than a wrong number in one column. Category totals, growth
figures between scans, and the projection built on those growth figures are all
downstream of it, and it is wrong in the flattering direction — it invents space
that is already free, and sends you looking for it.

So the rule is: **a file's identity is `(device, inode)`; a path is only a name
for it.** Bytes are counted once per unique inode, never once per path. The
collector reads all four values in one `stat` — the same syscall `filesize()`
already makes, with everything but the size thrown away.

Which path gets the bytes is whichever the walk reaches first, so directory
subtotals are order-dependent. That is what `du` does, for the same reason: any
other choice makes the subtotals stop adding up to the total. Counting it under
both and once in the grand total gives a table whose column does not sum to its
own footer.

Only files with more than one link are tracked. Almost every file has exactly
one, and remembering every inode under a home directory would cost more memory
than a scan is allowed to use.

The dashboard shows the consequence rather than hiding it. In the host panel one
row per physical file, with the other names beneath it:

| Path | Size | Names |
|---|---|---|
| `…/domlogs/git.lewismoten.com-ssl_log`<br>*also at `…/domlogs/regaldra/git.lewismoten.com-ssl_log`* | 199 MiB | 2 paths, 1 file |

and under the table, `250 MiB across 2 files, reached by 3 paths.` Where a file
has more links than the watch list can see, it says so — `2 here of 5` — because
removing every path shown would still not free the space.

In **What is taking the space**, a linked file carries `· 2 links` beside its
size, since the number there is not what you would get back.

**Deleting follows the same rule.** Removing one of two names frees nothing, so
the audit log records nothing freed and says why:

    delete_file  ok  freed 0  one of 2 links; 5.00 MiB stays on disk until the rest go

Emptying a category checks the link count immediately before each unlink, which
does the bookkeeping for free: if both names are in the category, the first
delete sees two links and counts nothing, the second sees one and counts the
whole file. The total comes out right however many names there were, and in
whatever order they were reached.

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

## Symlink loops

**Check for symlink loops** on the Health panel walks the tree looking for links
that will send something into a circle.

The scanner steps over every symlink it meets, deliberately — following them
double counts at best and walks out of the watched directory at worst. The
consequence is that a bad link is invisible *here* while still being the thing
that ruins everything else: a link pointing at one of its own ancestors is an
infinitely deep directory to anything that does follow links, and backup jobs,
`du`, `tar` and web crawlers all do. That is how a disk fills up with one
directory copied ten thousand times.

| finding | what it means |
| --- | --- |
| **Loop** | the chain of links comes back to one it already passed |
| **Endless directory** | resolves to a directory containing the link itself, so anything following it descends for ever |
| **Leaves the tree** | resolves outside the watched directory, so sizes measured here are not what a program following it would see |
| **Broken** | points at nothing — untidy rather than dangerous |

`photos/all -> ..` is one line in a listing and an infinitely deep tree, which
is why the endless-directory case is worth a category of its own.

The walk never follows a link. It reads where each one points and reasons about
the path, which is the only safe way to inspect a loop — and it follows chains
hop by hop rather than calling `realpath`, which collapses a loop, a broken link
and a permissions problem into the same `false`.

On a button rather than a schedule: it is a whole extra walk of the tree and the
answer only changes when somebody makes a link. It is bounded by a clock and
**says when it stopped early**, because a check that quietly gave up and reported
"nothing wrong" would be worse than not having one. Signed-in only, since it
reports paths.

## When something goes wrong

Open **`health.php`**. It requires nothing, includes nothing and never touches
the database or the config, so it runs on an install that is otherwise entirely
broken — which is the only time it is worth having. It checks that every file is
present and actually parses (a truncated upload looks identical to a healthy
file until PHP compiles it), that PHP is new enough, that SQLite is there, and
that `data/` is writable. It reports states and never paths, because it answers
to anyone who can reach it.

It also checks that **the files agree with each other**. A file can be present,
readable and parse perfectly and still be the wrong version: uploading by hand
means uploading one file at a time, and the moment one lands without another it
needs, the site dies with `Call to undefined function` — which reads like a bug
in the code and is actually half a deployment. So it reads every file, collects
what each one defines and what each one calls, and lists anything called but
defined nowhere:

    ✗ hostCronLine()      called in api.php
    ✗ hostCronCommand()   called in api.php

Statically, by reading — nothing is loaded or executed, because this page has to
work on the install that is too broken to run. Names the code deliberately probes
for with `function_exists` are excluded, so a host without the posix extension
does not light the whole list up.

**Identical faults are counted, not repeated.** A failing endpoint is not one
problem: the dashboard polls, every poll calls the API, and one broken function
becomes forty lines within a minute. Listed flat that reads as forty faults and
buries the two others that are genuinely different. The grouping has to ignore
the reference — every entry carries its own so a failure on screen can be traced
here, which means two records of the same fault never match as text — so it is
stripped for comparison and one sample kept for the link back. Seventy-three
lines from a bad afternoon collapse to three rows.

**Clear the log** removes the entries for good, once you have fixed what caused
them. There is no per-entry dismissal: entries are lines in a text file with no
identity beyond their position, so "dismiss this one" would mean rewriting the
file around a line number that shifts every time anything is appended. Clearing
is recorded in the audit log, because "the errors disappeared" should have an
explanation somewhere that is not the errors. Anything still broken writes a
fresh entry on its next failure, which is the point — what is left afterwards is
what is still wrong.

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
- `host.db` lists every process on the machine with its command line, so it is
  created 0640 and handed to one named account rather than left world-readable.
  The host panel has no signed-out form: there is no sensible way to mask it.
- A signed-in session can stop processes, so it is a kill switch as well as a
  dashboard. `processes.may_stop = false` removes that; pid 1, this dashboard's
  own ancestry and other users' processes are refused regardless. Patterns from
  the browser are matched in PHP and never handed to a shell.

## Configuration

Everything lives in the array returned by `lib/config.php`: scan root, timezone,
database path, the two secrets, session lifetime, all the alert thresholds and
the recipient, the category rules, the excluded paths, how many offenders to
keep per scan, which categories may be deleted, and — under `processes` —
whether processes may be stopped, which names are protected from it, and how
many may be signalled at once.

## Upgrading from the JSON version

The old collector wrote a tree of JSON files under `data/`. Nothing reads them
any more; the SQLite database is created alongside and starts from empty. Delete
`data/index.json` and the year directories once you are happy the new collector
is running.
