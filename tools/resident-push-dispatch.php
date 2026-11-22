<?php

declare(strict_types=1);

/**
 * Envia o push do app do morador (avisos do chamado, ADR 0641). Pelo cron da hospedagem a cada minuto
 * (tools/hosting/cron.sh minuto): php tools/resident-push-dispatch.php [limite]
 *
 * CONNECT_RESIDENT_PUSH=expo envia pelo serviço de push do Expo; qualquer outro valor (padrão: log) não envia nada
 * e marca os avisos como "skipped" (o aviso continua no sininho do app).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Resident\ResidentPush;

$limit = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : 100;
$summary = (new ResidentPush())->dispatch($limit);
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
