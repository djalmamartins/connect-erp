<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OperationalDashboard;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Dashboard com alertas reais, chamada da lista de espera e próxima manutenção (ADR 0053). */
final class OperationalDashboardIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private OperationalDashboard $dashboard;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->dashboard = new OperationalDashboard($this->pdo);
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testCardsAndAlertsFollowDatesAndScope(): void
    {
        $today = date('Y-m-d');
        $day = fn (int $n): string => (new \DateTimeImmutable('today'))->modify("{$n} days")->format('Y-m-d');
        $this->service->save($this->admin, 'maintenances', ['condominium_id' => $this->alpha, 'service' => 'Bombas', 'planned_on' => $day(-3), 'frequency' => 'mensal']);
        $this->service->save($this->admin, 'maintenances', ['condominium_id' => $this->alpha, 'service' => 'Elevador', 'planned_on' => $day(3), 'frequency' => 'anual']);
        $this->service->save($this->admin, 'insurances', ['condominium_id' => $this->alpha, 'insurer' => 'X', 'policy_number' => 'A1', 'starts_on' => $day(-300), 'ends_on' => $day(10)]);
        $this->service->save($this->admin, 'insurances', ['condominium_id' => $this->alpha, 'insurer' => 'Y', 'policy_number' => 'A2', 'starts_on' => $day(-400), 'ends_on' => $day(-1)]);

        $summary = $this->dashboard->summary($this->admin, $this->alpha, $today);
        self::assertSame([1, 1, 1, 1], [$summary['cards']['maintenances_late'], $summary['cards']['maintenances_week'],
            $summary['cards']['insurances_renewal'], $summary['cards']['insurances_expired']]);
        self::assertSame('maintenances_late', $summary['alerts'][0]['kind'], 'Alertas graves primeiro.');
        self::assertSame(0, $this->dashboard->summary($this->admin, $this->beta, $today)['cards']['maintenances_late'], 'Outro condomínio não soma.');

        $outsider = $this->user([$this->beta]);
        $this->expectException(RegistryNotFound::class);
        $this->dashboard->summary($outsider, $this->alpha, $today);
    }

    public function testCallWaitlistOnlyRecordsTheCall(): void
    {
        $date = (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
        $area = $this->service->save($this->admin, 'common_areas', ['condominium_id' => $this->alpha, 'name' => 'Salão', 'booking_mode' => 'approval'])['id'];
        $reservation = $this->service->save($this->admin, 'reservations', ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $this->unit,
            'resident_name' => 'Ana', 'reserved_on' => $date]);
        $other = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '102', 'type' => 'apartamento'])['id'];
        $first = $this->service->save($this->admin, 'waitlist', ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $other,
            'resident_name' => 'Bia', 'desired_on' => $date]);

        try {
            $this->dashboard->callWaitlist($this->admin, $reservation['id']);
            self::fail('Chamou a lista com a reserva ainda válida.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        $this->service->save($this->admin, 'reservations', ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $this->unit,
            'resident_name' => 'Ana', 'reserved_on' => $date, 'status' => 'inactive'], $reservation['id']);
        self::assertSame(1, $this->dashboard->summary($this->admin, $this->alpha)['cards']['waitlist_to_call']);

        $call = $this->dashboard->callWaitlist($this->admin, $reservation['id']);
        self::assertSame([$first['id'], 'Bia'], [$call['waitlist_id'], $call['resident_name']]);
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_reservations WHERE status IN ('active','confirmed')")->fetchColumn(), 'Não reserva sozinho.');
        self::assertSame(0, $this->dashboard->summary($this->admin, $this->alpha)['cards']['waitlist_to_call']);
        $this->expectException(DomainException::class);
        $this->dashboard->callWaitlist($this->admin, $reservation['id']);   // ninguém mais aguardando
    }

    public function testNextMaintenanceOnceAndOnlyWhenDone(): void
    {
        $maintenance = $this->service->save($this->admin, 'maintenances', ['condominium_id' => $this->alpha, 'service' => 'Bombas', 'planned_on' => '2026-11-30', 'frequency' => 'trimestral']);
        try {
            $this->dashboard->nextMaintenance($this->admin, $maintenance['id']);
            self::fail('Gerou a próxima de manutenção não concluída.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        $this->service->save($this->admin, 'maintenances', ['condominium_id' => $this->alpha, 'service' => 'Bombas', 'planned_on' => '2026-11-30',
            'frequency' => 'trimestral', 'done_on' => '2026-11-30', 'status' => 'done'], $maintenance['id']);
        $next = $this->dashboard->nextMaintenance($this->admin, $maintenance['id']);
        self::assertSame(['2027-02-28', 'active'], [$next['planned_on'], $next['status']]);
        try {
            $this->dashboard->nextMaintenance($this->admin, $maintenance['id']);
            self::fail('Gerou a próxima duas vezes.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        $single = $this->service->save($this->admin, 'maintenances', ['condominium_id' => $this->alpha, 'service' => 'Pintura', 'planned_on' => '2026-11-30',
            'frequency' => 'unica', 'status' => 'done']);
        $this->expectException(InvalidArgumentException::class);
        $this->dashboard->nextMaintenance($this->admin, $single['id']);
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
