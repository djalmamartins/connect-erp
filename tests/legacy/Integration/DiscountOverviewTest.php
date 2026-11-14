<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DiscountOverview;

/** ADR 0290: descontos com linhas de erp_entry_adjustments no MariaDB e os meses do relógio do banco; somente leitura e sem motivo nem quem deu. */
final class DiscountOverviewTest extends TestCase
{
    private int $condominium;
    private int $account;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.92', 'Taxa', 'receita', 'analitica')")
            ->execute([$this->condominium]);
        $this->account = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testOverviewFromStoredDiscounts(): void
    {
        $small = $this->entry();
        $zeroed = $this->entry();
        $old = $this->entry();
        $this->discount($small, 50, 1000, 0);
        $this->discount($zeroed, 400, 400, 0);
        $this->discount($old, 100, 1000, 70);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $current = (new DiscountOverview($this->pdo))->build($this->admin, $this->condominium, '1');
        self::assertSame(substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 7), $current['to'], 'O mês do banco.');
        self::assertSame([2, 450, 1400], [$current['totals']['discounts'], $current['totals']['discount_cents'], $current['totals']['before_cents']],
            'Um mês: o de 70 dias atrás fica de fora.');
        self::assertSame([[$zeroed], [$zeroed, $small]], [$current['zeroed_entry_ids'], $current['largest_entry_ids']]);
        $year = (new DiscountOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(3, $year['totals']['discounts']);
        self::assertSame($year['totals']['discounts'], array_sum($year['by_band']));
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($year, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Pedido'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new DiscountOverview($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function entry(): int
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, status)
            VALUES (?, 'receita', ?, 'Taxa do Fulano', 1000, DATE_FORMAT(CURDATE(), '%Y-%m'), CURDATE(), 'active')")->execute([$this->condominium, $this->account]);
        return (int)$this->pdo->lastInsertId();
    }

    private function discount(int $entry, int $cents, int $before, int $daysAgo): void
    {
        $this->pdo->prepare("INSERT INTO erp_entry_adjustments (condominium_id, entry_id, kind, amount_cents, before_cents, after_cents, reason, created_by, created_at)
            VALUES (?, ?, 'discount', ?, ?, ?, 'Pedido do Fulano', ?, DATE_SUB(NOW(), INTERVAL ? DAY))")
            ->execute([$this->condominium, $entry, $cents, $before, $before - $cents, $this->admin->id, $daysAgo]);
    }
}
