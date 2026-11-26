#!/bin/sh
# Agendador simples do serviço "scheduler" do compose (ADR 0013, ADR 0055).
# - A cada minuto: fila de avisos do ERP (tools/notifications-dispatch.php). O modo vem de
#   ERP_MAIL_DELIVERY (log | sandbox); fora de APP_ENV=production nunca envia para morador.
# - Só com LAB_MAIL_QUEUE_SEND=1: envio da mail_queue (service/jobs/sendEmail.php). Fica desligado
#   por padrão porque usa o SMTP gravado em settings, que pode apontar para um servidor real.
# - Ao subir e depois todo dia às 07:00 (TZ do container): avisos do checklist de chegada.
#   Rodar mais de uma vez no mesmo dia é seguro: cada item é avisado uma vez por situação.
# - No mesmo horário: lembrete da próxima ação do atendimento de cobrança (tools/collection-reminders.php,
#   ADR 0315), só no sino da equipe; cada contato é lembrado uma vez.
# - No mesmo horário: boleto que vence hoje e atraso com 10, 15, 30, 45 e 60 dias para o morador
#   (tools/resident-charge-emails.php, ADR 0601); cada boleto é avisado uma vez por marco.
# - No mesmo horário: chamado no SAC das vistorias que vencem em breve (tools/inspection-tickets.php, ADR 0630).
# - De hora em hora: consulta dos boletos em aberto nos bancos reais (tools/bank-charges-sync.php, ADR 0156).
#   Dá baixa do que foi pago sem depender do webhook (que precisa de endereço público).
set -u
cd "$(dirname "$0")/../.."
last_daily=""
last_hourly=""
while true; do
    today=$(date +%Y-%m-%d)
    hour=$(date +%H)
    if [ -z "$last_daily" ] || { [ "$today" != "$last_daily" ] && [ "$hour" -ge 7 ]; }; then
        php service/jobs/onboarding-alerts.php || echo "onboarding-alerts falhou com código $?" >&2
        php tools/collection-reminders.php || echo "collection-reminders falhou com código $?" >&2
        php tools/resident-charge-emails.php || echo "resident-charge-emails falhou com código $?" >&2
        php tools/inspection-tickets.php || echo "inspection-tickets falhou com código $?" >&2
        last_daily=$today
    fi
    hour_key=$(date +%Y-%m-%dT%H)
    if [ "$hour_key" != "$last_hourly" ]; then
        php tools/bank-charges-sync.php 200 || echo "bank-charges-sync falhou com código $?" >&2
        last_hourly=$hour_key
    fi
    php tools/notifications-dispatch.php 50 || echo "notifications-dispatch falhou com código $?" >&2
    # Push do app Meu Dia (ADR 0659): tarefa marcada por outra pessoa e resumo das 7h. Sem TEAM_PUSH_DELIVERY=expo, só log.
    php tools/my-day-push.php >/dev/null || echo "my-day-push falhou com código $?" >&2
    if [ "${LAB_MAIL_QUEUE_SEND:-0}" = "1" ]; then
        php service/jobs/sendEmail.php || echo "sendEmail falhou com código $?" >&2
    fi
    sleep 60
done
