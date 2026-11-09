<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Assemblies;
use Source\Services\Erp\IntegrationStatus;
use Source\Services\Erp\PartnerAccess;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Passada de qualidade dos ciclos 37 a 42 (ADR 0094): escritas fora do escopo, valor vencido mascarado e listas restritas ao escopo. */
final class QualityPassIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['erp_assembly_votes', 'erp_assembly_attendance', 'erp_assembly_items', 'erp_assemblies', 'erp_partner_agency_users', 'erp_partner_agencies'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        $this->admin = $this->user(2, [$this->alpha]);
        $this->outsider = $this->user(2, [$this->beta]);
    }

    public function testAssemblyWritesOutsideScopeAreNotFoundAndDebtIsHiddenByDefault(): void
    {
        $service = new Assemblies($this->pdo);
        $first = new \DateTimeImmutable('+10 days 19:00');
        $id = $service->create($this->admin, ['condominium_id' => $this->alpha, 'kind' => 'ordinaria', 'mode' => 'presencial', 'title' => 'AGO teste',
            'location' => 'Salão', 'first_call_at' => $first->format('Y-m-d H:i'), 'items' => [['title' => 'Contas', 'vote_basis' => 'unit', 'quorum' => 'simple']]])['id'];
        $item = $service->find($this->admin, $id)['items'][0]['id'];
        $calls = [
            'convocar' => fn () => $service->convene($this->outsider, $id),
            'abrir' => fn () => $service->open($this->outsider, $id, $first),
            'presença' => fn () => $service->checkIn($this->outsider, $id, ['unit_id' => 1, 'attendee_name' => 'Fulano']),
            'votação' => fn () => $service->startVoting($this->outsider, $id, $item),
            'voto' => fn () => $service->vote($this->outsider, $id, $item, 1, 'yes'),
            'apuração' => fn () => $service->closeItem($this->outsider, $id, $item),
            'encerrar' => fn () => $service->close($this->outsider, $id),
            'cancelar' => fn () => $service->cancel($this->outsider, $id, 'Tentativa de fora'),
            'presença (relatório)' => fn () => $service->attendanceReport($this->outsider, $id),
            'resultados' => fn () => $service->resultsReport($this->outsider, $id),
        ];
        foreach ($calls as $what => $call) {
            try {
                $call();
                self::fail("Aceitou {$what} fora do escopo.");
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('draft', $service->find($this->admin, $id)['status'], 'Nada mudou.');

        // O edital sai como comunicado e exige ao menos uma unidade ativa (ADR 0088).
        $unit = (new RegistryService($this->pdo))->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '11', 'type' => 'apartamento'])['id'];
        $service->convene($this->admin, $id);
        $service->open($this->admin, $id, $first->modify('+1 minute'));
        $service->checkIn($this->admin, $id, ['unit_id' => $unit, 'attendee_name' => 'Gil'], $first->modify('+2 minutes'));
        self::assertNull($service->attendanceReport($this->admin, $id)['rows'][0]['overdue_cents'], 'Sem erp.agreements.view, sem valor vencido.');
        self::assertSame(0, $service->attendanceReport($this->admin, $id, true)['rows'][0]['overdue_cents']);
    }

    public function testPartnerUnlinkAndListingRespectScope(): void
    {
        $this->pdo->exec("INSERT IGNORE INTO access_roles (name, slug, level, description) VALUES ('Imobiliária parceira', 'erp_partner', 1, 'teste')");
        $global = $this->user(2, [$this->alpha, $this->beta]);
        $service = new PartnerAccess($this->pdo);
        $agency = $service->createAgency($global, ['name' => 'Imob Teste', 'document' => '11.222.333/0001-81'])['id'];
        $partner = $this->user(1, []);
        $service->linkUser($global, $agency, (int)$partner->id);
        $service->grant($global, (int)$partner->id, $this->alpha);
        $service->grant($global, (int)$partner->id, $this->beta);

        $listed = $service->listAgencies($this->admin)[0]['users'][0]['condominium_ids'];
        self::assertSame([$this->alpha], $listed, 'Quem só vê Alpha não vê a concessão de Beta.');
        try {
            $service->unlinkUser($this->admin, (int)$partner->id);
            self::fail('Desvinculou parceiro com concessão fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::addToAssertionCount(1);
        }
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM user_condominium_access WHERE user_id = ' . (int)$partner->id)->fetchColumn(), 'Nada foi removido.');
        $service->unlinkUser($global, (int)$partner->id);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM user_condominium_access WHERE user_id = ' . (int)$partner->id)->fetchColumn());
    }

    public function testIntegrationOutboxCountsOnlyTheScope(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO erp_notification_outbox (condominium_id, event_type, event_key, subject_table, subject_id, payload_json, status)
            VALUES (?, 'announcement.published', ?, 'erp_announcements', 1, '{}', ?)");
        $insert->execute([$this->alpha, 'qa-alpha-1', 'pending']);
        $insert->execute([$this->beta, 'qa-beta-1', 'pending']);
        $insert->execute([$this->beta, 'qa-beta-2', 'failed']);
        $outbox = (new IntegrationStatus($this->pdo))->overview($this->admin)['outbox'];
        self::assertSame(['pending' => 1, 'failed' => 0], $outbox, 'A fila de Beta não aparece para quem só vê Alpha.');
    }

    /** @param list<int> $condominiums */
    private function user(int $level, array $condominiums): User
    {
        $id = $this->createUser(['level' => $level]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        return (new User())->findById($id);
    }
}
