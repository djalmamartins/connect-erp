<?php

declare(strict_types=1);

/**
 * Conta de morador para testar o aplicativo do morador (ADR 0302) no lab.
 *
 *   php tools/lab-morador-teste.php [--email=morador.teste@connect.lab] [--condominio=ID] [--usuario=ID]
 *
 * Liga a conta ao primeiro proprietário ativo do condomínio de teste Lafayette (dados fictícios; ou --condominio=ID)
 * e liga todas as funções do aplicativo nesse condomínio. Rodar de novo gera uma senha nova para a mesma conta.
 * Mesma trava dos dados de exemplo (ADR 0162): ERP_DEMO_DATA=true, MOVESOS_ENV lab ou dev e banco de laboratório
 * (ou o nome exato em ERP_DEMO_DB). A senha aparece só na tela; nada é enviado por e-mail.
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
use Source\Services\Erp\ResidentPortalPermissions;

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

$options = ['email' => 'morador.teste@connect.lab', 'condominio' => 0, 'usuario' => 0];
foreach ($argv as $arg) {
    if (preg_match('/^--(email|condominio|usuario)=(.+)$/', $arg, $m)) {
        $options[$m[1]] = $m[1] === 'email' ? trim($m[2]) : (int)$m[2];
    }
}
$email = (string)$options['email'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, 'E-mail inválido.' . PHP_EOL);
    exit(1);
}

// Quem liga as funções (auditoria): --usuario ou o primeiro com acesso a todos os condomínios.
$scope = new CondominiumScope($pdo);
$adminId = (int)$options['usuario'];
if ($adminId <= 0) {
    foreach ($pdo->query("SELECT id FROM users WHERE status = 'confirmed' ORDER BY level DESC, id")->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
        $found = (new User())->findById((int)$candidate);
        if ($found !== null && $scope->hasGlobalAccess($found)) {
            $adminId = (int)$candidate;
            break;
        }
    }
}
$admin = $adminId > 0 ? (new User())->findById($adminId) : null;
if ($admin === null || !$scope->hasGlobalAccess($admin)) {
    fwrite(STDERR, 'Informe --usuario=ID de um usuário com acesso a todos os condomínios.' . PHP_EOL);
    exit(1);
}
$_SESSION['authUser'] = $admin->id;

$condominiumId = (int)$options['condominio'];
if ($condominiumId <= 0) {
    $stmt = $pdo->prepare('SELECT id FROM condominiums WHERE name = ? ORDER BY id LIMIT 1');
    $stmt->execute([LafayetteSample::NAME]);
    $condominiumId = (int)$stmt->fetchColumn();
    if ($condominiumId <= 0) {
        fwrite(STDERR, 'O condomínio de teste Lafayette não existe. Crie com tools/lab-lafayette.php ou informe --condominio=ID.' . PHP_EOL);
        exit(1);
    }
}

$user = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$user->execute([$email]);
$userId = (int)$user->fetchColumn();

// Vínculo: o que já é desta conta no condomínio ou o primeiro proprietário ativo sem conta.
$stmt = $pdo->prepare("SELECT r.id, r.name, r.unit_id, u.label FROM erp_residents r JOIN erp_units u ON u.id = r.unit_id
    WHERE r.condominium_id = :c AND r.deleted_at IS NULL AND r.status = 'active' AND u.deleted_at IS NULL AND u.status = 'active' AND r.relationship = 'owner'
      AND (r.user_id IS NULL OR r.user_id = :u) AND (r.ends_on IS NULL OR r.ends_on >= CURDATE()) AND (r.starts_on IS NULL OR r.starts_on <= CURDATE())
    ORDER BY (r.user_id = :u2) DESC, u.label, r.id LIMIT 1");
$stmt->execute(['c' => $condominiumId, 'u' => $userId, 'u2' => $userId]);
$resident = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($resident)) {
    fwrite(STDERR, "Nenhum proprietário ativo sem conta no condomínio {$condominiumId}." . PHP_EOL);
    exit(1);
}

$password = 'Morador@' . random_int(1000, 9999);
$pdo->beginTransaction();
try {
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET password = ?, status = 'confirmed' WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    } else {
        $parts = explode(' ', trim((string)$resident['name']), 2);
        $pdo->prepare("INSERT INTO users (first_name, last_name, email, document, password, level, status) VALUES (?, ?, ?, ?, ?, 1, 'confirmed')")
            ->execute([$parts[0], $parts[1] ?? '', $email, testCpf(random_int(1, 99999)), password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$pdo->lastInsertId();
    }
    $pdo->exec("INSERT IGNORE INTO access_roles (name, slug, level) VALUES ('Usuário', 'user', 1)");
    $pdo->prepare("INSERT IGNORE INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = 'user'")->execute([$userId]);
    $pdo->prepare('UPDATE erp_residents SET user_id = ? WHERE id = ?')->execute([$userId, (int)$resident['id']]);
    $portal = new ResidentPortalPermissions($pdo);
    foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
        $portal->set($admin, $condominiumId, $feature, true);
    }
    $pdo->commit();
} catch (\Throwable $error) {
    $pdo->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

$condominium = $pdo->prepare('SELECT name FROM condominiums WHERE id = ?');
$condominium->execute([$condominiumId]);
fwrite(STDOUT, implode(PHP_EOL, [
    'Morador de teste pronto em ' . $database . ':',
    '  E-mail:      ' . $email,
    '  Senha:       ' . $password,
    '  Unidade:     ' . $resident['label'] . ' · ' . $condominium->fetchColumn() . ' (' . $resident['name'] . ')',
    '  Endereço:    https://www.connect.lab/app (ou https://app.connect.lab quando o host estiver ligado)',
    '  Funções do aplicativo ligadas nesse condomínio.',
]) . PHP_EOL);

/** CPF fictício com dígitos verificadores válidos. */
function testCpf(int $seed): string
{
    $digits = str_split(str_pad((string)(100000000 + $seed * 7919 % 800000000), 9, '0', STR_PAD_LEFT));
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($c = 0; $c < $t; $c++) {
            $sum += (int)$digits[$c] * (($t + 1) - $c);
        }
        $digits[] = (string)((10 * $sum) % 11 % 10);
    }
    return implode('', $digits);
}
