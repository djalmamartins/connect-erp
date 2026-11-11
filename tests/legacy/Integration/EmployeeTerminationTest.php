<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EmployeeHeadcount;
use Source\Services\Erp\EmployeeTermination;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0225: desligamento em lote pelo formulário de funcionários, tudo ou nada, prévia e auditoria, sem nome nem CPF. */
final class EmployeeTerminationTest extends TestCase
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

    public function testTerminatesThroughTheForm(): void
    {
        $doorA = $this->employee('Fulano Porteiro', '52998224725', '2025-01-01', null, '2000,00');
        $doorB = $this->employee('Beltrano Porteiro', '11144477735', '2025-02-01', null, '2100,01');
        $stays = $this->employee('Fica', '12345678909', '2024-01-01', null, '1800,00');
        $input = ['condominium_id' => $this->condominium, 'employee_ids' => [$doorA, $doorB], 'terminated_on' => '2026-11-10',
            'reason' => 'Portaria terceirizada a partir de 11/11'];

        $preview = (new EmployeeTermination($this->pdo))->terminate($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, 2], [$preview['dry_run'], $preview['changed'], $preview['count']]);
        self::assertNull($this->column($doorA, 'terminated_on'), 'dry_run não grava.');
        $json = (string)json_encode($preview);
        foreach (['Fulano', 'Beltrano', '52998224725', 'salary'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem nome, CPF nem salário: ' . $needle);
        }

        $done = (new EmployeeTermination($this->pdo))->terminate($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['2026-11-10', '2026-11-10', null], [$this->column($doorA, 'terminated_on'), $this->column($doorB, 'terminated_on'), $this->column($stays, 'terminated_on')]);
        self::assertSame(['200000', '210001', 'active'], [$this->column($doorA, 'salary_cents'), $this->column($doorB, 'salary_cents'), $this->column($doorA, 'status')],
            'Salário e situação intactos.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_employees' AND action = 'terminate'")->fetchColumn());
        $headcount = (new EmployeeHeadcount($this->pdo))->build($this->admin, $this->condominium, '2026-11-01', '2026-11-10');
        self::assertSame([1, 2], [$headcount['totals']['active'], $headcount['totals']['terminations']], 'O quadro da 0224 já lê o desligamento.');
    }

    public function testAllOrNothingRefusalsAndScope(): void
    {
        $active = $this->employee('Ativo', '52998224725', '2025-01-01', null, '2000,00');
        $gone = $this->employee('Já Saiu', '11144477735', '2024-01-01', '2026-10-31', '1500,00');
        $later = $this->employee('Admitido Depois', '12345678909', '2026-11-08', null, '1700,00');
        foreach ([[$gone, 'já desligado'], [$later, 'antes da admissão']] as [$refused, $message]) {
            try {
                (new EmployeeTermination($this->pdo))->terminate($this->admin, ['condominium_id' => $this->condominium, 'employee_ids' => [$active, $refused],
                    'terminated_on' => '2026-11-05', 'reason' => 'Troca da equipe']);
                self::fail('Funcionário recusado entrou no lote.');
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$refused, $exception->errors['_employee']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
        }
        self::assertNull($this->column($active, 'terminated_on'), 'Nada gravado.');
        self::assertSame('2026-10-31', $this->column($gone, 'terminated_on'), 'A data antiga não é trocada.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new EmployeeTermination($this->pdo))->terminate($limited, ['condominium_id' => $this->condominium, 'employee_ids' => [$active],
                'terminated_on' => '2026-11-05', 'reason' => 'Troca da equipe']);
            self::fail('Desligou fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function employee(string $name, string $cpf, string $hiredOn, ?string $terminatedOn, ?string $salary): int
    {
        return $this->registry->save($this->admin, 'employees', ['condominium_id' => $this->condominium, 'job' => 'porteiro', 'shift' => 'manha', 'name' => $name,
            'document' => $cpf, 'hired_on' => $hiredOn, 'terminated_on' => $terminatedOn, 'salary_cents' => $salary])['id'];
    }

    private function column(int $employee, string $column): ?string
    {
        $value = $this->pdo->query("SELECT {$column} FROM erp_employees WHERE id = {$employee}")->fetchColumn();
        return $value === null ? null : substr((string)$value, 0, 10);
    }
}
