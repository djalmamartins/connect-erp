<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BankAccountOverview;

/** ADR 0298: situação das contas bancárias com contas, lançamentos e transferências no MariaDB e o dia do banco; somente leitura e sem apelido, número nem valor. */
final class BankAccountOverviewTest extends TestCase
{
    private int $condominium;
    private int $chart;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '2.95', 'Despesa', 'despesa', 'analitica')")
            ->execute([$this->condominium]);
        $this->chart = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testOverviewFromStoredAccounts(): void
    {
        $main = $this->account('corrente', true, null);
        $idle = $this->account('poupanca', false, null);
        $orphan = $this->account('aplicacao', false, null);
        $linked = $this->account('aplicacao', false, $main);
        $this->entry($main, 5);
        $this->entry($main, 400);
        $this->entry(null, 2);
        $this->pdo->prepare("INSERT INTO erp_bank_transfers (condominium_id, from_bank_account_id, to_bank_account_id, amount_cents, transferred_on, description, status)
            VALUES (?, ?, ?, 4444, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Transferência Reservada', 'active')")->execute([$this->condominium, $main, $linked]);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new BankAccountOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame((string)$this->pdo->query('SELECT CURDATE()')->fetchColumn(), $report['to'], 'O dia do banco.');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        self::assertSame([1, 1, 1, 1], [$rows['corrente']['accounts'], $rows['corrente']['main'], $rows['corrente']['paid_entries'], $rows['corrente']['transfer_legs']],
            'O lançamento de 400 dias atrás fica de fora.');
        self::assertSame([2, 1, 1], [$rows['aplicacao']['accounts'], $rows['aplicacao']['orphan'], $rows['aplicacao']['transfer_legs']]);
        self::assertNull($report['main_issue']);
        self::assertSame([1, 2], [$report['elsewhere']['paid_entries'], $report['all_paid_entries']]);
        self::assertSame([[$idle, $orphan], [$orphan]], [$report['idle_ids'], $report['orphan_ids']]);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Reservad', '1234567', '4444'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new BankAccountOverview($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function account(string $kind, bool $main, ?int $parent): int
    {
        $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number, kind, parent_bank_account_id, is_main)
            VALUES (?, 'Conta Reservada', '341', '0001', '1234567-8', ?, ?, ?)")->execute([$this->condominium, $kind, $parent, $main ? 1 : 0]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entry(?int $account, int $daysAgo): void
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on, bank_account_id)
            VALUES (?, 'despesa', ?, 'Pagamento Reservado', 1000, DATE_FORMAT(CURDATE(), '%Y-%m'), CURDATE(), DATE_SUB(CURDATE(), INTERVAL ? DAY), ?)")
            ->execute([$this->condominium, $this->chart, $daysAgo, $account]);
    }
}
