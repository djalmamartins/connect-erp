<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\Registry\RegistryService;

/** Fila de avisos: log, sandbox, nunca morador fora de produção, retentativa, trilha e escopo (ADR 0055). */
final class NotificationDispatcherIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private int $unit;
    private int $area;
    /** @var list<array{string, string, string, string}> */
    private array $queued = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $service = new RegistryService($this->pdo);
        $this->unit = $service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->area = $service->save($this->admin, 'common_areas', ['condominium_id' => $this->alpha, 'name' => 'Salão', 'booking_mode' => 'approval'])['id'];
        $service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'relationship' => 'owner',
            'name' => 'Bia Moradora', 'document' => self::validCpf(), 'email' => 'bia@morador.test']);
    }

    public function testLogModeSendsNothingAndRecordsTrail(): void
    {
        $id = $this->event('e1');
        $summary = $this->dispatcher('development', 'log')->dispatch();
        self::assertSame(['log', 1, 1], [$summary['mode'], $summary['processed'], $summary['sent']]);
        self::assertSame([], $this->queued, 'Modo log não enfileira e-mail.');
        $row = $this->row($id);
        self::assertSame(['sent', 'log', 1], [$row['status'], $row['delivery_mode'], (int)$row['recipients_count']]);
        $trail = $this->pdo->query('SELECT recipient_masked, sent_to FROM erp_notification_deliveries')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['b**@morador.test', null], [$trail['recipient_masked'], $trail['sent_to']]);
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_notification_deliveries WHERE recipient_masked LIKE 'bia@%'")->fetchColumn(), 'E-mail não fica em claro.');
    }

    public function testOutsideProductionOnlyTheSandboxInboxReceives(): void
    {
        $this->event('e2');
        $summary = $this->dispatcher('development', 'production', 'caixa@mailpit.local')->dispatch();
        self::assertSame('sandbox', $summary['mode'], 'Configuração de produção fora de produção vira sandbox.');
        self::assertCount(1, $this->queued);
        self::assertSame('caixa@mailpit.local', $this->queued[0][2], 'Nunca o e-mail do morador.');
        self::assertStringStartsWith('[TESTE]', $this->queued[0][0]);
        self::assertStringContainsString('Salão', $this->queued[0][1]);
    }

    public function testRetryWithBackoffThenFailAndManualRetry(): void
    {
        $id = $this->event('e3');
        $failing = new NotificationDispatcher($this->pdo, 'development', 'sandbox', 'caixa@mailpit.local', static fn (): ?int => null);
        self::assertSame(1, $failing->dispatch()['retry']);
        $row = $this->row($id);
        self::assertSame(['pending', 1], [$row['status'], (int)$row['attempts']]);
        self::assertGreaterThan(date('Y-m-d H:i:s'), $row['available_at'], 'Espera antes da próxima tentativa.');

        $this->pdo->exec("UPDATE erp_notification_outbox SET attempts = " . NotificationDispatcher::MAX_ATTEMPTS . ", available_at = NOW() WHERE id = {$id}");
        $this->pdo->exec("UPDATE erp_notification_outbox SET attempts = attempts - 1 WHERE id = {$id}");
        self::assertSame(1, $failing->dispatch()['failed']);
        self::assertSame('failed', $this->row($id)['status']);

        self::assertSame('pending', $this->dispatcher('development', 'log')->retry($this->admin, $id)['status']);
        self::assertSame(1, $this->dispatcher('development', 'log')->dispatch()['sent']);
    }

    /** ADR 0108: aviso preso em 'processing' é retomado depois do prazo da posse; dentro do prazo, não. */
    public function testStuckProcessingIsReclaimedAfterLease(): void
    {
        $id = $this->event('e-lease');
        $this->pdo->exec("UPDATE erp_notification_outbox SET status = 'processing', attempts = 1,
            available_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = {$id}");
        self::assertSame(0, $this->dispatcher('development', 'log')->dispatch()['processed'], 'Posse em vigor: ninguém toma o aviso.');

        $this->pdo->exec("UPDATE erp_notification_outbox SET available_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$id}");
        self::assertSame(1, $this->dispatcher('development', 'log')->dispatch()['sent'], 'Posse vencida: o aviso é retomado.');
        self::assertSame(['sent', 2], [$this->row($id)['status'], (int)$this->row($id)['attempts']]);

        $stuck = $this->event('e-lease-2');
        $this->pdo->exec("UPDATE erp_notification_outbox SET status = 'processing', attempts = " . NotificationDispatcher::MAX_ATTEMPTS . ",
            available_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$stuck}");
        self::assertSame(1, $this->dispatcher('development', 'log')->dispatch()['failed'], 'Interrompido além do limite: falha, sem novo envio.');
        self::assertSame('failed', $this->row($stuck)['status']);
    }

    public function testNoEmailCancelsAndScopeHidesOthers(): void
    {
        $this->pdo->exec("UPDATE erp_residents SET email = NULL");
        $id = $this->event('e4');
        self::assertSame(1, $this->dispatcher('development', 'log')->dispatch()['cancelled']);
        self::assertSame('Nenhum destinatário com e-mail cadastrado.', $this->row($id)['last_error']);

        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $outsider = $this->user([(int)$this->pdo->lastInsertId()]);
        self::assertSame(0, $this->dispatcher('development', 'log')->list($outsider)['total']);
    }

    public function testBillingNoticesOnlyReachOptIn(): void
    {
        $outbox = new NotificationOutbox($this->pdo);
        $payload = ['unit_id' => $this->unit, 'unit_label' => '101', 'type_name' => 'Taxa condominial', 'competence' => '2026-11', 'due_on' => '2026-11-10', 'amount_cents' => 50000];
        $outbox->record('invoice.issued', 'invoice.issued:t1', $this->alpha, 'erp_invoice_items', 1, $payload, null);
        self::assertSame(1, $this->dispatcher('development', 'sandbox', 'caixa@mailpit.local')->dispatch()['cancelled'], 'Sem opt-in, ninguém recebe cobrança.');
        self::assertSame([], $this->queued);

        $this->pdo->exec('UPDATE erp_residents SET billing_by_email = 1');
        $outbox->record('invoice.issued', 'invoice.issued:t2', $this->alpha, 'erp_invoice_items', 2, $payload, null);
        self::assertSame(1, $this->dispatcher('development', 'sandbox', 'caixa@mailpit.local')->dispatch()['sent']);
        self::assertSame('caixa@mailpit.local', $this->queued[0][2], 'Fora de produção, só a caixa de teste.');
        self::assertStringContainsString('R$ 500,00', $this->queued[0][1]);
        self::assertStringContainsString('10/11/2026', $this->queued[0][1]);
    }

    public function testReservationApprovalRecordsNotice(): void
    {
        $service = new RegistryService($this->pdo);
        $input = ['condominium_id' => $this->alpha, 'common_area_id' => $this->area, 'unit_id' => $this->unit,
            'resident_name' => 'Bia Moradora', 'reserved_on' => date('Y-m-d', strtotime('+10 days')), 'starts_time' => '18:00', 'ends_time' => '22:00'];
        $reservation = $service->save($this->admin, 'reservations', $input);
        self::assertSame('active', $reservation['status'], 'Área com aprovação: nasce aguardando.');
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_notification_outbox WHERE event_type = 'reservation.approved'")->fetchColumn());

        $service->save($this->admin, 'reservations', ['status' => 'confirmed'] + $input, $reservation['id']);
        self::assertSame(1, $this->dispatcher('development', 'sandbox', 'caixa@mailpit.local')->dispatch()['sent']);
        self::assertStringContainsString('Salão', $this->queued[0][1]);
        self::assertStringContainsString('18:00', $this->queued[0][1]);
    }

    private function dispatcher(string $env, string $mode, ?string $sandbox = null): NotificationDispatcher
    {
        return new NotificationDispatcher($this->pdo, $env, $mode, $sandbox, function (string $subject, string $body, string $email, string $name): int {
            $this->queued[] = [$subject, $body, $email, $name];
            return count($this->queued);
        });
    }

    private function event(string $key): int
    {
        (new NotificationOutbox($this->pdo))->record('waitlist.called', $key, $this->alpha, 'erp_waitlist', 1,
            ['resident_name' => 'Bia', 'unit_id' => $this->unit, 'common_area_id' => $this->area, 'date' => '2026-11-20', 'reservation_id' => 1], null);
        return (int)$this->pdo->query("SELECT id FROM erp_notification_outbox WHERE event_key = " . $this->pdo->quote($key))->fetchColumn();
    }

    private function row(int $id): array
    {
        return $this->pdo->query("SELECT * FROM erp_notification_outbox WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        return (new User())->findById($id);
    }
}
