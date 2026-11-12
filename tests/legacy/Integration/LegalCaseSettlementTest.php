<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LegalCasePortfolio;
use Source\Services\Erp\LegalCaseSettlement;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0251: processos com as contas quitadas encerrados como no andamento, com auditoria e nenhum aviso. */
final class LegalCaseSettlementTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $bank;
    private int $chartAccount;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->bank = $this->registry->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->chartAccount = $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica',
            'name' => 'Cotas'])['id'];
        foreach (['101', '102', '103', '104'] as $number) {
            $this->units[$number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
    }

    public function testClosesSettledCasesLikeTheEventForm(): void
    {
        $settled = $this->legalCase('101', 'filed', -100);
        $this->link($settled, $this->income('1.000,00', -20), 100000);
        $this->link($settled, $this->income('500,00', -8), 50000);
        $open = $this->legalCase('102', 'suspended', -100);
        $this->link($open, $this->income('300,00', -8), 30000);
        $this->link($open, $this->income('200,00', null), 20000);
        $cancelled = $this->legalCase('103', 'agreement', -100);
        $gone = $this->income('400,00', null);
        $this->link($cancelled, $gone, 40000);
        $this->pdo->exec("UPDATE erp_entries SET status = 'inactive' WHERE id = {$gone}");   // trocada por acordo
        $this->legalCase('104', 'preparing', -10);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Conferência da carteira'];

        $preview = (new LegalCaseSettlement($this->pdo))->close($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, [$settled], 150000, 1, 1, 1], [$preview['dry_run'], array_column($preview['cases'], 'id'), $preview['recovered_cents'],
            $preview['skipped_open'], $preview['skipped_cancelled'], $preview['skipped_no_entries']]);
        self::assertSame('filed', $this->state($settled)['status'], 'dry_run não grava.');

        $done = (new LegalCaseSettlement($this->pdo))->close($this->admin, $input);
        self::assertSame(1, $done['changed']);
        $row = $this->state($settled);
        self::assertSame(['closed', (new \DateTimeImmutable('-8 days'))->format('Y-m-d')], [$row['status'], substr((string)$row['closed_on'], 0, 10)],
            'Encerrado na data do último pagamento.');
        self::assertStringStartsWith(LegalCaseSettlement::NOTE_PREFIX, (string)$row['close_reason']);
        self::assertSame(['closed', 'filed', 'closed'], array_values($this->pdo->query("SELECT kind, from_status, to_status FROM erp_legal_case_events
            WHERE case_id = {$settled} AND kind = 'closed'")->fetch(\PDO::FETCH_ASSOC)), 'O andamento de encerramento fica no processo.');
        self::assertSame(['suspended', 'agreement'], [$this->state($open)['status'], $this->state($cancelled)['status']]);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_legal_cases' AND action = 'close_settled'")->fetchColumn());
        self::assertSame([$outbox, $mails], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn()], 'Nenhum aviso.');
        self::assertSame(1, (new LegalCasePortfolio($this->pdo))->build($this->admin, $this->condominium)['rows'][4]['cases'], 'A carteira (0250) mostra o encerrado.');

        try {
            (new LegalCaseSettlement($this->pdo))->close($this->admin, $input + ['case_ids' => [$open]]);
            self::fail('Processo com conta em aberto entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$open, $exception->errors['_case']);
        }
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new LegalCaseSettlement($this->pdo))->close($limited, $input);
            self::fail('Encerramento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function legalCase(string $unit, string $status, int $openedDays): int
    {
        $this->pdo->prepare('INSERT INTO erp_legal_cases (condominium_id, unit_id, status, opened_on, claimed_cents) VALUES (?, ?, ?, ?, 0)')
            ->execute([$this->condominium, $this->units[$unit], $status, (new \DateTimeImmutable("{$openedDays} days"))->format('Y-m-d')]);
        return (int)$this->pdo->lastInsertId();
    }

    private function income(string $amount, ?int $paidDays): int
    {
        return (int)$this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'receita', 'chart_account_id' => $this->chartAccount,
            'amount_cents' => $amount, 'competence' => '2026-06', 'description' => 'Cota condominial', 'due_on' => '2026-06-10']
            + ($paidDays !== null ? ['paid_on' => (new \DateTimeImmutable("{$paidDays} days"))->format('Y-m-d'), 'bank_account_id' => $this->bank] : []))['id'];
    }

    private function link(int $case, int $entry, int $amount): void
    {
        $this->pdo->prepare('INSERT INTO erp_legal_case_entries (case_id, entry_id, amount_cents) VALUES (?, ?, ?)')->execute([$case, $entry, $amount]);
    }

    /** @return array<string, mixed> */
    private function state(int $id): array
    {
        return $this->pdo->query("SELECT status, closed_on, close_reason FROM erp_legal_cases WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
    }
}
