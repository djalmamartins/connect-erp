<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommonAreaUsage;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0228: uso dos espaços comuns com as reservas do cadastro; somente leitura e sem dado do morador. */
final class CommonAreaUsageTest extends TestCase
{
    private int $condominium;
    private User $admin;
    /** @var array<string, int> */
    private array $units = [];
    /** @var array<string, int> */
    private array $areas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['101', '102'] as $number) {
            $this->units[$number] = $registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        foreach ([['Salão de festas', 'approval', 'active'], ['Academia', 'free', 'active'], ['Sauna', 'blocked', 'inactive']] as [$name, $mode, $status]) {
            $this->pdo->prepare('INSERT INTO erp_common_areas (condominium_id, name, booking_mode, status) VALUES (?, ?, ?, ?)')
                ->execute([$this->condominium, $name, $mode, $status]);
            $this->areas[$name] = (int)$this->pdo->lastInsertId();
        }
    }

    public function testUsageByAreaWithoutResidentData(): void
    {
        $this->reservation('Salão de festas', '101', '2026-10-03', 'confirmed', 15000);
        $this->reservation('Salão de festas', '102', '2026-10-10', 'confirmed', 15000);
        $this->reservation('Salão de festas', '101', '2026-10-17', 'inactive', 15000);
        $this->reservation('Salão de festas', '102', '2026-10-24', 'active', 15000);
        $this->reservation('Salão de festas', '101', '2026-09-26', 'confirmed', 15000);   // fora do período
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new CommonAreaUsage($this->pdo))->build($this->admin, $this->condominium, '2026-10-01', '2026-10-31');
        self::assertSame([['Salão de festas', 4, 2, 1, 1, 30000, 2, 2, 25.0], ['Academia', 0, 0, 0, 0, 0, 0, 0, null]],
            array_map(static fn (array $r): array => [$r['name'], $r['reservations'], $r['confirmed'], $r['pending'], $r['cancelled'], $r['fee_cents'], $r['days_used'],
                $r['units'], $r['cancel_pct']], $report['rows']), 'A Sauna inativa sem reserva fica de fora.');
        self::assertSame(['Exige aprovação', 2], [$report['rows'][0]['booking_mode_label'], $report['rows'][0]['by_weekday']['sat']]);
        foreach (['reservations', 'confirmed', 'pending', 'cancelled', 'fee_cents', 'days_used'] as $field) {
            self::assertSame($report['totals'][$field], array_sum(array_column($report['rows'], $field)), 'Total = soma das linhas: ' . $field);
        }
        self::assertStringNotContainsString('Morador', (string)json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new CommonAreaUsage($this->pdo))->build($limited, $this->condominium, '2026-10-01', '2026-10-31');
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function reservation(string $area, string $unit, string $on, string $status, ?int $fee): void
    {
        $this->pdo->prepare("INSERT INTO erp_reservations (condominium_id, common_area_id, unit_id, resident_name, reserved_on, starts_time, ends_time, fee_cents, status)
            VALUES (?, ?, ?, 'Morador de teste', ?, '18:00:00', '23:00:00', ?, ?)")->execute([$this->condominium, $this->areas[$area], $this->units[$unit], $on, $fee, $status]);
    }
}
