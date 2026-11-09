#!/bin/sh
set -eu

# Create a consistent, offline archive of the single-instance Docker volume.
# Usage: scripts/backup-data.sh [destination-directory]

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

if [ "$#" -gt 1 ]; then
    echo "Usage: $0 [destination-directory]" >&2
    exit 2
fi

backup_dir=${1:-./backups}
mkdir -p "$backup_dir"
backup_dir=$(CDPATH= cd -- "$backup_dir" && pwd)
filename="socialmedia-ia-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
if [ -e "$backup_dir/$filename" ]; then
    echo "Backup already exists: $backup_dir/$filename" >&2
    exit 1
fi
was_running=$(docker compose -f compose.yaml ps --status running -q web)

restart_service() {
    if [ -n "$was_running" ]; then
        docker compose -f compose.yaml start web >/dev/null
    fi
}

trap restart_service EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
if [ -n "$was_running" ]; then
    docker compose -f compose.yaml stop web
fi

# The app is stopped while SQLite and uploads are copied, so both represent
# one consistent point in time. The read-only mount protects the live volume.
docker run --rm \
    --mount type=volume,source=socialmedia-ia-data,target=/data,readonly \
    --mount "type=bind,source=$backup_dir,target=/backup" \
    alpine:3.21.3 \
    tar -czf "/backup/$filename" -C /data .

if [ ! -s "$backup_dir/$filename" ]; then
    echo "Backup archive was not created or is empty." >&2
    exit 1
fi
printf 'Backup created: %s\n' "$backup_dir/$filename"
