<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EmployeeSalaryReadjustment;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0223: reajuste salarial em lote pelo formulário de funcionários, tudo ou nada, prévia e auditoria, sem nome nem CPF. */
final class EmployeeSalaryReadjustmentTest extends TestCase
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

    public function testReadjustsActiveSalariesThroughTheForm(): void
    {
        $doorA = $this->employee('porteiro', 'Fulano Porteiro', '52998224725', '2000,00');
        $doorB = $this->employee('porteiro', 'Beltrano Porteiro', '11144477735', '1999,99');
        $keeper = $this->employee('zelador', 'Ciclano Zelador', '12345678909', '3000,00');
        $gone = $this->employee('faxineiro', 'Desligado', '98765432100', '1500,00', '2026-10-31');
        $unpaid = $this->employee('faxineiro', 'Sem salário', '39053344705', null);
        $input = ['condominium_id' => $this->condominium, 'percent' => '5,5', 'effective_on' => '2026-11-01', 'reason' => 'Dissídio 2026/2027'];

        $preview = (new EmployeeSalaryReadjustment($this->pdo))->readjust($this->admin, $input + ['job' => 'porteiro', 'dry_run' => true]);
        self::assertSame([true, 0, [$doorA, $doorB], 399999, 421999], [$preview['dry_run'], $preview['changed'], array_column($preview['employees'], 'id'),
            $preview['before_cents'], $preview['after_cents']]);
        self::assertSame(200000, $this->salary($doorA), 'dry_run não grava.');
        $json = (string)json_encode($preview);
        foreach (['Fulano', 'Beltrano', '52998224725', '11144477735'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem nome nem CPF: ' . $needle);
        }

        $done = (new EmployeeSalaryReadjustment($this->pdo))->readjust($this->admin, $input);
        self::assertSame([$doorA, $doorB, $keeper], array_column($done['employees'], 'id'), 'Desligado e sem salário ficam de fora.');
        self::assertSame([211000, 210999, 316500, 150000, null], [$this->salary($doorA), $this->salary($doorB), $this->salary($keeper), $this->salary($gone),
            $this->salary($unpaid)]);
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_employees' AND action = 'readjust'")->fetchColumn());
        self::assertSame('52998224725', (string)$this->pdo->query("SELECT document FROM erp_employees WHERE id = {$doorA}")->fetchColumn(), 'CPF intacto.');
    }

    public function testChosenIdsAreAllOrNothingAndScoped(): void
    {
        $door = $this->employee('porteiro', 'Fulano Porteiro', '52998224725', '2000,00');
        $gone = $this->employee('faxineiro', 'Desligado', '98765432100', '1500,00', '2026-10-31');
        try {
            (new EmployeeSalaryReadjustment($this->pdo))->readjust($this->admin, ['condominium_id' => $this->condominium, 'percent' => '5', 'effective_on' => '2026-11-01',
                'reason' => 'Dissídio 2026/2027', 'employee_ids' => [$door, $gone]]);
            self::fail('Funcionário desligado entrou no reajuste.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$gone, $exception->errors['_employee']);
        }
        self::assertSame(200000, $this->salary($door), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new EmployeeSalaryReadjustment($this->pdo))->readjust($limited, ['condominium_id' => $this->condominium, 'percent' => '5', 'effective_on' => '2026-11-01',
                'reason' => 'Dissídio 2026/2027']);
            self::fail('Reajustou fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function employee(string $job, string $name, string $cpf, ?string $salary, ?string $terminatedOn = null): int
    {
        return $this->registry->save($this->admin, 'employees', ['condominium_id' => $this->condominium, 'job' => $job, 'name' => $name, 'document' => $cpf,
            'hired_on' => '2025-01-01', 'shift' => 'comercial', 'salary_cents' => $salary, 'terminated_on' => $terminatedOn])['id'];
    }

    private function salary(int $employee): ?int
    {
        $value = $this->pdo->query("SELECT salary_cents FROM erp_employees WHERE id = {$employee}")->fetchColumn();
        return $value === null ? null : (int)$value;
    }
}
