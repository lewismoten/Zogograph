#!/usr/bin/env bash
set -euo pipefail

site=$1
snapshots=$2
snapshot_id=$3

case "$snapshots" in "$site"|"$site"/*) echo 'Snapshot path must be outside the deploy path.' >&2; exit 1;; esac
mkdir -p "$site" "$snapshots"
snapshot="$snapshots/$snapshot_id"
test ! -e "$snapshot" || { echo "Snapshot already exists: $snapshot" >&2; exit 1; }
mkdir -p "$snapshot/site" "$snapshot/databases"

if [ -n "$(find "$site" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
  rsync -a --delete --exclude='*.db' --exclude='*.db-*' --exclude='*.sqlite' --exclude='*.sqlite-*' --exclude='*.sqlite3' --exclude='*.sqlite3-*' "$site/" "$snapshot/site/"
  if [ -d "$site/data" ]; then
    command -v sqlite3 >/dev/null || { echo 'sqlite3 is required on the deployment server for safe snapshots.' >&2; exit 1; }
    # Some shared-host SSH jails do not expose /dev/fd, so avoid Bash process
    # substitution here. Deployment paths must not contain newline characters.
    find "$site/data" -type f \( -name '*.db' -o -name '*.sqlite' -o -name '*.sqlite3' \) -print |
    while IFS= read -r database; do
      relative=${database#"$site"/}
      destination="$snapshot/databases/$relative"
      mkdir -p "$(dirname "$destination")"
      sqlite3 "$database" ".backup '$destination'"
    done
  fi
fi

printf '%s\n' "$snapshot_id" > "$snapshot/SNAPSHOT_ID"
