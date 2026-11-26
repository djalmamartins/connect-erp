<?php

declare(strict_types=1);

/**
 * Condomínios de teste por forma de rateio (docs/process/RATEIO_TEST_DATA.md, ADR 0640): um condomínio para cada tipo
 * de rateio, de 1º de janeiro até hoje, como em operação normal, e a auditoria contábil de todos.
 *
 *   php tools/lab-rateios.php                cria os condomínios e audita
 *   php tools/lab-rateios.php --conferir     só audita
 *   php tools/lab-rateios.php --remover      apaga os condomínios de teste do rateio (para criar de novo)
 *   php tools/lab-rateios.php --usuario=ID   usuário que grava (padrão: o primeiro com acesso a todos os condomínios)
 *   php tools/lab-rateios.php --relatorio=arquivo.md   grava também a auditoria em Markdown
 *
 * Mesma trava dos dados de exemplo (ADR 0162): ERP_DEMO_DATA=true, MOVESOS_ENV lab ou dev e banco de laboratório (ou o
 * nome exato em ERP_DEMO_DB). No Mac, dentro do container:
 *   docker compose exec app sh -c 'ERP_DEMO_DATA=true ERP_DEMO_DB="$MOVESOS_DB_NAME" php tools/lab-rateios.php'
 * Nenhum banco real: os boletos usam o adaptador sandbox.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ApportionmentSample;
use Source\Services\Erp\LabSampleData;

$environment = getenv('MOVESOS_ENV') ?: (getenv('APP_ENV') ?: (defined('MOVESOS_ENV') ? (string)constant('MOVESOS_ENV') : ''));
try {
    $pdo = Connect::getInstance();
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (\Throwable $error) {
    fwrite(STDERR, 'Recusado: não foi possível conferir o banco.' . PHP_EOL);
    exit(1);
}
$demoData = defined('ERP_DEMO_DATA') ? constant('ERP_DEMO_DATA') : getenv('ERP_DEMO_DATA');
$refusal = LabSampleData::guard($environment, $database, $demoData, getenv('ERP_DEMO_DB') ?: null);
if ($refusal !== null) {
    fwrite(STDERR, $refusal . PHP_EOL);
    exit(1);
}

$userId = 0;
$reportFile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--usuario=')) {
        $userId = (int)substr($arg, 10);
    }
    if (str_starts_with($arg, '--relatorio=')) {
        $reportFile = substr($arg, 12);
    }
}
$scope = new CondominiumScope($pdo);
if ($userId <= 0) {
    foreach ($pdo->query("SELECT id FROM users WHERE status = 'confirmed' ORDER BY level DESC, id")->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
        $found = (new User())->findById((int)$candidate);
        if ($found !== null && $scope->hasGlobalAccess($found)) {
            $userId = (int)$candidate;
            break;
        }
    }
}
$user = $userId > 0 ? (new User())->findById($userId) : null;
if ($user === null || !$scope->hasGlobalAccess($user)) {
    fwrite(STDERR, 'Informe --usuario=ID de um usuário com acesso a todos os condomínios.' . PHP_EOL);
    exit(1);
}
$_SESSION['authUser'] = $user->id;   // a auditoria registra quem criou

$sample = new ApportionmentSample($pdo, $user);
$started = microtime(true);
try {
    if (in_array('--remover', $argv, true)) {
        $removed = $sample->remove();
        fwrite(STDOUT, ($removed === [] ? 'Não há condomínios de teste do rateio; nada a apagar.' : 'Condomínios de teste do rateio apagados: ' . array_sum($removed) . ' linhas.') . PHP_EOL);
        exit(0);
    }
    if (!in_array('--conferir', $argv, true)) {
        fwrite(STDOUT, "Criando os condomínios de teste do rateio em {$database} (leva alguns minutos)..." . PHP_EOL);
        foreach ($sample->create() as $key => $created) {
            $parts = [];
            foreach ($created as $what => $count) {
                if ($what !== 'condominium_id') {
                    $parts[] = "{$what} {$count}";
                }
            }
            fwrite(STDOUT, sprintf('  %-14s condomínio %d: %s', $key, $created['condominium_id'], implode(', ', $parts)) . PHP_EOL);
        }
    }
    $report = $sample->verify();
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . $error->getFile() . ':' . $error->getLine() . PHP_EOL);
    exit(1);
}
$format = static function (int $value, string $kind): string {
    if ($value === PHP_INT_MIN) {
        return '(não encontrado)';
    }
    return match ($kind) {
        'cents' => 'R$ ' . ($value < 0 ? '-' : '') . number_format(abs($value) / 100, 2, ',', '.'),
        'millionths' => number_format($value / 10_000, 4, ',', '.') . '%',
        'ten_thousandths' => number_format($value / 10_000, 4, ',', '.') . '%',
        'percent' => $value . '%',
        default => (string)$value,
    };
};
$md = ['# Auditoria dos condomínios de teste do rateio', '', 'Gerada em ' . date('d/m/Y H:i') . " no banco {$database}.", ''];
foreach ($report['condominiums'] as $condo) {
    $bad = count(array_filter($condo['checks'], static fn (array $c): bool => !$c['ok']));
    fwrite(STDOUT, PHP_EOL . "{$condo['name']} (condomínio {$condo['id']}) — " . ($bad === 0 ? 'tudo fechou' : "{$bad} conferência(s) NÃO fecharam") . PHP_EOL);
    $md[] = "## {$condo['name']}";
    $md[] = '';
    $md[] = $condo['about'];
    $md[] = '';
    $md[] = '| | Área | Conferência | Esperado | Encontrado |';
    $md[] = '|---|---|---|---|---|';
    foreach ($condo['checks'] as $check) {
        $mark = !empty($check['info']) ? 'INFO' : ($check['ok'] ? 'OK  ' : 'ERRO');
        $expected = $format($check['expected'], $check['kind']);
        $actual = $format($check['actual'], $check['kind']);
        fwrite(STDOUT, sprintf('  %s  %-14s %-95s %18s %18s', $mark, $check['area'], mb_strimwidth($check['check'], 0, 95), $expected, !empty($check['info']) ? '' : $actual) . PHP_EOL);
        $md[] = sprintf('| %s | %s | %s | %s | %s |', !empty($check['info']) ? 'ℹ️' : ($check['ok'] ? '✅' : '❌'), $check['area'], $check['check'], $expected, !empty($check['info']) ? '' : $actual);
    }
    $md[] = '';
}
$summary = $report['failed'] === 0 ? 'Tudo fechou: ' . $report['total'] . ' conferências.' : "{$report['failed']} de {$report['total']} conferências NÃO fecharam.";
fwrite(STDOUT, PHP_EOL . $summary . sprintf(' (%.0f s)', microtime(true) - $started) . PHP_EOL);
if ($reportFile !== null && $reportFile !== '') {
    file_put_contents($reportFile, implode(PHP_EOL, array_merge($md, ['', $summary, ''])));
    fwrite(STDOUT, "Relatório gravado em {$reportFile}." . PHP_EOL);
}
exit($report['failed'] === 0 ? 0 : 2);
