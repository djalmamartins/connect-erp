<?php

declare(strict_types=1);

/**
 * Envia o push dos apps da equipe (SAC e Meu Dia, ADR 0660). Pelo cron da hospedagem a cada minuto
 * (tools/hosting/cron.sh minuto): php tools/team-push-dispatch.php [limite]
 *
 * TEAM_PUSH_DELIVERY=expo envia pelo serviço de push do Expo; qualquer outro valor (padrão: log) não envia nada
 * e marca os avisos como "skipped" (o aviso continua no sininho do ERP).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Team\TeamPush;

$limit = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : 100;
$summary = (new TeamPush())->dispatch($limit);
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
