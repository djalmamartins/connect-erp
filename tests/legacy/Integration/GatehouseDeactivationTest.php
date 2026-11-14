<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\GatehouseDeactivation;
use Source\Services\Erp\GatehouseOverview;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0295: portarias paradas inativadas em lote no MariaDB, com o dia do banco, as travas de linha e a auditoria só quando grava. */
final class GatehouseDeactivationTest extends TestCase
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

    public function testDeactivatesOnlyTheIdleOnes(): void
    {
        $idle = $this->gatehouse();
        $busy = $this->gatehouse();
        $waiting = $this->gatehouse();
        $this->access($idle, 200);
        $this->access($busy, 3);
        $this->delivery($waiting);
        $before = $this->audits();

        $preview = (new GatehouseDeactivation($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Guarita desativada', 'dry_run' => true]);
        self::assertSame([[$idle], 1, 0], [array_column($preview['gatehouses'], 'id'), $preview['skipped_pending'], $preview['changed']]);
        self::assertSame((string)$this->pdo->query('SELECT CURDATE()')->fetchColumn(), $preview['to'], 'O dia do banco.');
        self::assertSame([$before, 'active'], [$this->audits(), $this->gateStatus($idle)], 'A prévia não grava nem audita.');

        try {
            (new GatehouseDeactivation($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Guarita desativada',
                'gatehouse_ids' => [$idle, $busy]]);
            self::fail('A portaria com acesso recente passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$busy, $exception->errors['_gatehouse']);
        }
        self::assertSame('active', $this->gateStatus($idle), 'Tudo ou nada.');

        $done = (new GatehouseDeactivation($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Guarita desativada']);
        self::assertSame(1, $done['changed']);
        self::assertSame(['inactive', 'active', 'active'], [$this->gateStatus($idle), $this->gateStatus($busy), $this->gateStatus($waiting)]);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_gatehouses' AND action = 'update' AND entity_id = '{$idle}'")
            ->fetchColumn());
        $report = (new GatehouseOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(1, $report['totals']['inactive']);

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new GatehouseDeactivation($this->pdo))->deactivate($limited, ['condominium_id' => $this->condominium, 'reason' => 'Guarita desativada']);
            self::fail('Fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function audits(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
    }

    private function gateStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_gatehouses WHERE id = {$id}")->fetchColumn();
    }

    private function gatehouse(): int
    {
        $this->pdo->prepare("INSERT INTO erp_gatehouses (condominium_id, name, kind, status, created_by) VALUES (?, 'Guarita', ?, 'active', ?)")
            ->execute([$this->condominium, array_keys(GatehouseOverview::kinds())[0], $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }

    private function access(int $gatehouse, int $daysAgo): void
    {
        $this->pdo->prepare("INSERT INTO erp_access_logs (condominium_id, person_name, kind, entered_on, entered_time, gatehouse_id, status)
            VALUES (?, 'Visitante Fulano', 'visitante', DATE_SUB(CURDATE(), INTERVAL ? DAY), '10:00:00', ?, 'active')")->execute([$this->condominium, $daysAgo, $gatehouse]);
    }

    private function delivery(int $gatehouse): void
    {
        $this->pdo->prepare("INSERT INTO erp_deliveries (condominium_id, unit_id, recipient, carrier, received_on, gatehouse_id, status)
            VALUES (?, ?, 'Morador Fulano', 'correios', DATE_SUB(CURDATE(), INTERVAL 200 DAY), ?, 'active')")->execute([$this->condominium, $this->unit, $gatehouse]);
    }
}
