#!/bin/sh
# Agendador simples do serviço "scheduler" do compose (ADR 0013).
# Roda os avisos do checklist ao subir e depois todo dia às 07:00 (TZ do container).
# Rodar mais de uma vez no mesmo dia é seguro: cada item é avisado uma vez por situação.
set -u
cd "$(dirname "$0")/../.."
while true; do
    php service/jobs/onboarding-alerts.php || echo "onboarding-alerts falhou com código $?" >&2
    now=$(date +%s)
    next=$(date -d "tomorrow 07:00" +%s)
    sleep $((next - now))
done
