#!/usr/bin/env bash
# Connect ERP — vigia de 30 minutos (ADR 0016).
#
# O launchd chama este script a cada 30 minutos (br.com.connect.erp.vigia.plist). Ele:
#   1. sai se outra rodada do vigia ainda estiver viva (trava em ~/Library/Application Support);
#   2. sai se houve trabalho recente (algum arquivo do repositório ou transcript do Claude
#      Code mexido nos últimos VIGIA_IDLE_MIN minutos): o trabalho não parou;
#   3. lê as issues abertas de djalmamartins/connect-erp, sem as que têm o rótulo
#      precisa-djalma (dependem do Djalma) ou em-andamento;
#   4. pega a primeira (rótulo prioridade primeiro, depois a mais antiga) e roda o Claude
#      Code headless num worktree só do vigia, numa branch vigia/issue-N, com o prompt
#      prompt.md. O Claude abre PR em rascunho para o claude-dev e comenta na issue.
#
# Nunca faz merge na main, nunca roda migration no banco do lab e nunca roda phpunit direto:
# isso está no prompt e na guarda tools/claude/connect-erp-guard.sh.
#
# Variáveis (opcionais): VIGIA_REPO, VIGIA_MAIN, VIGIA_WORKTREE, VIGIA_IDLE_MIN (30),
# VIGIA_MAX_MIN (180), VIGIA_BUDGET_USD (15), VIGIA_DRY_RUN=1 (só mostra o que faria).
# Compatível com o bash 3.2 do macOS.

set -uo pipefail
export PATH="/opt/homebrew/bin:/usr/local/bin:$HOME/.local/bin:/usr/bin:/bin:/usr/sbin:/sbin"

REPO=${VIGIA_REPO:-djalmamartins/connect-erp}
MAIN=${VIGIA_MAIN:-"/Volumes/MAC HD/Docker/xampp/www/connect"}
WORKTREES=$(dirname "$MAIN")/../worktrees
WORKTREES=$(cd "$WORKTREES" 2>/dev/null && pwd || echo "/Volumes/MAC HD/Docker/xampp/worktrees")
WT=${VIGIA_WORKTREE:-"$WORKTREES/connect-claude-vigia"}
IDLE_MIN=${VIGIA_IDLE_MIN:-30}
MAX_MIN=${VIGIA_MAX_MIN:-180}
BUDGET=${VIGIA_BUDGET_USD:-15}
HERE=$(cd "$(dirname "$0")" && pwd)
STATE="$HOME/Library/Application Support/connect-erp-vigia"
LOG_DIR="$HOME/Library/Logs/connect-erp-vigia"
mkdir -p "$STATE" "$LOG_DIR"
LOG="$LOG_DIR/vigia.log"

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"; [ -t 1 ] && printf '%s\n' "$*"; return 0; }

# 1. Trava: uma rodada por vez.
LOCK="$STATE/lock"
if ! mkdir "$LOCK" 2>/dev/null; then
  pid=$(cat "$LOCK/pid" 2>/dev/null || echo "")
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    log "rodada anterior (pid $pid) ainda ativa; nada a fazer"
    exit 0
  fi
  log "trava órfã removida (pid ${pid:-?})"
  rm -rf "$LOCK"; mkdir "$LOCK"
fi
echo $$ >"$LOCK/pid"
trap 'rm -rf "$LOCK"' EXIT

# 2. O trabalho parou? Procura mudanças recentes no código e nas sessões do Claude Code.
recent=$(
  {
    find "$MAIN" "$WORKTREES" -maxdepth 6 -type f -mmin "-$IDLE_MIN" \
      -not -path '*/vendor/*' -not -path '*/storage/*' -not -path '*/.phpunit.cache/*' \
      -not -path '*/node_modules/*' -not -path "$WT/*" -not -name '*.log' 2>/dev/null | head -n 1
    find "$HOME/.claude/projects" -maxdepth 2 -type f -name '*.jsonl' -mmin "-$IDLE_MIN" \
      -path '*connect*' 2>/dev/null | grep -v vigia | head -n 1
  } | head -n 1
)
if [ -n "$recent" ] && [ "${VIGIA_FORCE:-0}" != 1 ]; then
  log "trabalho ativo nos últimos $IDLE_MIN min ($recent); vigia não interfere"
  exit 0
fi

# 3. Fila: issues abertas sem precisa-djalma / em-andamento.
command -v gh >/dev/null || { log "gh não encontrado"; exit 1; }
gh auth status >/dev/null 2>&1 || { log "gh sem login; rode: gh auth login"; exit 1; }
issues=$(gh issue list --repo "$REPO" --state open --limit 100 \
  --json number,title,labels,createdAt \
  --jq '[.[] | select(([.labels[].name] | index("precisa-djalma") or index("em-andamento")) | not)]
        | sort_by((if ([.labels[].name] | index("prioridade")) then 0 else 1 end), .createdAt)
        | .[] | "\(.number)\t\(.title)"' 2>>"$LOG")
if [ -z "$issues" ]; then
  log "fila vazia (ou só issues com precisa-djalma); nada a retomar"
  exit 0
fi
number=$(printf '%s\n' "$issues" | head -n 1 | cut -f1)
title=$(printf '%s\n' "$issues" | head -n 1 | cut -f2-)
log "retomando issue #$number: $title"

if [ "${VIGIA_DRY_RUN:-0}" = 1 ]; then
  log "VIGIA_DRY_RUN=1: parando antes de rodar o Claude"
  printf '%s\n' "$issues"
  exit 0
fi

# 4. Worktree do vigia, atualizado a partir do claude-dev.
git -C "$MAIN" fetch --quiet origin claude-dev main 2>>"$LOG" || { log "git fetch falhou"; exit 1; }
if [ ! -e "$WT/.git" ]; then
  git -C "$MAIN" worktree add --quiet --detach "$WT" origin/claude-dev 2>>"$LOG" || { log "não consegui criar $WT"; exit 1; }
  [ -d "$MAIN/vendor" ] && cp -R "$MAIN/vendor" "$WT/vendor"
fi
if [ -n "$(git -C "$WT" status --porcelain 2>/dev/null)" ]; then
  log "worktree do vigia tem alterações sem commit; deixo como está e não começo outra issue"
  exit 0
fi
branch="vigia/issue-$number"
if git -C "$WT" ls-remote --exit-code --heads origin "$branch" >/dev/null 2>&1; then
  git -C "$WT" fetch --quiet origin "$branch" && git -C "$WT" checkout --quiet -B "$branch" "origin/$branch"
else
  git -C "$WT" checkout --quiet -B "$branch" origin/claude-dev
fi

gh issue edit "$number" --repo "$REPO" --add-label em-andamento >/dev/null 2>>"$LOG" || true

prompt=$(sed -e "s|{{ISSUE}}|$number|g" -e "s|{{REPO}}|$REPO|g" -e "s|{{BRANCH}}|$branch|g" "$HERE/prompt.md")
run_log="$LOG_DIR/issue-$number-$(date +%Y%m%d-%H%M%S).log"
log "claude headless em $WT (branch $branch, limite $MAX_MIN min, US\$ $BUDGET); saída em $run_log"

(
  cd "$WT" && claude -p "$prompt" \
    --permission-mode auto \
    --max-budget-usd "$BUDGET" \
    --output-format text
) >"$run_log" 2>&1 &
child=$!
elapsed=0
while kill -0 "$child" 2>/dev/null; do
  sleep 30; elapsed=$((elapsed + 30))
  if [ "$elapsed" -ge $((MAX_MIN * 60)) ]; then
    log "issue #$number passou de $MAX_MIN min; encerrando o Claude"
    kill "$child" 2>/dev/null; sleep 5; kill -9 "$child" 2>/dev/null
    break
  fi
done
wait "$child" 2>/dev/null; status=$?

gh issue edit "$number" --repo "$REPO" --remove-label em-andamento >/dev/null 2>>"$LOG" || true
log "issue #$number terminou com status $status"
exit 0
