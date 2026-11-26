<?php

declare(strict_types=1);

/**
 * Push do app Meu Dia (ADR 0659): tarefa marcada por outra pessoa e o resumo das 7h com as tarefas atrasadas.
 * Pelo cron de cada minuto (tools/hosting/cron.sh minuto) e pelo scheduler do lab: php tools/my-day-push.php
 *
 * O envio usa o push da equipe (ADR 0660): só chama o Expo com TEAM_PUSH_DELIVERY=expo; sem isso fica no log.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\MyDayPush;

$summary = (new MyDayPush())->run();
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
