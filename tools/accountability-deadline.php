<?php

declare(strict_types=1);

/**
 * Prazo da prestação de contas (ADR 0300): uma vez por dia, pelo cron do servidor, por exemplo às 7h:
 *   php tools/accountability-deadline.php
 *
 * Avisa a equipe (sino do sistema) quando o livro do mês anterior ainda não foi fechado perto do 5º dia útil ou
 * passou do prazo, e envia aos moradores o link dos meses já fechados que ainda não foram enviados. O envio
 * passa pela fila do ERP (tools/notifications-dispatch.php): fora de produção nada sai para morador.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\AccountabilityDeadline;

$summary = (new AccountabilityDeadline())->run();
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
