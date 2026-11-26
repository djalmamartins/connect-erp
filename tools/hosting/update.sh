#!/usr/bin/env bash
# Connect ERP — atualizar a versão na hospedagem, com volta automática quando dá para voltar (ADR 0361).
#
#   bash tools/hosting/update.sh <commit-ou-tag> [--domain=connectcondominios.com.br]
#
# Ordem (HOSPEDAGEM.md §6):
#   1. Recusa se houver arquivo versionado alterado no servidor (o checkout levaria junto ou falharia).
#   2. Anota o commit atual em .local/deploy/previous e faz o backup (tools/hosting/backup.sh).
#   3. git fetch + checkout do commit novo + composer install --no-dev.
#   4. deploy-check. Se falhar, VOLTA SOZINHO para o commit anterior (o banco ainda não mudou) e sai com 1.
#   5. composer db:migrate. Se falhar, para com 2 e mostra como voltar: o banco pode ter mudado em parte, então a
#      volta é manual (restaurar o backup do passo 2 e rodar este script com o commit anterior).
#   6. Conferência por HTTP (tools/hosting/smoke-http.php) quando há --domain. Se falhar, sai com 3 e mostra como voltar.
# Tudo vai também para storage/logs/deploy.log.
set -uo pipefail
project=$(cd "$(dirname "$0")/../.." && pwd)
cd "$project" || exit 1

target=""
domain=""
for arg in "$@"; do
    case "$arg" in
        --domain=*) domain=${arg#--domain=} ;;
        -*) echo "opção desconhecida: $arg" >&2; exit 64 ;;
        *) target=$arg ;;
    esac
done
if [ -z "$target" ]; then
    echo "uso: bash tools/hosting/update.sh <commit-ou-tag> [--domain=connectcondominios.com.br]" >&2
    exit 64
fi

php_bin=${PHP_BIN:-php}
composer_bin=${COMPOSER_BIN:-composer}
mkdir -p "$project/storage/logs" "$project/.local/deploy"
log="$project/storage/logs/deploy.log"
say() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$log"; }

if ! git diff --quiet || ! git diff --cached --quiet; then
    say "PAROU: há arquivos versionados alterados no servidor (git status). Nada foi feito."
    git status --short | tee -a "$log"
    exit 4
fi

previous=$(git rev-parse HEAD)
echo "$previous" >"$project/.local/deploy/previous"
say "versão atual: $previous; atualizando para $target"

say "backup antes de atualizar"
if ! bash "$project/tools/hosting/backup.sh" >>"$log" 2>&1; then
    say "PAROU: o backup falhou. Nada foi feito."
    exit 5
fi

if git remote get-url origin >/dev/null 2>&1; then
    git fetch -q origin >>"$log" 2>&1 || say "aviso: git fetch falhou; usando o que já está no servidor"
fi
if ! git checkout -q --detach "$target" >>"$log" 2>&1; then
    say "PAROU: commit $target não encontrado. Nada foi feito."
    exit 6
fi
new=$(git rev-parse HEAD)

rollback_code() {
    git checkout -q --detach "$previous" >>"$log" 2>&1
    "$composer_bin" install --no-dev --optimize-autoloader >>"$log" 2>&1
}

if ! "$composer_bin" install --no-dev --optimize-autoloader >>"$log" 2>&1; then
    rollback_code
    say "VOLTOU para $previous: composer install falhou no commit novo. O banco não mudou."
    exit 1
fi

if ! "$php_bin" service/commands/deploy-check.php 2>&1 | tee -a "$log"; then
    rollback_code
    say "VOLTOU para $previous: o deploy-check acusou erro no commit novo. O banco não mudou."
    exit 1
fi

if ! "$composer_bin" db:migrate 2>&1 | tee -a "$log"; then
    say "PAROU com o código em $new: a migration falhou e o banco pode ter mudado em parte."
    say "Para voltar: restaurar o backup acima (HOSPEDAGEM.md §5) e rodar: bash tools/hosting/update.sh $previous"
    exit 2
fi

# Central de Ajuda (revisão de 23/11): os artigos ficam em arquivos e só aparecem em /suporte depois do sync.
if ! "$php_bin" tools/help.php sync 2>&1 | tee -a "$log"; then
    say "aviso: a Central de Ajuda não foi atualizada (php tools/help.php sync); o resto da versão está no ar"
fi

if [ -n "$domain" ]; then
    if ! "$php_bin" tools/hosting/smoke-http.php --domain="$domain" 2>&1 | tee -a "$log"; then
        say "ATENÇÃO: a conferência por HTTP falhou em $new. Para voltar o código: bash tools/hosting/update.sh $previous"
        say "(se a migration nova mudou o banco, restaurar antes o backup acima)"
        exit 3
    fi
else
    say "aviso: sem --domain, a conferência por HTTP não rodou"
fi

echo "$new" >"$project/.local/deploy/current"
say "OK: versão $new no ar (anterior: $previous)"
