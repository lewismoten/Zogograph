#!/usr/bin/env bash
set -euo pipefail

site=$1
snapshots=$2
snapshot_id=$3

case "$snapshots" in "$site"|"$site"/*) echo 'Snapshot path must be outside the deploy path.' >&2; exit 1;; esac
test -d "$site" || { echo "Deploy path does not exist: $site" >&2; exit 1; }
test -r "$site" && test -x "$site" || { echo "Deploy path is not readable: $site" >&2; exit 1; }
if ! source_entry=$(find "$site" -mindepth 1 -maxdepth 1 -print -quit); then
  echo "Cannot list deploy path: $site" >&2
  exit 1
fi
test -n "$source_entry" || { echo "Deploy path is empty; refusing to deploy without a snapshot: $site" >&2; exit 1; }
mkdir -p "$snapshots"
snapshot="$snapshots/$snapshot_id"
test ! -e "$snapshot" || { echo "Snapshot already exists: $snapshot" >&2; exit 1; }
mkdir -p "$snapshot/site" "$snapshot/databases"

rsync -a --delete --exclude='*.db' --exclude='*.db-*' --exclude='*.sqlite' --exclude='*.sqlite-*' --exclude='*.sqlite3' --exclude='*.sqlite3-*' "$site/" "$snapshot/site/"
if [ -d "$site/data" ]; then
  command -v sqlite3 >/dev/null || { echo 'sqlite3 is required on the deployment server for safe snapshots.' >&2; exit 1; }
  # Some shared-host SSH jails do not expose /dev/fd, so avoid Bash process
  # substitution here. Deployment paths must not contain newline characters.
  # Migration backups are already historical database copies. They are not
  # live stores and can be deliberately unreadable to the web deployment
  # user, so do not try to open each one as a current SQLite database.
  find "$site/data" \
    -path "$site/data/migration-backups" -prune -o \
    -type f \( -name '*.db' -o -name '*.sqlite' -o -name '*.sqlite3' \) -print |
  while IFS= read -r database; do
    relative=${database#"$site"/}
    destination="$snapshot/databases/$relative"
    mkdir -p "$(dirname "$destination")"
    sqlite3 "$database" ".backup '$destination'"
  done
fi

printf '%s\n' "$snapshot_id" > "$snapshot/SNAPSHOT_ID"
if ! snapshot_entry=$(find "$snapshot/site" "$snapshot/databases" -type f -print -quit); then
  echo "Cannot verify snapshot: $snapshot" >&2
  exit 1
fi
test -n "$snapshot_entry" || { echo "Snapshot has no files: $snapshot" >&2; exit 1; }
files=$(find "$snapshot" -type f | wc -l | tr -d ' ')
printf 'source=%s\nfiles=%s\ncreatedAt=%s\n' "$site" "$files" "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" > "$snapshot/MANIFEST"
echo "Snapshot ready: $snapshot ($files files)"
