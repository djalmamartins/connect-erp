<?php

declare(strict_types=1);

/**
 * Apaga os PDFs de relatório de vistoria que já saíram por e-mail (ADR 0335). Roda uma vez por dia pelo cron
 * (tools/hosting/cron.sh dia). Testar sem apagar: php tools/inspection-reports-cleanup.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$summary = (new \Source\Services\Inspections\InspectionReportCleanup())->run(null, in_array('--dry-run', $argv, true));
fwrite(STDOUT, json_encode(['inspection_reports_cleanup' => $summary], JSON_UNESCAPED_UNICODE) . PHP_EOL);
