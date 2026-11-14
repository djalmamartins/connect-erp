<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\WaitlistDemand;

/** ADR 0260: procura pelas áreas comuns com as inscrições da lista de espera do cadastro; somente leitura e sem dado do morador. */
final class WaitlistDemandTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $units = [];
    /** @var array<string, int> */
    private array $areas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['101', '102'] as $number) {
            $this->units[$number] = (int)$this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        foreach (['Salão de festas', 'Quadra'] as $name) {
            $this->pdo->prepare("INSERT INTO erp_common_areas (condominium_id, name, booking_mode) VALUES (?, ?, 'approval')")->execute([$this->condominium, $name]);
            $this->areas[$name] = (int)$this->pdo->lastInsertId();
        }
    }

    public function testDemandFromTheRegistry(): void
    {
        $called = $this->entry('Salão de festas', '101', 5);
        $this->entry('Salão de festas', '102', 5);
        $this->entry('Salão de festas', '101', 12);
        $this->entry('Salão de festas', '102', -10);
        $served = $this->entry('Quadra', '101', -20);
        $this->pdo->prepare("UPDATE erp_waitlist SET status = 'served' WHERE id = ?")->execute([$served]);
        $this->pdo->prepare('UPDATE erp_waitlist SET called_at = NOW() WHERE id = ?')->execute([$called]);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new WaitlistDemand($this->pdo))->build($this->admin, $this->condominium);
        $rows = array_column($report['rows'], null, 'area_name');
        self::assertSame([3, 1, 1, 0, 4, 2, 2, (new \DateTimeImmutable('+5 days'))->format('Y-m-d')], [$rows['Salão de festas']['waiting_upcoming'],
            $rows['Salão de festas']['waiting_past'], $rows['Salão de festas']['called'], $rows['Salão de festas']['served'], $rows['Salão de festas']['entries'],
            $rows['Salão de festas']['units'], $rows['Salão de festas']['dates_upcoming'], $rows['Salão de festas']['next_desired_on']]);
        self::assertSame([1, 1, null], [$rows['Quadra']['served'], $rows['Quadra']['entries'], $rows['Quadra']['next_desired_on']]);
        self::assertSame(['waiting_upcoming' => 3, 'waiting_past' => 1, 'called' => 1, 'served' => 1, 'gave_up' => 0, 'entries' => 5, 'units' => 3, 'dates_upcoming' => 2],
            $report['totals']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Morador de teste', 'resident'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new WaitlistDemand($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function entry(string $area, string $unit, int $days): int
    {
        return (int)$this->registry->save($this->admin, 'waitlist', ['condominium_id' => $this->condominium, 'common_area_id' => $this->areas[$area],
            'unit_id' => $this->units[$unit], 'resident_name' => 'Morador de teste', 'desired_on' => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d')])['id'];
    }
}
