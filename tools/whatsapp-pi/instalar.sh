#!/usr/bin/env bash
# Instala o motor do WhatsApp do Connect Talk (Evolution) num Raspberry Pi 4/5 com Raspberry Pi OS 64 bits.
# Também serve para qualquer Linux com Docker (VPS). Rodar de novo só confere: não apaga nada, não troca chaves.
#
#   bash instalar.sh
#
# Precisa: internet e o token do túnel da Cloudflare (README.md, passo 3). Não pede nem guarda senha do ERP.
set -euo pipefail

dir=/opt/connect-whatsapp
here=$(cd "$(dirname "$0")" && pwd)
envf="$dir/.env"

say() { printf '\n== %s\n' "$*"; }
die() { echo "ERRO: $*" >&2; exit 1; }
envget() { { grep -E "^$1=" "$envf" 2>/dev/null || true; } | tail -1 | cut -d= -f2-; }
envset() { # acrescenta só se ainda não existir
  [[ -n "$(envget "$1")" ]] && return 0
  sed -i "/^$1=/d" "$envf"; echo "$1=$2" >>"$envf"
}
sudo_() { if [[ $EUID -eq 0 ]]; then "$@"; else sudo "$@"; fi; }

say "1/6 Conferindo o aparelho"
arch=$(uname -m)
[[ "$arch" == "aarch64" || "$arch" == "x86_64" ]] || die "processador $arch: use o Raspberry Pi OS de 64 bits (aarch64)."
mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
(( mem >= 1800 )) || echo "Aviso: ${mem} MB de RAM. O recomendado é 2 GB ou mais."
root_dev=$(findmnt -n -o SOURCE /)
if [[ "$root_dev" == /dev/mmcblk* ]]; then
  echo "Aviso: o sistema está no cartão SD ($root_dev). Com o banco gravando o tempo todo o cartão estraga em meses."
  echo "       O recomendado é ligar o Pi por um SSD no USB 3 (README.md, passo 1)."
fi

say "2/6 Docker"
if ! command -v docker >/dev/null; then
  echo "Instalando o Docker (script oficial get.docker.com)…"
  curl -fsSL https://get.docker.com | sudo_ sh
  sudo_ usermod -aG docker "${SUDO_USER:-$USER}" || true
fi
sudo_ systemctl enable --now docker >/dev/null 2>&1 || true
docker_() { sudo_ docker "$@"; }
docker_ compose version >/dev/null || die "o Docker não tem o 'docker compose'. Reinstale pelo get.docker.com."

say "3/6 Pasta $dir e chaves (.env)"
sudo_ mkdir -p "$dir"
sudo_ cp "$here/docker-compose.yml" "$dir/docker-compose.yml"
[[ -f "$envf" ]] || { sudo_ touch "$envf"; }
sudo_ chown "$(id -u):$(id -g)" "$envf"; chmod 600 "$envf"
envset EVOLUTION_API_KEY "$(openssl rand -hex 24)"
envset DB_PASSWORD "$(openssl rand -hex 24)"
host=$(envget EVOLUTION_HOST)
if [[ -z "$host" ]]; then
  read -r -p "Endereço público do motor [whatsapp.connectcondominios.com.br]: " host
  envset EVOLUTION_HOST "${host:-whatsapp.connectcondominios.com.br}"
fi
if [[ -z "$(envget CLOUDFLARE_TUNNEL_TOKEN)" ]]; then
  echo "Cole o token do túnel da Cloudflare (README.md, passo 3). Ele começa com 'eyJ'."
  read -r -s -p "Token: " tok; echo
  [[ "$tok" == eyJ* ]] || die "isso não parece o token do túnel. Rode de novo e cole só o token."
  envset CLOUDFLARE_TUNNEL_TOKEN "$tok"
fi
echo "Chaves guardadas em $envf (só o seu usuário lê)."

say "4/6 Baixando e ligando (a primeira vez demora alguns minutos)"
cd "$dir"
docker_ compose pull -q
docker_ compose up -d

say "5/6 Esperando o motor responder"
key=$(envget EVOLUTION_API_KEY)
ok=0
for _ in $(seq 1 60); do
  if curl -fsS -H "apikey: $key" http://127.0.0.1:8080/instance/fetchInstances >/dev/null 2>&1; then ok=1; break; fi
  sleep 3
done
(( ok )) || { docker_ compose logs --tail 40 whatsapp; die "o motor não respondeu em 3 minutos (logs acima)."; }
echo "Motor respondendo no próprio Pi."
pub="https://$(envget EVOLUTION_HOST)"
if curl -fsS -m 15 -H "apikey: $key" "$pub/instance/fetchInstances" >/dev/null 2>&1; then
  echo "Motor respondendo também pela internet em $pub."
else
  echo "Aviso: $pub ainda não respondeu. Confira no painel da Cloudflare se o túnel está 'Healthy' e se o"
  echo "       Public Hostname aponta para http://whatsapp:8080 (README.md, passo 3). O resto já está ligado."
fi

say "6/6 Pronto. Cole estas duas linhas no .env do ERP (no servidor, nunca no Git):"
echo
echo "EVOLUTION_URL=$pub"
echo "EVOLUTION_API_KEY=$key"
echo
echo "Depois, no Talk: Conexões > Novo número > QR Code. O Pi liga sozinho depois de faltar luz."
