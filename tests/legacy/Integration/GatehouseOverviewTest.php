<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\GatehouseOverview;

/** ADR 0294: situação das portarias com portarias, acessos e encomendas no MariaDB e o dia do banco; somente leitura e sem nome, responsável nem pessoa. */
final class GatehouseOverviewTest extends TestCase
{
    private int $condominium;
    private int $unit;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO erp_units (condominium_id, block, number) VALUES (?, ?, ?)')->execute([$this->condominium, 'A', '101']);
        $this->unit = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testOverviewFromStoredGatehouses(): void
    {
        $kind = array_keys(GatehouseOverview::kinds())[0];
        $busy = $this->gatehouse($kind, 'active');
        $idle = $this->gatehouse($kind, 'active');
        $this->access($busy, 0);
        $this->access($busy, 5);
        $this->access($busy, 40);
        $this->access(null, 1);
        $this->delivery($busy, 3);
        $this->delivery(null, 10);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new GatehouseOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame((string)$this->pdo->query('SELECT CURDATE()')->fetchColumn(), $report['to'], 'O dia do banco.');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        self::assertSame([2, 2, 1], [$rows[$kind]['gatehouses'], $rows[$kind]['entries'], $rows[$kind]['pending_deliveries']], 'O acesso de 40 dias atrás fica de fora.');
        self::assertSame([1, 1, 3, 2], [$report['elsewhere']['entries'], $report['elsewhere']['pending_deliveries'], $report['all_entries'], $report['all_pending']]);
        self::assertSame([$idle], $report['idle_ids']);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Portaria Central'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new GatehouseOverview($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function gatehouse(string $kind, string $state): int
    {
        $this->pdo->prepare("INSERT INTO erp_gatehouses (condominium_id, name, kind, manager_name, status, created_by) VALUES (?, 'Portaria Central', ?, 'Fulano', ?, ?)")
            ->execute([$this->condominium, $kind, $state, $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }

    private function access(?int $gatehouse, int $daysAgo): void
    {
        $this->pdo->prepare("INSERT INTO erp_access_logs (condominium_id, person_name, kind, entered_on, entered_time, gatehouse_id, status)
            VALUES (?, 'Visitante Fulano', 'visitante', DATE_SUB(CURDATE(), INTERVAL ? DAY), '10:00:00', ?, 'active')")->execute([$this->condominium, $daysAgo, $gatehouse]);
    }

    private function delivery(?int $gatehouse, int $daysAgo): void
    {
        $this->pdo->prepare("INSERT INTO erp_deliveries (condominium_id, unit_id, recipient, carrier, received_on, gatehouse_id, status)
            VALUES (?, ?, 'Morador Fulano', 'correios', DATE_SUB(CURDATE(), INTERVAL ? DAY), ?, 'active')")->execute([$this->condominium, $this->unit, $daysAgo, $gatehouse]);
    }
}
