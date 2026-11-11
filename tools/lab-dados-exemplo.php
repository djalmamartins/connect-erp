<?php

declare(strict_types=1);

/**
 * Dados de exemplo para testar a inadimplência no laboratório (ADR 0162).
 *   php tools/lab-dados-exemplo.php            cria (idempotente; tudo com "EXEMPLO" no nome)
 *   php tools/lab-dados-exemplo.php --usuario=ID  cria e gera também o ano contábil de exemplo (ADR 0173)
 *   php tools/lab-dados-exemplo.php --remover  apaga só o que tem a marca (antes do ano de exemplo)
 *
 * Recusa rodar sem ERP_DEMO_DATA=true, fora de lab/dev (MOVESOS_ENV ou APP_ENV) ou com banco sem marca de
 * laboratório no nome (lab, dev, local ou sandbox). Não cria boleto, não chama banco e não manda e-mail.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\LabSampleData;
use Source\Services\Erp\LabSampleYear;

$environment = getenv('MOVESOS_ENV') ?: (getenv('APP_ENV') ?: '');
try {
    $pdo = Connect::getInstance();
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (\Throwable $error) {
    fwrite(STDERR, 'Recusado: não foi possível conferir o banco.' . PHP_EOL);
    exit(1);
}
// ERP_DEMO_DATA: a constante que o Config.php define a partir do .env, ou o ambiente, se a constante não existir.
$demoData = defined('ERP_DEMO_DATA') ? constant('ERP_DEMO_DATA') : getenv('ERP_DEMO_DATA');
// ERP_DEMO_DB: libera um banco pelo nome exato (o lab do Djalma usa connect_legacy).
$refusal = LabSampleData::guard($environment, $database, $demoData, getenv('ERP_DEMO_DB') ?: null);
if ($refusal !== null) {
    fwrite(STDERR, $refusal . PHP_EOL);
    exit(1);
}

// --usuario=ID (ADR 0173): também gera o ano contábil de exemplo pelos services, em nome deste usuário, que
// precisa acessar todos os condomínios. Sem ele, só os cadastros e as contas vencidas do ADR 0162.
$userId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--usuario=')) {
        $userId = filter_var(substr($arg, 10), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            fwrite(STDERR, 'Recusado: --usuario precisa ser o id numérico de um usuário.' . PHP_EOL);
            exit(1);
        }
    }
}

$service = new LabSampleData($pdo);
try {
    if (in_array('--remover', $argv, true)) {
        $result = ['removed' => $service->remove()];
    } else {
        $result = ['created' => $service->create()];
        if ($userId !== null) {
            $check = $pdo->prepare('SELECT id FROM users WHERE id = ?');
            $check->execute([$userId]);
            if ($check->fetchColumn() === false) {
                throw new RuntimeException('Recusado: usuário ' . $userId . ' não encontrado.');
            }
            $user = new User();
            $user->id = $userId;
            $result['year'] = (new LabSampleYear($pdo))->create($user);
        }
    }
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, json_encode($result + ['database' => $database], JSON_UNESCAPED_UNICODE) . PHP_EOL);
