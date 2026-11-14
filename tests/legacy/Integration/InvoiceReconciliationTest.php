<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvoiceReconciliation;

/** ADR 0400: conferência entre faturas e contas a receber no MariaDB (faturas, itens e contas ligadas por invoice_item_id) e o mês do banco; somente leitura e sem unidade nem descrição. */
final class InvoiceReconciliationTest extends TestCase
{
    private int $condominium;
    private int $chart;
    private int $type;
    private array $units = [];
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        foreach (['101', '102', '103'] as $number) {
            $this->pdo->prepare('INSERT INTO erp_units (condominium_id, block, number) VALUES (?, ?, ?)')->execute([$this->condominium, 'A', $number]);
            $this->units[] = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.95', 'Receita', 'receita', 'analitica')")
            ->execute([$this->condominium]);
        $this->chart = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_invoice_types (condominium_id, name, chart_account_id, recurrence, status) VALUES (?, 'Taxa Reservada', ?, 'mensal', 'active')")
            ->execute([$this->condominium, $this->chart]);
        $this->type = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testReconciliationFromStoredInvoices(): void
    {
        $issued = $this->invoice(1, 'issued', 300, 3);
        $open = $this->item($issued, 0, 100);
        $changed = $this->item($issued, 1, 100);
        $this->item($issued, 2, 100);
        $this->entry($open, 100, 'active');
        $this->entry($changed, 120, 'active');
        $cancelled = $this->invoice(2, 'cancelled', 100, 1);
        $this->entry($this->item($cancelled, 0, 100), 100, 'active');
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new InvoiceReconciliation($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame((string)$this->pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn(), $report['to'], 'O mês do banco.');
        $rows = array_combine(array_column($report['rows'], 'status'), $report['rows']);
        self::assertSame([1, 3, 300, 1, 1, 1], [$rows['issued']['invoices'], $rows['issued']['items'], $rows['issued']['amount_cents'], $rows['issued']['open'],
            $rows['issued']['amount_mismatch'], $rows['issued']['missing']]);
        self::assertSame(1, $rows['cancelled']['still_active']);
        self::assertSame([$issued, $cancelled], $report['attention_ids']);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Reservad', 'A-101', 'Bloco'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new InvoiceReconciliation($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function invoice(int $monthsAgo, string $state, int $amount, int $units): int
    {
        $this->pdo->prepare("INSERT INTO erp_invoices (condominium_id, invoice_type_id, competence, due_on, status, units_count, amount_cents)
            VALUES (?, ?, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m'), CURDATE(), ?, ?, ?)")
            ->execute([$this->condominium, $this->type, $monthsAgo, $state, $units, $amount]);
        return (int)$this->pdo->lastInsertId();
    }

    private function item(int $invoice, int $unit, int $amount): int
    {
        $this->pdo->prepare("INSERT INTO erp_invoice_items (invoice_id, unit_id, unit_label, amount_cents) VALUES (?, ?, 'Bloco A-101', ?)")
            ->execute([$invoice, $this->units[$unit], $amount]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entry(int $item, int $amount, string $state): void
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, status, invoice_item_id)
            VALUES (?, 'receita', ?, 'Taxa Reservada', ?, DATE_FORMAT(CURDATE(), '%Y-%m'), CURDATE(), ?, ?)")->execute([$this->condominium, $this->chart, $amount, $state, $item]);
    }
}
