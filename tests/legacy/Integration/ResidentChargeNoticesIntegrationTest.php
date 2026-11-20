<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Announcements;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\ResidentChargeNotices;
use Source\Services\Erp\ResidentEmailPolicy;

/** Morador recebe só cinco e-mails (ADR 0600): boleto no vencimento, atraso nos marcos, sem repetir e o resto desligado. */
final class ResidentChargeNoticesIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private int $unit;
    private int $account;
    private int $bank;
    /** @var list<array{string, string, string, string}> */
    private array $queued = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->admin = (new User())->findById($id);
        $service = new RegistryService($this->pdo);
        $this->unit = $service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'relationship' => 'owner',
            'name' => 'Bia Moradora', 'document' => self::validCpf(), 'email' => 'bia@morador.test']);
        $this->pdo->exec('UPDATE erp_residents SET billing_by_email = 1');
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.01', 'Taxa', 'receita', 'analitica')")->execute([$this->alpha]);
        $this->account = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number, kind) VALUES (?, 'Conta', '341', '0001', '1234-5', 'corrente')")
            ->execute([$this->alpha]);
        $this->bank = (int)$this->pdo->lastInsertId();
    }

    public function testDueTodayOnceWithDigitableLine(): void
    {
        $entry = $this->entry('Taxa 11/2026', '2026-11-20');
        $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on, digitable_line)
            VALUES (?, ?, ?, 'sandbox', 'registered', '0001', 45000, '2026-11-20', '00190.00009 01234.567890')")->execute([$this->alpha, $this->bank, $entry]);
        $this->entry('Taxa futura', '2026-11-21');

        $first = $this->runOn('2026-11-20');
        self::assertSame([1, 0, 1], [$first['due_today'], $first['overdue'], $first['units']]);
        $second = $this->runOn('2026-11-20');
        self::assertSame([0, 1], [$second['units'], $second['skipped']], 'Rodar de novo no mesmo dia não repete.');
        self::assertSame(1, $this->outboxCount("event_type = 'charge.due_today'"));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_resident_charge_notices WHERE item_type = 'charge' AND milestone = 0")->fetchColumn(),
            'O boleto conta, não a conta a receber por baixo dele.');

        self::assertSame(1, $this->dispatcher()->dispatch()['sent']);
        self::assertSame('[TESTE] Seu boleto do condomínio vence hoje', $this->queued[0][0]);
        self::assertStringContainsString('00190.00009 01234.567890', $this->queued[0][1]);
        self::assertStringContainsString('R$ 450,00', $this->queued[0][1]);
        self::assertSame(1, $this->runOn('2026-11-21')['due_today'], 'No dia seguinte só a conta que vence nesse dia; o boleto de ontem não volta.');
    }

    public function testOverdueOnlyAtMilestonesAndStopsWhenPaid(): void
    {
        $entry = $this->entry('Taxa 09/2026', '2026-09-01');
        $sent = [];
        foreach (new \DatePeriod(new DateTimeImmutable('2026-09-02'), new \DateInterval('P1D'), new DateTimeImmutable('2026-11-15')) as $day) {
            if ($this->runOn($day->format('Y-m-d'))['overdue'] > 0) {
                $sent[] = (int)$day->diff(new DateTimeImmutable('2026-09-01'))->format('%a');
            }
        }
        self::assertSame(ResidentEmailPolicy::MILESTONES, $sent, 'Só com 10, 15, 30, 45 e 60 dias, uma vez cada.');
        self::assertSame(5, $this->outboxCount("event_type = 'charge.overdue'"));

        $other = $this->entry('Taxa 10/2026', '2026-10-01');
        $this->pdo->exec("UPDATE erp_entries SET paid_on = '2026-10-05' WHERE id = {$other}");
        self::assertSame(0, $this->runOn('2026-10-11')['overdue'], 'Paga não é avisada.');

        $late = $this->entry('Taxa 08/2026', '2026-08-01');
        self::assertSame(1, $this->runOn('2026-08-13')['overdue'], 'Cron que falhou: ainda avisa até 3 dias depois do marco.');
        self::assertSame(0, $this->runOn('2026-08-14')['overdue']);
        unset($entry, $late);
    }

    public function testOtherResidentEmailsAreOff(): void
    {
        $outbox = new NotificationOutbox($this->pdo);
        foreach (['bank_charge.issued', 'invoice.issued', 'announcement.published', 'reservation.approved', 'waitlist.called', 'agreement.created'] as $event) {
            $outbox->record($event, $event . ':1', $this->alpha, 'x', 1, ['unit_id' => $this->unit], null);
        }
        self::assertSame(6, $this->outboxCount("status = 'cancelled' AND processed_at IS NOT NULL"), 'Desligado fica só no histórico, já cancelado.');
        self::assertSame(0, $this->dispatcher()->dispatch()['processed'], 'Nada desligado vai para a fila de envio.');

        // Aviso antigo que já estava na fila antes da mudança: a fila cancela, ninguém recebe.
        $this->pdo->prepare("INSERT INTO erp_notification_outbox (condominium_id, event_type, event_key, subject_table, subject_id, payload_json, available_at)
            VALUES (?, 'invoice.issued', 'velho', 'erp_invoice_items', 1, ?, NOW())")->execute([$this->alpha, json_encode(['unit_id' => $this->unit])]);
        self::assertSame(1, $this->dispatcher()->dispatch()['cancelled']);
        self::assertSame([], $this->queued);
        self::assertSame(ResidentEmailPolicy::DISABLED_REASON, $this->pdo->query("SELECT last_error FROM erp_notification_outbox WHERE event_key = 'velho'")->fetchColumn());

        try {
            (new Announcements($this->pdo))->collection($this->admin, ['condominium_id' => $this->alpha]);
            self::fail('Aviso de cobrança manual devia estar desligado.');
        } catch (\DomainException $error) {
            self::assertSame(Announcements::COLLECTION_DISABLED, $error->getMessage());
        }
    }

    private function runOn(string $day): array
    {
        return (new ResidentChargeNotices($this->pdo, new DateTimeImmutable($day)))->run();
    }

    private function entry(string $description, string $dueOn): int
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, unit_id, description, amount_cents, competence, due_on, status)
            VALUES (?, 'receita', ?, ?, ?, 45000, ?, ?, 'active')")->execute([$this->alpha, $this->account, $this->unit, $description, substr($dueOn, 0, 7), $dueOn]);
        return (int)$this->pdo->lastInsertId();
    }

    private function outboxCount(string $where): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_notification_outbox WHERE {$where}")->fetchColumn();
    }

    private function dispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher($this->pdo, 'development', 'sandbox', 'caixa@mailpit.local', function (string $subject, string $body, string $email, string $name): int {
            $this->queued[] = [$subject, $body, $email, $name];
            return count($this->queued);
        });
    }
}
