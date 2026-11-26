<?php

declare(strict_types=1);

/**
 * Alerta das tarefas atrasadas do Meu Dia (ADR 0658): aviso no sininho de quem tem algo que ficou para trás.
 * Rodar uma vez por dia (scheduler do lab às 07:00; na hospedagem, tools/hosting/cron.sh daily).
 * Rodar de novo no mesmo dia é seguro: um aviso por pessoa por dia.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$jobHost = getenv('CONNECT_JOB_HOST') ?: '';
if ($jobHost !== '' && preg_match('~^[a-z0-9.-]+(?::[0-9]{1,5})?$~i', $jobHost)) {
    $_SERVER['HTTP_HOST'] = $jobHost;
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

require dirname(__DIR__) . '/vendor/autoload.php';

$result = (new \Source\Services\Erp\MyDayAlerts())->run();
echo sprintf("Meu Dia: %d pessoas, %d com atrasadas, %d avisadas agora, %d já avisadas hoje.\n", $result['people'], $result['late'], $result['notified'], $result['already']);
