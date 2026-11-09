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

/**
 * Cobrança e multas de ponta a ponta (ADR 0080 e 0066): ocorrência → multa avulsa com a
 * origem → multas pendentes → fila de atendimento → promessa quebrada → caso judicial só com contas
 * da unidade → pagamento reduz o que segue em aberto → andamento até o encerramento, que é final.
 * Nada é enviado e nenhum lançamento é criado pelo caso. No fim, um usuário de outro condomínio não
 * alcança nada.
 */
final class CollectionsCycleIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
    }

    public function testFineDeskPromiseLegalCaseAndScope(): void
    {
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $units = [];
        foreach ([[$this->alpha, '101'], [$this->alpha, '102'], [$this->beta, '201']] as [$condominium, $number]) {
            $units[$number] = $this->service->save($this->admin, 'units', ['condominium_id' => $condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $overdue = fn (int $condominium, string $unit, int $days): int => $this->service->save($this->admin, 'entries', ['condominium_id' => $condominium,
            'nature' => 'receita', 'chart_account_id' => $fee, 'unit_id' => $units[$unit], 'description' => "Taxa {$unit} -{$days}d", 'amount_cents' => '500,00',
            'competence' => date('Y-m', strtotime("-{$days} days")), 'due_on' => date('Y-m-d', strtotime("-{$days} days"))])['id'];
        $old = $overdue($this->alpha, '101', 70);
        $recent = $overdue($this->alpha, '101', 40);
        $betaEntry = $overdue($this->beta, '201', 40);
        $collections = new Collections($this->pdo);
        $shortcuts = new EntryShortcuts($this->pdo);

        // 1. Ocorrência da 102 vira multa com a origem; a reserva de outra unidade não serve de origem.
        $occurrence = $this->service->save($this->admin, 'occurrences', ['condominium_id' => $this->alpha, 'unit_id' => $units['102'], 'subject' => 'Som alto',
            'priority' => 'media', 'occurred_on' => date('Y-m-d'), 'description' => 'Som alto depois das 22h.'])['id'];
        $fineInput = ['condominium_id' => $this->alpha, 'kind' => 'fine', 'description' => 'Multa por barulho', 'chart_account_id' => $fee,
            'competence' => date('Y-m'), 'due_on' => date('Y-m-d', strtotime('+10 days')), 'amount_cents' => '150,00', 'unit_ids' => [$units['102']],
            'reason' => 'Regulamento interno, art. 12'];
        $fine = $shortcuts->createCharge($this->admin, $fineInput + ['occurrence_id' => $occurrence]);
        $area = $this->service->save($this->admin, 'common_areas', ['condominium_id' => $this->alpha, 'name' => 'Salão', 'booking_mode' => 'approval'])['id'];
        $reservation = $this->service->save($this->admin, 'reservations', ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $units['101'],
            'resident_name' => 'Morador 101', 'reserved_on' => date('Y-m-d', strtotime('+5 days')), 'starts_time' => '18:00', 'ends_time' => '22:00'])['id'];
        try {
            $shortcuts->createCharge($this->admin, $fineInput + ['reservation_id' => $reservation]);
            self::fail('Aceitou reserva de outra unidade como origem da multa.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $pending = $collections->pendingFines($this->admin, $this->alpha);
        self::assertSame(['fines' => 1, 'open_cents' => 15000, 'overdue' => 0], $pending['totals']);
        self::assertSame([(int)$fine['id'], $occurrence], [$pending['data'][0]['id'], $pending['data'][0]['occurrence_id']]);

        // 2. Fila: a 101 está em atraso e sem contato. Promessa para hoje; amanhã, quebrada e no topo.
        $desk = $collections->desk($this->admin, $this->alpha);
        self::assertSame([$units['101'], 100000, 1], [$desk['data'][0]['unit_id'], $desk['data'][0]['amount_cents'], $desk['totals']['without_contact']]);
        $contact = $collections->recordContact($this->admin, ['unit_id' => $units['101'], 'channel' => 'phone', 'outcome' => 'promise', 'contact_name' => "Ana\r\nSilva\x07",
            'promise_on' => date('Y-m-d'), 'promise_cents' => '1.000,00', 'note' => 'Prometeu pagar hoje no PIX.']);
        self::assertSame(['Ana Silva', 100000, 100000], [$contact['contact_name'], $contact['open_cents'], $contact['promise_cents']]);
        $tomorrow = $collections->desk($this->admin, $this->alpha, date('Y-m-d', strtotime('+1 day')));
        self::assertSame([$units['101'], true, 1], [$tomorrow['data'][0]['unit_id'], $tomorrow['data'][0]['promise_broken'], $tomorrow['totals']['promise_broken']]);

        // 3. Caso judicial: conta de outro condomínio ou de outra unidade é tratada como inexistente.
        $entriesBefore = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn();
        $outboxBefore = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        foreach ([[$old, $betaEntry], [$old, (int)$fine['entries'][0]['entry_id']], ['x'], []] as $bad) {
            try {
                $collections->openCase($this->admin, ['unit_id' => $units['101'], 'entry_ids' => $bad, 'note' => 'Tentativa com conta errada.']);
                self::fail('Abriu caso com conta indevida: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $case = $collections->openCase($this->admin, ['unit_id' => $units['101'], 'entry_ids' => [$old, (string)$recent, $old], 'note' => 'Dois meses sem pagar.']);
        self::assertSame(['preparing', 100000, 2], [$case['status'], $case['claimed_cents'], count($case['entries'])]);
        self::assertSame('preparing', $collections->desk($this->admin, $this->alpha)['data'][0]['legal_case']['status']);

        // 4. Uma conta paga depois sai do que segue em aberto; o valor cobrado na abertura não muda.
        $this->pdo->prepare('UPDATE erp_entries SET paid_on = CURDATE() WHERE id = ?')->execute([$recent]);
        $found = $collections->findCase($this->admin, (int)$case['id']);
        self::assertSame([100000, 50000], [$found['claimed_cents'], $found['still_open_cents']]);

        // 5. Andamento até o encerramento, que é final. Nada foi enviado nem lançado.
        $collections->addEvent($this->admin, (int)$case['id'], ['to_status' => 'filed', 'case_number' => '1001234-56.2026.8.26.0100', 'note' => 'Ação distribuída.']);
        $closed = $collections->addEvent($this->admin, (int)$case['id'], ['to_status' => 'closed', 'note' => 'Débito quitado em juízo.']);
        self::assertSame(['closed', [], 3], [$closed['status'], $closed['next_statuses'], count($closed['events'])]);
        try {
            $collections->addEvent($this->admin, (int)$case['id'], ['note' => 'Nota depois do fim.']);
            self::fail('Aceitou andamento em caso encerrado.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($entriesBefore, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn(), 'O caso não cria lançamento.');
        self::assertSame($outboxBefore, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(), 'O caso não envia nada.');

        // 6. Usuário só do Beta não alcança nada do Alpha.
        $outsider = $this->user([$this->beta]);
        foreach ([
            fn () => $collections->contacts($outsider, $units['101']),
            fn () => $collections->recordContact($outsider, ['unit_id' => $units['101'], 'channel' => 'phone', 'outcome' => 'other', 'note' => 'Fora do escopo.']),
            fn () => $collections->openCase($outsider, ['unit_id' => $units['101'], 'entry_ids' => [$old], 'note' => 'Fora do escopo.']),
            fn () => $collections->findCase($outsider, (int)$case['id']),
            fn () => $collections->addEvent($outsider, (int)$case['id'], ['note' => 'Fora do escopo.']),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Chamada {$i} alcançou dado de outro condomínio.");
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $collections->pendingFines($outsider, $this->alpha)['totals']['fines']);
        try {
            $collections->desk($outsider, $this->alpha);
            self::fail('A fila de outro condomínio respondeu.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);   // fora do escopo responde como inexistente (404)
        }
        self::assertSame(0, $collections->listCases($outsider, null, ['include_closed' => 1])['total']);
        self::assertSame([$units['201']], array_column($collections->desk($outsider, null)['data'], 'unit_id'), 'Sem condomínio informado, só o escopo dele.');
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
