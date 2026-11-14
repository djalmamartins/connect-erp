<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MeterDeactivation;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0299: relógios parados inativados em lote no MariaDB, com o mês do banco, as travas de linha e a auditoria só quando grava; leituras intactas. */
final class MeterDeactivationTest extends TestCase
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
        $kind = array_keys(MeterDeactivation::kinds())[0];
        $idle = $this->meter($kind, 'HID-0001');
        $busy = $this->meter($kind, 'HID-0002');
        $unbilled = $this->meter($kind, 'HID-0003');
        $this->reading($idle, 20, 0);
        $this->reading($busy, 1, 0);
        $this->reading($unbilled, 30, 500);
        $readings = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_meter_readings')->fetchColumn();
        $before = $this->audits();

        $input = ['condominium_id' => $this->condominium, 'reason' => 'Relógio retirado'];
        $preview = (new MeterDeactivation($this->pdo))->deactivate($this->admin, ['dry_run' => true] + $input);
        self::assertSame([[$idle], 1, 0], [array_column($preview['meters'], 'id'), $preview['skipped_unbilled'], $preview['changed']]);
        self::assertSame((string)$this->pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn(), $preview['to'], 'O mês do banco.');
        self::assertSame([$before, 'active'], [$this->audits(), $this->meterStatus($idle)], 'A prévia não grava nem audita.');

        try {
            (new MeterDeactivation($this->pdo))->deactivate($this->admin, ['meter_ids' => [$idle, $busy]] + $input);
            self::fail('O relógio com leitura recente passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$busy, $exception->errors['_meter']);
        }
        self::assertSame('active', $this->meterStatus($idle), 'Tudo ou nada.');

        $done = (new MeterDeactivation($this->pdo))->deactivate($this->admin, $input);
        self::assertSame(1, $done['changed']);
        self::assertSame(['inactive', 'active', 'active'], [$this->meterStatus($idle), $this->meterStatus($busy), $this->meterStatus($unbilled)]);
        self::assertSame($readings, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_meter_readings')->fetchColumn(), 'Nenhuma leitura muda.');
        self::assertSame($before + 1, $this->audits(), 'Um registro por relógio inativado.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['HID-', 'retirado'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    private function meter(string $kind, string $number): int
    {
        $this->pdo->prepare("INSERT INTO erp_meters (condominium_id, unit_id, kind, number, status, created_at) VALUES (?, ?, ?, ?, 'active', DATE_SUB(NOW(), INTERVAL 5 YEAR))")
            ->execute([$this->condominium, $this->unit, $kind, $number]);
        return (int)$this->pdo->lastInsertId();
    }

    private function reading(int $meter, int $monthsAgo, int $amount): void
    {
        $this->pdo->prepare("INSERT INTO erp_meter_readings (condominium_id, meter_id, competence, read_on, previous_reading, reading, consumption, amount_cents)
            VALUES (?, ?, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m'), CURDATE(), 0, 10, 10, ?)")->execute([$this->condominium, $meter, $monthsAgo, $amount]);
    }

    private function meterStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_meters WHERE id = {$id}")->fetchColumn();
    }

    private function audits(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_meters'")->fetchColumn();
    }
}
