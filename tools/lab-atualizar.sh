#!/usr/bin/env bash
# Connect ERP — atualiza o lab (erp.connect.lab) depois de a main receber código novo.
#
#   bash tools/lab-atualizar.sh                                # aplica todas as pendentes
#   MIGRATE_UNTIL=20261108_0399 bash tools/lab-atualizar.sh    # só até esse prefixo
#
# Passos: composer install (dependências novas do composer.lock) -> backup do banco (tools/backup-database.sh) e dos arquivos privados (tools/backup-files.sh) -> composer test:integration (banco
# *_test) -> status e apply das migrations no banco do lab -> rebuild do serviço scheduler.
# Para no primeiro erro: se a integração falhar, nada é migrado.
# Rode a partir do checkout principal (www/connect), que é o que o compose monta.
set -euo pipefail

project=$(cd "$(dirname "$0")/.." && pwd)
cd "$project"
# MIGRATE_UNTIL=AAAAMMDD_NNNN segura migrations de uma fatia ainda em desenvolvimento.
until_prefix=${MIGRATE_UNTIL-}
until_arg=()
[ -n "$until_prefix" ] && until_arg=("--until=$until_prefix")

echo "== 0/4 dependências (composer install)"
docker compose exec -T app composer install --no-interaction --no-progress --prefer-dist

echo "== 1/4 backup do banco e dos arquivos privados (storage/private/documents e imports)"
backup=$(bash tools/backup-database.sh)
[ -s "$backup" ] || { echo "Backup vazio: $backup" >&2; exit 1; }
echo "backup: $backup ($(du -h "$backup" | cut -f1))"
files_backup=$(bash tools/backup-files.sh)
echo "arquivos privados: $files_backup ($(du -h "$files_backup" | cut -f1))"

echo "== 2/4 testes de integração (banco *_test)"
docker compose exec -T app composer test:integration

echo "== 3/4 migrations ${until_prefix:+(até $until_prefix)}"
docker compose exec -T app php service/commands/database-migrate.php status ${until_arg[@]+"${until_arg[@]}"} || true
docker compose exec -T app php service/commands/database-migrate.php apply ${until_arg[@]+"${until_arg[@]}"}

echo "== 4/4 scheduler"
docker compose up -d --build scheduler

echo "Lab atualizado. Abra https://erp.connect.lab"
