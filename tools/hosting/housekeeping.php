<?php

declare(strict_types=1);

/**
 * Faxina diária da hospedagem (ADR 0336): php tools/hosting/housekeeping.php [--dry-run]
 * Roda no tools/hosting/cron.sh dia. Compacta os logs diários com mais de 2 dias, apaga os com mais de 6 meses e mede o
 * espaço usado pela instalação (du), gravando em storage/temp/disk-usage para o vigia (watchdog.php) comparar com o plano.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Source\Support\HostingHousekeeping;

$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__, 2);
$logs = $root . '/storage/logs';
$names = is_dir($logs) ? array_values(array_filter((array)scandir($logs), static fn ($n): bool => is_string($n) && is_file($logs . '/' . $n))) : [];
$plan = HostingHousekeeping::logPlan($names, date('Y-m-d'));
$done = ['compressed' => 0, 'deleted' => 0];
if (!$dryRun) {
    foreach ($plan['compress'] as $name) {
        $source = $logs . '/' . $name;
        $data = @file_get_contents($source);
        if ($data !== false && @file_put_contents($source . '.gz', gzencode($data, 9)) !== false) {
            @chmod($source . '.gz', 0640);
            @unlink($source);
            $done['compressed']++;
        }
    }
    foreach ($plan['delete'] as $name) {
        if (@unlink($logs . '/' . $name)) {
            $done['deleted']++;
        }
    }
}

$usedKb = null;
$du = trim((string)@shell_exec('du -sk ' . escapeshellarg($root) . ' 2>/dev/null'));
if (preg_match('/^(\d+)/', $du, $m)) {
    $usedKb = (int)$m[1];
    if (!$dryRun) {
        @mkdir($root . '/storage/temp', 0775, true);
        file_put_contents($root . '/storage/temp/disk-usage', time() . ' ' . $usedKb . "\n");
    }
}
fwrite(STDOUT, json_encode(['housekeeping' => ['dry_run' => $dryRun, 'plan' => array_map('count', $plan), 'done' => $done, 'used_kb' => $usedKb]]) . PHP_EOL);
