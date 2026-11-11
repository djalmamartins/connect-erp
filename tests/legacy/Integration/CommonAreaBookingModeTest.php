<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommonAreaBookingMode;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0229: bloqueio e liberação em lote das áreas comuns pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class CommonAreaBookingModeTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->unit = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testBlocksAndReleasesThroughTheRegistry(): void
    {
        $hall = $this->area('Salão de festas', 'approval', '150,00');
        $grill = $this->area('Churrasqueira', 'free', null);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'area_ids' => [$hall, $grill], 'booking_mode' => 'blocked', 'reason' => 'Dedetização do prédio'];

        $preview = (new CommonAreaBookingMode($this->pdo))->change($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$hall, $grill]], [$preview['dry_run'], $preview['changed'], array_column($preview['areas'], 'id')]);
        self::assertSame('approval', $this->mode($hall), 'dry_run não grava.');

        $done = (new CommonAreaBookingMode($this->pdo))->change($this->admin, $input);
        self::assertSame([2, 'Bloqueada'], [$done['changed'], $done['booking_mode_label']]);
        self::assertSame(['blocked', 'blocked'], [$this->mode($hall), $this->mode($grill)]);
        self::assertSame(15000, (int)$this->pdo->query("SELECT booking_fee_cents FROM erp_common_areas WHERE id = {$hall}")->fetchColumn(), 'Taxa preservada.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_common_areas' AND action = 'booking_mode'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_common_areas' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        try {
            $this->registry->save($this->admin, 'reservations', ['condominium_id' => $this->condominium, 'common_area_id' => $hall, 'unit_id' => $this->unit,
                'resident_name' => 'Morador de teste', 'reserved_on' => date('Y-m-d', strtotime('+20 days'))]);
            self::fail('Reservou área bloqueada.');
        } catch (RegistryValidationException $exception) {
            self::assertStringContainsString('bloqueada', implode(' ', $exception->errors));
        }

        $back = (new CommonAreaBookingMode($this->pdo))->change($this->admin, ['booking_mode' => 'approval', 'area_ids' => [$hall]] + $input);
        self::assertSame([1, 'approval'], [$back['changed'], $this->mode($hall)]);
        self::assertSame([$outbox, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(), $this->notices()],
            'Nenhum aviso (a notificação interna da auditoria fica de fora).');
    }

    public function testUpcomingReservationRefusesTheWholeBatch(): void
    {
        $grill = $this->area('Churrasqueira', 'free', null);
        $hall = $this->area('Salão de festas', 'approval', null);
        $this->pdo->prepare("INSERT INTO erp_reservations (condominium_id, common_area_id, unit_id, resident_name, reserved_on, status)
            VALUES (?, ?, ?, 'Morador de teste', ?, 'confirmed')")->execute([$this->condominium, $hall, $this->unit, date('Y-m-d', strtotime('+5 days'))]);
        try {
            (new CommonAreaBookingMode($this->pdo))->change($this->admin, ['condominium_id' => $this->condominium, 'area_ids' => [$grill, $hall],
                'booking_mode' => 'blocked', 'reason' => 'Dedetização do prédio']);
            self::fail('Bloqueou área com reserva confirmada.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$hall, $exception->errors['_area']);
            self::assertStringNotContainsString('Morador', $exception->errors['_form']);
        }
        self::assertSame('free', $this->mode($grill), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new CommonAreaBookingMode($this->pdo))->change($limited, ['condominium_id' => $this->condominium, 'area_ids' => [$grill], 'booking_mode' => 'blocked',
                'reason' => 'Dedetização do prédio']);
            self::fail('Mudou área fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function area(string $name, string $mode, ?string $fee): int
    {
        return (int)$this->registry->save($this->admin, 'common_areas', ['condominium_id' => $this->condominium, 'name' => $name, 'booking_mode' => $mode,
            'booking_fee_cents' => $fee, 'rules' => 'Silêncio após as 22h.'])['id'];
    }

    private function mode(int $area): string
    {
        return (string)$this->pdo->query("SELECT booking_mode FROM erp_common_areas WHERE id = {$area}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
