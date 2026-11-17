<?php

declare(strict_types=1);

/**
 * Vigia da hospedagem (ADR 0333): php tools/hosting/watchdog.php [--dry-run]
 * Roda de hora em hora pelo tools/hosting/cron.sh (grupo "hora"). Lê o sinal de vida das rotinas do cron, conta os avisos
 * da fila que falharam nas últimas 24 horas, o disco perto do limite do plano, o certificado HTTPS (ADR 0336) e o certificado dos bancos (ADR 0352) e manda um e-mail para ALERT_EMAIL quando algo muda (ou a cada 12 horas
 * enquanto continuar). Sem ALERT_EMAIL, ou com --dry-run, só escreve no log do cron. Não envia nada para morador.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Source\Support\Email;
use Source\Support\HostingHealth;
use Source\Support\HostingHousekeeping;
use Source\Support\HostingWatchdog;

$dryRun = in_array('--dry-run', $argv, true);
$now = time();
$failed = 0;
try {
    $failed = (int)\Source\Core\Connect::getInstance()
        ->query("SELECT COUNT(*) FROM erp_notification_outbox WHERE status = 'failed' AND updated_at >= NOW() - INTERVAL 1 DAY")
        ->fetchColumn();
} catch (\Throwable $error) {
    $problemsExtra = ['database' => 'O banco de dados não respondeu ao vigia.'];
}
$problems = HostingWatchdog::problems(HostingHealth::evaluate(HostingHealth::read(), $now), $failed) + ($problemsExtra ?? []);

// Certificado do Inter e da Cora (ADR 0352): 30 dias antes e quando vencer.
if (!isset($problemsExtra)) {
    try {
        $rows = \Source\Core\Connect::getInstance()->query("SELECT c.id, c.adapter, c.environment, c.certificate_expires_on,
                a.name AS account, co.name AS condominium
            FROM erp_bank_credentials c
            LEFT JOIN erp_bank_accounts a ON a.id = c.bank_account_id
            LEFT JOIN condominiums co ON co.id = a.condominium_id
            WHERE c.certificate_expires_on IS NOT NULL")->fetchAll(\PDO::FETCH_ASSOC);
        $problems += HostingWatchdog::bankCertProblems($rows, $now);
    } catch (\Throwable $error) {
        // Sem a tabela (instalação antiga) o vigia segue com o resto.
    }
}

// Disco (medido pela faxina diária) e certificado HTTPS do site (ADR 0336).
$usage = @file_get_contents(dirname(__DIR__, 2) . '/storage/temp/disk-usage');
if (is_string($usage) && preg_match('/^\d+ (\d+)/', $usage, $m)) {
    $limit = (float)(getenv('HOSTING_DISK_LIMIT_GB') ?: 100);
    if (($disk = HostingHousekeeping::diskProblem((int)$m[1], $limit)) !== null) {
        $problems['disk'] = $disk;
    }
}
$appUrl = (string)getenv('APP_URL');
$appHost = (string)(parse_url($appUrl, PHP_URL_HOST) ?: '');
if (str_starts_with($appUrl, 'https://') && $appHost !== '' && !str_ends_with($appHost, '.lab')
    && ($cert = HostingHousekeeping::certProblem(HostingHousekeeping::certDaysLeft($appHost, $now), $appHost)) !== null) {
    $problems['cert'] = $cert;
}

$stateFile = dirname(__DIR__, 2) . '/storage/temp/watchdog-state.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$line = '[' . date('Y-m-d H:i:s', $now) . '] vigia: ' . ($problems === [] ? 'tudo certo' : implode(' | ', $problems));
fwrite(STDOUT, $line . PHP_EOL);

if (!HostingWatchdog::due($problems, $state, $now)) {
    exit(0);
}
$to = trim((string)getenv('ALERT_EMAIL'));
if ($dryRun || $to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDOUT, 'vigia: e-mail não enviado (' . ($dryRun ? '--dry-run' : 'ALERT_EMAIL vazio ou inválido') . ')' . PHP_EOL);
    exit(0);
}
$host = (string)(parse_url((string)getenv('APP_URL'), PHP_URL_HOST) ?: gethostname() ?: 'servidor');
$mail = HostingWatchdog::message($problems, $host);
if ((new Email())->bootstrap($mail['subject'], $mail['body'], $to, 'Connect ERP')->send()) {
    @mkdir(dirname($stateFile), 0775, true);
    file_put_contents($stateFile, json_encode(['hash' => HostingWatchdog::hash($problems), 'sent_at' => $now]));
    fwrite(STDOUT, 'vigia: aviso enviado' . PHP_EOL);
    exit(0);
}
fwrite(STDERR, 'vigia: o e-mail de aviso não saiu' . PHP_EOL);
exit(1);
