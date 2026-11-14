<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\ExtraChargeOverview;

/** ADR 0288: situação das cobranças extras e multas com linhas de erp_extra_charges e erp_entries no MariaDB e o dia do banco; somente leitura e sem descrição nem motivo. */
final class ExtraChargeOverviewTest extends TestCase
{
    private int $condominium;
    private int $account;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.91', 'Cobrança extra', 'receita', 'analitica')")
            ->execute([$this->condominium]);
        $this->account = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testOverviewFromStoredCharges(): void
    {
        $kinds = array_keys(EntryShortcuts::CHARGE_KINDS);
        $extra = $this->charge($kinds[0], 'active');
        $fine = $this->charge($kinds[count($kinds) - 1], 'active');
        $cancelled = $this->charge($kinds[0], 'cancelled');
        $this->entry($extra, 1000, 'active', true, -5);
        $this->entry($extra, 2000, 'active', false, -3);
        $this->entry($extra, 3000, 'active', false, 0);
        $this->entry($fine, 500, 'active', false, -10);
        $this->entry($cancelled, 9000, 'inactive', false, -10);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new ExtraChargeOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame((string)$this->pdo->query('SELECT CURDATE()')->fetchColumn(), $report['on'], 'O dia do banco.');
        self::assertSame($kinds, array_column($report['rows'], 'kind'));
        self::assertSame([2, 1, 4, 6500, 1000, 3000, 2500], [$report['totals']['charges'], $report['totals']['cancelled'], $report['totals']['entries'],
            $report['totals']['amount_cents'], $report['totals']['paid_cents'], $report['totals']['open_cents'], $report['totals']['overdue_cents']],
            'Vencer hoje ainda é estar em aberto; a cobrança cancelada não soma valores.');
        self::assertSame([$fine, $extra], $report['overdue_ids'], 'Da cobrança que venceu antes.');
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Pintura'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ExtraChargeOverview($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function charge(string $kind, string $state): int
    {
        $this->pdo->prepare("INSERT INTO erp_extra_charges (condominium_id, kind, description, chart_account_id, method, total_cents, units_count, competence, due_on,
                reason, status, created_by) VALUES (?, ?, 'Pintura do Fulano', ?, 'fixed', 1000, 1, DATE_FORMAT(CURDATE(), '%Y-%m'), CURDATE(), 'Pedido do Fulano', ?, ?)")
            ->execute([$this->condominium, $kind, $this->account, $state, $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entry(int $charge, int $cents, string $state, bool $paid, int $dueDays): void
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on, status,
                extra_charge_id) VALUES (?, 'receita', ?, 'Cobrança do Fulano', ?, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, ?, ?)")
            ->execute([$this->condominium, $this->account, $cents, $dueDays, $paid ? date('Y-m-d') : null, $state, $charge]);
    }
}
