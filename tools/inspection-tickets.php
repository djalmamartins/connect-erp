<?php

declare(strict_types=1);

/**
 * Vistoria que vira chamado (ADR 0628): abre no SAC o chamado das vistorias que vencem em breve ou venceram.
 * Rodar uma vez por dia (scheduler do lab às 07:00; na hospedagem, tools/hosting/cron.sh daily).
 * Rodar de novo no mesmo dia é seguro: um chamado por plano e por data devida.
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

$result = (new \Source\Services\Inspections\InspectionTickets())->run();
echo sprintf("Vistorias: %d planos, %d chamados abertos, %d sem responsável.\n", $result['plans'], $result['opened'], $result['skipped']);
