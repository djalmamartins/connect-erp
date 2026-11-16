<?php

declare(strict_types=1);

/**
 * Lembrete da próxima ação do atendimento de cobrança (ADR 0315): uma vez por dia, pelo cron do servidor, de manhã:
 *   php tools/collection-reminders.php
 *
 * Avisa no sino do sistema quem registrou o contato (ou, se a pessoa não pode mais atender, a equipe que atende o
 * condomínio) no dia marcado para a próxima ação. Nada sai para morador.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\CollectionActionReminders;

$summary = (new CollectionActionReminders())->run();
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
