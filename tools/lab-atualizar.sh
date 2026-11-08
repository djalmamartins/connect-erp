#!/usr/bin/env bash
# Connect ERP — atualiza o lab (erp.connect.lab) depois de a main receber código novo.
#
#   bash tools/lab-atualizar.sh                         # tudo, migrations até MIGRATE_UNTIL
#   MIGRATE_UNTIL= bash tools/lab-atualizar.sh          # aplica todas as migrations pendentes
#
# Passos: backup do banco (tools/backup-database.sh) -> status e apply das migrations
# (composer db:migrate dentro do container app, até MIGRATE_UNTIL) -> rebuild do serviço
# scheduler -> composer test:integration (banco *_test). Para no primeiro erro.
# Rode a partir do checkout principal (www/connect), que é o que o compose monta.
set -euo pipefail

project=$(cd "$(dirname "$0")/.." && pwd)
cd "$project"
# Unidades (0401-0403) ainda em desenvolvimento: só entram quando a thread delas liberar.
until_prefix=${MIGRATE_UNTIL-20261108_0399}
until_arg=()
[ -n "$until_prefix" ] && until_arg=("--until=$until_prefix")

echo "== 1/4 backup do banco"
backup=$(bash tools/backup-database.sh)
[ -s "$backup" ] || { echo "Backup vazio: $backup" >&2; exit 1; }
echo "backup: $backup ($(du -h "$backup" | cut -f1))"

echo "== 2/4 migrations ${until_prefix:+(até $until_prefix)}"
docker compose exec -T app php service/commands/database-migrate.php status "${until_arg[@]}" || true
docker compose exec -T app php service/commands/database-migrate.php apply "${until_arg[@]}"

echo "== 3/4 scheduler"
docker compose up -d --build scheduler

echo "== 4/4 testes de integração (banco *_test)"
docker compose exec -T app composer test:integration

echo "Lab atualizado. Abra https://erp.connect.lab"
