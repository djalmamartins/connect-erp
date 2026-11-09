<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Occurrences;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Reservas pelas regras da área comum, conflito de horário, mudanças e ocorrência → Help Desk (ADR 0051). */
final class OperationalRegistriesIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private int $unit;
    private int $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->admin = $this->user([$this->alpha], true);
        $this->service = new RegistryService($this->pdo);
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->area = $this->service->save($this->admin, 'common_areas', ['condominium_id' => $this->alpha, 'name' => 'Salão de festas', 'booking_mode' => 'approval',
            'booking_fee_cents' => '150,00', 'min_advance_days' => '2', 'max_advance_days' => '60', 'opening_time' => '08:00', 'closing_time' => '22:00'])['id'];
    }

    public function testReservationFollowsAreaRules(): void
    {
        $in = fn (int $days): string => (new \DateTimeImmutable('today'))->modify("+{$days} days")->format('Y-m-d');
        $base = ['condominium_id' => $this->alpha, 'common_area_id' => $this->area, 'unit_id' => $this->unit, 'resident_name' => 'Ana'];

        $ok = $this->service->save($this->admin, 'reservations', $base + ['reserved_on' => $in(5), 'starts_time' => '14:00', 'ends_time' => '18:00']);
        self::assertSame(['active', 15000], [$ok['status'], $ok['fee_cents']], 'Aguarda aprovação e herda a taxa da área.');

        $this->assertInvalid('reservations', $base + ['reserved_on' => $in(1)], 'reserved_on');                                   // antecedência mínima
        $this->assertInvalid('reservations', $base + ['reserved_on' => $in(90)], 'reserved_on');                                  // máxima
        $this->assertInvalid('reservations', $base + ['reserved_on' => $in(5), 'starts_time' => '07:00', 'ends_time' => '09:00'], 'starts_time'); // fora do horário
        $this->assertInvalid('reservations', $base + ['reserved_on' => $in(5), 'starts_time' => '17:00', 'ends_time' => '20:00'], 'starts_time'); // conflito
        self::assertSame('active', $this->service->save($this->admin, 'reservations', $base + ['reserved_on' => $in(5), 'starts_time' => '18:00', 'ends_time' => '21:00'])['status'],
            'Encostado no fim da outra não conflita.');

        // Cancelada libera o horário.
        $this->service->save($this->admin, 'reservations', $base + ['reserved_on' => $in(5), 'starts_time' => '14:00', 'ends_time' => '18:00', 'status' => 'inactive'], $ok['id']);
        self::assertSame('active', $this->service->save($this->admin, 'reservations', $base + ['reserved_on' => $in(5), 'starts_time' => '15:00', 'ends_time' => '17:00'])['status']);

        // Área livre confirma sozinha; área bloqueada recusa.
        $free = $this->service->save($this->admin, 'common_areas', ['condominium_id' => $this->alpha, 'name' => 'Churrasqueira', 'booking_mode' => 'free'])['id'];
        self::assertSame('confirmed', $this->service->save($this->admin, 'reservations', ['common_area_id' => $free, 'reserved_on' => $in(3)] + $base)['status']);
        $blocked = $this->service->save($this->admin, 'common_areas', ['condominium_id' => $this->alpha, 'name' => 'Quadra', 'booking_mode' => 'blocked'])['id'];
        $this->assertInvalid('reservations', ['common_area_id' => $blocked, 'reserved_on' => $in(3)] + $base, 'common_area_id');

        try {
            $this->service->delete($this->admin, 'common_areas', $this->area);
            self::fail('Excluiu área comum com reservas.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
    }

    public function testMovesOnePerPeriod(): void
    {
        $move = ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'resident_name' => 'Ana', 'kind' => 'entrada', 'scheduled_on' => '2026-12-01', 'period' => 'manha'];
        $this->service->save($this->admin, 'moves', $move);
        $this->assertInvalid('moves', ['kind' => 'saida'] + $move, 'period');
        self::assertSame('active', $this->service->save($this->admin, 'moves', ['period' => 'tarde'] + $move)['status']);
    }

    public function testOccurrenceOpensHelpDeskTicketOnce(): void
    {
        $occurrence = $this->service->save($this->admin, 'occurrences', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'subject' => 'Vazamento na garagem',
            'priority' => 'alta', 'occurred_on' => '2026-11-09', 'description' => 'Água pingando do teto da vaga 12.']);
        $result = (new Occurrences($this->pdo))->openTicket($this->admin, $occurrence['id']);
        $ticket = $this->pdo->query('SELECT origin, priority, condominium_id, unit_id FROM studio_support_tickets WHERE id = ' . (int)$result['ticket_id'])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['erp', 'high', $this->alpha, $this->unit], [$ticket['origin'], $ticket['priority'], (int)$ticket['condominium_id'], (int)$ticket['unit_id']]);
        $after = $this->service->find($this->admin, 'occurrences', $occurrence['id']);
        self::assertSame(['running', (string)$result['ticket_id']], [$after['status'], $after['ticket_id']]);
        $this->expectException(DomainException::class);
        (new Occurrences($this->pdo))->openTicket($this->admin, $occurrence['id']);
    }

    private function assertInvalid(string $slug, array $input, string $field): void
    {
        try {
            $this->service->save($this->admin, $slug, $input);
            self::fail("{$slug} aceitou entrada inválida.");
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors, json_encode($exception->errors));
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
