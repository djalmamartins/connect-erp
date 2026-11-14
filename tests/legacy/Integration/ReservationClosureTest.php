<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ReservationClosure;

/** ADR 0263: reservas da área interditada canceladas pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class ReservationClosureTest extends TestCase
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
            $this->units[$number] = (int)$registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        foreach (['Piscina', 'Salão de festas'] as $name) {
            $this->pdo->prepare("INSERT INTO erp_common_areas (condominium_id, name, booking_mode) VALUES (?, ?, 'approval')")->execute([$this->condominium, $name]);
            $this->areas[$name] = (int)$this->pdo->lastInsertId();
        }
    }

    public function testCancelsTheAreaReservationsInThePeriodOnly(): void
    {
        $pending = $this->reservation('Piscina', '101', 10, 'active', 15000);
        $confirmed = $this->reservation('Piscina', '102', 20, 'confirmed', null);
        $later = $this->reservation('Piscina', '101', 60, 'active', null);
        $other = $this->reservation('Salão de festas', '101', 10, 'active', null);
        $orphan = $this->reservation('Piscina', '102', 15, 'active', null);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'common_area_id' => $this->areas['Piscina'], 'from' => $this->day(5), 'to' => $this->day(30),
            'reason' => 'Reforma da piscina'];

        $preview = (new ReservationClosure($this->pdo))->cancel($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$pending, $orphan, $confirmed], 1], [$preview['dry_run'], $preview['changed'], array_column($preview['reservations'], 'id'),
            $preview['confirmed']]);
        self::assertSame('active', $this->state($pending), 'dry_run não grava.');

        $done = (new ReservationClosure($this->pdo))->cancel($this->admin, $input);
        self::assertSame(3, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'inactive', 'active', 'active'], [$this->state($pending), $this->state($confirmed), $this->state($orphan),
            $this->state($later), $this->state($other)]);
        self::assertSame(15000, (int)$this->pdo->query("SELECT fee_cents FROM erp_reservations WHERE id = {$pending}")->fetchColumn(), 'Taxa preservada.');
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_reservations' AND action = 'closure_cancel'")->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_reservations' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        self::assertStringNotContainsString('Morador de teste', (string)json_encode($done, JSON_UNESCAPED_UNICODE));
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $inside = $this->reservation('Piscina', '101', 10, 'active', null);
        $outside = $this->reservation('Piscina', '101', 60, 'active', null);
        $input = ['condominium_id' => $this->condominium, 'common_area_id' => $this->areas['Piscina'], 'from' => $this->day(5), 'to' => $this->day(30),
            'reason' => 'Reforma da piscina'];
        try {
            (new ReservationClosure($this->pdo))->cancel($this->admin, $input + ['reservation_ids' => [$inside, $outside]]);
            self::fail('Reserva fora do período entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$outside, $exception->errors['_reservation']);
        }
        self::assertSame('active', $this->state($inside), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ReservationClosure($this->pdo))->cancel($limited, $input);
            self::fail('Cancelamento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function reservation(string $area, string $unit, int $days, string $status, ?int $fee): int
    {
        $this->pdo->prepare("INSERT INTO erp_reservations (condominium_id, common_area_id, unit_id, resident_name, reserved_on, starts_time, ends_time, fee_cents, status)
            VALUES (?, ?, ?, 'Morador de teste', ?, '18:00:00', '23:00:00', ?, ?)")->execute([$this->condominium, $this->areas[$area], $this->units[$unit], $this->day($days),
            $fee, $status]);
        return (int)$this->pdo->lastInsertId();
    }

    private function day(int $days): string
    {
        return (new \DateTimeImmutable("+{$days} days"))->format('Y-m-d');
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_reservations WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
