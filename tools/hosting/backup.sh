#!/usr/bin/env bash
# Connect ERP — backup na hospedagem sem Docker (ADR 0325).
#
#   bash tools/hosting/backup.sh            # gera banco + arquivos e envia ao OneDrive se configurado
#
# 1. Banco: mariadb-dump (ou mysqldump) com --single-transaction, senha num arquivo 0600 temporário
#    (nunca na linha de comando).
# 2. Arquivos: storage/private/{documents,imports} (o banco só guarda os metadados).
# 3. Envio: se BACKUP_RCLONE_REMOTE estiver no ambiente (ex.: connect-backup:, um remote "crypt" do
#    rclone por cima do OneDrive da Connect), copia para <remote>/diario/<data>/ e, no dia 1, também
#    para <remote>/mensal/<AAAA-MM>/. Apaga do remoto o diário com mais de BACKUP_KEEP_DAYS (30) dias e o
#    mensal com mais de 400 dias. Sem a variável, fica só a cópia local (aviso no log).
# 4. Local: mantém BACKUP_KEEP_LOCAL (3) dias em .local/backups, para não encher os 100 GB do plano.
set -euo pipefail
project=$(cd "$(dirname "$0")/../.." && pwd)
backup_dir="$project/.local/backups"
# O cron não carrega o .env: as chaves BACKUP_* e RCLONE_BIN vêm de lá quando não estão no ambiente.
env_value() {
    [ -f "$project/.env" ] || return 0
    { grep -E "^[[:space:]]*$1=" "$project/.env" || true; } | tail -n 1 | cut -d= -f2- | sed -e 's/^["'\'']//' -e 's/["'\'']$//'
}
for key in BACKUP_RCLONE_REMOTE BACKUP_KEEP_DAYS BACKUP_KEEP_LOCAL RCLONE_BIN; do
    if [ -z "${!key:-}" ]; then
        value=$(env_value "$key")
        if [ -n "$value" ]; then export "$key=$value"; fi
    fi
done
keep_local=${BACKUP_KEEP_LOCAL:-3}
keep_days=${BACKUP_KEEP_DAYS:-30}
remote=${BACKUP_RCLONE_REMOTE:-}
remote=${remote%/}
# "remote:" + "diario" = "remote:diario"; "remote:pasta" + "diario" = "remote:pasta/diario".
join_remote() {
    case "$remote" in
        *:) printf '%s%s' "$remote" "$1" ;;
        *) printf '%s/%s' "$remote" "$1" ;;
    esac
}
php_bin=${PHP_BIN:-php}
rclone_bin=${RCLONE_BIN:-rclone}
stamp=$(date +%Y%m%d-%H%M%S)
day=$(date +%Y-%m-%d)

umask 077
mkdir -p "$backup_dir"
work=$(mktemp -d "$backup_dir/.tmp-XXXXXX")
trap 'rm -rf "$work"' EXIT

dump_bin=$(command -v mariadb-dump || command -v mysqldump || true)
if [ -z "$dump_bin" ]; then
    echo "mariadb-dump/mysqldump não encontrado" >&2
    exit 1
fi
"$php_bin" "$project/tools/hosting/db-client-config.php" >"$work/client.cnf"
database=$("$php_bin" "$project/tools/hosting/db-client-config.php" nome)

db_file="$backup_dir/connect-erp-db-$stamp.sql.gz"
"$dump_bin" --defaults-extra-file="$work/client.cnf" --single-transaction --quick --routines --triggers \
    --no-tablespaces "$database" | gzip >"$db_file"

files_file="$backup_dir/connect-erp-files-$stamp.tar.gz"
dirs=()
for d in documents imports; do
    [ -d "$project/storage/private/$d" ] && dirs+=("$d")
done
if [ "${#dirs[@]}" -gt 0 ]; then
    tar -czf "$files_file" -C "$project/storage/private" "${dirs[@]}"
else
    tar -czf "$files_file" -T /dev/null
fi
chmod 600 "$db_file" "$files_file"
echo "$db_file"
echo "$files_file"

if [ -n "$remote" ]; then
    "$rclone_bin" copy "$db_file" "$(join_remote "diario/$day/")"
    "$rclone_bin" copy "$files_file" "$(join_remote "diario/$day/")"
    if [ "$(date +%d)" = "01" ]; then
        "$rclone_bin" copy "$db_file" "$(join_remote "mensal/$(date +%Y-%m)/")"
        "$rclone_bin" copy "$files_file" "$(join_remote "mensal/$(date +%Y-%m)/")"
    fi
    "$rclone_bin" delete --min-age "${keep_days}d" "$(join_remote diario/)" || echo "aviso: limpeza do diário falhou" >&2
    "$rclone_bin" delete --min-age 400d "$(join_remote mensal/)" || echo "aviso: limpeza do mensal falhou" >&2
    "$rclone_bin" rmdirs --leave-root "$(join_remote diario/)" >/dev/null 2>&1 || true
    echo "enviado para $remote"
else
    echo "aviso: BACKUP_RCLONE_REMOTE não configurado; backup só local" >&2
fi

find "$backup_dir" -maxdepth 1 -type f -name 'connect-erp-*' -mtime +"$keep_local" -delete
