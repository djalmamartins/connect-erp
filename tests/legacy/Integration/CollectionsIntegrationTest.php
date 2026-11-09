<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Collections;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Cobrança e multas (ADR 0080): multas pendentes com origem, atendimento, promessa quebrada e caso judicial. */
final class CollectionsIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private Collections $collections;
    private int $account;
    /** @var list<int> */
    private array $units = [];
    private int $overdue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->collections = new Collections($this->pdo);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->account = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        foreach (['101', '102'] as $number) {
            $this->units[] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $this->overdue = $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $this->account,
            'unit_id' => $this->units[0], 'description' => 'Taxa 101', 'amount_cents' => '500,00', 'competence' => date('Y-m', strtotime('-2 months')),
            'due_on' => date('Y-m-d', strtotime('-40 days'))])['id'];
    }

    public function testPendingFinesShowOriginAndState(): void
    {
        $occurrence = $this->service->save($this->admin, 'occurrences', ['condominium_id' => $this->alpha, 'unit_id' => $this->units[1], 'subject' => 'Som alto no salão',
            'priority' => 'media', 'occurred_on' => date('Y-m-d'), 'description' => 'Som alto depois das 22h na festa da unidade 102.']);
        $fine = (new EntryShortcuts($this->pdo))->createCharge($this->admin, ['condominium_id' => $this->alpha, 'kind' => 'fine', 'description' => 'Multa por barulho',
            'chart_account_id' => $this->account, 'competence' => date('Y-m'), 'due_on' => date('Y-m-d', strtotime('+10 days')), 'amount_cents' => '150,00',
            'unit_ids' => [$this->units[1]], 'reason' => 'Regulamento, art. 12', 'occurrence_id' => $occurrence['id']]);
        self::assertSame($occurrence['id'], $fine['occurrence_id']);
        $pending = $this->collections->pendingFines($this->admin, $this->alpha);
        self::assertSame(['fines' => 1, 'open_cents' => 15000, 'overdue' => 0], $pending['totals']);
        self::assertSame(['open', $occurrence['id']], [$pending['data'][0]['state'], $pending['data'][0]['occurrence_id']]);

        $this->expectException(InvalidArgumentException::class);
        (new EntryShortcuts($this->pdo))->createCharge($this->admin, ['condominium_id' => $this->alpha, 'kind' => 'fine', 'description' => 'Multa errada',
            'chart_account_id' => $this->account, 'competence' => date('Y-m'), 'due_on' => date('Y-m-d', strtotime('+10 days')), 'amount_cents' => '150,00',
            'unit_ids' => [$this->units[0]], 'reason' => 'Ocorrência de outra unidade', 'occurrence_id' => $occurrence['id']]);
    }

    public function testDeskFlagsBrokenPromise(): void
    {
        $desk = $this->collections->desk($this->admin, $this->alpha);
        self::assertSame([1, 1], [count($desk['data']), $desk['totals']['without_contact']]);
        $contact = $this->collections->recordContact($this->admin, ['unit_id' => $this->units[0], 'channel' => 'phone', 'outcome' => 'promise',
            'promise_on' => date('Y-m-d'), 'promise_cents' => '500,00', 'note' => 'Prometeu pagar hoje no PIX.']);
        self::assertSame([50000, 'promise'], [$contact['open_cents'], $contact['outcome']]);
        self::assertFalse($this->collections->desk($this->admin, $this->alpha)['data'][0]['promise_broken']);
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        self::assertTrue($this->collections->desk($this->admin, $this->alpha, $tomorrow)['data'][0]['promise_broken'], 'Data passou e segue em atraso.');

        $this->expectException(InvalidArgumentException::class);
        $this->collections->recordContact($this->admin, ['unit_id' => $this->units[0], 'channel' => 'phone', 'outcome' => 'promise', 'note' => 'Sem a data prometida.']);
    }

    public function testLegalCaseOnlyTracksAndAllowsOneOpenPerUnit(): void
    {
        $lawyer = $this->service->save($this->admin, 'lawyers', ['name' => 'Dra. Ana', 'oab' => 'SP 123456']);
        $entriesBefore = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn();
        $case = $this->collections->openCase($this->admin, ['unit_id' => $this->units[0], 'entry_ids' => [$this->overdue], 'lawyer_id' => $lawyer['id'],
            'note' => 'Três meses sem pagar; enviado ao jurídico.']);
        self::assertSame(['preparing', 50000, 1], [$case['status'], $case['claimed_cents'], count($case['events'])]);
        self::assertSame($entriesBefore, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn(), 'Nenhum encargo criado.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(), 'Nada é enviado.');

        $filed = $this->collections->addEvent($this->admin, $case['id'], ['to_status' => 'filed', 'case_number' => '1001234-56.2026.8.26.0100', 'note' => 'Ação distribuída.']);
        self::assertSame(['filed', '1001234-56.2026.8.26.0100'], [$filed['status'], $filed['case_number']]);
        try {
            $this->collections->addEvent($this->admin, $case['id'], ['to_status' => 'preparing', 'note' => 'Voltar não pode.']);
            self::fail('Aceitou transição inválida.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        try {
            $this->collections->openCase($this->admin, ['unit_id' => $this->units[0], 'entry_ids' => [$this->overdue], 'note' => 'Segundo caso aberto.']);
            self::fail('Abriu dois casos para a unidade.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        $closed = $this->collections->addEvent($this->admin, $case['id'], ['to_status' => 'closed', 'note' => 'Débito quitado em juízo.']);
        self::assertSame(['closed', 'Débito quitado em juízo.'], [$closed['status'], $closed['close_reason']]);
        self::assertSame('preparing', $this->collections->openCase($this->admin, ['unit_id' => $this->units[0], 'entry_ids' => [$this->overdue], 'note' => 'Novo caso depois do encerramento.'])['status']);
    }

    public function testScope(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $outsider = $this->user([(int)$this->pdo->lastInsertId()]);
        self::assertSame(0, $this->collections->listCases($outsider)['total']);
        $this->expectException(RegistryNotFound::class);
        $this->collections->recordContact($outsider, ['unit_id' => $this->units[0], 'channel' => 'phone', 'outcome' => 'other', 'note' => 'Fora do escopo.']);
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
