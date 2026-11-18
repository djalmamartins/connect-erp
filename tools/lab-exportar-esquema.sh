#!/usr/bin/env bash
# Exporta só a ESTRUTURA do banco do laboratório (ADR 0379), sem nenhum dado, para montar a instalação limpa da produção.
# Hoje o banco de produção começa vazio e `composer db:migrate` falha: as tabelas base (users, access_*, settings,
# mail_queue, studio_support_*...) nunca vieram de migration, só do banco antigo do laboratório.
#
#   bash tools/lab-exportar-esquema.sh
#
# Grava database/base/schema.sql (CREATE TABLE sem AUTO_INCREMENT=N nem DEFINER) e database/base/tables.txt
# (tabela e quantidade de linhas, para separar o que é catálogo do sistema do que é dado de condomínio).
# Nenhum dado sai: --no-data. Pode ir para o Git.
# Testes: DUMP_CMD e COUNT_CMD substituem o docker compose.
set -euo pipefail
project=$(cd "$(dirname "$0")/.." && pwd)
# O docker compose roda de dentro da pasta do projeto: o caminho do Mac tem espaço ("/Volumes/MAC HD/...") e não pode
# entrar no texto do comando, que passa pelo eval.
cd "$project"
out="$project/database/base"
mkdir -p "$out"
dump=${DUMP_CMD:-"docker compose exec -T legacy-db sh -c 'mariadb-dump --no-data --skip-dump-date --skip-comments --routines --triggers -u\"\$MARIADB_USER\" -p\"\$MARIADB_PASSWORD\" \"\$MARIADB_DATABASE\"'"}
count=${COUNT_CMD:-"docker compose exec -T legacy-db sh -c 'mariadb -N -u\"\$MARIADB_USER\" -p\"\$MARIADB_PASSWORD\" \"\$MARIADB_DATABASE\" -e \"SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name\"'"}

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
eval "$dump" >"$tmp"
if grep -qiE '^INSERT INTO' "$tmp"; then
  echo "O dump trouxe dados (INSERT): parado, nada gravado." >&2
  exit 3
fi
{
  echo "-- Estrutura do banco do laboratório (ADR 0379), sem dados. Gerado por tools/lab-exportar-esquema.sh."
  sed -E -e 's/ AUTO_INCREMENT=[0-9]+//' -e 's/DEFINER=`[^`]*`@`[^`]*`//g' -e '/^\/\*M?!50013 .*DEFINER/d' "$tmp"
} >"$out/schema.sql"
eval "$count" | awk -F'\t' 'NF >= 1 { printf "%s\t%s\n", $1, ($2 == "" ? 0 : $2) }' >"$out/tables.txt"
echo "Gravado: database/base/schema.sql ($(grep -c '^CREATE TABLE' "$out/schema.sql") tabelas) e database/base/tables.txt."
