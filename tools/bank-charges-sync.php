<?php

declare(strict_types=1);

/**
 * Consulta automática dos boletos em aberto nos bancos reais (Inter, ADR 0156): de hora em hora, pelo agendador
 * (service/jobs/scheduler.sh) ou pelo cron do servidor:
 *   php tools/bank-charges-sync.php [limite]
 *
 * Cada boleto aguardando banco ou registrado é consultado no banco; pago vira baixa da conta a receber pela
 * mesma conciliação do webhook, expirado ou cancelado no banco vira cancelado. Funciona sem endereço público.
 * Pausa de 0,7 s entre consultas para ficar abaixo do limite de chamadas do banco.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\Banking\BankCharges;

$limit = max(1, min(1000, (int)($argv[1] ?? 200)));
$summary = (new BankCharges())->syncOpen($limit, static function (): void {
    usleep(700000);
});
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit($summary['failed'] > 0 ? 1 : 0);
