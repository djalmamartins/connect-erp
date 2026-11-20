<?php

declare(strict_types=1);

/**
 * Avisos de cobrança por e-mail ao morador (ADR 0600): uma vez por dia, pelo cron do servidor, de manhã:
 *   php tools/resident-charge-emails.php [AAAA-MM-DD]
 *
 * Boleto que vence hoje e boleto com 10, 15, 30, 45 ou 60 dias de atraso entram na fila de avisos. Cada boleto é
 * avisado uma vez por marco; rodar de novo no mesmo dia não repete. A data opcional serve só para teste no lab.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\ResidentChargeNotices;

$today = null;
if (isset($argv[1])) {
    $today = DateTimeImmutable::createFromFormat('!Y-m-d', $argv[1]);
    if ($today === false || $today->format('Y-m-d') !== $argv[1]) {
        fwrite(STDERR, "Data inválida (use AAAA-MM-DD).\n");
        exit(2);
    }
}
$summary = (new ResidentChargeNotices(null, $today ?: null))->run();
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
