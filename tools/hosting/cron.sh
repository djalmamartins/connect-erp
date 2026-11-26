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
# Ao terminar, grava o sinal de vida em storage/temp/heartbeat/<grupo> ("<epoch> <falhas>"), lido pelo /api/v1/health
# e pelo vigia (tools/hosting/watchdog.php, ADR 0333).
set -u
project=$(cd "$(dirname "$0")/../.." && pwd)
cd "$project" || exit 1
group=${1:-}
log_dir="$project/storage/logs"
mkdir -p "$log_dir"
log="$log_dir/cron-${group:-sem-grupo}.log"

failures=0

# O cron não carrega o .env (ADR 0358): MAIL_QUEUE_SEND, PHP_BIN e ERP_MAIL_DELIVERY vêm de lá quando não estão no ambiente.
env_value() {
    [ -f "$project/.env" ] || return 0
    { grep -E "^[[:space:]]*$1=" "$project/.env" || true; } | tail -n 1 | cut -d= -f2- | sed -e 's/^["'\'']//' -e 's/["'\'']$//'
}
for key in MAIL_QUEUE_SEND ERP_MAIL_DELIVERY PHP_BIN; do
    if [ -z "${!key:-}" ]; then
        value=$(env_value "$key")
        if [ -n "$value" ]; then export "$key=$value"; fi
    fi
done
php_bin=${PHP_BIN:-php}

run() {
    local started
    started=$(date '+%Y-%m-%d %H:%M:%S')
    "$@" >>"$log" 2>&1
    local code=$?
    if [ "$code" -ne 0 ]; then
        echo "[$started] falhou com código $code: $*" >>"$log"
        failures=$((failures + 1))
    fi
    return 0
}

case "$group" in
    minuto)
        # Fila de avisos do ERP (ADR 0055). A mail_queue só sai pelo SMTP com MAIL_QUEUE_SEND=1 ou, na produção,
        # com ERP_MAIL_DELIVERY=production (ADR 0358); no lab fica só no log.
        jobs=("$php_bin tools/notifications-dispatch.php 50")
        # Push do app do morador (ADR 0642): só envia com CONNECT_RESIDENT_PUSH=expo no .env; sem isso marca como não enviado.
        jobs+=("$php_bin tools/resident-push-dispatch.php 100")
        # Push do app Meu Dia (ADR 0659): tarefa marcada por outra pessoa e resumo das 7h com as atrasadas.
        jobs+=("$php_bin tools/my-day-push.php")
        if [ "${MAIL_QUEUE_SEND:-0}" = "1" ] || [ "${ERP_MAIL_DELIVERY:-}" = "production" ]; then
            jobs+=("$php_bin service/jobs/sendEmail.php")
        fi
        ;;
    hora)
        # Consulta dos boletos em aberto nos bancos reais (ADR 0156): dá baixa mesmo sem webhook.
        # Vigia (ADR 0333): avisa por e-mail quando uma rotina parou, o backup atrasou ou a fila de avisos falhou.
        jobs=("$php_bin tools/bank-charges-sync.php 200" "$php_bin tools/hosting/watchdog.php")
        ;;
    dia)
        # Avisos do checklist de chegada e lembretes do atendimento de cobrança (ADR 0315); PDFs de vistoria já
        # enviados por e-mail (ADR 0335); faxina dos logs e medida do disco (ADR 0336). Boleto que vence hoje e atraso
        # com 10, 15, 30, 45 e 60 dias para o morador (ADR 0601).
        jobs=("$php_bin service/jobs/onboarding-alerts.php" "$php_bin tools/collection-reminders.php" "$php_bin tools/resident-charge-emails.php" "$php_bin tools/inspection-tickets.php" "$php_bin tools/inspection-reports-cleanup.php" "$php_bin tools/hosting/housekeeping.php")
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

beat_dir="$project/storage/temp/heartbeat"
mkdir -p "$beat_dir"
echo "$(date +%s) $failures" >"$beat_dir/$group.tmp" && mv "$beat_dir/$group.tmp" "$beat_dir/$group"

if [ -f "$log" ] && [ "$(wc -l <"$log")" -gt 4000 ]; then
    tail -n 2000 "$log" >"$log.tmp" && mv "$log.tmp" "$log"
fi
