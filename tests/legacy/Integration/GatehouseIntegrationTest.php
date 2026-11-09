<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Portaria pelo motor de cadastros: regras, vínculos, travas de exclusão e escopo (ADR 0042). */
final class GatehouseIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private int $unit;
    private int $gatehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->gatehouse = $this->service->save($this->admin, 'gatehouses', ['condominium_id' => $this->alpha, 'name' => 'Principal', 'kind' => 'presencial', 'shifts' => '3'])['id'];
    }

    public function testForecastAccessLogAndLinks(): void
    {
        $forecast = $this->service->save($this->admin, 'visit_forecasts', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit,
            'visitor_name' => 'Ana Visitante', 'expected_on' => '2026-11-12', 'authorized_by' => 'Morador 101']);
        self::assertSame('active', $forecast['status']);
        $this->assertInvalid('visit_forecasts', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'visitor_name' => 'X',
            'expected_on' => '2026-11-12', 'expected_until' => '2026-11-10'], 'expected_until');

        $log = $this->service->save($this->admin, 'access_logs', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'person_name' => 'Ana Visitante',
            'kind' => 'visitante', 'entered_on' => '2026-11-12', 'entered_time' => '09:30', 'gatehouse_id' => $this->gatehouse, 'visit_forecast_id' => $forecast['id']]);
        self::assertSame([$this->gatehouse, $forecast['id']], [$log['gatehouse_id'], $log['visit_forecast_id']]);

        // Portaria e visita prevista em uso não saem; a unidade com registros também não.
        foreach ([['gatehouses', $this->gatehouse], ['visit_forecasts', $forecast['id']], ['units', $this->unit]] as [$slug, $id]) {
            try {
                $this->service->delete($this->admin, $slug, $id);
                self::fail("Excluiu {$slug} em uso.");
            } catch (RegistryInUse) {
                self::addToAssertionCount(1);
            }
        }
        // Portaria de outro condomínio não serve.
        $other = $this->service->save($this->admin, 'gatehouses', ['condominium_id' => $this->beta, 'name' => 'Beta', 'kind' => 'remota'])['id'];
        $this->assertInvalid('access_logs', ['condominium_id' => $this->alpha, 'person_name' => 'B', 'kind' => 'prestador', 'entered_on' => '2026-11-12',
            'entered_time' => '10:00', 'gatehouse_id' => $other], 'gatehouse_id');
    }

    public function testDeliveriesAndLostFoundRules(): void
    {
        $delivery = $this->service->save($this->admin, 'deliveries', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'recipient' => 'Morador 101',
            'carrier' => 'correios', 'received_on' => '2026-11-09', 'tracking_code' => 'BR123']);
        self::assertSame('active', $delivery['status']);
        $base = ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'recipient' => 'Morador 101', 'carrier' => 'correios', 'received_on' => '2026-11-09'];
        $this->assertInvalid('deliveries', $base + ['picked_up_on' => '2026-11-10'], 'picked_up_by');
        $this->assertInvalid('deliveries', $base + ['picked_up_on' => '2026-11-08', 'picked_up_by' => 'Ana'], 'picked_up_on');
        $picked = $this->service->save($this->admin, 'deliveries', $base + ['picked_up_on' => '2026-11-10', 'picked_up_by' => 'Ana', 'status' => 'picked_up'], $delivery['id']);
        self::assertSame('picked_up', $picked['status']);
        self::assertSame(1, $this->service->list($this->admin, 'deliveries', ['status' => 'picked_up'])['total'], 'AP-136: entregas retiradas pelo filtro de situação.');

        $lost = ['condominium_id' => $this->alpha, 'item' => 'Guarda-chuva', 'found_on' => '2026-11-09', 'location' => 'Hall'];
        $this->assertInvalid('lost_found', $lost + ['returned_on' => '2026-11-10'], 'returned_to');
        self::assertSame('returned', $this->service->save($this->admin, 'lost_found', $lost + ['returned_on' => '2026-11-10', 'returned_to' => 'Morador 101', 'status' => 'returned'])['status']);
    }

    public function testScope(): void
    {
        $outsider = $this->user([$this->beta]);
        self::assertSame(0, $this->service->list($outsider, 'gatehouses')['total']);
        $this->expectException(RegistryNotFound::class);
        $this->service->find($outsider, 'gatehouses', $this->gatehouse);
    }

    private function assertInvalid(string $slug, array $input, string $field): void
    {
        try {
            $this->service->save($this->admin, $slug, $input);
            self::fail("{$slug} aceitou entrada inválida.");
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors);
        }
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
