#!/usr/bin/env bash
# Connect ERP — rotinas agendadas pelo cron da hospedagem (ADR 0325).
#
# Na hospedagem compartilhada (Cloud Startup) não há processo rodando o tempo todo, então o laço do
# service/jobs/scheduler.sh vira três linhas de cron (ver docs/process/HOSPEDAGEM.md):
#
#   * * * * *   bash /caminho/connect/tools/hosting/cron.sh minuto
#   7 * * * *   bash /caminho/connect/tools/hosting/cron.sh hora
#   0 7 * * *   bash /caminho/connect/tools/hosting/cron.sh dia
#   30 3 * * *  bash /caminho/connect/tools/hosting/cron.sh backup
#
# Cada grupo tem trava própria (flock): se a rodada anterior ainda estiver rodando, esta sai sem fazer nada.
# Saída em storage/logs/cron-<grupo>.log (só as últimas 2000 linhas).
set -u
project=$(cd "$(dirname "$0")/../.." && pwd)
cd "$project" || exit 1
group=${1:-}
php_bin=${PHP_BIN:-php}
log_dir="$project/storage/logs"
mkdir -p "$log_dir"
log="$log_dir/cron-${group:-sem-grupo}.log"

run() {
    local started
    started=$(date '+%Y-%m-%d %H:%M:%S')
    "$@" >>"$log" 2>&1
    local code=$?
    if [ "$code" -ne 0 ]; then
        echo "[$started] falhou com código $code: $*" >>"$log"
    fi
    return 0
}

case "$group" in
    minuto)
        # Fila de avisos do ERP (ADR 0055). Envio da mail_queue só com MAIL_QUEUE_SEND=1 no .env do cron.
        jobs=("$php_bin tools/notifications-dispatch.php 50")
        if [ "${MAIL_QUEUE_SEND:-0}" = "1" ]; then
            jobs+=("$php_bin service/jobs/sendEmail.php")
        fi
        ;;
    hora)
        # Consulta dos boletos em aberto nos bancos reais (ADR 0156): dá baixa mesmo sem webhook.
        jobs=("$php_bin tools/bank-charges-sync.php 200")
        ;;
    dia)
        # Avisos do checklist de chegada e lembretes do atendimento de cobrança (ADR 0315).
        jobs=("$php_bin service/jobs/onboarding-alerts.php" "$php_bin tools/collection-reminders.php")
        ;;
    backup)
        jobs=("bash tools/hosting/backup.sh")
        ;;
    *)
        echo "uso: bash tools/hosting/cron.sh minuto|hora|dia|backup" >&2
        exit 2
        ;;
esac

lock="$project/storage/temp/cron-$group.lock"
mkdir -p "$(dirname "$lock")"
exec 9>"$lock"
if command -v flock >/dev/null 2>&1; then
    flock -n 9 || exit 0
fi

for job in "${jobs[@]}"; do
    # shellcheck disable=SC2086
    run $job
done

if [ -f "$log" ] && [ "$(wc -l <"$log")" -gt 4000 ]; then
    tail -n 2000 "$log" >"$log.tmp" && mv "$log.tmp" "$log"
fi
