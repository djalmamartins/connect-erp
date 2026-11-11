<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ReservationExpiry;

/** ADR 0221: reservas que passaram sem aprovação ficam canceladas pelo formulário, tudo ou nada, sem aviso na outbox. */
final class ReservationExpiryTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private int $area;
    /** @var array<string, int> */
    private array $units = [];

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
        $this->pdo->prepare("INSERT INTO erp_common_areas (condominium_id, name, booking_mode) VALUES (?, 'Salão de festas', 'approval')")->execute([$this->condominium]);
        $this->area = (int)$this->pdo->lastInsertId();
    }

    public function testExpiresPastPendingOnly(): void
    {
        $old = $this->reservation('101', '2026-10-05', 'active', 15000);
        $older = $this->reservation('102', '2026-09-20', 'active', null);
        $confirmed = $this->reservation('101', '2026-10-06', 'confirmed', 15000);
        $future = $this->reservation('102', (new \DateTimeImmutable('+5 days'))->format('Y-m-d'), 'active', null);
        $outbox = $this->rows('erp_notification_outbox');
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Data passou sem aprovação'];

        $preview = (new ReservationExpiry($this->pdo))->expire($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$older, $old]], [$preview['dry_run'], $preview['changed'], array_column($preview['reservations'], 'id')]);
        self::assertSame('active', $this->state($old), 'dry_run não grava.');
        self::assertStringNotContainsString('Morador', (string)json_encode($preview));

        $done = (new ReservationExpiry($this->pdo))->expire($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'confirmed', 'active'], [$this->state($old), $this->state($older), $this->state($confirmed), $this->state($future)]);
        self::assertSame(15000, (int)$this->pdo->query("SELECT fee_cents FROM erp_reservations WHERE id = {$old}")->fetchColumn(), 'Taxa preservada.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_reservations' AND action = 'expire'")->fetchColumn());
        self::assertSame($outbox, $this->rows('erp_notification_outbox'), 'Nada vai para a outbox.');
    }

    public function testChosenIdsAreAllOrNothing(): void
    {
        $old = $this->reservation('101', '2026-10-05', 'active', null);
        $confirmed = $this->reservation('102', '2026-10-06', 'confirmed', null);
        try {
            (new ReservationExpiry($this->pdo))->expire($this->admin, ['condominium_id' => $this->condominium, 'reservation_ids' => [$old, $confirmed],
                'reason' => 'Data passou sem aprovação']);
            self::fail('Reserva confirmada entrou na baixa.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$confirmed, $exception->errors['_reservation']);
        }
        self::assertSame('active', $this->state($old), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ReservationExpiry($this->pdo))->expire($limited, ['condominium_id' => $this->condominium, 'reason' => 'Data passou sem aprovação']);
            self::fail('Baixa fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function reservation(string $unit, string $on, string $status, ?int $fee): int
    {
        $this->pdo->prepare("INSERT INTO erp_reservations (condominium_id, common_area_id, unit_id, resident_name, reserved_on, starts_time, ends_time, fee_cents, status)
            VALUES (?, ?, ?, 'Morador de teste', ?, '18:00:00', '23:00:00', ?, ?)")->execute([$this->condominium, $this->area, $this->units[$unit], $on, $fee, $status]);
        return (int)$this->pdo->lastInsertId();
    }

    private function state(int $reservation): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_reservations WHERE id = {$reservation}")->fetchColumn();
    }

    private function rows(string $table): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
