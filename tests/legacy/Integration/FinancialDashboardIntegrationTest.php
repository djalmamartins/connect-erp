<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\FinancialDashboard;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Dashboard com dados reais (ADR 0157): balancete do mês, inadimplência por atraso, quadro, agenda e atividades. */
final class FinancialDashboardIntegrationTest extends TestCase
{
    private const TODAY = '2026-11-10';

    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private FinancialDashboard $dashboard;
    private int $fee;
    private int $water;
    private array $banks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->dashboard = new FinancialDashboard($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->water = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Água'])['id'];
        foreach ([$this->alpha => '11111-1', $this->beta => '22222-2'] as $condo => $number) {
            $this->banks[$condo] = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $condo, 'name' => 'Conta ' . $number, 'bank_code' => '341',
                'branch' => '1234', 'account_number' => $number, 'kind' => 'corrente'])['id'];
        }
    }

    public function testMonthSeriesBucketsAndBoardFollowTheEntries(): void
    {
        $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento']);
        // Alpha, novembro: 1.000 lançado, 600 recebido, 400 vencido (5 dias); despesa 300 paga.
        $this->entry($this->alpha, 'receita', '600,00', '2026-11', '2026-11-05', '2026-11-05');
        $this->entry($this->alpha, 'receita', '400,00', '2026-11', '2026-11-05');
        $this->entry($this->alpha, 'despesa', '300,00', '2026-11', '2026-11-08', '2026-11-08');
        // Alpha, agosto: 200 vencido há mais de 90 dias; outubro fica de fora do mês.
        $this->entry($this->alpha, 'receita', '200,00', '2026-08', '2026-08-01');
        $this->entry($this->alpha, 'receita', '50,00', '2026-10', '2026-12-01');
        // Beta, novembro: 100 a vencer (não é inadimplência).
        $this->entry($this->beta, 'receita', '100,00', '2026-11', '2026-11-20');

        $all = $this->dashboard->finance($this->admin, null, '2026-11', self::TODAY);
        self::assertSame(['Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov'], array_column($all['months'], 'label'));
        self::assertSame([110000, 60000, 30000, 30000], [$all['month']['revenue_cents'], $all['month']['revenue_received_cents'],
            $all['month']['expense_cents'], $all['month']['expense_paid_cents']]);
        self::assertSame(30000, $all['month']['result_cents'], 'Resultado = recebido menos pago.');
        self::assertSame(20000, $all['months'][2]['revenue_cents'], 'Agosto no gráfico.');
        self::assertSame(['1-30' => 40000, '31-60' => 0, '61-90' => 0, '90+' => 20000],
            array_combine(array_column($all['delinquency']['buckets'], 'key'), array_column($all['delinquency']['buckets'], 'amount_cents')));
        self::assertSame(60000, $all['delinquency']['open_cents']);

        $board = array_column($all['condominiums'], null, 'name');
        self::assertSame([1, 60.0, 40.0, 'Crítico'], [$board['Alpha']['units'], $board['Alpha']['received_rate'], $board['Alpha']['delinquency_rate'], $board['Alpha']['status']]);
        self::assertSame([0.0, 'Em dia'], [$board['Beta']['delinquency_rate'], $board['Beta']['status']]);

        $beta = $this->dashboard->finance($this->admin, $this->beta, '2026-11', self::TODAY);
        self::assertSame([10000, 0], [$beta['month']['revenue_cents'], $beta['delinquency']['open_cents']], 'Só o condomínio da barra superior.');
        self::assertSame(['Beta'], array_column($beta['condominiums'], 'name'));
    }

    public function testScopeHidesOtherCondominiums(): void
    {
        $this->entry($this->alpha, 'receita', '600,00', '2026-11', '2026-11-05');
        $outsider = $this->user([$this->beta]);
        $mine = $this->dashboard->finance($outsider, null, '2026-11', self::TODAY);
        self::assertSame([0, ['Beta']], [$mine['month']['revenue_cents'], array_column($mine['condominiums'], 'name')]);
        self::assertSame([], $this->dashboard->activities($outsider, null), 'Ações de outro condomínio não aparecem.');
        self::assertNotSame([], $this->dashboard->activities($this->admin, $this->alpha));
        $this->expectException(RegistryNotFound::class);
        $this->dashboard->finance($outsider, $this->alpha, '2026-11', self::TODAY);
    }

    public function testAgendaListsTheNextThirtyDaysInOrder(): void
    {
        $day = fn (int $n): string => (new \DateTimeImmutable('today'))->modify("{$n} days")->format('Y-m-d');
        $this->service->save($this->admin, 'maintenances', ['condominium_id' => $this->alpha, 'service' => 'Elevador', 'planned_on' => $day(5), 'frequency' => 'anual']);
        $this->service->save($this->admin, 'maintenances', ['condominium_id' => $this->alpha, 'service' => 'Bombas', 'planned_on' => $day(40), 'frequency' => 'anual']);
        $this->service->save($this->admin, 'maintenances', ['condominium_id' => $this->beta, 'service' => 'Portão', 'planned_on' => $day(2), 'frequency' => 'anual']);
        $this->pdo->prepare("INSERT INTO erp_assemblies (condominium_id, kind, mode, title, location, first_call_at, status) VALUES (?, 'ordinaria', 'virtual', 'AGO', 'x', ?, 'convened')")
            ->execute([$this->alpha, $day(3) . ' 19:00:00']);
        $this->pdo->prepare("INSERT INTO erp_assemblies (condominium_id, kind, mode, title, location, first_call_at, status) VALUES (?, 'ordinaria', 'virtual', 'Rascunho', 'x', ?, 'draft')")
            ->execute([$this->alpha, $day(4) . ' 19:00:00']);
        $all = ['assemblies' => true, 'maintenances' => true, 'reservations' => true];

        $alpha = $this->dashboard->agenda($this->admin, $this->alpha, $all);
        self::assertSame(['AGO', 'Manutenção: Elevador'], array_column($alpha, 'title'), 'Rascunho e depois de 30 dias ficam de fora.');
        self::assertSame('19:00', $alpha[0]['time']);
        self::assertSame(['Manutenção: Portão', 'AGO', 'Manutenção: Elevador'], array_column($this->dashboard->agenda($this->admin, null, $all), 'title'));
        self::assertSame(['Manutenção: Elevador'], array_column($this->dashboard->agenda($this->admin, $this->alpha, ['maintenances' => true]), 'title'), 'Sem permissão, o módulo fica de fora.');
    }

    private function entry(int $condo, string $nature, string $amount, string $competence, string $dueOn, ?string $paidOn = null): void
    {
        $this->service->save($this->admin, 'entries', ['condominium_id' => $condo, 'nature' => $nature, 'chart_account_id' => $nature === 'receita' ? $this->fee : $this->water,
            'description' => 'Teste', 'amount_cents' => $amount, 'competence' => $competence, 'due_on' => $dueOn]
            + ($paidOn !== null ? ['paid_on' => $paidOn, 'bank_account_id' => $this->banks[$condo]] : []));
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
