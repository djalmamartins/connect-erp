<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EmployeeSalaryReadjustment;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0223: reajuste salarial em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO de teste tira o
 * FOR UPDATE) para a regra pura, a validação, o escopo, a recusa antes do formulário e o contrato de reuso; a regravação
 * pelo formulário é conferida no MariaDB (Integration\EmployeeSalaryReadjustmentTest).
 */
final class EmployeeSalaryReadjustmentTest extends TestCase
{
    private PDO $pdo;
    private EmployeeSalaryReadjustment $service;

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
            'CREATE TABLE erp_employees (id INTEGER PRIMARY KEY, condominium_id INTEGER, job TEXT, name TEXT, document TEXT, hired_on TEXT, terminated_on TEXT,
                shift TEXT, salary_cents INTEGER, phone TEXT, email TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_employees (id, condominium_id, job, name, document, hired_on, terminated_on, salary_cents, status) VALUES
            (1, 1, 'porteiro', 'Fulano', '52998224725', '2025-01-01', NULL, 200000, 'active'),
            (2, 1, 'zelador', 'Beltrano', '11144477735', '2025-01-01', '2026-10-31', 300000, 'active'),
            (3, 1, 'faxineiro', 'Ciclano', '12345678909', '2025-01-01', NULL, NULL, 'active'),
            (4, 1, 'vigia', 'Inativo', '98765432100', '2025-01-01', NULL, 150000, 'inactive'),
            (5, 3, 'porteiro', 'Outro', '39053344705', '2025-01-01', NULL, 200000, 'active')");
        $this->service = new EmployeeSalaryReadjustment($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'terminated_on' => null, 'salary_cents' => 200000];
        self::assertNull(EmployeeSalaryReadjustment::problem($row, '2026-11-01'));
        self::assertNull(EmployeeSalaryReadjustment::problem(['terminated_on' => '2026-11-02'] + $row, '2026-11-01'), 'Desligado depois do reajuste entra.');
        self::assertStringContainsString('não encontrado', (string)EmployeeSalaryReadjustment::problem(null, '2026-11-01'));
        self::assertStringContainsString('inativo', (string)EmployeeSalaryReadjustment::problem(['status' => 'inactive'] + $row, '2026-11-01'));
        self::assertStringContainsString('desligado', (string)EmployeeSalaryReadjustment::problem(['terminated_on' => '2026-11-01'] + $row, '2026-11-01'));
        self::assertStringContainsString('sem salário', (string)EmployeeSalaryReadjustment::problem(['salary_cents' => null] + $row, '2026-11-01'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'percent' => '5', 'effective_on' => '2026-11-01', 'reason' => 'Dissídio 2026/2027'];
        foreach ([['condominium_id' => 0] + $ok, ['percent' => '0'] + $ok, ['percent' => '101'] + $ok, ['percent' => null] + $ok, ['effective_on' => '01/11/2026'] + $ok,
            ['reason' => 'abc'] + $ok, ['job' => 'piloto'] + $ok, ['employee_ids' => []] + $ok, ['employee_ids' => ['x']] + $ok,
            ['employee_ids' => range(1, 301)] + $ok] as $input) {
            try {
                $this->service->readjust($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->readjust($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'desligado'], [3, 'sem salário'], [4, 'inativo'], [5, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->readjust($this->user(), ['condominium_id' => 1, 'percent' => '5', 'effective_on' => '2026-11-01', 'reason' => 'Dissídio 2026/2027',
                    'employee_ids' => [1, $id]]);
                self::fail("Funcionário {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_employee']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame(200000, (int)$this->pdo->query('SELECT salary_cents FROM erp_employees WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/EmployeeSalaryReadjustment.php');
        foreach (['RecurringReadjustment::basisPoints(', 'RecurringReadjustment::readjusted(', "\$registry->save(\$user, 'employees'", ' FOR UPDATE',
            "Audit::record('readjust'", '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'NotificationOutbox', "['name']", "['document']", "['phone']", "['email']"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário e sem dado pessoal na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/EmployeeSalaryReadjustment.php');
        foreach (["Access::can('erp.employees.view', \$user)", "Access::can('erp.employees.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/employees/readjust-salaries", "EmployeeSalaryReadjustment:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
