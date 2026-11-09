<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Announcements;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Comunicação pela outbox (ADR 0059): comunicado, recusa, agendado, cancelamento, cobrança, endereços e escopo. */
final class AnnouncementsIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private Announcements $announcements;
    /** @var list<int> */
    private array $units = [];
    /** @var list<array> */
    private array $queued = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->announcements = new Announcements($this->pdo);
        foreach (['101', '102'] as $number) {
            $this->units[] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $this->service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->units[0], 'relationship' => 'owner',
            'name' => 'Bia Moradora', 'document' => self::validCpf(), 'email' => 'bia@morador.test']);
        $this->service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->units[1], 'relationship' => 'owner',
            'name' => 'Caio Morador', 'document' => self::validCpf(), 'email' => 'caio@morador.test', 'notices_opt_out' => true]);
    }

    public function testAnnouncementRespectsOptOutAndOnlyReachesSandbox(): void
    {
        $created = $this->announcements->announce($this->admin, ['condominium_id' => $this->alpha, 'subject' => 'Limpeza da caixa d’água',
            'body' => "A água será cortada no dia 20, das 8h às 12h.\nObrigado."]);
        self::assertSame([2, 2], [$created['units_count'], $created['delivery']['pending']]);
        $summary = $this->dispatcher()->dispatch();
        self::assertSame(['sandbox', 1, 1], [$summary['mode'], $summary['sent'], $summary['cancelled']], 'A 102 recusou comunicado.');
        self::assertCount(1, $this->queued);
        self::assertSame('caixa@mailpit.local', $this->queued[0][2], 'Fora de produção, só a caixa de teste.');
        self::assertSame('[TESTE] Limpeza da caixa d’água', $this->queued[0][0], 'Assunto do próprio comunicado.');
        self::assertStringContainsString('cortada no dia 20', $this->queued[0][1]);
    }

    public function testScheduledWaitsAndCancelStopsIt(): void
    {
        $sendAt = date('Y-m-d H:i', strtotime('+2 days'));
        $created = $this->announcements->announce($this->admin, ['condominium_id' => $this->alpha, 'subject' => 'Assembleia', 'body' => 'Assembleia geral no salão.',
            'audience' => 'units', 'unit_ids' => [$this->units[0]], 'send_at' => $sendAt]);
        self::assertTrue($created['scheduled']);
        self::assertSame(1, $this->announcements->list($this->admin, $this->alpha, ['scheduled' => 1])['total']);
        self::assertSame(0, $this->dispatcher()->dispatch()['processed'], 'Agendado não sai antes da hora.');

        $cancelled = $this->announcements->cancel($this->admin, $created['id'], 'Assembleia adiada');
        self::assertSame(['cancelled', 1, 1], [$cancelled['status'], $cancelled['stopped'], $cancelled['delivery']['cancelled']]);
        $this->pdo->exec('UPDATE erp_notification_outbox SET available_at = NOW()');
        self::assertSame(0, $this->dispatcher()->dispatch()['processed'], 'Cancelado não sai.');
        self::assertSame([], $this->queued);

        try {
            $this->announcements->cancel($this->admin, $created['id'], 'Segundo cancelamento');
            self::fail('Cancelar duas vezes deve ser recusado (ADR 0109).');
        } catch (\DomainException) {
            self::assertFalse($this->pdo->inTransaction(), 'Recusa sem transação aberta.');
        }
    }

    public function testCollectionReminderTargetsOverdueOnceAWeek(): void
    {
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $account = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $account, 'unit_id' => $this->units[0],
            'description' => 'Taxa 101', 'amount_cents' => '500,00', 'competence' => date('Y-m', strtotime('-2 months')), 'due_on' => date('Y-m-d', strtotime('-40 days'))]);
        $this->pdo->exec('UPDATE erp_residents SET billing_by_email = 1');

        $reminder = $this->announcements->collection($this->admin, ['condominium_id' => $this->alpha, 'min_days_late' => 30]);
        self::assertSame(['collection', 1, 0], [$reminder['kind'], $reminder['units_count'], $reminder['skipped_recent']], 'Só a 101 está em atraso.');
        $this->dispatcher()->dispatch();
        self::assertStringContainsString('R$ 500,00', $this->queued[0][1]);

        $this->expectException(DomainException::class);
        $this->announcements->collection($this->admin, ['condominium_id' => $this->alpha]);
    }

    public function testAddressesMaskEmailAndShowWhoReceives(): void
    {
        $addresses = $this->announcements->addresses($this->admin, $this->alpha);
        self::assertSame(['units' => 2, 'without_notices' => 1, 'without_billing' => 2], $addresses['totals']);
        self::assertSame('b**@morador.test', $addresses['data'][0]['people'][0]['email']);
        self::assertFalse($addresses['data'][1]['people'][0]['notices'], 'Caio recusou comunicado.');
    }

    public function testScope(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $outsider = $this->user([(int)$this->pdo->lastInsertId()]);
        $this->expectException(RegistryNotFound::class);
        $this->announcements->announce($outsider, ['condominium_id' => $this->alpha, 'subject' => 'Fora do escopo', 'body' => 'Não deveria sair.']);
    }

    /** ADR 0065: assunto em uma linha até o e-mail, unidades escolhidas validadas e teto diário por condomínio. */
    public function testHardeningSubjectLineUnitsAndDailyCap(): void
    {
        $created = $this->announcements->announce($this->admin, ['condominium_id' => $this->alpha, 'subject' => "Obra\r\nBcc: x@y.test",
            'body' => 'Texto do comunicado.', 'audience' => 'units', 'unit_ids' => [$this->units[0], (string)$this->units[0]]]);
        self::assertSame(['Obra Bcc: x@y.test', 1], [$created['subject'], $created['units_count']], 'Quebra vira espaço; unidade repetida conta uma vez.');
        $this->dispatcher()->dispatch();
        self::assertStringNotContainsString("\n", $this->queued[0][0]);

        foreach ([['abc'], [0], [], array_fill(0, Announcements::MAX_UNITS + 1, $this->units[0])] as $bad) {
            try {
                $this->announcements->announce($this->admin, ['condominium_id' => $this->alpha, 'subject' => 'Assunto ok', 'body' => 'Texto do comunicado.',
                    'audience' => 'units', 'unit_ids' => $bad]);
                self::fail('Unidades indevidas aceitas.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        for ($i = 1; $i < Announcements::MAX_PER_DAY; $i++) {
            $this->announcements->announce($this->admin, ['condominium_id' => $this->alpha, 'subject' => "Comunicado {$i}", 'body' => 'Texto do comunicado.']);
        }
        try {
            $this->announcements->announce($this->admin, ['condominium_id' => $this->alpha, 'subject' => 'Um a mais', 'body' => 'Texto do comunicado.']);
            self::fail('Passou do teto diário.');
        } catch (DomainException $error) {
            self::assertStringContainsString('24 horas', $error->getMessage());
        }
        self::assertSame(Announcements::MAX_PER_DAY, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_announcements WHERE condominium_id = {$this->alpha}")->fetchColumn());
    }

    private function dispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher($this->pdo, 'development', 'production', 'caixa@mailpit.local', function (string $subject, string $body, string $email, string $name): int {
            $this->queued[] = [$subject, $body, $email, $name];
            return count($this->queued);
        });
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
