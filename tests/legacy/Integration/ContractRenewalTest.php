<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContractRenewal;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0217: renovação de contratos em lote pelo formulário, com reajuste opcional, tudo ou nada, prévia e auditoria. */
final class ContractRenewalTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->supplier = $this->registry->save($this->admin, 'suppliers', ['legal_name' => 'Portaria Teste Ltda', 'document' => '11222333000181',
            'category' => 'portaria'])['id'];
    }

    public function testRenewsAndReadjustsThroughTheForm(): void
    {
        $portaria = $this->contract('Portaria 24h', '2026-11-30', '10000,00', 'ipca');
        $limpeza = $this->contract('Limpeza', '2027-01-31', '4999,99', 'igpm');
        $input = ['condominium_id' => $this->condominium, 'contract_ids' => [$portaria, $limpeza], 'months' => 12, 'percent' => '4,62', 'reason' => 'Renovação anual 2027'];

        $preview = (new ContractRenewal($this->pdo))->renew($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, 1499999, 1569299], [$preview['dry_run'], $preview['changed'], $preview['before_cents'], $preview['after_cents']]);
        self::assertSame(['2026-11-30', 1000000], $this->state($portaria), 'dry_run não grava.');

        $done = (new ContractRenewal($this->pdo))->renew($this->admin, $input);
        self::assertSame([['2027-11-30', 1046200], ['2028-01-31', 523099]], array_map(static fn (array $c): array => [$c['ends_on_after'], $c['monthly_after_cents']],
            $done['contracts']));
        self::assertSame([['2027-11-30', 1046200], ['2028-01-31', 523099]], [$this->state($portaria), $this->state($limpeza)]);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_contracts' AND action = 'renew'")->fetchColumn());

        $plain = (new ContractRenewal($this->pdo))->renew($this->admin, ['condominium_id' => $this->condominium, 'contract_ids' => [$portaria], 'months' => 6,
            'reason' => 'Prorrogação sem reajuste']);
        self::assertSame(['2028-05-30', 1046200], $this->state($portaria));
        self::assertNull($plain['percent']);
    }

    public function testAllOrNothingAndScope(): void
    {
        $ok = $this->contract('Elevador', '2026-12-31', '1000,00', 'ipca');
        $none = $this->contract('Jardim', '2026-12-31', '500,00', 'nenhum');
        try {
            (new ContractRenewal($this->pdo))->renew($this->admin, ['condominium_id' => $this->condominium, 'contract_ids' => [$ok, $none], 'months' => 12,
                'percent' => '5', 'reason' => 'Renovação anual']);
            self::fail('Contrato sem reajuste aceitou percentual.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$none, $exception->errors['_contract']);
        }
        self::assertSame(['2026-12-31', 100000], $this->state($ok), 'Nada gravado.');
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE action = 'renew'")->fetchColumn());

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ContractRenewal($this->pdo))->renew($limited, ['condominium_id' => $this->condominium, 'contract_ids' => [$ok], 'months' => 12, 'reason' => 'Renovação anual']);
            self::fail('Renovou fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function contract(string $object, string $endsOn, string $monthly, string $readjustment): int
    {
        return $this->registry->save($this->admin, 'contracts', ['condominium_id' => $this->condominium, 'supplier_id' => $this->supplier, 'object' => $object,
            'starts_on' => '2025-12-01', 'ends_on' => $endsOn, 'monthly_cents' => $monthly, 'readjustment' => $readjustment])['id'];
    }

    /** @return array{0:string, 1:int} */
    private function state(int $contract): array
    {
        $row = $this->pdo->query('SELECT ends_on, monthly_cents FROM erp_contracts WHERE id = ' . $contract)->fetch(\PDO::FETCH_ASSOC);
        return [substr((string)$row['ends_on'], 0, 10), (int)$row['monthly_cents']];
    }
}
