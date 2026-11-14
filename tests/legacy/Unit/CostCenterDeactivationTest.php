<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CostCenterDeactivation;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0273: inativação em lote dos centros de custo parados. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o
 * PDO de teste tira o FOR UPDATE) para a regra pura (o início da janela, com meses negativos e a virada do ano), a
 * validação, o escopo, as recusas antes do formulário, os que passam pela recusa, as contagens dos que ficam de fora e o
 * contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB (Integration\CostCenterDeactivationTest).
 */
final class CostCenterDeactivationTest extends TestCase
{
    private PDO $pdo;
    private CostCenterDeactivation $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_cost_centers (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, cost_center_id INTEGER, competence TEXT, paid_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_recurring_entries (id INTEGER PRIMARY KEY, cost_center_id INTEGER, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, cost_center_id INTEGER, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $insert = $this->pdo->prepare('INSERT INTO erp_cost_centers (id, condominium_id, code, name, notes, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([[1, 1, 'OBRA-A', 'Obra do bloco A', 'active', null], [2, 1, 'NUNCA', 'Nunca usado', 'active', null], [3, 1, 'RECENTE', 'Recente', 'active', null],
            [4, 1, 'ABERTO', 'Com conta em aberto', 'active', null], [5, 1, 'FIXO', 'Com fixo', 'active', null], [6, 1, 'BANCO', 'Padrão do banco', 'active', null],
            [7, 1, '!!', 'Código antigo', 'active', null], [8, 1, 'INATIVO', 'Já inativo', 'inactive', null], [9, null, 'PADRAO', 'Padrão da Connect', 'active', null],
            [10, 3, 'FORA', 'De fora', 'active', null], [11, 1, 'EXCL', 'Excluído', 'active', '2026-01-01 00:00:00'], [12, 1, 'CANC', 'Só conta cancelada', 'active', null],
            [13, 1, 'BANCO-INAT', 'Banco inativo', 'active', null]] as [$id, $c, $code, $name, $status, $deleted]) {
            $insert->execute([$id, $c, $code, $name, 'Reforma da piscina', $status, $deleted]);
        }
        $old = date('Y-m', strtotime('first day of -13 months'));
        $since = CostCenterDeactivation::since(date('Y-m'), 12);
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, cost_center_id, competence, paid_on, status, deleted_at) VALUES
            (1, 1, '{$old}', '2025-01-10', 'active', NULL), (1, 3, '{$since}', '2026-01-10', 'active', NULL), (1, 4, '{$old}', NULL, 'active', NULL),
            (1, 12, '{$old}', NULL, 'inactive', NULL), (1, 13, '{$since}', NULL, 'active', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_recurring_entries (cost_center_id, status, deleted_at) VALUES (5, 'active', NULL), (13, 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (cost_center_id, status, deleted_at) VALUES (6, 'active', NULL), (13, 'inactive', NULL)");
        $this->service = new CostCenterDeactivation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSinceAndProblemPureRules(): void
    {
        self::assertSame('2025-12', CostCenterDeactivation::since('2026-11', 12), 'Doze meses: do mês atual voltando onze.');
        self::assertSame('2026-11', CostCenterDeactivation::since('2026-11', 1), 'Um mês: só o mês atual.');
        self::assertSame('2025-12', CostCenterDeactivation::since('2026-01', 2), 'Volta pela virada do ano.');
        self::assertSame('2016-12', CostCenterDeactivation::since('2026-11', 120));
        $none = ['recent' => false, 'open' => false, 'recurring' => false, 'bank_default' => false];
        $row = ['status' => 'active'];
        self::assertNull(CostCenterDeactivation::problem($row, $none));
        self::assertStringContainsString('não encontrado', (string)CostCenterDeactivation::problem(null, $none));
        self::assertStringContainsString('já está inativo', (string)CostCenterDeactivation::problem(['status' => 'inactive'], $none));
        self::assertStringContainsString('lançamento recente', (string)CostCenterDeactivation::problem($row, ['recent' => true] + $none));
        self::assertStringContainsString('conta em aberto', (string)CostCenterDeactivation::problem($row, ['open' => true] + $none));
        self::assertStringContainsString('lançamento fixo ativo', (string)CostCenterDeactivation::problem($row, ['recurring' => true] + $none));
        self::assertStringContainsString('conta bancária ativa', (string)CostCenterDeactivation::problem($row, ['bank_default' => true] + $none));
        self::assertStringContainsString('formulário não regrava o centro de custo (Código', (string)CostCenterDeactivation::problem($row, $none, 'Código: inválido'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Obras encerradas'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['idle_months' => 0] + $ok, ['idle_months' => 121] + $ok,
            ['idle_months' => 'x'] + $ok, ['cost_center_ids' => []] + $ok, ['cost_center_ids' => ['x']] + $ok, ['cost_center_ids' => 'abc'] + $ok,
            ['cost_center_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->deactivate($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->deactivate($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[3, 'lançamento recente'], [4, 'conta em aberto'], [5, 'lançamento fixo ativo'], [6, 'conta bancária ativa'], [7, 'Código'], [8, 'já está inativo'],
            [9, 'não encontrado'], [10, 'não encontrado'], [11, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->deactivate($this->user(), ['condominium_id' => 1, 'cost_center_ids' => [1, $id], 'reason' => 'Obras encerradas']);
                self::fail("Centro de custo {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_cost_center']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['Obra do bloco', 'OBRA-A', 'Reforma da piscina'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        foreach ([1, 2, 12, 13] as $parado) {
            try {
                $this->service->deactivate($this->user(), ['condominium_id' => 1, 'cost_center_ids' => [$parado, 4], 'reason' => 'Obras encerradas']);
                self::fail('O centro de custo com conta em aberto passou.');
            } catch (RegistryValidationException $exception) {
                self::assertSame('4', $exception->errors['_cost_center'], "O {$parado} está parado e passa pela recusa (conta cancelada, fixo e banco inativos não contam).");
            }
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_cost_centers WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_cost_centers SET deleted_at = '2026-01-01 00:00:00' WHERE id IN (1, 2, 12, 13)");
        $result = $this->service->deactivate($this->user(), ['condominium_id' => '1', 'reason' => 'Obras encerradas', 'dry_run' => true]);
        self::assertSame([1, 1, 1, 1, 0, 0], [$result['skipped_open'], $result['skipped_recurring'], $result['skipped_bank_default'], $result['skipped_invalid'],
            $result['count'], $result['changed']]);
        self::assertSame([12, CostCenterDeactivation::since(date('Y-m'), 12)], [$result['idle_months'], $result['since_competence']]);
        $longer = $this->service->deactivate($this->user(), ['condominium_id' => '1', 'reason' => 'Obras encerradas', 'dry_run' => true, 'idle_months' => '24']);
        self::assertSame(0, $longer['skipped_open'], 'Com 24 meses, a conta em aberto de 13 meses atrás é movimento recente.');
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/CostCenterDeactivation.php');
        foreach (["\$registry->save(\$user, 'cost_centers', array_merge(self::formData(\$fields, \$row), ['status' => 'inactive'])", ' FOR UPDATE',
            "Audit::record('deactivate_idle'", '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('cost_centers')",
            'VehicleUnitRelease::formError(', "sprintf('%+d months'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'code' =>", "['code']", "['name']", "['notes']"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem código ou nome na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CostCenterDeactivation.php');
        foreach (["Access::can('erp.cost_centers.view', \$user)", "Access::can('erp.cost_centers.manage', \$user)", "Access::can('erp.entries.view', \$user)",
            'catch (CondominiumAccessDenied)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/cost-centers/deactivate-idle", "CostCenterDeactivation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/recurring-portfolio"'), strpos($index, $route), 'Logo depois da carteira de lançamentos fixos.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
