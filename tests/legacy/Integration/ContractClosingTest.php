<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContractClosing;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0245: contratos terminados encerrados pelo cadastro, com o fim e o valor mantidos, os fixos apontados e nenhum aviso. */
final class ContractClosingTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $supplier;
    private int $oldSupplier;

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
        $this->oldSupplier = $this->registry->save($this->admin, 'suppliers', ['legal_name' => 'Antiga Teste Ltda', 'document' => '11444777000161',
            'category' => 'portaria'])['id'];
    }

    public function testClosesEndedContractsKeepingEndAndValue(): void
    {
        $portaria = $this->contract('Portaria 24h', -60, '1.000,00');
        $limpeza = $this->contract('Limpeza', -5, '500,00');
        $elevador = $this->contract('Elevador', 30, '200,00');
        $jardim = $this->contract('Jardim', null, '100,00');
        $piscina = $this->contract('Piscina', -10, '300,00', $this->oldSupplier);
        $this->pdo->prepare("UPDATE erp_suppliers SET status = 'inactive' WHERE id = ?")->execute([$this->oldSupplier]);
        $account = $this->chartAccount();
        $recurring = $this->pdo->prepare("INSERT INTO erp_recurring_entries (condominium_id, nature, chart_account_id, supplier_id, description, amount_cents, day_of_month,
            starts_competence, ends_competence, status) VALUES (?, 'despesa', ?, ?, 'Mensalidade', 100000, 10, '2025-01', ?, 'active')");
        $recurring->execute([$this->condominium, $account, $this->supplier, null]);        // segue gerando: revisar
        $recurring->execute([$this->condominium, $account, $this->supplier, '2020-01']);   // já terminou
        $recurring->execute([$this->condominium, $account, $this->oldSupplier, null]);     // contrato de fora
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Troca de empresa'];

        $preview = (new ContractClosing($this->pdo))->close($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$portaria, $limpeza], [60, 5], 150000, 1, 1], [$preview['dry_run'], $preview['changed'], array_column($preview['contracts'], 'id'),
            array_column($preview['contracts'], 'days_since_end'), $preview['monthly_cents'], $preview['skipped_inactive_link'], $preview['recurring_to_review']]);
        self::assertSame('active', $this->state($portaria)[0], 'dry_run não grava.');

        $done = (new ContractClosing($this->pdo))->close($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['ended', 'ended', 'active', 'active', 'active'], [$this->state($portaria)[0], $this->state($limpeza)[0], $this->state($elevador)[0],
            $this->state($jardim)[0], $this->state($piscina)[0]]);
        self::assertSame(['ended', (new \DateTimeImmutable('-60 days'))->format('Y-m-d'), 100000], $this->state($portaria), 'Fim e valor mantidos.');
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_recurring_entries WHERE status = 'active'")->fetchColumn(), 'Os fixos não são tocados.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_contracts' AND action = 'close'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_contracts' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Portaria', 'Limpeza', 'Teste Ltda'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $ended = $this->contract('Portaria 24h', -10, '1.000,00');
        $current = $this->contract('Elevador', 10, '200,00');
        $open = $this->contract('Jardim', null, '100,00');
        foreach ([[$current, 'ainda não terminou'], [$open, 'indeterminado']] as [$refused, $message]) {
            try {
                (new ContractClosing($this->pdo))->close($this->admin, ['condominium_id' => $this->condominium, 'contract_ids' => [$ended, $refused],
                    'reason' => 'Troca de empresa']);
                self::fail('Contrato recusado entrou.');
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$refused, $exception->errors['_contract']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
        }
        self::assertSame('active', $this->state($ended)[0], 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ContractClosing($this->pdo))->close($limited, ['condominium_id' => $this->condominium, 'reason' => 'Troca de empresa']);
            self::fail('Encerramento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function contract(string $object, ?int $endDays, string $monthly, ?int $supplier = null): int
    {
        return (int)$this->registry->save($this->admin, 'contracts', ['condominium_id' => $this->condominium, 'supplier_id' => $supplier ?? $this->supplier,
            'object' => $object, 'starts_on' => (new \DateTimeImmutable('-400 days'))->format('Y-m-d'),
            'ends_on' => $endDays !== null ? (new \DateTimeImmutable("{$endDays} days"))->format('Y-m-d') : null, 'monthly_cents' => $monthly,
            'readjustment' => 'ipca'])['id'];
    }

    private function chartAccount(): int
    {
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '2.01', 'Portaria', 'despesa', 'analitica')")
            ->execute([$this->condominium]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{0:string, 1:string, 2:int} */
    private function state(int $id): array
    {
        $row = $this->pdo->query("SELECT status, ends_on, monthly_cents FROM erp_contracts WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        return [(string)$row['status'], substr((string)$row['ends_on'], 0, 10), (int)$row['monthly_cents']];
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
