<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EmployeeHeadcount;

/**
 * ADR 0224: quadro e folha por função e turno, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2) para a regra pura (ativos, admissões e desligamentos no período, "Sem turno", totais iguais à soma das linhas), a
 * montagem, a validação e o escopo; os funcionários gravados pelo formulário são conferidos no MariaDB
 * (Integration\EmployeeHeadcountTest).
 */
final class EmployeeHeadcountTest extends TestCase
{
    private PDO $pdo;
    private EmployeeHeadcount $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_employees (id INTEGER PRIMARY KEY, condominium_id INTEGER, job TEXT, name TEXT, document TEXT, hired_on TEXT, terminated_on TEXT,
                shift TEXT, salary_cents INTEGER, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new EmployeeHeadcount($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $e = static fn (?string $job, ?string $shift, string $hired, ?string $ended, ?int $salary, string $status = 'active'): array => ['job' => $job,
            'shift' => $shift, 'status' => $status, 'hired_on' => $hired, 'terminated_on' => $ended, 'salary_cents' => $salary];
        $summary = EmployeeHeadcount::summarize([
            $e('porteiro', 'manha', '2025-01-01', null, 200000),
            $e('porteiro', 'manha', '2025-03-01', null, 210001),
            $e('porteiro', 'noite', '2026-10-10', null, 220000),            // admitido no período
            $e('zelador', null, '2024-01-01', null, null),                  // sem turno e sem salário
            $e('faxineiro', 'tarde', '2024-01-01', '2026-10-20', 150000),   // desligado no período
            $e('vigia', 'noite', '2024-01-01', null, 180000, 'inactive'),   // cadastro inativo, sem movimento
            $e('porteiro', 'manha', '2026-11-05', null, 200000),            // admitido depois do período
            $e('jardineiro', 'comercial', '2024-01-01', '2026-09-30', 160000), // desligado antes do período
        ], '2026-10-01', '2026-10-31');
        self::assertSame([
            ['zelador', 'Sem turno', 1, 0, 0, null, 0, 0],
            ['porteiro', 'Manhã', 2, 2, 410001, 205001, 0, 0],
            ['porteiro', 'Noite', 1, 1, 220000, 220000, 1, 0],
            ['faxineiro', 'Tarde', 0, 0, 0, null, 0, 1],
        ], array_map(static fn (array $r): array => [$r['job'], $r['shift_label'], $r['active'], $r['with_salary'], $r['salary_total_cents'], $r['salary_avg_cents'],
            $r['hires'], $r['terminations']], $summary['rows']));
        self::assertSame(['active' => 4, 'with_salary' => 3, 'salary_total_cents' => 630001, 'salary_avg_cents' => 210000, 'hires' => 1, 'terminations' => 1],
            $summary['totals'], 'Totais = soma das linhas, com "Sem turno".');
        foreach (['active', 'with_salary', 'salary_total_cents', 'hires', 'terminations'] as $field) {
            self::assertSame($summary['totals'][$field], array_sum(array_column($summary['rows'], $field)), $field);
        }
        self::assertSame('Sem função', EmployeeHeadcount::summarize([$e(null, null, '2024-01-01', null, 100)], '2026-10-01', '2026-10-31')['rows'][0]['job_label']);
        self::assertSame(['rows' => [], 'totals' => ['active' => 0, 'with_salary' => 0, 'salary_total_cents' => 0, 'salary_avg_cents' => null, 'hires' => 0,
            'terminations' => 0]], EmployeeHeadcount::summarize([], '2026-10-01', '2026-10-31'));
    }

    public function testBuildWithoutPersonalData(): void
    {
        $this->pdo->exec("INSERT INTO erp_employees (id, condominium_id, job, name, document, hired_on, terminated_on, shift, salary_cents, status, deleted_at) VALUES
            (1, 1, 'porteiro', 'Fulano', '52998224725', '2025-01-01', NULL, 'manha', 200000, 'active', NULL),
            (2, 1, 'porteiro', 'Beltrano', '11144477735', '2025-01-01', NULL, 'manha', 200000, 'active', '2026-01-01 00:00:00'),
            (3, 3, 'porteiro', 'Outro', '12345678909', '2025-01-01', NULL, 'manha', 900000, 'active', NULL)");
        $report = $this->service->build($this->user(), '1', '2026-10-01', '2026-10-31');
        self::assertSame([1, 200000], [$report['totals']['active'], $report['totals']['salary_total_cents']], 'Apagado e outro condomínio de fora.');
        $json = (string)json_encode($report);
        foreach (['Fulano', 'Beltrano', '52998224725', '"id"', 'name'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
        self::assertSame(['2026-11-01', '2026-11-11'], [$this->service->build($this->user(), 1, null, '2026-11-11')['from'],
            $this->service->build($this->user(), 1, null, '2026-11-11')['to']], 'Início padrão: primeiro dia do mês do fim.');
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [1, '01/10/2026', '2026-10-31'], [1, '2026-11-01', '2026-10-31'], [1, '2024-01-01', '2026-10-31'],
            [1, '2026-10-01', '2026-02-30']] as [$c, $from, $to]) {
            try {
                $this->service->build($this->user(), $c, $from, $to);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $from, $to]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $body = explode('final class', (string)file_get_contents($root . '/source/Services/Erp/EmployeeHeadcount.php'))[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'name,', 'document', 'phone', 'email'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/EmployeeHeadcount.php');
        foreach (["Access::can('erp.employees.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/employee-headcount", "EmployeeHeadcount:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
