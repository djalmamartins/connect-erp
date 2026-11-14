<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvoiceTypeOverview;

/** ADR 0296: situação dos tipos de faturas com tipos, contas e faturas no MariaDB e o mês do banco; somente leitura e sem nome de tipo, conta nem observação. */
final class InvoiceTypeOverviewTest extends TestCase
{
    private int $condominium;
    private int $account;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (NULL, '1.93', 'Conta Reservada', 'receita', 'analitica')");
        $this->account = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testOverviewFromStoredTypes(): void
    {
        $recurrence = array_keys(InvoiceTypeOverview::recurrences())[0];
        $hidden = $this->type(null, 'Taxa Reservada', $recurrence, 1000);
        $own = $this->type($this->condominium, 'Taxa Reservada', $recurrence, 1500);
        $idle = $this->type(null, 'Fundo Reservado', $recurrence, null);
        $this->invoice($own, 0, 'issued', 5000);
        $this->invoice($own, 1, 'cancelled', 700);
        $this->invoice($own, 13, 'issued', 900);
        $this->invoice($hidden, 2, 'issued', 300);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new InvoiceTypeOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame((string)$this->pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn(), $report['to'], 'O mês do banco.');
        $rows = array_combine(array_column($report['rows'], 'recurrence'), $report['rows']);
        self::assertSame([2, 1, 1, 1, 1, 1, 1, 5000], [$rows[$recurrence]['available'], $rows[$recurrence]['own'], $rows[$recurrence]['standard'],
            $rows[$recurrence]['overridden_standard'], $rows[$recurrence]['unused'], $rows[$recurrence]['without_amount'], $rows[$recurrence]['invoices'],
            $rows[$recurrence]['amount_cents']], 'O padrão de mesmo nome fica encoberto; a cancelada e a de 13 meses atrás ficam de fora.');
        self::assertSame(['invoices' => 1, 'amount_cents' => 300], $report['elsewhere']);
        self::assertSame([[$idle], []], [$report['unused_ids'], $report['account_issue_ids']]);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Reservad', 'Observação'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new InvoiceTypeOverview($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function type(?int $condominium, string $name, string $recurrence, ?int $amount): int
    {
        $this->pdo->prepare("INSERT INTO erp_invoice_types (condominium_id, name, chart_account_id, recurrence, default_amount_cents, notes, status)
            VALUES (?, ?, ?, ?, ?, 'Observação', 'active')")->execute([$condominium, $name, $this->account, $recurrence, $amount]);
        return (int)$this->pdo->lastInsertId();
    }

    private function invoice(int $type, int $monthsAgo, string $state, int $amount): void
    {
        $this->pdo->prepare("INSERT INTO erp_invoices (condominium_id, invoice_type_id, competence, due_on, status, amount_cents)
            VALUES (?, ?, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m'), CURDATE(), ?, ?)")->execute([$this->condominium, $type, $monthsAgo, $state, $amount]);
    }
}
