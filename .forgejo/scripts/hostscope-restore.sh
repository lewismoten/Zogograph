#!/usr/bin/env bash
set -euo pipefail

site=$1
snapshots=$2
snapshot_id=$3
snapshot="$snapshots/$snapshot_id"

test -f "$snapshot/SNAPSHOT_ID" || { echo "Unknown snapshot: $snapshot_id" >&2; exit 1; }
test -d "$snapshot/site" || { echo 'Snapshot site files are missing.' >&2; exit 1; }

# Database files are restored only from SQLite's consistent .backup copies.
rsync -a --delete --exclude='data/***' --exclude='.well-known/' "$snapshot/site/" "$site/"
if [ -d "$snapshot/site/data" ]; then
  rsync -a --delete \
    --exclude='*.db' --exclude='*.db-*' \
    --exclude='*.sqlite' --exclude='*.sqlite-*' \
    --exclude='*.sqlite3' --exclude='*.sqlite3-*' \
    "$snapshot/site/data/" "$site/data/"
fi
if [ -d "$snapshot/databases" ]; then
  # See hostscope-snapshot.sh: use a portable pipe rather than /dev/fd-backed
  # process substitution for restricted SSH environments.
  find "$snapshot/databases" -type f \( -name '*.db' -o -name '*.sqlite' -o -name '*.sqlite3' \) -print |
  while IFS= read -r source; do
    relative=${source#"$snapshot/databases"/}
    destination="$site/$relative"
    mkdir -p "$(dirname "$destination")"
    rm -f "${destination}-wal" "${destination}-shm" "${destination}-journal"
    rsync -a "$source" "$destination"
  done
fi
