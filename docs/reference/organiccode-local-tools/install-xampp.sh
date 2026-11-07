#!/usr/bin/env bash
# Adds Organic Code to the shared XAMPP stack. Never deletes, overwrites or recreates anything.
set -euo pipefail

project=$(cd "$(dirname "$0")/../.." && pwd)
xampp=$(cd "$project/../.." && pwd)
hosts=(organiccode.lab www.organiccode.lab app.organiccode.lab cms.organiccode.lab erp.organiccode.lab send.organiccode.lab)
crt="$xampp/certs/organiccode.pem"
key="$xampp/certs/organiccode-key.pem"
src_crt="$project/.local/certs/organiccode.pem"
src_key="$project/.local/certs/organiccode-key.pem"
conf_src="$project/tools/local/organiccode.conf"
conf="$xampp/apache/organiccode.conf"
db_env="$project/.local/db.env"
backup="$xampp/backups/organiccode-$(date +%Y%m%d-%H%M%S)"

die() { echo "ERRO: $*" >&2; exit 1; }

confirm() {
  local answer
  read -r -p "$1 [s/N] " answer
  [[ "$answer" =~ ^[sS]$ ]] || { echo "Interrompido; nada mais foi alterado."; exit 0; }
}

covers_all_hosts() {
  local san host
  san=$(openssl x509 -in "$1" -noout -text | grep 'DNS:' | tr -d ' ')
  for host in "${hosts[@]}"; do
    grep -Eq "DNS:$host(,|$)" <<<"$san" || return 1
  done
}

key_matches() {
  [[ "$(openssl x509 -in "$1" -noout -pubkey)" == "$(openssl pkey -in "$2" -pubout)" ]]
}

for container in dev-apache dev-mysql; do
  [[ "$(docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null)" == "true" ]] || die "$container não está em execução."
done
[[ -d "$xampp/apache" && -d "$xampp/certs" && -d "$xampp/backups" ]] || die "XAMPP não encontrado em $xampp."

echo "1/5 Backup e retrato do estado atual em $backup"
confirm "Criar o backup?"
mkdir -p "$backup"
cp -Rp "$xampp/apache" "$xampp/docker-compose.yml" "$xampp/Dockerfile" "$backup/"
cp -p /etc/hosts "$backup/hosts"
docker exec dev-apache ls -la /etc/apache2/sites-enabled/ >"$backup/sites-enabled.txt"
docker exec dev-apache apache2ctl -S >"$backup/vhosts.txt" 2>&1 || true
"$project/tools/local/test-local.sh" --probe >"$backup/existing-domains.txt"
echo "Backup criado."

echo "2/5 Certificado em certs/organiccode.pem"
if [[ -e "$crt" || -e "$key" ]]; then
  [[ -f "$crt" && -f "$key" ]] || die "Só um dos arquivos do certificado existe; corrija manualmente."
  covers_all_hosts "$crt" || die "O certificado existente não cobre os 6 domínios; não vou sobrescrevê-lo."
  key_matches "$crt" "$key" || die "O certificado existente não corresponde à chave."
  echo "Certificado existente mantido."
else
  confirm "Criar o certificado do Organic Code em certs/?"
  if [[ -f "$src_crt" && -f "$src_key" ]] && covers_all_hosts "$src_crt" && key_matches "$src_crt" "$src_key"; then
    cp -n "$src_crt" "$crt"
    cp -n "$src_key" "$key"
  else
    echo "Certificado de .local/certs ausente ou incompleto; gerando com mkcert."
    mkcert -cert-file "$crt" -key-file "$key" "${hosts[@]}"
  fi
  chmod 600 "$key"
  covers_all_hosts "$crt" || die "O certificado criado não cobre os 6 domínios."
fi

echo "3/5 VirtualHost em apache/organiccode.conf"
if [[ -e "$conf" ]]; then
  cmp -s "$conf_src" "$conf" || die "apache/organiccode.conf já existe e é diferente; não vou sobrescrevê-lo."
else
  confirm "Adicionar apache/organiccode.conf?"
  cp -n "$conf_src" "$conf"
fi

echo "4/5 Ativação no dev-apache, sem recriar o container"
if docker exec dev-apache test -e /etc/apache2/sites-enabled/organiccode.conf; then
  docker exec -i dev-apache cmp -s /etc/apache2/sites-available/organiccode.conf - <"$conf" \
    || die "O site organiccode já está ativo com outra configuração."
  echo "Site já ativo."
else
  confirm "Ativar o site e recarregar o Apache (graceful)?"
  docker exec dev-apache test ! -e /etc/apache2/sites-available/organiccode.conf \
    || die "sites-available/organiccode.conf já existe no container; revise manualmente."
  docker cp "$conf" dev-apache:/etc/apache2/sites-available/organiccode.conf
  docker exec dev-apache a2ensite organiccode >/dev/null
  if ! docker exec dev-apache apache2ctl configtest; then
    docker exec dev-apache a2dissite organiccode >/dev/null
    die "configtest falhou; site desativado e Apache não recarregado."
  fi
  docker exec dev-apache apache2ctl graceful
  echo "Apache recarregado."
fi

echo "5/5 Banco organiccode_dev e usuário organiccode"
confirm "Criar o banco e o usuário, se ainda não existirem?"
user_exists=$(docker exec dev-mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e "SELECT COUNT(*) FROM mysql.user WHERE user=\"organiccode\""')
if [[ ! -f "$db_env" ]]; then
  [[ "$user_exists" == "0" ]] || die "O usuário organiccode já existe, mas .local/db.env não; não vou trocar a senha."
  mkdir -p "$(dirname "$db_env")"
  (
    umask 077
    printf 'DB_HOST=mysql\nDB_PORT=3306\nDB_DATABASE=organiccode_dev\nDB_USERNAME=organiccode\nDB_PASSWORD=%s\n' "$(openssl rand -hex 24)" >"$db_env"
  )
fi
db_password=$(sed -n 's/^DB_PASSWORD=//p' "$db_env")
[[ "$db_password" =~ ^[A-Za-z0-9]+$ ]] || die "DB_PASSWORD em .local/db.env deve ser alfanumérica."
docker exec -i dev-mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot' <<SQL
CREATE DATABASE IF NOT EXISTS organiccode_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'organiccode'@'%' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON organiccode_dev.* TO 'organiccode'@'%';
SQL
chmod 600 "$project/.env"
echo "Banco pronto; credenciais em .local/db.env. Copie os valores DB_* para o .env do projeto."

echo "Concluído. Rode: tools/local/test-local.sh $backup/existing-domains.txt"
