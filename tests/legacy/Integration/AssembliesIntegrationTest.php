<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Assemblies;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Assembleia e votação (ADR 0088): edital pela outbox, presença, inadimplente sem voto, apuração e ata imutável. */
final class AssembliesIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->registry = new RegistryService($this->pdo);
        foreach (['101' => '0.500000', '102' => '0.300000', '103' => '0.200000'] as $number => $fraction) {
            $id = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
            $this->pdo->prepare('UPDATE erp_units SET ideal_fraction = ? WHERE id = ?')->execute([$fraction, $id]);
            $this->units[$number] = (int)$id;
        }
    }

    public function testFullFlowWithDelinquentUnitAndImmutableMinutes(): void
    {
        $service = new Assemblies($this->pdo);
        $first = new \DateTimeImmutable('+10 days 19:00');
        $assembly = $service->create($this->admin, ['condominium_id' => $this->alpha, 'kind' => 'ordinaria', 'mode' => 'presencial', 'title' => 'AGO 2026',
            'location' => 'Salão de festas', 'first_call_at' => $first->format('Y-m-d H:i'), 'items' => [
                ['title' => 'Aprovar as contas', 'vote_basis' => 'fraction', 'quorum' => 'simple'],
                ['title' => 'Trocar o portão', 'vote_basis' => 'unit', 'quorum' => 'two_thirds'],
            ]]);
        $id = $assembly['id'];
        self::assertSame('draft', $assembly['status']);

        $convened = $service->convene($this->admin, $id);
        self::assertSame('convened', $convened['status']);
        $events = (int)$this->pdo->query("SELECT COUNT(*) FROM erp_notification_outbox WHERE subject_table = 'erp_announcements' AND subject_id = " . (int)$convened['announcement_id'])->fetchColumn();
        self::assertSame(3, $events, 'O edital vira um evento por unidade na outbox; nada é enviado aqui.');

        // 103 tem conta vencida em aberto: entra, mas não vota.
        $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $account = $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $due = date('Y-m-d', strtotime('-20 days'));
        $this->registry->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $account, 'unit_id' => $this->units['103'],
            'description' => 'Taxa', 'amount_cents' => '300,00', 'competence' => substr($due, 0, 7), 'due_on' => $due]);

        $at = $first->modify('+5 minutes');
        try {
            $service->open($this->admin, $id, $first->modify('-1 hour'));
            self::fail('Abriu antes da primeira convocação.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        $service->open($this->admin, $id, $at);
        $service->checkIn($this->admin, $id, ['unit_id' => $this->units['101'], 'attendee_name' => 'Ana Proprietária'], $at);
        $service->checkIn($this->admin, $id, ['unit_id' => $this->units['102'], 'attendee_name' => 'Caio Inquilino'], $at);
        $blocked = $service->checkIn($this->admin, $id, ['unit_id' => $this->units['103'], 'attendee_name' => 'Duda Devedora'], $at);
        self::assertFalse($blocked['eligible']);
        self::assertSame('inadimplente', $blocked['reason']);
        self::assertNull($blocked['overdue_cents'], 'ADR 0095: sem erp.agreements.view, sem valor.');
        self::assertSame(30000, (int)$this->pdo->query('SELECT overdue_cents FROM erp_assembly_attendance WHERE id = ' . (int)$blocked['id'])->fetchColumn(), 'O valor fica gravado.');
        $this->assertConflict(fn () => $service->checkIn($this->admin, $id, ['unit_id' => $this->units['101'], 'attendee_name' => 'Outra Pessoa'], $at), 'presença repetida');
        $this->assertConflict(fn () => $service->checkIn($this->admin, $id, ['unit_id' => $this->units['102'], 'attendee_name' => 'Procurador', 'is_proxy' => true]), 'procuração sem arquivo', true);

        [$contas, $portao] = array_column($service->find($this->admin, $id)['items'], 'id');
        $service->startVoting($this->admin, $id, $contas);
        $this->assertConflict(fn () => $service->startVoting($this->admin, $id, $portao), 'dois itens em votação');
        $service->vote($this->admin, $id, $contas, $this->units['101'], 'yes');
        $service->vote($this->admin, $id, $contas, $this->units['102'], 'no');
        $this->assertConflict(fn () => $service->vote($this->admin, $id, $contas, $this->units['103'], 'yes'), 'inadimplente votando');
        $this->assertConflict(fn () => $service->vote($this->admin, $id, $contas, $this->units['101'], 'no'), 'voto repetido');
        $this->assertConflict(fn () => $service->close($this->admin, $id), 'encerrar com item aberto');
        $result = $service->closeItem($this->admin, $id, $contas)['items'][0]['result'];
        self::assertSame([500000, 300000, 1000000, true], [$result['yes'], $result['no'], $result['total'], $result['approved']], 'Fração: 0,5 contra 0,3.');

        $service->startVoting($this->admin, $id, $portao);
        $service->vote($this->admin, $id, $portao, $this->units['101'], 'yes');
        $service->vote($this->admin, $id, $portao, $this->units['102'], 'yes');
        $result = $service->closeItem($this->admin, $id, $portao)['items'][1]['result'];
        self::assertTrue($result['approved'], 'Duas de três unidades atingem os dois terços.');

        $closed = $service->close($this->admin, $id, $first->modify('+2 hours'));
        self::assertSame('closed', $closed['status']);
        self::assertSame(hash('sha256', (string)$closed['minutes']), $closed['minutes_sha256']);
        self::assertStringNotContainsString('Duda', (string)$closed['minutes'], 'A ata não traz nome de pessoa.');
        self::assertStringContainsString('Sem direito a voto por conta vencida: 1 (103)', (string)$closed['minutes']);
        $this->assertConflict(fn () => $service->checkIn($this->admin, $id, ['unit_id' => $this->units['103'], 'attendee_name' => 'Tarde Demais']), 'mudança depois da ata');
        $this->assertConflict(fn () => $service->cancel($this->admin, $id, 'Cancelar depois de fechada'), 'cancelar depois da ata');

        $report = $service->attendanceReport($this->admin, $id);
        self::assertSame(['units' => 3, 'proxies' => 0, 'without_vote' => 1], $report['totals']);
        self::assertCount(2, $service->resultsReport($this->admin, $id)['rows']);
        self::assertGreaterThan(0, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_assembly_votes'")->fetchColumn());
    }

    public function testNoticeTooShortScopeAndCancel(): void
    {
        $service = new Assemblies($this->pdo);
        $input = ['condominium_id' => $this->alpha, 'kind' => 'extraordinaria', 'mode' => 'virtual', 'title' => 'AGE portão', 'location' => 'https://sala.test/x',
            'first_call_at' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i'), 'items' => [['title' => 'Portão', 'vote_basis' => 'unit', 'quorum' => 'simple']]];
        $id = $service->create($this->admin, $input)['id'];
        $this->assertConflict(fn () => $service->convene($this->admin, $id), 'edital com menos de 8 dias');
        self::assertSame('draft', $service->find($this->admin, $id)['status']);

        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $other = $this->user([(int)$this->pdo->lastInsertId()]);
        try {
            $service->find($other, $id);
            self::fail('Mostrou assembleia de outro condomínio.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
        self::assertSame([], $service->list($other)['data']);

        $cancelled = $service->cancel($this->admin, $id, 'Assunto resolvido sem assembleia');
        self::assertSame('cancelled', $cancelled['status']);
    }

    private function assertConflict(callable $action, string $what, bool $validation = false): void
    {
        try {
            $action();
            self::fail("Aceitou {$what}.");
        } catch (DomainException | \InvalidArgumentException $error) {
            self::assertInstanceOf($validation ? \InvalidArgumentException::class : DomainException::class, $error, $what);
        }
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
