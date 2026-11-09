<?php

declare(strict_types=1);

/**
 * Processa a fila de avisos do ERP (ADR 0055). Para rodar pelo cron do servidor, por exemplo a cada
 * minuto: php tools/notifications-dispatch.php [limite]
 *
 * O modo vem do ambiente: ERP_MAIL_DELIVERY (log, sandbox ou production) e ERP_MAIL_SANDBOX_TO.
 * Fora de APP_ENV=production nunca envia para morador. O envio SMTP da mail_queue continua no
 * Email::sendQueue já existente.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\NotificationDispatcher;

$limit = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : 50;
$summary = (new NotificationDispatcher())->dispatch($limit);
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
