#!/usr/bin/env bash
# Connect ERP — libera as permissões do Claude Code para o desenvolvimento seguir
# sem pedir aprovação a cada passo, mantendo bloqueios para o que é perigoso.
#
# O que faz, em cada repositório alvo:
#   1. Faz backup do .claude/settings.local.json existente (se houver) em
#      .claude/backups/settings.local.json.AAAAMMDD-HHMMSS
#   2. Grava um .claude/settings.local.json novo que:
#        - permite Bash, Edit, Write, Read, Glob, Grep, WebFetch, WebSearch etc.;
#        - nega phpunit direto, force push, push na main, remoção de volumes
#          Docker, sudo e leitura de .env/chaves;
#        - pede confirmação para ssh/scp/rsync, rollback de migration e merge de PR;
#        - liga a guarda ~/.claude/hooks/connect-erp-guard.sh antes de todo Bash.
#   3. Instala a guarda em ~/.claude/hooks/connect-erp-guard.sh.
# O settings.json versionado do repositório não é alterado.
#
# Uso:
#   bash tools/claude/liberar-permissoes.sh            # connect + worktrees padrão
#   bash tools/claude/liberar-permissoes.sh /caminho   # só os caminhos informados
#   bash tools/claude/liberar-permissoes.sh --desfazer # restaura os backups
#
# Depois de rodar, feche e abra de novo as sessões do Claude Code nessas pastas.

set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
guard_src="$here/connect-erp-guard.sh"
guard_dst="$HOME/.claude/hooks/connect-erp-guard.sh"
default_main="/Volumes/MAC HD/Docker/xampp/www/connect"
default_worktrees="/Volumes/MAC HD/Docker/xampp/worktrees"
stamp=$(date +%Y%m%d-%H%M%S)

mode=install
targets=()
for arg in "$@"; do
  case $arg in
    --desfazer|--undo) mode=undo ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
    *) targets+=("$arg") ;;
  esac
done

if [ ${#targets[@]} -eq 0 ]; then
  [ -d "$default_main" ] && targets+=("$default_main")
  if [ -d "$default_worktrees" ]; then
    for wt in "$default_worktrees"/*; do
      [ -e "$wt/.git" ] && targets+=("$wt")
    done
  fi
fi
if [ ${#targets[@]} -eq 0 ]; then
  echo "Nenhum repositório encontrado. Informe o caminho: bash $0 /caminho/do/repo" >&2
  exit 1
fi

validate_json() {
  if command -v python3 >/dev/null 2>&1; then python3 -m json.tool "$1" >/dev/null
  elif command -v php >/dev/null 2>&1; then php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$1"
  elif command -v plutil >/dev/null 2>&1; then plutil -convert xml1 -o /dev/null "$1"
  else return 0; fi
}

ensure_gitignore() {
  # Usa o exclude local do Git (não versionado) para não sujar o .gitignore do repo.
  local repo=$1 ex
  ex=$(git -C "$repo" rev-parse --git-path info/exclude 2>/dev/null) || return 0
  case $ex in /*) ;; *) ex="$repo/$ex" ;; esac
  mkdir -p "$(dirname "$ex")"
  touch "$ex"
  grep -qxF '.claude/settings.local.json' "$ex" || printf '.claude/settings.local.json\n' >>"$ex"
  grep -qxF '.claude/backups/' "$ex" || printf '.claude/backups/\n' >>"$ex"
}

write_settings() {
  local file=$1
  cat >"$file" <<JSON
{
  "permissions": {
    "defaultMode": "acceptEdits",
    "allow": [
      "Bash",
      "Edit",
      "Write",
      "NotebookEdit",
      "Read",
      "Glob",
      "Grep",
      "WebFetch",
      "WebSearch"
    ],
    "deny": [
      "Bash(vendor/bin/phpunit*)",
      "Bash(./vendor/bin/phpunit*)",
      "Bash(php vendor/bin/phpunit*)",
      "Bash(phpunit*)",
      "Bash(git push --force*)",
      "Bash(git push -f*)",
      "Bash(git push * --force*)",
      "Bash(git push * -f)",
      "Bash(git push * -f *)",
      "Bash(git push --mirror*)",
      "Bash(git push origin main*)",
      "Bash(git push * main)",
      "Bash(git push * HEAD:main*)",
      "Bash(git push * refs/heads/main*)",
      "Bash(docker compose down -v*)",
      "Bash(docker compose down --volumes*)",
      "Bash(docker volume rm*)",
      "Bash(docker volume prune*)",
      "Bash(docker system prune*)",
      "Bash(sudo *)",
      "Bash(rm -rf /)",
      "Bash(rm -rf ~*)",
      "Bash(rm -rf .git*)",
      "Read(./.env)",
      "Read(./.env.local)",
      "Read(./.env.production)",
      "Read(./.local/**)",
      "Read(./**/*-key.pem)"
    ],
    "ask": [
      "Bash(composer db:rollback*)",
      "Bash(composer db:baseline*)",
      "Bash(ssh *)",
      "Bash(scp *)",
      "Bash(rsync *)",
      "Bash(gh pr merge*)",
      "Bash(git push * --delete*)"
    ]
  },
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          { "type": "command", "command": "bash '$guard_dst'" }
        ]
      }
    ]
  }
}
JSON
}

install_guard() {
  mkdir -p "$(dirname "$guard_dst")"
  if [ -f "$guard_dst" ] && ! cmp -s "$guard_src" "$guard_dst"; then
    cp "$guard_dst" "$guard_dst.$stamp.bak"
  fi
  cp "$guard_src" "$guard_dst"
  chmod 755 "$guard_dst"
  echo "Guarda instalada: $guard_dst"
}

if [ "$mode" = install ]; then
  [ -f "$guard_src" ] || { echo "Não achei $guard_src" >&2; exit 1; }
  install_guard
  for repo in "${targets[@]}"; do
    if [ ! -d "$repo" ]; then echo "Pulando (não existe): $repo"; continue; fi
    mkdir -p "$repo/.claude/backups"
    file="$repo/.claude/settings.local.json"
    if [ -f "$file" ]; then
      cp "$file" "$repo/.claude/backups/settings.local.json.$stamp"
      echo "Backup: $repo/.claude/backups/settings.local.json.$stamp"
    else
      : >"$repo/.claude/backups/settings.local.json.$stamp.none"
    fi
    write_settings "$file.tmp"
    if validate_json "$file.tmp"; then
      mv "$file.tmp" "$file"
      ensure_gitignore "$repo"
      echo "Permissões liberadas: $file"
    else
      rm -f "$file.tmp"
      echo "ERRO: JSON inválido, nada alterado em $repo" >&2
      exit 1
    fi
  done
  echo
  echo "Pronto. Feche e abra de novo as sessões do Claude Code nessas pastas."
  echo "Para desfazer: bash \"$here/liberar-permissoes.sh\" --desfazer"
else
  for repo in "${targets[@]}"; do
    dir="$repo/.claude/backups"
    file="$repo/.claude/settings.local.json"
    latest=$(ls -1 "$dir"/settings.local.json.[0-9]* 2>/dev/null | grep -v '\.usado$' | sort | tail -n 1 || true)
    if [ -z "$latest" ]; then echo "Sem backup em $repo; nada a desfazer."; continue; fi
    case $latest in
      *.none)
        mv "$file" "$dir/settings.local.json.desfeito-$stamp" 2>/dev/null || true
        echo "Removido (não existia antes): $file" ;;
      *)
        [ -f "$file" ] && cp "$file" "$dir/settings.local.json.desfeito-$stamp"
        cp "$latest" "$file"
        echo "Restaurado $file a partir de $latest" ;;
    esac
    mv "$latest" "$latest.usado"
  done
  echo "A guarda continua em $guard_dst (inofensiva sem o settings). Pode apagar à mão se quiser."
fi
