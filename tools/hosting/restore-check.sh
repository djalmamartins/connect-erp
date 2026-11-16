#!/usr/bin/env bash
# Connect ERP — teste de restauração do backup (ADR 0334).
#
#   bash tools/hosting/restore-check.sh                      # o backup local mais novo (.local/backups)
#   bash tools/hosting/restore-check.sh --remoto             # baixa o diário mais novo do BACKUP_RCLONE_REMOTE
#   bash tools/hosting/restore-check.sh <banco.sql.gz> [<arquivos.tar.gz>]
#
# Restaura o banco num banco SEPARADO de teste (BACKUP_RESTORE_DB no .env, criado vazio no hPanel e com o mesmo usuário),
# confere o pacote de arquivos e compara tabela por tabela com o banco real: mesmas tabelas e quantas linhas cada uma
# tem. Diz o tempo que levou e grava uma linha em storage/logs/restore-check.log.
#
# Travas: o banco de teste precisa ter "restore" no nome e ser diferente do banco do .env; o script nunca escreve no
# banco real (só lê as contagens). O banco de teste é sobrescrito a cada execução.
set -euo pipefail
project=$(cd "$(dirname "$0")/../.." && pwd)
php_bin=${PHP_BIN:-php}
env_value() {
    [ -f "$project/.env" ] || return 0
    { grep -E "^[[:space:]]*$1=" "$project/.env" || true; } | tail -n 1 | cut -d= -f2- | sed -e 's/^["'\'']//' -e 's/["'\'']$//'
}
for key in BACKUP_RESTORE_DB BACKUP_RCLONE_REMOTE RCLONE_BIN; do
    if [ -z "${!key:-}" ]; then
        value=$(env_value "$key")
        if [ -n "$value" ]; then export "$key=$value"; fi
    fi
done

production=$("$php_bin" "$project/tools/hosting/db-client-config.php" nome)
target=${BACKUP_RESTORE_DB:-}
if [ -z "$target" ]; then
    echo "Defina BACKUP_RESTORE_DB no .env (um banco vazio só para o teste, com \"restore\" no nome)." >&2
    exit 2
fi
if [ "$target" = "$production" ]; then
    echo "Recusado: BACKUP_RESTORE_DB é o banco de produção ($production)." >&2
    exit 2
fi
case "$target" in
    *restore*) ;;
    *) echo "Recusado: o banco de teste precisa ter \"restore\" no nome (recebido: $target)." >&2; exit 2 ;;
esac
if ! [[ "$target" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Recusado: nome de banco inválido ($target)." >&2
    exit 2
fi

client_bin=$(command -v mariadb || command -v mysql || true)
if [ -z "$client_bin" ]; then
    echo "mariadb/mysql não encontrado" >&2
    exit 1
fi

umask 077
mkdir -p "$project/.local/backups" "$project/storage/logs"
work=$(mktemp -d "$project/.local/backups/.restore-XXXXXX")
trap 'rm -rf "$work"' EXIT
"$php_bin" "$project/tools/hosting/db-client-config.php" >"$work/client.cnf"
sql() { "$client_bin" --defaults-extra-file="$work/client.cnf" -N -B "$@"; }

db_file=""
files_file=""
if [ "${1:-}" = "--remoto" ]; then
    remote=${BACKUP_RCLONE_REMOTE:-}
    remote=${remote%/}
    [ -n "$remote" ] || { echo "BACKUP_RCLONE_REMOTE não configurado" >&2; exit 2; }
    case "$remote" in *:) base="${remote}diario/" ;; *) base="$remote/diario/" ;; esac
    rclone_bin=${RCLONE_BIN:-rclone}
    day=$("$rclone_bin" lsf --dirs-only "$base" | sort | tail -n 1)
    [ -n "$day" ] || { echo "Nenhum backup diário em $base" >&2; exit 1; }
    "$rclone_bin" copy "$base$day" "$work/remoto/"
    db_file=$(ls -1 "$work"/remoto/connect-erp-db-*.sql.gz | sort | tail -n 1)
    files_file=$(ls -1 "$work"/remoto/connect-erp-files-*.tar.gz 2>/dev/null | sort | tail -n 1 || true)
elif [ -n "${1:-}" ]; then
    db_file=$1
    files_file=${2:-}
else
    db_file=$(ls -1 "$project"/.local/backups/connect-erp-db-*.sql.gz 2>/dev/null | sort | tail -n 1 || true)
    files_file=$(ls -1 "$project"/.local/backups/connect-erp-files-*.tar.gz 2>/dev/null | sort | tail -n 1 || true)
fi
[ -n "$db_file" ] && [ -f "$db_file" ] || { echo "Backup do banco não encontrado. Rode bash tools/hosting/backup.sh antes." >&2; exit 1; }

started=$(date +%s)
echo "Restaurando $(basename "$db_file") em $target ..."
gzip -t "$db_file"
# Esvazia o banco de teste (só ele) e importa.
drops=$(sql -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = '$target'")
{ echo "SET FOREIGN_KEY_CHECKS=0;"; echo "$drops"; } | sql "$target"
gunzip -c "$db_file" | sql "$target"
restored_at=$(date +%s)

files_status="sem pacote de arquivos"
if [ -n "$files_file" ] && [ -f "$files_file" ]; then
    count=$(tar -tzf "$files_file" | grep -vc '/$' || true)
    files_status="pacote de arquivos OK ($count arquivo(s))"
fi

tables_prod=$(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$production' AND table_type = 'BASE TABLE' ORDER BY table_name")
tables_rest=$(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$target' AND table_type = 'BASE TABLE' ORDER BY table_name")
missing=$(comm -23 <(echo "$tables_prod") <(echo "$tables_rest") | sed '/^$/d' || true)
total_prod=0
total_rest=0
for t in $tables_rest; do
    r=$(sql -e "SELECT COUNT(*) FROM \`$target\`.\`$t\`")
    total_rest=$((total_rest + r))
    if echo "$tables_prod" | grep -qx "$t"; then
        p=$(sql -e "SELECT COUNT(*) FROM \`$production\`.\`$t\`")
        total_prod=$((total_prod + p))
    fi
done
finished=$(date +%s)

n_rest=$(echo "$tables_rest" | sed '/^$/d' | wc -l | tr -d ' ')
n_missing=$(echo "$missing" | sed '/^$/d' | wc -l | tr -d ' ')
result="OK"
[ "$n_missing" -eq 0 ] && [ "$n_rest" -gt 0 ] || result="FALHOU"
summary="$result: $(basename "$db_file") restaurado em $((restored_at - started))s (total $((finished - started))s); $n_rest tabela(s), $total_rest linha(s) no backup e $total_prod hoje no banco real; $n_missing tabela(s) faltando; $files_status"
echo "$summary"
if [ "$n_missing" -gt 0 ]; then
    echo "Tabelas que estão no banco real e não no backup:"
    echo "$missing" | sed 's/^/  - /'
fi
echo "[$(date '+%Y-%m-%d %H:%M:%S')] $summary" >>"$project/storage/logs/restore-check.log"
[ "$result" = "OK" ]
