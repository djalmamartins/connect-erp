#!/usr/bin/env bash
# Central de Ajuda (ADR 0141): regera os prints marcados dos tutoriais, no Mac, sem dado real.
#
#   cd "/Volumes/MAC HD/Docker/xampp/www/connect"
#   bash tools/help/prints.sh                 # todos os prints
#   bash tools/help/prints.sh --only unidades # só os de um artigo (ou ids de print, separados por vírgula)
#
# O que faz:
#   1. sobe legacy-db e app pelo compose;
#   2. monta o banco connect_ajuda_test (precisa terminar em _test) com o esquema completo e dados
#      fictícios (tools/help/demo-seed.php). O connect_legacy não é lido nem alterado;
#   3. sobe um servidor PHP dentro do container app, na porta 8089, apontando para esse banco;
#   4. roda o Playwright num container próprio (mcr.microsoft.com/playwright), na mesma rede, e grava
#      os JPEGs em container/web/support/v1/assets/kb/ (só os que mudaram);
#   5. roda `php tools/help.php check` e para o servidor.
# Depois: confira os prints, faça commit e rode `php tools/help.php sync` para publicar.
set -euo pipefail

project=$(cd "$(dirname "$0")/../.." && pwd)
cd "$project"

[ -f .env ] || { echo "Falta o .env (LEGACY_DB_ROOT_PASSWORD)." >&2; exit 1; }
root_pass=$(grep -E '^LEGACY_DB_ROOT_PASSWORD=' .env | tail -1 | cut -d= -f2- | sed -E "s/^[\"']//; s/[\"']\$//")
[ -n "$root_pass" ] || { echo "LEGACY_DB_ROOT_PASSWORD vazio no .env." >&2; exit 1; }

db=connect_ajuda_test
password="Ajuda@$(date +%Y)"
playwright_version=$(sed -nE 's/.*"playwright": "([0-9.]+)".*/\1/p' tools/help/package.json)

echo "== 1/5 subindo o banco e o app"
docker compose up -d --wait legacy-db app

echo "== 2/5 banco de demonstração $db (dados fictícios)"
docker compose exec -T -e MOVESOS_ENV=testing -e MOVESOS_TEST_DB="$db" -e MOVESOS_TEST_DB_HOST=legacy-db \
  -e MOVESOS_TEST_DB_PORT=3306 -e MOVESOS_TEST_DB_USER=root -e MOVESOS_TEST_DB_PASS="$root_pass" \
  -e HELP_DEMO_PASSWORD="$password" app php tools/help/demo-seed.php

echo "== 3/5 servidor dos prints (porta 8089 dentro da rede do compose)"
docker compose exec -T app sh -c 'pkill -f "php -S 0.0.0.0:8089" 2>/dev/null || true'
docker compose exec -d -e MOVESOS_ENV=local -e APP_URL=http://app:8089 \
  -e MOVESOS_DB_HOST=legacy-db -e MOVESOS_DB_PORT=3306 -e MOVESOS_DB_NAME="$db" -e MOVESOS_DB_USER=root -e MOVESOS_DB_PASS="$root_pass" \
  -e DB_HOST=legacy-db -e DB_PORT=3306 -e DB_NAME="$db" -e DB_USER=root -e DB_PASS="$root_pass" \
  app php -S 0.0.0.0:8089 tools/help/router.php
trap 'docker compose exec -T app sh -c "pkill -f \"php -S 0.0.0.0:8089\" 2>/dev/null || true"' EXIT
sleep 2

echo "== 4/5 prints com Playwright $playwright_version"
mkdir -p .local/help
docker compose exec -T app php tools/help.php screens >.local/help/telas.json
docker compose exec -T app php -r 'echo json_encode((require "container/erp/connect/v1/components/layout/catalog.php")["pages"]);' >.local/help/catalogo.json
network=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$(docker compose ps -q app)" | awk '{print $1}')
docker run --rm --network "$network" -v "$project":/work -w /work/tools/help --ipc=host \
  -e HELP_BASE_URL=http://app:8089/erp -e HELP_ALLOW_REMOTE=1 -e HELP_USER=ajuda@connect.demo -e HELP_PASSWORD="$password" \
  -e HELP_SCREENS_FILE=/work/.local/help/telas.json -e HELP_CATALOG_FILE=/work/.local/help/catalogo.json \
  "mcr.microsoft.com/playwright:v${playwright_version}-noble" \
  sh -c "npm install --no-audit --no-fund --silent && node shots.mjs $*"

echo "== 5/5 conferência"
docker compose exec -T app php tools/help.php check
