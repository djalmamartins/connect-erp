#!/usr/bin/env bash
# Connect ERP — traz a main, faz backup, instala, testa, aplica as migrations e mostra o resumo.
#
#   cd "/Volumes/MAC HD/Docker/xampp/www/connect"
#   bash tools/testar-main.sh
#
# Opções (variáveis antes do comando):
#   SEM_PULL=1          não faz git pull (testa o que já está na pasta)
#   MIGRATE_UNTIL=20261108_0546   aplica as migrations só até esse prefixo
#   SEM_HTTP=1          pula a conferência das páginas por HTTP
#   FORCAR_MIGRATIONS=1 aplica as migrations mesmo com falha na integração (o backup é feito antes)
#
# Ordem e travas:
#   0. confere a pasta: precisa estar na main e sem alteração local em arquivo versionado;
#   1. git pull --ff-only da main (nunca faz merge nem reescreve nada);
#   2. sobe o banco (legacy-db) e o app pelo compose;
#   3. backup do banco (tools/backup-database.sh) e dos arquivos privados (tools/backup-files.sh).
#      Sem backup, para aqui;
#   4. composer install dentro do container;
#   5. testes: unitários, integração e smoke das telas do ERP. Os testes rodam SÓ por
#      `composer test:unit` / `composer test:integration`, que usam o banco movesos_test
#      (o nome precisa terminar em _test). NUNCA rode vendor/bin/phpunit direto: em 07/11/2026
#      isso esvaziou o banco real connect_legacy;
#   6. migrations no banco do lab, só se os testes de integração passaram;
#   7. reconstrói o scheduler e confere as páginas principais por HTTP;
#   8. mostra o resumo. Tudo fica gravado em .local/logs/testar-main-AAAAMMDD-HHMMSS/.
#
# O script não apaga nada, não roda `docker compose down -v` e não envia e-mail nem boleto real.
set -uo pipefail

project=$(cd "$(dirname "$0")/.." && pwd)
cd "$project" || exit 1

stamp=$(date +%Y%m%d-%H%M%S)
logs="$project/.local/logs/testar-main-$stamp"
mkdir -p "$logs"

# Resumo: uma linha por etapa ("etapa|situação|detalhe"). Compatível com o bash 3.2 do macOS.
summary=()
add() { summary+=("$1|$2|$3"); }
say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
strip() { sed -E $'s/\x1b\\[[0-9;]*m//g'; }

# Linha final do PHPUnit ("OK (501 tests, ...)" ou "Tests: 10, Assertions: ..., Failures: 1").
phpunit_line() {
  strip <"$1" | grep -E '^(OK \(|OK, but|Tests: |FAILURES!|ERRORS!|No tests executed)' | tail -1
}

finish() {
  say "RESUMO"
  printf '%-34s %-9s %s\n' "Etapa" "Estado" "Detalhe"
  printf '%-34s %-9s %s\n' "-----" "------" "-------"
  local line step state detail
  for line in ${summary[@]+"${summary[@]}"}; do
    step=${line%%|*}; line=${line#*|}
    state=${line%%|*}; detail=${line#*|}
    case "$state" in
      OK) color='\033[32m' ;;
      FALHOU) color='\033[31m' ;;
      *) color='\033[33m' ;;
    esac
    # Preenche pelo número de caracteres (o printf do bash 3.2 conta bytes e desalinha os acentos).
    printf "%s%*s ${color}%s\033[0m%*s %s\n" "$step" $((34 - ${#step})) '' "$state" $((9 - ${#state})) '' "$detail"
  done
  echo
  echo "Logs completos: $logs"
  echo "Guia das páginas: docs/guides/GUIA_DO_SISTEMA.md"
}
trap finish EXIT

stop() {
  echo "PARADO: $1" >&2
  exit 1
}

# --- 0. conferências -------------------------------------------------------------------------
say "0/8 conferindo a pasta"
git rev-parse --git-dir >/dev/null 2>&1 || stop "esta pasta não é um repositório git."
branch=$(git rev-parse --abbrev-ref HEAD)
if [ "$branch" != "main" ]; then
  add "Pasta" FALHOU "está no branch $branch; rode: git checkout main"
  stop "o script só roda na main."
fi
if ! git diff --quiet || ! git diff --cached --quiet; then
  git status --short | grep -v '^??' >"$logs/alteracoes-locais.txt"
  add "Pasta" FALHOU "há arquivos versionados alterados (ver alteracoes-locais.txt)"
  echo "Arquivos alterados nesta pasta:" >&2
  cat "$logs/alteracoes-locais.txt" >&2
  stop "guarde ou descarte essas alterações antes (o script não mexe nelas)."
fi
[ -f .env ] || { add "Pasta" FALHOU "falta o .env (LEGACY_DB_PASSWORD)"; stop "sem .env o compose não sobe."; }
docker info >/dev/null 2>&1 || { add "Pasta" FALHOU "Docker Desktop não está rodando"; stop "abra o Docker Desktop."; }
add "Pasta" OK "main, sem alterações locais"

# --- 1. git pull ------------------------------------------------------------------------------
say "1/8 trazendo a main"
before=$(git rev-parse --short HEAD)
if [ "${SEM_PULL:-0}" = "1" ]; then
  add "git pull" PULADO "SEM_PULL=1; ficou em $before"
else
  if git pull --ff-only origin main 2>&1 | tee "$logs/git-pull.log"; then
    after=$(git rev-parse --short HEAD)
    if [ "$before" = "$after" ]; then
      add "git pull" OK "já estava atualizada em $after"
    else
      add "git pull" OK "$before -> $after ($(git rev-list --count "$before..$after") commits)"
    fi
  else
    add "git pull" FALHOU "ver git-pull.log (a main local divergiu da origin?)"
    stop "não foi possível atualizar a main por fast-forward."
  fi
fi
echo "main em $(git log -1 --format='%h %s')"

# --- 2. containers ----------------------------------------------------------------------------
say "2/8 subindo o banco e o app"
if docker compose up -d --wait legacy-db app 2>&1 | tee "$logs/compose-up.log"; then
  add "Containers" OK "legacy-db e app saudáveis"
else
  add "Containers" FALHOU "ver compose-up.log"
  stop "o compose não ficou saudável."
fi

# --- 3. backup --------------------------------------------------------------------------------
say "3/8 backup do banco e dos arquivos privados"
backup=$(bash tools/backup-database.sh 2>"$logs/backup.err")
if [ -n "$backup" ] && [ -s "$backup" ] && [ "$(gzip -dc "$backup" 2>/dev/null | head -c 2000 | wc -c)" -gt 100 ]; then
  files_backup=$(bash tools/backup-files.sh 2>>"$logs/backup.err") || files_backup=""
  if [ -n "$files_backup" ]; then
    add "Backup" OK "$(basename "$backup") ($(du -h "$backup" | cut -f1)) + arquivos"
    echo "banco: $backup"
    echo "arquivos: $files_backup"
  else
    add "Backup" FALHOU "arquivos privados (ver backup.err)"
    stop "backup dos arquivos falhou; nada foi alterado."
  fi
else
  add "Backup" FALHOU "dump vazio ou com erro (ver backup.err)"
  stop "sem backup do banco o script não continua; nada foi alterado."
fi

# --- 4. composer install ----------------------------------------------------------------------
say "4/8 dependências (composer install no container)"
if docker compose exec -T app composer install --no-interaction --no-progress --prefer-dist >"$logs/composer-install.log" 2>&1; then
  add "composer install" OK ""
else
  tail -20 "$logs/composer-install.log"
  add "composer install" FALHOU "ver composer-install.log"
  stop "dependências não instalaram; o banco não foi tocado."
fi

# --- 5. testes --------------------------------------------------------------------------------
say "5/8 testes (banco movesos_test, nunca o connect_legacy)"
echo "-- unitários"
if docker compose exec -T app composer test:unit >"$logs/test-unit.log" 2>&1; then
  add "Testes unitários" OK "$(phpunit_line "$logs/test-unit.log")"
else
  add "Testes unitários" FALHOU "$(phpunit_line "$logs/test-unit.log") (ver test-unit.log)"
fi
tail -3 "$logs/test-unit.log" | strip

echo "-- integração"
integration_ok=0
if docker compose exec -T app composer test:integration >"$logs/test-integration.log" 2>&1; then
  integration_ok=1
  add "Testes de integração" OK "$(phpunit_line "$logs/test-integration.log")"
else
  add "Testes de integração" FALHOU "$(phpunit_line "$logs/test-integration.log") (ver test-integration.log)"
fi
tail -3 "$logs/test-integration.log" | strip

echo "-- smoke das telas do ERP (sem banco)"
if docker compose exec -T app php tests/erp/render-smoke.php >"$logs/render-smoke.log" 2>&1; then
  add "Smoke das telas do ERP" OK "$(grep -E 'verificações' "$logs/render-smoke.log" | tail -1)"
else
  add "Smoke das telas do ERP" FALHOU "$(grep -E 'verificações' "$logs/render-smoke.log" | tail -1) (ver render-smoke.log)"
fi

# --- 6. migrations ----------------------------------------------------------------------------
say "6/8 migrations no banco do lab"
until_arg=""
[ -n "${MIGRATE_UNTIL:-}" ] && until_arg="--until=$MIGRATE_UNTIL"
docker compose exec -T app php service/commands/database-migrate.php status $until_arg >"$logs/migrations-antes.log" 2>&1
pending=$(grep -c '^\[pendente\]' "$logs/migrations-antes.log" || true)
echo "pendentes antes: $pending"
if [ "$integration_ok" != "1" ] && [ "${FORCAR_MIGRATIONS:-0}" != "1" ]; then
  add "Migrations" PULADO "integração falhou; $pending pendente(s) sem aplicar (FORCAR_MIGRATIONS=1 aplica assim mesmo)"
elif [ "$pending" = "0" ]; then
  add "Migrations" OK "nenhuma pendente; última: $(grep '^\[aplicada\]' "$logs/migrations-antes.log" | tail -1 | sed 's/\[aplicada\] //')"
else
  if docker compose exec -T app php service/commands/database-migrate.php apply $until_arg 2>&1 | tee "$logs/migrations-apply.log"; then
    add "Migrations" OK "$pending aplicada(s); última: $(grep '^\[executada\]' "$logs/migrations-apply.log" | tail -1 | sed 's/\[executada\] //')"
  else
    add "Migrations" FALHOU "ver migrations-apply.log; restaurar com o backup $(basename "$backup") se preciso"
  fi
fi

# --- 7. scheduler e HTTP ----------------------------------------------------------------------
say "7/8 scheduler e páginas por HTTP"
if docker compose up -d --build scheduler >"$logs/scheduler.log" 2>&1; then
  add "Scheduler" OK "reconstruído (avisos só em log/sandbox)"
else
  add "Scheduler" FALHOU "ver scheduler.log"
fi

if [ "${SEM_HTTP:-0}" = "1" ]; then
  add "HTTP" PULADO "SEM_HTTP=1"
else
  # Sem login: as páginas protegidas devem redirecionar (302) para o login; as públicas, 200.
  # Formato: "URL|códigos aceitos|nome".
  checks=(
    "http://127.0.0.1:${CONNECT_HTTP_PORT:-8088}/api/v1/health|200|API /api/v1/health"
    "https://erp.connect.lab/login|200|ERP login"
    "https://erp.connect.lab/dashboard|302 303|ERP dashboard (sem login → login)"
    "https://erp.connect.lab/verificar|200|Verificação pública /verificar"
    "https://erp.connect.lab/convite|200|Convite de morador /convite"
    "https://www.connect.lab/studio/login|200|Studio login"
    "https://www.connect.lab/helpdesk/login|200|Help Desk login"
    "https://www.connect.lab/|200 302 503|Site público"
  )
  : >"$logs/http.log"
  http_fail=0; http_ok=0
  for c in "${checks[@]}"; do
    url=${c%%|*}; rest=${c#*|}; want=${rest%%|*}; name=${rest#*|}
    code=$(curl -ks -o /dev/null -m 15 -w '%{http_code}' "$url" || echo 000)
    if echo " $want " | grep -q " $code "; then
      http_ok=$((http_ok + 1)); mark=OK
    else
      http_fail=$((http_fail + 1)); mark=FALHOU
    fi
    printf '%-7s %s  %s  (%s)\n' "$mark" "$code" "$name" "$url" | tee -a "$logs/http.log"
  done
  if [ "$http_fail" = "0" ]; then
    add "HTTP (sem login)" OK "$http_ok de ${#checks[@]} responderam como esperado"
  else
    add "HTTP (sem login)" FALHOU "$http_fail de ${#checks[@]} fora do esperado (ver http.log)"
  fi
fi

say "8/8 estado das migrations depois"
docker compose exec -T app php service/commands/database-migrate.php status >"$logs/migrations-depois.log" 2>&1
left=$(grep -c '^\[pendente\]' "$logs/migrations-depois.log" || true)
echo "pendentes agora: $left"
add "Migrations pendentes agora" "$([ "$left" = "0" ] && echo OK || echo ATENÇÃO)" "$left"

failed=0
for line in ${summary[@]+"${summary[@]}"}; do
  case "$line" in *"|FALHOU|"*) failed=1 ;; esac
done
exit $failed
