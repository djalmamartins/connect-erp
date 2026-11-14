<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ManagementFees;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0402: contrato de honorários, chave das notas desligada por padrão, um pedido aberto por competência, cancelamento e escopo. */
final class ManagementFeesTest extends TestCase
{
    private int $condominium;
    private int $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $this->other = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testContractRequestAndCancel(): void
    {
        $service = new ManagementFees($this->pdo);
        $contract = $service->save($this->admin, $this->condominium, ['monthly_fee' => '2.000,00', 'start_date' => '2026-01-01', 'due_day' => 10]);
        self::assertSame([200000, 'active', ManagementFees::DEFAULT_SERVICE], [$contract['monthly_fee_cents'], $contract['status'], $contract['service_description']]);
        $service->save($this->admin, $this->condominium, ['monthly_fee' => '2.500,00', 'start_date' => '2026-01-01', 'due_day' => 10]);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_management_contracts')->fetchColumn(), 'Um contrato por condomínio.');

        $overview = $service->overview($this->admin, '2026-11');
        self::assertSame([false, 250000, 1, [$this->condominium]], [$overview['nfse_enabled'], $overview['monthly_total_cents'], $overview['active_contracts'],
            array_column($overview['rows'], 'condominium_id')], 'Só o condomínio do escopo; notas desligadas por padrão.');

        try {
            $service->request($this->admin, $this->condominium, '2026-11');
            self::fail('Pedido aceito com as notas desligadas.');
        } catch (DomainException) {
            self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_nfse_requests')->fetchColumn());
        }

        $this->pdo->exec("INSERT INTO erp_settings (condominium_id, setting_key, value_json) VALUES (NULL, 'nfse.enabled', 'true')");
        $request = $service->request($this->admin, $this->condominium, '2026-11');
        self::assertSame(['requested', 250000, '2026-11'], [$request['status'], $request['amount_cents'], $request['competence']]);
        try {
            $service->request($this->admin, $this->condominium, '2026-11');
            self::fail('Segundo pedido da mesma competência passou.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        try {
            $service->request($this->admin, $this->condominium, '2025-12');
            self::fail('Competência antes do contrato passou.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('competence', $exception->errors);
        }

        $cancelled = $service->cancel($this->admin, $request['id'], 'Valor errado');
        self::assertSame('cancelled', $cancelled['status']);
        $again = $service->request($this->admin, $this->condominium, '2026-11');
        self::assertSame('requested', $again['status'], 'O cancelamento libera a competência.');
        self::assertCount(2, $service->requests($this->admin, $this->condominium));

        $service->save($this->admin, $this->condominium, ['monthly_fee' => '2.500,00', 'start_date' => '2026-01-01', 'due_day' => 10, 'status' => 'suspended']);
        try {
            $service->request($this->admin, $this->condominium, '2026-12');
            self::fail('Contrato suspenso pediu nota.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('_contract', $exception->errors);
        }
        self::assertSame(0, $service->overview($this->admin, '2026-12')['monthly_total_cents'], 'Suspenso não entra no total.');
        $audits = (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity IN ('erp_management_contracts','erp_nfse_requests')")->fetchColumn();
        self::assertSame(6, $audits, 'Três gravações do contrato e três do pedido (dois criados e um cancelado).');
    }

    public function testOutOfScope(): void
    {
        $this->expectException(RegistryNotFound::class);
        (new ManagementFees($this->pdo))->save($this->admin, $this->other, ['monthly_fee' => '100,00', 'start_date' => '2026-01-01', 'due_day' => 1]);
    }
}
