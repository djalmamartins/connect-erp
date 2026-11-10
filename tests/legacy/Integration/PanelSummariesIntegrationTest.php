<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OperationalDashboard;
use Source\Services\Erp\PanelSummaries;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Painéis do condomínio e da unidade, e a chamada da lista de espera na outbox (ADR 0054). */
final class PanelSummariesIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private int $unit;
    private int $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '102', 'type' => 'apartamento']);
        $this->service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'relationship' => 'owner',
            'name' => 'Ana Proprietária', 'document' => self::validCpf()]);
    }

    public function testCondominiumAndUnitPanels(): void
    {
        $today = date('Y-m-d');
        $past = (new \DateTimeImmutable('today'))->modify('-20 days')->format('Y-m-d');
        $future = (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
        foreach ([[$past, '500,00'], [$future, '500,00']] as [$due, $amount]) {
            $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $this->fee,
                'amount_cents' => $amount, 'competence' => substr($due, 0, 7), 'description' => 'Taxa', 'due_on' => $due, 'unit_id' => $this->unit]);
        }
        // Permissões dos módulos do painel (ADR 0061) liberadas aqui; a recusa é testada abaixo.
        $panels = new PanelSummaries($this->pdo, null, static fn (string $permission, User $user): bool => true);
        $partial = new PanelSummaries($this->pdo, null, static fn (string $permission, User $user): bool => $permission === 'erp.units.view');
        foreach ([fn () => $partial->condominium($this->admin, $this->alpha, $today), fn () => $partial->unit($this->admin, $this->unit, $today)] as $call) {
            try {
                $call();
                self::fail('Painel abriu sem as permissões dos módulos que mostra.');
            } catch (RegistryForbidden) {
                self::addToAssertionCount(1);
            }
        }
        $condo = $panels->condominium($this->admin, $this->alpha, $today);
        self::assertSame(['units' => 2, 'occupied' => 1, 'vacant' => 1, 'rate' => 50.0], $condo['occupancy']);
        self::assertSame([50000, 1], [$condo['finance']['delinquency_cents'], $condo['finance']['delinquent_units']]);
        self::assertArrayHasKey('cards', $condo['operations']);

        $unit = $panels->unit($this->admin, $this->unit, $today);
        self::assertSame('101', $unit['unit']['label']);
        self::assertSame(['Ana Proprietária'], array_column($unit['people'], 'name'));
        self::assertSame([50000, 1], [$unit['finance']['overdue_cents'], count($unit['finance']['upcoming'])]);
        self::assertGreaterThan(0, $unit['finance']['charges_cents'], 'Vencida com encargos.');

        $outsider = $this->user([$this->beta]);
        foreach ([fn () => $panels->condominium($outsider, $this->alpha), fn () => $panels->unit($outsider, $this->unit)] as $call) {
            try {
                $call();
                self::fail('Painel fora do escopo.');
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** ADR 0155: a ficha da pessoa traz os dados do cadastro e só as outras unidades dentro do escopo. */
    public function testPersonCardShowsRecordAndOtherUnitsInScope(): void
    {
        $document = (string)$this->pdo->query("SELECT document FROM erp_residents WHERE name = 'Ana Proprietária'")->fetchColumn();
        $this->pdo->prepare("UPDATE erp_residents SET phone = '11999990000', email = 'ana@example.com', notes = 'Prefere WhatsApp' WHERE document = ?")->execute([$document]);
        $betaUnit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->beta, 'number' => '7', 'type' => 'sala'])['id'];
        $this->service->save($this->admin, 'residents', ['condominium_id' => $this->beta, 'unit_id' => $betaUnit, 'relationship' => 'tenant',
            'name' => 'Ana Proprietária', 'document' => $document]);
        $this->pdo->prepare("UPDATE condominiums SET street = 'Rua das Flores', number = '10', city = 'Campinas', state = 'SP', zip_code = '13000000' WHERE id = ?")->execute([$this->alpha]);
        $panels = new PanelSummaries($this->pdo, null, static fn (string $permission, User $user): bool => true);

        $person = $panels->unit($this->admin, $this->unit)['people'][0];
        self::assertSame([$document, '11999990000', 'ana@example.com', 'Prefere WhatsApp', false], [$person['document'], $person['phone'], $person['email'], $person['notes'], $person['has_portal_account']]);
        self::assertSame([['unit_id' => $betaUnit, 'label' => '7', 'condominium_name' => 'Beta', 'relationship' => 'tenant']], $person['other_units']);
        self::assertSame('Rua das Flores, 10 · unidade 101 · Campinas/SP · CEP 13000-000', $panels->unit($this->admin, $this->unit)['unit']['address']);

        $onlyAlpha = $this->user([$this->alpha]);
        self::assertSame([], $panels->unit($onlyAlpha, $this->unit)['people'][0]['other_units'], 'Unidade de outro condomínio não aparece fora do escopo.');
    }

    public function testWaitlistCallIsRecordedInOutboxOnce(): void
    {
        $date = (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
        $area = $this->service->save($this->admin, 'common_areas', ['condominium_id' => $this->alpha, 'name' => 'Salão', 'booking_mode' => 'approval'])['id'];
        $reservation = $this->service->save($this->admin, 'reservations', ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $this->unit,
            'resident_name' => 'Ana', 'reserved_on' => $date]);
        $this->service->save($this->admin, 'reservations', ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $this->unit,
            'resident_name' => 'Ana', 'reserved_on' => $date, 'status' => 'inactive'], $reservation['id']);
        $this->service->save($this->admin, 'waitlist', ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $this->unit,
            'resident_name' => 'Bia', 'desired_on' => $date]);

        (new OperationalDashboard($this->pdo))->callWaitlist($this->admin, $reservation['id']);
        $rows = $this->pdo->query("SELECT event_type, status, payload_json FROM erp_notification_outbox")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame(['waitlist.called', 'pending'], [$rows[0]['event_type'], $rows[0]['status']], 'Nada é enviado: fica pendente.');
        self::assertSame('Bia', json_decode($rows[0]['payload_json'], true)['resident_name']);
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
