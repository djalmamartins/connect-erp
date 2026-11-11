<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VisitForecastExpiry;

/** ADR 0231: visitas previstas vencidas em aberto canceladas pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class VisitForecastExpiryTest extends TestCase
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

    public function testExpiresOverdueOpenForecastsOnly(): void
    {
        $old = $this->forecast(-10, null, 'active');
        $ranged = $this->forecast(-20, -5, 'active');
        $current = $this->forecast(-2, 3, 'active');
        $done = $this->forecast(-15, null, 'done');
        $arrived = $this->forecast(-12, null, 'active');   // teve acesso registrado: aconteceu, fica de fora
        $this->pdo->prepare("INSERT INTO erp_access_logs (condominium_id, unit_id, person_name, kind, entered_on, entered_time, visit_forecast_id, status)
            VALUES (?, ?, 'Visitante de teste', 'visitante', ?, '10:00:00', ?, 'active')")
            ->execute([$this->condominium, $this->unit, (new \DateTimeImmutable('-12 days'))->format('Y-m-d'), $arrived]);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Visita não aconteceu'];

        $preview = (new VisitForecastExpiry($this->pdo))->expire($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$ranged, $old], 1], [$preview['dry_run'], $preview['changed'], array_column($preview['forecasts'], 'id'),
            $preview['skipped_with_access']]);
        self::assertSame('active', $this->state($old), 'dry_run não grava.');

        $expired = (new VisitForecastExpiry($this->pdo))->expire($this->admin, $input);
        self::assertSame(2, $expired['changed']);
        self::assertSame(['inactive', 'inactive', 'active', 'done', 'active'], [$this->state($old), $this->state($ranged), $this->state($current), $this->state($done),
            $this->state($arrived)]);
        self::assertSame('Visitante de teste', $this->pdo->query("SELECT visitor_name FROM erp_visit_forecasts WHERE id = {$old}")->fetchColumn(), 'Dados preservados.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_visit_forecasts' AND action = 'expire'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_visit_forecasts' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        self::assertStringNotContainsString('Visitante de teste', (string)json_encode($expired, JSON_UNESCAPED_UNICODE));
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $old = $this->forecast(-10, null, 'active');
        $current = $this->forecast(-2, 3, 'active');
        try {
            (new VisitForecastExpiry($this->pdo))->expire($this->admin, ['condominium_id' => $this->condominium, 'forecast_ids' => [$old, $current],
                'reason' => 'Visita não aconteceu']);
            self::fail('Visita ainda válida entrou na baixa.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$current, $exception->errors['_forecast']);
        }
        self::assertSame('active', $this->state($old), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VisitForecastExpiry($this->pdo))->expire($limited, ['condominium_id' => $this->condominium, 'reason' => 'Visita não aconteceu']);
            self::fail('Baixa fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function forecast(int $onDays, ?int $untilDays, string $status): int
    {
        $day = static fn (int $days): string => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d');
        $id = (int)$this->registry->save($this->admin, 'visit_forecasts', ['condominium_id' => $this->condominium, 'unit_id' => $this->unit,
            'visitor_name' => 'Visitante de teste', 'expected_on' => $day($onDays), 'expected_until' => $untilDays !== null ? $day($untilDays) : null,
            'authorized_by' => 'Morador de teste'])['id'];
        if ($status !== 'active') {
            $this->pdo->prepare('UPDATE erp_visit_forecasts SET status = ? WHERE id = ?')->execute([$status, $id]);
        }
        return $id;
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_visit_forecasts WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
