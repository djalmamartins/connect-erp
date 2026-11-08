<?php

declare(strict_types=1);

/**
 * Avisos diários do checklist de chegada (ADR 0013). Agendar uma vez por dia, ex.:
 *   0 7 * * * php /var/www/html/service/jobs/onboarding-alerts.php
 * Só enfileira notificações e e-mails; o envio fica com service/workers/process-mail-queue.php.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$result = (new \Source\Services\OnboardingAlerts())->run();
echo sprintf("Checklist de chegada: %d itens avisados, %d notificações, %d e-mails na fila.\n", $result['items'], $result['notifications'], $result['emails']);
