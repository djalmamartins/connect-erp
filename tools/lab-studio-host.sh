#!/usr/bin/env bash
# Connect ERP — liga o host studio.connect.lab no lab (Studio e Help Desk na moldura do ERP).
#
#   bash tools/lab-studio-host.sh
#
# O vhost já está em xampp/apache/connect.conf e o hosts em xampp/scripts/update-lab-hosts.sh.
# Este script faz o que o sandbox do Claude não pode:
#   1. gera o certificado com o mkcert em xampp/certs/studio-connect{,-key}.pem (se faltar);
#   2. atualiza o /etc/hosts com sudo (pede a sua senha do Mac);
#   3. reconstrói o Apache do lab (a configuração é copiada na build da imagem).
set -euo pipefail

xampp=${XAMPP_DIR:-"/Volumes/MAC HD/Docker/xampp"}
certs="$xampp/certs"
host=studio.connect.lab

grep -q "ServerName $host" "$xampp/apache/connect.conf" || { echo "vhost de $host não está em $xampp/apache/connect.conf" >&2; exit 1; }

echo "== 1/3 certificado"
if [ -s "$certs/studio-connect.pem" ] && [ -s "$certs/studio-connect-key.pem" ]; then
  echo "já existe: $certs/studio-connect.pem"
else
  command -v mkcert >/dev/null || { echo "Instale o mkcert: brew install mkcert && mkcert -install" >&2; exit 1; }
  mkcert -cert-file "$certs/studio-connect.pem" -key-file "$certs/studio-connect-key.pem" "$host"
fi

echo "== 2/3 /etc/hosts (sudo)"
sudo bash "$xampp/scripts/update-lab-hosts.sh"

echo "== 3/3 Apache do lab"
docker compose --project-directory "$xampp" up -d --build apache

echo "Pronto: https://$host"
