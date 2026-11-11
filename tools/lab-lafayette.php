<?php

declare(strict_types=1);

/**
 * Condomínio de teste Lafayette: 6 meses de dados fictícios em todos os módulos e a conferência dos números
 * (docs/process/LAFAYETTE_TEST_DATA.md).
 *
 *   php tools/lab-lafayette.php --usuario=ID              cria e confere
 *   php tools/lab-lafayette.php --usuario=ID --conferir   só confere
 *   php tools/lab-lafayette.php --usuario=ID --remover    apaga o Lafayette (para criar de novo)
 *
 * Mesma trava dos dados de exemplo (ADR 0162): ERP_DEMO_DATA=true, MOVESOS_ENV lab ou dev e banco de
 * laboratório (ou o nome exato em ERP_DEMO_DB). Sem --usuario usa o primeiro usuário com acesso a todos os
 * condomínios. Nenhum banco real: os boletos usam o adaptador sandbox.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LabSampleData;
use Source\Services\Erp\LafayetteSample;

$environment = getenv('MOVESOS_ENV') ?: (getenv('APP_ENV') ?: '');
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
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--usuario=')) {
        $userId = (int)substr($arg, 10);
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

$sample = new LafayetteSample($pdo, $user);
try {
    if (in_array('--remover', $argv, true)) {
        $removed = $sample->remove();
        fwrite(STDOUT, ($removed === [] ? 'Lafayette não existe; nada a apagar.' : 'Lafayette apagado: ' . array_sum($removed) . ' linhas.') . PHP_EOL);
        exit(0);
    }
    if (!in_array('--conferir', $argv, true)) {
        $created = $sample->create();
        fwrite(STDOUT, "Lafayette criado em {$database} (condomínio {$created['condominium_id']}):" . PHP_EOL);
        foreach ($created as $what => $count) {
            if ($what !== 'condominium_id') {
                fwrite(STDOUT, sprintf('  %-22s %d', $what, $count) . PHP_EOL);
            }
        }
    }
    $report = $sample->verify();
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
$money = static fn (int $cents): string => ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2, ',', '.');
fwrite(STDOUT, PHP_EOL . 'Conferência:' . PHP_EOL);
foreach ($report['checks'] as $check) {
    fwrite(STDOUT, sprintf('  %s  %-80s esperado %15s  encontrado %15s', $check['ok'] ? 'OK ' : 'ERRO', $check['check'], $money($check['expected']), $money($check['actual'])) . PHP_EOL);
}
$failed = count(array_filter(array_column($report['checks'], 'ok'), static fn (bool $ok): bool => !$ok));
fwrite(STDOUT, PHP_EOL . ($failed === 0 ? 'Tudo fechou: ' . count($report['checks']) . ' conferências.' : "{$failed} conferência(s) NÃO fecharam.") . PHP_EOL);
exit($failed === 0 ? 0 : 2);
