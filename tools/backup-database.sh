#!/usr/bin/env bash
set -euo pipefail
project=$(cd "$(dirname "$0")/.." && pwd)
backup_dir="$project/.local/backups"
mkdir -p "$backup_dir"
output="$backup_dir/connect-erp-$(date +%Y%m%d-%H%M%S).sql.gz"
docker compose --project-directory "$project" exec -T db \
  sh -c 'mariadb-dump --single-transaction --routines --triggers -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
  | gzip >"$output"
chmod 600 "$output"
echo "$output"
