<?php

declare(strict_types=1);

/**
 * Avisos diários do checklist de chegada (ADR 0013). Agendar uma vez por dia, ex.:
 *   0 7 * * * php /var/www/html/service/jobs/onboarding-alerts.php
 * Só enfileira notificações e e-mails; o envio fica com service/workers/process-mail-queue.php.
 */

// Links dos avisos: sem requisição HTTP, url() usaria o domínio de produção das configurações.
// CONNECT_JOB_HOST (ex.: erp.connect.lab) faz os links apontarem para o ambiente certo.
$jobHost = getenv('CONNECT_JOB_HOST') ?: '';
if ($jobHost !== '' && preg_match('~^[a-z0-9.-]+(?::[0-9]{1,5})?$~i', $jobHost)) {
    $_SERVER['HTTP_HOST'] = $jobHost;
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$result = (new \Source\Services\OnboardingAlerts())->run();
echo sprintf("Checklist de chegada: %d itens avisados, %d notificações, %d e-mails na fila.\n", $result['items'], $result['notifications'], $result['emails']);
