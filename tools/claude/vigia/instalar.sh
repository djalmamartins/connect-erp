#!/usr/bin/env bash
# Connect ERP — instala (ou remove) o vigia de 30 minutos no launchd do usuário.
#
#   bash tools/claude/vigia/instalar.sh            # instala e liga
#   bash tools/claude/vigia/instalar.sh --testar   # mostra qual issue pegaria, sem rodar o Claude
#   bash tools/claude/vigia/instalar.sh --remover  # desliga e apaga o agente
#
# Copia vigia.sh e prompt.md para ~/Library/Application Support/connect-erp-vigia/bin, para que
# trocar de branch no checkout não mude o que o launchd executa. Rode de novo para atualizar.
set -euo pipefail

label=br.com.connect.erp.vigia
here=$(cd "$(dirname "$0")" && pwd)
bin="$HOME/Library/Application Support/connect-erp-vigia/bin"
plist="$HOME/Library/LaunchAgents/$label.plist"
domain="gui/$(id -u)"

case "${1:-}" in
  --remover)
    launchctl bootout "$domain/$label" 2>/dev/null || true
    rm -f "$plist"
    echo "Vigia removido. Logs continuam em ~/Library/Logs/connect-erp-vigia/."
    exit 0 ;;
  --testar)
    VIGIA_DRY_RUN=1 VIGIA_FORCE=1 bash "$here/vigia.sh"
    exit 0 ;;
esac

command -v gh >/dev/null || { echo "Instale o gh (brew install gh) e rode gh auth login." >&2; exit 1; }
command -v claude >/dev/null || [ -x "$HOME/.local/bin/claude" ] || { echo "Claude Code não encontrado no PATH." >&2; exit 1; }

mkdir -p "$bin" "$HOME/Library/LaunchAgents" "$HOME/Library/Logs/connect-erp-vigia"
cp "$here/vigia.sh" "$here/prompt.md" "$bin/"
chmod 755 "$bin/vigia.sh"

# Rótulos da fila (ignora erro se já existirem).
for spec in "precisa-djalma|d93f0b|Depende de decisão ou ação do Djalma; o vigia pula" \
            "em-andamento|fbca04|O vigia está trabalhando nesta issue agora" \
            "prioridade|b60205|O vigia pega estas primeiro"; do
  IFS='|' read -r name color desc <<<"$spec"
  gh label create "$name" --repo djalmamartins/connect-erp --color "$color" --description "$desc" >/dev/null 2>&1 || true
done

cat >"$plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$label</string>
  <key>ProgramArguments</key>
  <array><string>/bin/bash</string><string>$bin/vigia.sh</string></array>
  <key>StartInterval</key><integer>1800</integer>
  <key>RunAtLoad</key><false/>
  <key>ProcessType</key><string>Background</string>
  <key>StandardOutPath</key><string>$HOME/Library/Logs/connect-erp-vigia/launchd.out.log</string>
  <key>StandardErrorPath</key><string>$HOME/Library/Logs/connect-erp-vigia/launchd.err.log</string>
</dict>
</plist>
EOF
plutil -lint "$plist" >/dev/null
launchctl bootout "$domain/$label" 2>/dev/null || true
launchctl bootstrap "$domain" "$plist"
echo "Vigia instalado: roda a cada 30 minutos. Log: ~/Library/Logs/connect-erp-vigia/vigia.log"
echo "Testar agora sem rodar o Claude: bash $here/instalar.sh --testar"
