<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvoiceEntryCleanup;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0401: contas ativas de faturas canceladas inativadas em lote no MariaDB, com a trava das contas, o fechamento mensal e a auditoria só quando grava; faturas intactas. */
final class InvoiceEntryCleanupTest extends TestCase
{
    private int $condominium;
    private int $chart;
    private int $type;
    private int $unit;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO erp_units (condominium_id, block, number) VALUES (?, ?, ?)')->execute([$this->condominium, 'A', '101']);
        $this->unit = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.96', 'Receita', 'receita', 'analitica')")
            ->execute([$this->condominium]);
        $this->chart = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_invoice_types (condominium_id, name, chart_account_id, recurrence, status) VALUES (?, 'Taxa Reservada', ?, 'mensal', 'active')")
            ->execute([$this->condominium, $this->chart]);
        $this->type = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testDeactivatesOnlyTheSafeOnes(): void
    {
        $cancelled = $this->invoice('2026-01', 'cancelled');
        $stale = $this->entry($this->item($cancelled), '2026-01', null);
        $paid = $this->entry($this->item($cancelled), '2026-01', '2026-01-20');
        $closedInvoice = $this->invoice('2025-12', 'cancelled');
        $closed = $this->entry($this->item($closedInvoice), '2025-12', null);
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2025-12', 'closed')")->execute([$this->condominium]);
        $issued = $this->invoice('2026-02', 'issued');
        $live = $this->entry($this->item($issued), '2026-02', null);
        $before = $this->audits();

        $input = ['condominium_id' => $this->condominium, 'reason' => 'Fatura cancelada'];
        $preview = (new InvoiceEntryCleanup($this->pdo))->deactivate($this->admin, ['dry_run' => true] + $input);
        self::assertSame([[$stale], 1, 0, 1, 0], [array_column($preview['entries'], 'id'), $preview['skipped_paid'], $preview['skipped_linked'],
            $preview['skipped_closed'], $preview['changed']]);
        self::assertSame([$before, 'active'], [$this->audits(), $this->entryStatus($stale)], 'A prévia não grava nem audita.');

        try {
            (new InvoiceEntryCleanup($this->pdo))->deactivate($this->admin, ['entry_ids' => [$stale, $live]] + $input);
            self::fail('A conta de fatura emitida passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$live, $exception->errors['_entry']);
        }
        self::assertSame('active', $this->entryStatus($stale), 'Tudo ou nada.');

        $done = (new InvoiceEntryCleanup($this->pdo))->deactivate($this->admin, $input);
        self::assertSame(1, $done['changed']);
        self::assertSame(['inactive', 'active', 'active', 'active'], [$this->entryStatus($stale), $this->entryStatus($paid), $this->entryStatus($closed),
            $this->entryStatus($live)]);
        self::assertSame([['cancelled'], ['issued']], [$this->pdo->query("SELECT DISTINCT status FROM erp_invoices WHERE id IN ({$cancelled}, {$closedInvoice})")->fetchAll(\PDO::FETCH_COLUMN),
            $this->pdo->query("SELECT status FROM erp_invoices WHERE id = {$issued}")->fetchAll(\PDO::FETCH_COLUMN)], 'Nenhuma fatura muda.');
        self::assertSame($before + 1, $this->audits(), 'Um registro por conta inativada.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Reservad', 'Bloco', 'cancelada'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    private function invoice(string $competence, string $state): int
    {
        $this->pdo->prepare("INSERT INTO erp_invoices (condominium_id, invoice_type_id, competence, due_on, status, units_count, amount_cents) VALUES (?, ?, ?, CURDATE(), ?, 1, 100)")
            ->execute([$this->condominium, $this->type, $competence, $state]);
        return (int)$this->pdo->lastInsertId();
    }

    private function item(int $invoice): int
    {
        // Cada item numa unidade própria: a chave uq_erp_invoice_items_unit aceita uma unidade por fatura.
        static $number = 100;
        $number++;
        $this->pdo->prepare('INSERT INTO erp_units (condominium_id, block, number) VALUES (?, ?, ?)')->execute([$this->condominium, 'A', (string)$number]);
        $unit = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_invoice_items (invoice_id, unit_id, unit_label, amount_cents) VALUES (?, ?, 'Bloco A-101', 100)")->execute([$invoice, $unit]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entry(int $item, string $competence, ?string $paidOn): int
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on, status, invoice_item_id)
            VALUES (?, 'receita', ?, 'Taxa Reservada', 100, ?, CURDATE(), ?, 'active', ?)")->execute([$this->condominium, $this->chart, $competence, $paidOn, $item]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entryStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_entries WHERE id = {$id}")->fetchColumn();
    }

    private function audits(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_entries'")->fetchColumn();
    }
}
