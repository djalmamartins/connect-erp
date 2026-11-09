<?php

declare(strict_types=1);

/**
 * Reverifica com o antivírus os arquivos do Disco Virtual enviados antes de ele ser ligado (ADR 0161).
 * Um lote de até 50 arquivos por execução, com trava (não roda duas vezes ao mesmo tempo). O agendamento
 * fica com a infraestrutura, por exemplo a cada 10 minutos: php tools/erp-rescan.php
 *
 * O modo vem do ambiente: ERP_AV_MODE (off ou enforce) e ERP_AV_ENDPOINT (endereço do clamd).
 * Com ERP_AV_MODE=off não faz nada. Saída: uma linha JSON com o resumo do lote.
 * Código de saída: 0 (feito, desligado, já rodando ou nada pendente), 2 (antivírus indisponível ou falhou
 * no meio do lote).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\Antivirus\DocumentRescan;

$summary = (new DocumentRescan())->run();
$messages = [
    'off' => 'Antivírus desligado (ERP_AV_MODE=off): nada a fazer.',
    'unavailable' => 'Antivírus indisponível: nada foi marcado.',
    'locked' => 'Outra reverificação está rodando: este lote foi pulado.',
    'interrupted' => 'O antivírus falhou no meio do lote: o restante fica para a próxima execução.',
    'done' => $summary['checked'] === 0 ? 'Nenhum arquivo pendente.' : 'Lote verificado.',
];
fwrite(STDOUT, json_encode($summary + ['message' => $messages[$summary['status']] ?? ''], JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit(in_array($summary['status'], ['unavailable', 'interrupted'], true) ? 2 : 0);
