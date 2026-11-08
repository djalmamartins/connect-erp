#!/usr/bin/env bash
# Connect ERP — guarda PreToolUse do Claude Code para o tool Bash.
#
# Instalado em ~/.claude/hooks/connect-erp-guard.sh por tools/claude/liberar-permissoes.sh.
# Recebe o JSON do hook no stdin. Sai com 0 para permitir e com 2 para bloquear
# (a mensagem em stderr volta para o Claude explicando o motivo).
#
# Bloqueia, mesmo com as permissões liberadas:
#   - phpunit chamado direto (incidente 07/11: truncou o banco real connect_legacy);
#   - DROP DATABASE/TABLE/SCHEMA e TRUNCATE fora de banco *_test;
#   - git push com --force/-f/--force-with-lease/--mirror;
#   - git push para a main (explícito ou com a main como branch atual);
#   - remoção de volumes Docker (onde fica o MariaDB) e rm -rf em / , ~ , /Volumes ou .git;
#   - sudo.
# Compatível com o bash 3.2 e as ferramentas BSD do macOS.

input=$(cat)

extract() {
  # $1 = chave dentro de tool_input (ou "cwd")
  local key=$1 path value=""
  if [ "$key" = "cwd" ]; then path="cwd"; else path="tool_input.$key"; fi
  if command -v plutil >/dev/null 2>&1; then
    value=$(printf '%s' "$input" | plutil -extract "$path" raw -o - - 2>/dev/null) && { printf '%s' "$value"; return; }
  fi
  if command -v python3 >/dev/null 2>&1; then
    value=$(printf '%s' "$input" | python3 -c '
import json, sys
d = json.load(sys.stdin)
k = sys.argv[1]
v = d.get("cwd", "") if k == "cwd" else (d.get("tool_input") or {}).get(k, "")
sys.stdout.write(v if isinstance(v, str) else "")
' "$key" 2>/dev/null) && { printf '%s' "$value"; return; }
  fi
  if command -v php >/dev/null 2>&1; then
    value=$(printf '%s' "$input" | php -r '
$d = json_decode(stream_get_contents(STDIN), true) ?: [];
$k = $argv[1];
$v = $k === "cwd" ? ($d["cwd"] ?? "") : ($d["tool_input"][$k] ?? "");
echo is_string($v) ? $v : "";
' "$key" 2>/dev/null) && { printf '%s' "$value"; return; }
  fi
  # Sem parser: usa o JSON bruto (mais conservador, pode bloquear a mais).
  [ "$key" = "command" ] && printf '%s' "$input"
}

cmd=$(extract command)
[ -z "$cmd" ] && exit 0
cwd=$(extract cwd)
[ -n "$cwd" ] && [ -d "$cwd" ] || cwd=$(pwd)

lower=$(printf '%s' "$cmd" | tr '[:upper:]' '[:lower:]')
B='(^|[^a-z0-9_./-])'   # fronteira de palavra portátil (BSD grep não garante \b)

block() {
  echo "Bloqueado pela guarda do Connect ERP: $1" >&2
  echo "Se isto for realmente necessário, peça aprovação explícita ao Djalma e ele roda o comando manualmente." >&2
  exit 2
}

has() { printf '%s' "$lower" | grep -Eq -- "$1"; }

# 1. phpunit direto. Use composer test:unit / composer test:integration.
if has "${B}(\./)?(vendor/bin/)?phpunit([^a-z0-9_.-]|$)" || has "vendor/phpunit/phpunit/phpunit"; then
  block "phpunit direto não é permitido (já truncou o banco real). Use 'composer test:unit' ou 'composer test:integration'."
fi

# 2. SQL destrutivo fora de banco *_test.
if has "${B}(drop[[:space:]]+(database|schema|table)|truncate([[:space:]]+table)?[[:space:]]+[a-z0-9_\`])"; then
  if ! has "_test([^a-z0-9_]|$)"; then
    block "DROP/TRUNCATE só é permitido em banco *_test."
  fi
fi
if has "mariadb-admin[^|;&]*drop|mysqladmin[^|;&]*drop"; then
  block "mysqladmin/mariadb-admin drop não é permitido."
fi

# 3. Docker: não apagar volumes (dados do MariaDB).
if has "docker(-compose|[[:space:]]+compose)[^|;&]*[[:space:]]down[^|;&]*([[:space:]]-v([[:space:]]|$)|--volumes)" \
  || has "docker[[:space:]]+volume[[:space:]]+(rm|prune)" \
  || has "docker[[:space:]]+system[[:space:]]+prune"; then
  block "remover volumes Docker apaga o banco local."
fi

# 4. sudo e rm -rf em alvos perigosos.
if has "${B}sudo[[:space:]]"; then
  block "sudo não é permitido."
fi
if has "${B}rm[[:space:]]+(-[a-z]*r[a-z]*f|-[a-z]*f[a-z]*r|-r[[:space:]]+-f|-f[[:space:]]+-r)[a-z]*[[:space:]]+([^|;&]*[[:space:]])?[\"']?(/|~|~/|\\\$home|/volumes|/users|/users/[a-z0-9._-]+|\.git|\.|\.\.|\*)/?[\"']?([[:space:]]|$)"; then
  block "rm -rf em /, ~, /Volumes, /Users, .git, . ou * não é permitido."
fi

# 5. git push: sem force e nunca para a main.
segments=$(printf '%s\n' "$cmd" | awk '{ gsub(/&&|\|\||;|\|/, "\n"); print }')
current_branch() {
  local dir=$1
  git -C "$dir" symbolic-ref --short -q HEAD 2>/dev/null
}
printf '%s\n' "$segments" | while IFS= read -r seg; do
  seg_lower=$(printf '%s' "$seg" | tr '[:upper:]' '[:lower:]')
  printf '%s' "$seg_lower" | grep -Eq "(^|[[:space:]/])git([[:space:]].*)?[[:space:]]push([[:space:]]|$)" || continue

  if printf '%s' "$seg_lower" | grep -Eq "[[:space:]](--force|--force-with-lease|--force-if-includes|-f|--mirror|--delete|-d)([[:space:]=]|$)|[[:space:]]\+[^[:space:]]"; then
    echo "force push, --mirror, --delete e refspec com + não são permitidos." >&2; exit 2
  fi
  if printf '%s' "$seg_lower" | grep -Eq "[[:space:]:/]main([[:space:]]|$)|refs/heads/main"; then
    echo "push para a main só com aprovação do Djalma." >&2; exit 2
  fi

  # Sem branch explícita: o push usa a branch atual.
  dir=$cwd
  cdir=$(printf '%s' "$seg" | sed -n 's/.*git[[:space:]]\{1,\}-C[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' | tr -d "\"'")
  if [ -n "$cdir" ]; then
    case $cdir in /*) dir=$cdir ;; *) dir="$cwd/$cdir" ;; esac
  fi
  args=$(printf '%s' "$seg" | sed 's/.*[[:space:]]push//')
  positional=0
  explicit=0
  for tok in $args; do
    case $tok in
      -*) ;;
      *)
        positional=$((positional + 1))
        if [ "$positional" -ge 2 ] && [ "$tok" != "HEAD" ]; then explicit=1; fi
        ;;
    esac
  done
  if [ "$explicit" -eq 0 ] && [ "$(current_branch "$dir")" = "main" ]; then
    echo "a branch atual é a main; push para a main só com aprovação do Djalma." >&2; exit 2
  fi
done
status=$?
if [ "$status" -eq 2 ]; then
  echo "Bloqueado pela guarda do Connect ERP (git push)." >&2
  echo "Se isto for realmente necessário, peça aprovação explícita ao Djalma e ele roda o comando manualmente." >&2
  exit 2
fi

exit 0
