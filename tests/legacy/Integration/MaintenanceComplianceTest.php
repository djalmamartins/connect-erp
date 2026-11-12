<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MaintenanceCompliance;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0248: cumprimento do plano de manutenções com as manutenções do cadastro; somente leitura e sem dado da manutenção. */
final class MaintenanceComplianceTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testComplianceFromTheRegistry(): void
    {
        $onTime = $this->maintenance('Revisão do elevador', -60, 'mensal');
        $late = $this->maintenance('Bombas', -40, 'mensal');
        $this->maintenance('Extintores', -20, 'trimestral');
        $this->maintenance('Para-raios', 0, 'anual');
        $cancelled = $this->maintenance('Jardim', -100, 'anual');
        $this->maintenance('Antiga', -400, 'mensal');   // fora dos 12 meses
        $day = static fn (int $d): string => (new \DateTimeImmutable("{$d} days"))->format('Y-m-d');
        $this->pdo->prepare("UPDATE erp_maintenances SET status = 'done', done_on = ? WHERE id = ?")->execute([$day(-60), $onTime]);
        $this->pdo->prepare("UPDATE erp_maintenances SET status = 'done', done_on = ? WHERE id = ?")->execute([$day(-30), $late]);
        $this->pdo->prepare("UPDATE erp_maintenances SET status = 'inactive' WHERE id = ?")->execute([$cancelled]);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new MaintenanceCompliance($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([date('Y-m-d'), date('Y-m-d')], [$report['to'], $report['reference_on']]);
        self::assertSame([0, 2, 1, 0, 1], array_column($report['rows'], 'planned'));
        self::assertSame([1, 1, 10, 50, 2], [$report['rows'][1]['done_on_time'], $report['rows'][1]['done_late'], $report['rows'][1]['avg_delay_days'],
            $report['rows'][1]['on_time_rate'], $report['rows'][1]['done_without_document']]);
        self::assertSame(['planned' => 4, 'done_on_time' => 1, 'done_late' => 1, 'overdue' => 1, 'upcoming' => 1, 'cancelled' => 1, 'done_without_document' => 2,
            'avg_delay_days' => 10, 'on_time_rate' => 33], $report['totals']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['elevador', 'Bombas', 'Extintores'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new MaintenanceCompliance($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function maintenance(string $service, int $days, string $frequency): int
    {
        return (int)$this->registry->save($this->admin, 'maintenances', ['condominium_id' => $this->condominium, 'service' => $service,
            'planned_on' => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d'), 'frequency' => $frequency])['id'];
    }
}
