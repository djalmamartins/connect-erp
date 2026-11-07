#!/usr/bin/env bash
# Read-only checks. Usage: test-local.sh [baseline-file] | test-local.sh --probe
set -uo pipefail

project=$(cd "$(dirname "$0")/../.." && pwd)
https_port=443
http_port=8080
existing=(www.saas.lab www.realizze.lab app.realizze.lab cms.realizze.lab www.moves.lab www.mvs.lab www.code.lab www.connect.lab homolog.realizze.lab app.homolog.realizze.lab)
failures=0

probe_existing() {
  local host
  for host in "${existing[@]}"; do
    printf '%s %s\n' "$host" "$(curl -ks -o /dev/null -m 10 -w '%{http_code}' "https://$host:$https_port/")"
  done
}

if [[ "${1:-}" == "--probe" ]]; then
  probe_existing
  exit 0
fi
baseline=${1:-}

check() {
  local label=$1 expected=$2 actual=$3
  if [[ "$actual" == "$expected" ]]; then
    echo "OK    $label"
  else
    echo "FALHA $label (esperado: $expected; obtido: $actual)"
    failures=$((failures + 1))
  fi
}

ca="$(mkcert -CAROOT)/rootCA.pem"

echo "== Apache"
check "configtest" "Syntax OK" "$(docker exec dev-apache apache2ctl configtest 2>&1 | grep -o 'Syntax OK')"

echo "== DNS"
for host in organiccode.lab www.organiccode.lab app.organiccode.lab cms.organiccode.lab erp.organiccode.lab send.organiccode.lab; do
  check "$host resolve" "127.0.0.1" "$(dscacheutil -q host -a name "$host" | awk '/^ip_address/{print $2; exit}')"
done

echo "== HTTPS com certificado validado pela CA do mkcert"
for pair in www:web app:app cms:cms erp:erp send:send; do
  host="${pair%%:*}.organiccode.lab"
  check "$host" "200 ${pair##*:}" "$(curl -sS --cacert "$ca" -m 10 -D - -o /dev/null "https://$host:$https_port/" 2>&1 | awk 'NR==1{code=$2} tolower($1)=="x-organic-environment:"{gsub(/\r/,"",$2); env=$2} END{print code, env}')"
done
check "organiccode.lab redireciona" "301 https://www.organiccode.lab/" "$(curl -sS --cacert "$ca" -m 10 -o /dev/null -w '%{http_code} %{redirect_url}' "https://organiccode.lab:$https_port/" 2>&1)"
check "HTTP redireciona para HTTPS" "301 https://app.organiccode.lab/" "$(curl -sS -m 10 -o /dev/null -w '%{http_code} %{redirect_url}' "http://app.organiccode.lab:$http_port/" 2>&1)"
check ".env fora do DocumentRoot" "404" "$(curl -s --cacert "$ca" -m 10 -o /dev/null -w '%{http_code}' "https://www.organiccode.lab:$https_port/.env")"

echo "== Banco"
db_env="$project/.local/db.env"
if [[ -f "$db_env" ]]; then
  db_password=$(sed -n 's/^DB_PASSWORD=//p' "$db_env")
  check "PHP conecta do dev-apache em organiccode_dev" "organiccode_dev" "$(docker exec -e OC_DB_PASSWORD="$db_password" dev-apache php -r '$p = new PDO("mysql:host=mysql;dbname=organiccode_dev", "organiccode", getenv("OC_DB_PASSWORD")); echo $p->query("SELECT DATABASE()")->fetchColumn();' 2>&1)"
  visible=$(docker exec -e MYSQL_PWD="$db_password" dev-mysql mysql -uorganiccode -N -e 'SHOW DATABASES' 2>/dev/null | grep -vE '^(information_schema|performance_schema)$' | tr '\n' ' ' | sed 's/ $//')
  check "usuário só enxerga o próprio banco" "organiccode_dev" "$visible"
else
  check "credenciais em .local/db.env" "presente" "ausente"
fi

echo "== Regressão nos domínios existentes"
if [[ -n "$baseline" ]]; then
  check "códigos HTTP iguais ao estado anterior" "$(cat "$baseline")" "$(probe_existing)"
else
  probe_existing
fi

echo
if [[ "$failures" -eq 0 ]]; then
  echo "Todos os testes passaram."
else
  echo "$failures teste(s) falharam."
  exit 1
fi
