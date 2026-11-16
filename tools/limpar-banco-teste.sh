#!/usr/bin/env bash
# Destrava o banco de teste (movesos_test) antes da suíte de integração. Usado pelo lab-atualizar.sh
# e pelo testar-main.sh; também pode ser rodado sozinho na pasta do projeto.
#
# 16/11/2026: um Ctrl+C no `docker compose exec -T` não chega ao PHP dentro do container. A rodada
# antiga continua viva, e a conexão dela que recebe KILL no meio de um TRUNCATE pode ficar "Killed"
# para sempre, segurando a trava do banco. As rodadas seguintes ficam em
# "Waiting for schema metadata lock" no CREATE DATABASE, sem DB na PROCESSLIST, e a suíte não mostra
# nada. Por isso, em ordem:
#   1. encerra no container app os PHPs de teste que sobraram (composer test:* e tests/legacy/run.php);
#   2. encerra as conexões que estão no movesos_test OU cuja consulta cita o movesos_test;
#   3. se ainda sobrar conexão "Killed" nele, reinicia o legacy-db (o lab fica no volume; o banco
#      de teste é recriado a cada rodada) e espera o MariaDB voltar.
# Conexões e dados do banco do lab (connect_legacy) nunca são tocados por KILL.
#
# Precisa de MOVESOS_TEST_DB_PASS no ambiente (senha root do legacy-db, LEGACY_DB_ROOT_PASSWORD do .env).
# LIMPEZA_LOG=arquivo guarda o detalhe; sem ele, o detalhe é descartado.
set -u
log=${LIMPEZA_LOG:-/dev/null}
export MOVESOS_TEST_DB_PASS=${MOVESOS_TEST_DB_PASS:-}

test_db_sql() {
  docker compose exec -T -e MOVESOS_TEST_DB_PASS legacy-db \
    sh -c 'mariadb -uroot -p"$MOVESOS_TEST_DB_PASS" -N -B' 2>>"$log"
}

# 1. PHPs de teste órfãos. Os colchetes evitam que o próprio sh -c case com o padrão.
orphans=$(docker compose exec -T app sh -c "ps -eo pid=,args= | grep -E '[t]ests/legacy/run.php|composer [t]est:' || true" 2>>"$log" || true)
if [ -n "$orphans" ]; then
  { echo "PHPs de teste que sobraram no container app:"; echo "$orphans"; } >>"$log"
  docker compose exec -T app sh -c "ps -eo pid=,args= | grep -E '[t]ests/legacy/run.php|composer [t]est:' | awk '{print \$1}' | xargs -r kill -9" >>"$log" 2>&1 || true
  echo "encerrados $(echo "$orphans" | grep -c .) processo(s) de teste antigos no container"
fi

# 2. Conexões no banco de teste ou esperando por ele.
where="ID <> CONNECTION_ID() AND (DB LIKE 'movesos%test%' OR INFO LIKE '%movesos%test%')"
stale=$(echo "SELECT ID, USER, COMMAND, TIME, LEFT(COALESCE(INFO, ''), 120) FROM information_schema.PROCESSLIST WHERE $where;" | test_db_sql || true)
if [ -n "$stale" ]; then
  { echo "conexões no movesos_test antes dos testes:"; echo "$stale"; } >>"$log"
  echo "$stale" | cut -f1 | grep -E '^[0-9]+$' | sed 's/.*/KILL &;/' | test_db_sql >>"$log" || true
  echo "encerradas $(echo "$stale" | grep -c .) conexão(ões) presas no banco de teste"
  sleep 2
fi

# 3. Conexão que não morre com KILL: só o restart do MariaDB solta a trava.
stuck=$(echo "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE $where;" | test_db_sql || true)
if [ -n "$stuck" ] && [ "$stuck" != "0" ]; then
  echo "$stuck conexão(ões) não saíram com KILL; reiniciando o legacy-db (o banco do lab não é apagado)"
  docker compose restart legacy-db >>"$log" 2>&1 || true
  for _ in $(seq 1 30); do
    if echo "SELECT 1;" | test_db_sql >/dev/null; then break; fi
    sleep 2
  done
fi
exit 0
