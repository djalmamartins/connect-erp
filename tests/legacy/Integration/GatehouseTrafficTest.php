<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\GatehouseTraffic;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0230: movimento da portaria com os acessos e as visitas previstas; somente leitura e sem dado pessoal. */
final class GatehouseTrafficTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->unit = (new RegistryService($this->pdo))->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testTrafficByKindWithoutPersonalData(): void
    {
        $forecast = $this->forecast('2026-10-05', 'done');
        $this->forecast('2026-10-20', 'active');
        $this->forecast('2026-10-21', 'inactive');
        $this->access('visitante', '2026-10-05', '08:30:00', '09:15:00', 'ABC1D23', $forecast, 'active');
        $this->access('visitante', '2026-10-06', '19:00:00', null, null, null, 'active');
        $this->access('prestador', '2026-10-07', '07:00:00', '11:30:00', null, null, 'active');
        $this->access('visitante', '2026-10-08', '10:00:00', null, null, null, 'inactive');   // cancelado
        $this->access('visitante', '2026-09-30', '10:00:00', null, null, null, 'active');     // fora do período
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new GatehouseTraffic($this->pdo))->build($this->admin, $this->condominium, '2026-10-01', '2026-10-31');
        self::assertSame([['visitante', 2, 1, 1, 1, 45], ['prestador', 1, 0, 0, 0, 270], ['entregador', 0, 0, 0, 0, null], ['morador', 0, 0, 0, 0, null]],
            array_map(static fn (array $r): array => [$r['kind'], $r['entries'], $r['with_vehicle'], $r['from_forecast'], $r['no_exit'], $r['avg_stay_minutes']],
                $report['rows']));
        self::assertSame(['madrugada' => 0, 'manha' => 2, 'tarde' => 0, 'noite' => 1], $report['totals']['by_band']);
        foreach (['entries', 'with_vehicle', 'with_unit', 'from_forecast', 'exited', 'no_exit', 'timed', 'stay_minutes_total'] as $field) {
            self::assertSame($report['totals'][$field], array_sum(array_column($report['rows'], $field)), 'Total = soma das linhas: ' . $field);
        }
        self::assertSame(['total' => 3, 'done' => 1, 'cancelled' => 1, 'open' => 1, 'overdue' => 1], $report['forecasts']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Pessoa de teste', 'Visita de teste', 'ABC1D23'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new GatehouseTraffic($this->pdo))->build($limited, $this->condominium, '2026-10-01', '2026-10-31');
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function forecast(string $on, string $status): int
    {
        $this->pdo->prepare("INSERT INTO erp_visit_forecasts (condominium_id, unit_id, visitor_name, expected_on, status) VALUES (?, ?, 'Visita de teste', ?, ?)")
            ->execute([$this->condominium, $this->unit, $on, $status]);
        return (int)$this->pdo->lastInsertId();
    }

    private function access(string $kind, string $on, string $in, ?string $out, ?string $plate, ?int $forecast, string $status): void
    {
        $this->pdo->prepare("INSERT INTO erp_access_logs (condominium_id, unit_id, person_name, kind, plate, entered_on, entered_time, left_time, visit_forecast_id, status)
            VALUES (?, ?, 'Pessoa de teste', ?, ?, ?, ?, ?, ?, ?)")->execute([$this->condominium, $this->unit, $kind, $plate, $on, $in, $out, $forecast, $status]);
    }
}
