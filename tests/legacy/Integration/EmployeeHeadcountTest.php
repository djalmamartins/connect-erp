<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EmployeeHeadcount;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0224: quadro e folha por função e turno a partir do cadastro de funcionários; somente leitura e sem dado pessoal. */
final class EmployeeHeadcountTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testHeadcountAndPayrollByJobAndShift(): void
    {
        $this->employee('porteiro', 'manha', 'Fulano Porteiro', '52998224725', '2025-01-01', null, '2000,00');
        $this->employee('porteiro', 'manha', 'Beltrano Porteiro', '11144477735', '2025-02-01', null, '2100,01');
        $this->employee('porteiro', 'noite', 'Novo Porteiro', '12345678909', '2026-10-10', null, '2200,00');
        $this->employee('zelador', null, 'Zelador Sem Turno', '98765432100', '2024-01-01', null, null);
        $this->employee('faxineiro', 'tarde', 'Saiu no Mês', '39053344705', '2024-01-01', '2026-10-20', '1500,00');
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new EmployeeHeadcount($this->pdo))->build($this->admin, $this->condominium, '2026-10-01', '2026-10-31');
        self::assertSame([
            ['zelador', 'Sem turno', 1, 0, null, 0, 0],
            ['porteiro', 'Manhã', 2, 410001, 205001, 0, 0],
            ['porteiro', 'Noite', 1, 220000, 220000, 1, 0],
            ['faxineiro', 'Tarde', 0, 0, null, 0, 1],
        ], array_map(static fn (array $r): array => [$r['job'], $r['shift_label'], $r['active'], $r['salary_total_cents'], $r['salary_avg_cents'], $r['hires'],
            $r['terminations']], $report['rows']));
        self::assertSame(['active' => 4, 'with_salary' => 3, 'salary_total_cents' => 630001, 'salary_avg_cents' => 210000, 'hires' => 1, 'terminations' => 1],
            $report['totals']);
        $json = (string)json_encode($report);
        foreach (['Fulano', 'Beltrano', 'Zelador Sem Turno', '52998224725', '98765432100'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem nome nem CPF: ' . $needle);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $september = (new EmployeeHeadcount($this->pdo))->build($this->admin, $this->condominium, '2026-09-01', '2026-09-30');
        self::assertSame([4, 0, 0], [$september['totals']['active'], $september['totals']['hires'], $september['totals']['terminations']],
            'Em setembro o faxineiro ainda estava ativo e o porteiro novo não tinha sido admitido.');
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new EmployeeHeadcount($this->pdo))->build($limited, $this->condominium);
            self::fail('Consultou condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function employee(string $job, ?string $shift, string $name, string $cpf, string $hiredOn, ?string $terminatedOn, ?string $salary): void
    {
        $this->registry->save($this->admin, 'employees', ['condominium_id' => $this->condominium, 'job' => $job, 'shift' => $shift, 'name' => $name, 'document' => $cpf,
            'hired_on' => $hiredOn, 'terminated_on' => $terminatedOn, 'salary_cents' => $salary]);
    }
}
