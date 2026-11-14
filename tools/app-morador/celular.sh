#!/usr/bin/env bash
# Abre o app do morador (apps/morador) no celular pelo Expo Go, falando com o Connect do Docker do Mac.
# Uso, na pasta do projeto:   bash tools/app-morador/celular.sh
# O celular precisa estar no mesmo Wi-Fi do Mac. Só para o laboratório: nada aqui vale para produção.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
bridge_port="${PONTE_PORTA:-8090}"
connect_port="${CONNECT_HTTP_PORT:-8088}"

say() { printf '\n== %s\n' "$*"; }
stop() { printf '\nPAROU: %s\n' "$*" >&2; exit 1; }

say "1/5 Node"
command -v node >/dev/null || stop "instale o Node 22 (https://nodejs.org) e rode de novo."
major="$(node -p 'process.versions.node.split(".")[0]')"
[ "$major" -ge 20 ] || stop "o Expo precisa do Node 20 ou mais novo (este Mac tem $(node -v))."
echo "Node $(node -v)"

say "2/5 Connect no Docker"
if ! curl -fsS "http://127.0.0.1:${connect_port}/api/v1/health" >/dev/null 2>&1; then
  docker compose up -d --wait legacy-db app || stop "o Docker não subiu o Connect. Abra o Docker Desktop e rode de novo."
fi
curl -fsS "http://127.0.0.1:${connect_port}/api/v1/health" >/dev/null || stop "o Connect não respondeu em 127.0.0.1:${connect_port}."
echo "Connect respondendo em 127.0.0.1:${connect_port}"

say "3/5 Endereço do Mac na rede"
ip="${MAC_IP:-}"
if [ -z "$ip" ]; then
  for nic in en0 en1 en2; do
    ip="$(ipconfig getifaddr "$nic" 2>/dev/null || true)"
    [ -n "$ip" ] && break
  done
fi
[ -n "$ip" ] || stop "não achei o IP do Mac. Rode de novo com MAC_IP=192.168.x.x bash tools/app-morador/celular.sh"
echo "Mac em ${ip}"

say "4/5 Ponte para o celular (porta ${bridge_port})"
node tools/app-morador/ponte.mjs "$bridge_port" "$connect_port" &
bridge_pid=$!
trap 'kill "$bridge_pid" 2>/dev/null || true' EXIT
sleep 1
curl -fsS "http://${ip}:${bridge_port}/api/v1/health" >/dev/null || stop "a ponte não respondeu em ${ip}:${bridge_port}. Se o macOS perguntar sobre conexões de entrada para o node, clique em Permitir."
echo "Ponte ok: http://${ip}:${bridge_port}"

say "5/5 Expo (leia o QR code com o celular)"
cd apps/morador
[ -d node_modules ] && [ node_modules -nt package-lock.json ] || npm install
EXPO_PUBLIC_API_URL="http://${ip}:${bridge_port}" npx expo start --lan --clear
