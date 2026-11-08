#!/usr/bin/env bash
# Testa tools/claude/connect-erp-guard.sh sem executar nenhum dos comandos.
# Uso: bash tools/claude/test-guard.sh
set -u
here=$(cd "$(dirname "$0")" && pwd)
guard="$here/connect-erp-guard.sh"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# Repositórios temporários: um na main, outro numa branch de trabalho.
git init -q -b main "$tmp/on-main"
git init -q -b claude-dev "$tmp/on-dev"

fail=0
run() { # $1 = esperado (allow|block), $2 = cwd, $3 = comando
  local json
  json=$(python3 -c 'import json,sys; print(json.dumps({"tool_name":"Bash","cwd":sys.argv[1],"tool_input":{"command":sys.argv[2]}}))' "$2" "$3")
  printf '%s' "$json" | bash "$guard" >/dev/null 2>&1
  local code=$? got=allow
  [ $code -eq 2 ] && got=block
  if [ "$got" != "$1" ]; then
    echo "FALHOU: esperado $1, obtido $got (exit $code): $3"
    fail=$((fail + 1))
  fi
}

D="$tmp/on-dev"; M="$tmp/on-main"

# Devem passar
run allow "$D" "composer test:unit"
run allow "$D" "composer test:integration"
run allow "$D" "git status && git add -A && git commit -m 'feat: x'"
run allow "$D" "git push -u origin claude-dev"
run allow "$D" "git push"
run allow "$D" "git push origin HEAD"
run allow "$M" "git push -u origin claude-dev"
run allow "$D" "docker compose up -d"
run allow "$D" "docker compose down"
run allow "$D" "rm -rf build/ node_modules"
run allow "$D" "rm -rf ./storage/cache/x"
run allow "$D" "rm -rf \"/Volumes/MAC HD/Docker/xampp/www/connect/build\""
run allow "$D" "mariadb -e 'TRUNCATE TABLE users' connect_legacy_test"
run allow "$D" "grep -rn mainMenu source/"
run allow "$D" "npm run build"
run allow "$D" "php -l source/Boot/Config.php"

# Devem ser bloqueados
run block "$D" "vendor/bin/phpunit"
run block "$D" "./vendor/bin/phpunit --testsuite unit"
run block "$D" "php vendor/bin/phpunit"
run block "$D" "cd tests && phpunit"
run block "$D" "mariadb -e 'TRUNCATE TABLE users' connect_legacy"
run block "$D" "mysql -u root -e \"drop database connect_legacy\""
run block "$D" "echo 'DROP TABLE units;' | mariadb connect_legacy"
run block "$D" "git push --force origin claude-dev"
run block "$D" "git push -f"
run block "$D" "git push origin +claude-dev"
run block "$D" "git push --force-with-lease"
run block "$D" "git push origin main"
run block "$D" "git push origin HEAD:main"
run block "$D" "git add . && git commit -m x && git push origin main"
run block "$M" "git push"
run block "$M" "git push -u origin HEAD"
run block "$D" "git -C $M push"
run block "$D" "docker compose down -v"
run block "$D" "docker volume rm connect_db"
run block "$D" "docker system prune -af"
run block "$D" "sudo rm x"
run block "$D" "rm -rf /"
run block "$D" "rm -rf ~"
run block "$D" "rm -rf .git"
run block "$D" "rm -rf *"
run block "$D" "rm -rf ."

if [ $fail -eq 0 ]; then echo "guarda: todos os casos passaram"; else echo "guarda: $fail caso(s) falharam"; exit 1; fi
