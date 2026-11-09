#!/bin/sh
# Agendador simples do serviço "scheduler" do compose (ADR 0013, ADR 0055).
# - A cada minuto: fila de avisos do ERP (tools/notifications-dispatch.php). O modo vem de
#   ERP_MAIL_DELIVERY (log | sandbox); fora de APP_ENV=production nunca envia para morador.
# - Só com LAB_MAIL_QUEUE_SEND=1: envio da mail_queue (service/jobs/sendEmail.php). Fica desligado
#   por padrão porque usa o SMTP gravado em settings, que pode apontar para um servidor real.
# - Ao subir e depois todo dia às 07:00 (TZ do container): avisos do checklist de chegada.
#   Rodar mais de uma vez no mesmo dia é seguro: cada item é avisado uma vez por situação.
set -u
cd "$(dirname "$0")/../.."
last_daily=""
while true; do
    today=$(date +%Y-%m-%d)
    hour=$(date +%H)
    if [ -z "$last_daily" ] || { [ "$today" != "$last_daily" ] && [ "$hour" -ge 7 ]; }; then
        php service/jobs/onboarding-alerts.php || echo "onboarding-alerts falhou com código $?" >&2
        last_daily=$today
    fi
    php tools/notifications-dispatch.php 50 || echo "notifications-dispatch falhou com código $?" >&2
    if [ "${LAB_MAIL_QUEUE_SEND:-0}" = "1" ]; then
        php service/jobs/sendEmail.php || echo "sendEmail falhou com código $?" >&2
    fi
    sleep 60
done
