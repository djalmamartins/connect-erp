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
# Para o ciclo completo (pull da main, unitários, smoke, HTTP e resumo), use tools/testar-main.sh.
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
MOVESOS_TEST_DB_PASS=$(grep -E '^LEGACY_DB_ROOT_PASSWORD=' .env | tail -1 | cut -d= -f2- | sed -E "s/^[\"']//; s/[\"']\$//")
export MOVESOS_TEST_DB_PASS
# Conexões presas no movesos_test (de uma rodada interrompida com Ctrl+C) seguram trava de tabela e a
# suíte fica parada sem mostrar nada. O banco de teste é descartável: elas são encerradas antes (como no
# testar-main.sh). Conexões do banco do lab (connect_legacy) nunca são tocadas.
test_db_sql() {
  docker compose exec -T -e MOVESOS_TEST_DB_PASS legacy-db sh -c 'mariadb -uroot -p"$MOVESOS_TEST_DB_PASS" -N -B' 2>/dev/null
}
stale=$(echo "SELECT ID FROM information_schema.PROCESSLIST WHERE DB LIKE 'movesos%test%' AND ID <> CONNECTION_ID();" | test_db_sql || true)
if [ -n "$stale" ]; then
  echo "$stale" | grep -E '^[0-9]+$' | sed 's/.*/KILL &;/' | test_db_sql || true
  echo "encerradas $(echo "$stale" | grep -cE '^[0-9]+$') conexão(ões) presas no banco de teste"
fi
echo "(leva de 5 a 10 minutos; o PHPUnit só aparece depois de montar o banco de teste)"
docker compose exec -T -e MOVESOS_TEST_DB_HOST=legacy-db -e MOVESOS_TEST_DB_USER=root -e MOVESOS_TEST_DB_PASS app composer test:integration

echo "== 3/4 migrations ${until_prefix:+(até $until_prefix)}"
docker compose exec -T app php service/commands/database-migrate.php status ${until_arg[@]+"${until_arg[@]}"} || true
docker compose exec -T app php service/commands/database-migrate.php apply ${until_arg[@]+"${until_arg[@]}"}

echo "== 4/4 scheduler"
docker compose up -d --build scheduler

echo "Lab atualizado. Abra https://erp.connect.lab"
