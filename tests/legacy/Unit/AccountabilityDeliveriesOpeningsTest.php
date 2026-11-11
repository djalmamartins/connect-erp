<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Accountability;
use Source\Services\Erp\ChartOpenings;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\DeliveryLog;

/** ADR 0166: prestação de contas mensal (AP-101), registros de entregas (AP-136) e saldo inicial do plano (AP-161). */
final class AccountabilityDeliveriesOpeningsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER, transferred_on TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT, revenue_cents INTEGER, expense_cents INTEGER,
                open_revenue_cents INTEGER, open_expense_cents INTEGER, summary_json TEXT, closed_by INTEGER, closed_at TEXT, reopened_by INTEGER, reopened_at TEXT, reopen_reason TEXT)',
            "CREATE TABLE erp_deliveries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, recipient TEXT, carrier TEXT, tracking_code TEXT, received_on TEXT,
                received_time TEXT, picked_up_on TEXT, picked_up_by TEXT, gatehouse_id INTEGER, notes TEXT, status TEXT DEFAULT 'active', created_by INTEGER, updated_by INTEGER, deleted_at TEXT)",
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT,
                updated_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE (condominium_id, chart_account_id))',
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_date TEXT, description TEXT, amount_cents INTEGER, debit_account_id INTEGER,
                credit_account_id INTEGER, bank_account_id INTEGER, linked_entry_id INTEGER, import_id INTEGER, status TEXT DEFAULT 'active', reversal_of_id INTEGER, created_by INTEGER)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Bosque'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label) VALUES (11, 1, '101'), (12, 1, '102'), (21, 2, 'Sala 1'), (31, 3, '301')");
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (4, NULL, '2.01', 'Energia', 'despesa', 'analitica'),
            (5, NULL, '2.02', 'Água', 'despesa', 'analitica'), (6, 3, '2.09', 'Do condomínio 3', 'despesa', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind, status) VALUES (7, NULL, '2.03', 'Inativa', 'despesa', 'analitica', 'inactive')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 50000, '2026-01-01')");
        $insert = $this->pdo->prepare('INSERT INTO erp_entries (condominium_id, unit_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 11, 'receita', 2, 10, 'Taxa 101 out', 100000, '2026-10', '2026-10-10', '2026-10-12'],
            [1, 12, 'receita', 2, null, 'Taxa 102 out', 100000, '2026-10', '2026-10-10', null],         // em aberto
            [1, null, 'despesa', 4, 10, 'Energia out', 30000, '2026-10', '2026-10-20', '2026-10-20'],
            [3, 31, 'receita', 2, null, 'Fora', 999999, '2026-10', '2026-10-10', '2026-10-10'],
        ] as $row) {
            $insert->execute($row);
        }
        $this->pdo->exec("INSERT INTO erp_deliveries (id, condominium_id, unit_id, recipient, carrier, received_on, status, picked_up_on, picked_up_by, created_by) VALUES
            (1, 1, 11, 'Ana', 'correios', '2026-10-01', 'active', NULL, NULL, 7),
            (2, 1, 12, 'Bruno', 'amazon', '2026-10-02', 'picked_up', '2026-10-05', 'Bruno', 7),
            (3, 1, 11, 'Carla', 'outra', '2026-10-03', 'inactive', NULL, NULL, 7),
            (4, 2, 21, 'Dora', 'correios', '2026-10-04', 'active', NULL, NULL, 7),
            (5, 3, 31, 'Fora', 'correios', '2026-10-04', 'active', NULL, NULL, 7)");
    }

    public function testAccountabilityMonthPackage(): void
    {
        $calls = [];
        $delinquency = static function (User $user, int $condominiumId, string $asOf) use (&$calls): array {
            $calls[] = [$condominiumId, $asOf];
            return ['as_of' => $asOf, 'units' => [['condominium_id' => 1, 'condominium_name' => 'Aurora', 'unit_id' => 12, 'unit_label' => '102', 'entries' => [['id' => 2]],
                'amount_cents' => 100000, 'charges_cents' => 2000, 'total_cents' => 102000, 'oldest_due_on' => '2026-10-10', 'max_days_late' => 21]],
                'totals' => ['units' => 1, 'entries' => 1, 'amount_cents' => 100000, 'charges_cents' => 2000, 'total_cents' => 102000, 'buckets' => ['1-30' => 100000]]];
        };
        $service = new Accountability($this->pdo, new CondominiumScope($this->pdo), $delinquency);
        $pack = $service->build($this->user(), 1, '2026-10');
        self::assertSame(['2026-10', '2026-10-01', '2026-10-31'], [$pack['competence'], $pack['from'], $pack['to']]);
        self::assertSame([[1, '2026-10-31']], $calls, 'Inadimplência no último dia do mês.');
        self::assertSame('open', $pack['closing']['status']);
        self::assertSame(200000, $pack['summary']['revenue_cents'], 'DRE por competência: a taxa em aberto entra.');
        self::assertSame([30000, 170000], [$pack['summary']['expense_cents'], $pack['summary']['result_cents']]);
        self::assertSame([100000, 30000, 50000, 120000], [$pack['summary']['cash_in_cents'], $pack['summary']['cash_out_cents'],
            $pack['summary']['bank_opening_cents'], $pack['summary']['bank_closing_cents']], 'Banco por caixa.');
        self::assertSame(100000, $pack['summary']['open_revenue_cents'], 'Do balancete da competência.');
        self::assertSame(['month_open', 'open_entries', 'delinquency'], array_column($pack['warnings'], 'code'));
        self::assertSame('102', $pack['delinquency']['units'][0]['unit_label']);
        self::assertArrayNotHasKey('entries_detail', $pack['delinquency']['units'][0]);
        self::assertFalse($pack['truncated']);
        self::assertSame(['accounts' => [], 'totals' => ['opening_cents' => 0, 'in_cents' => 0, 'out_cents' => 0, 'closing_cents' => 0]], $pack['funds'],
            'Sem conta de fundo no plano: seção vazia (ADR 0177).');

        // CSV do pacote (ADR 0178): uma tabela com a seção em cada linha, valores em reais.
        $table = Accountability::csvTable($pack);
        self::assertSame(['Seção', 'Código', 'Item', 'Saldo anterior', 'Entradas', 'Saídas', 'Valor ou saldo final'], $table['header']);
        $sections = array_unique(array_column($table['rows'], 0));
        self::assertSame(['Resumo', 'Fechamento', 'DRE', 'Bancos', 'Inadimplência', 'Avisos'], array_values($sections));
        $summary = array_column(array_filter($table['rows'], static fn (array $r): bool => $r[0] === 'Resumo'), 6, 2);
        self::assertSame(['2000,00', '300,00', '1700,00'], [$summary['Receitas'], $summary['Despesas'], $summary['Resultado']]);
        self::assertContains(['Bancos', '', 'Movimento', '500,00', '1000,00', '300,00', '1200,00'], $table['rows']);
        self::assertContains(['Inadimplência', '', 'Unidade 102 (21 dias)', '', '', '', '1000,00'], $table['rows']);
        $csv = CollectionReports::csv($table);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('Mês 2026-10: Aberto', $csv);
        self::assertStringContainsString("\r\n", $csv);

        // Texto que vem do cadastro passa pela neutralização de fórmula em todas as seções.
        $evil = '=HYPERLINK("http://x")';
        $hostile = Accountability::csvTable(['competence' => '2026-10', 'summary' => [], 'closing' => ['status' => 'closed'], 'truncated' => true,
            'income_statement' => ['rows' => [['code' => '1.01', 'name' => $evil, 'level' => 2, 'amount_cents' => 100]]],
            'bank_accounts' => ['accounts' => [['name' => '+banco', 'opening_cents' => 0, 'received_cents' => 0, 'paid_cents' => 0, 'closing_cents' => 0]]],
            'funds' => ['accounts' => [['code' => '8.2', 'name' => '@fundo', 'opening_cents' => 0, 'in_cents' => 0, 'out_cents' => 0, 'closing_cents' => 0,
                'movements' => [['on_date' => '2026-10-05', 'description' => '-1+2', 'in_cents' => 5, 'out_cents' => 0, 'balance_cents' => 5]]]]],
            'delinquency' => ['units' => [['unit_label' => '=1', 'max_days_late' => 3, 'amount_cents' => 1]]],
            'warnings' => [['message' => '=aviso']]]);
        foreach ($hostile['rows'] as $row) {
            foreach ([1, 2] as $col) {
                self::assertDoesNotMatchRegularExpression('/^\s*[=+\-@]/', $row[$col], json_encode($row));
            }
        }
        self::assertContains(['Fundos', '', '  05/10/2026 -1+2', '', '0,05', '0,00', '0,05'], $hostile['rows'], 'Movimento com data e indentação (começa com espaço, não com sinal).');
        self::assertSame('Relatório parcial: algum bloco passou do limite de linhas.', end($hostile['rows'])[2]);
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/Accountability.php');
        foreach (['(new ReportExportLimit())->assertAllowed($user)', "Audit::record('export'", "'report' => 'accountability'", 'X-Content-Type-Options: nosniff',
            "Access::can('erp.entries.view', \$user)", 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }

        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status, revenue_cents, expense_cents, open_revenue_cents, open_expense_cents, closed_by, closed_at)
            VALUES (1, '2026-10', 'closed', 0, 0, 0, 0, 7, '2026-11-02 10:00:00')");
        $closed = $service->build($this->user(), 1, '2026-10');
        self::assertSame(['closed', '2026-11-02 10:00:00'], [$closed['closing']['status'], $closed['closing']['closed_at']]);
        self::assertNotContains('month_open', array_column($closed['warnings'], 'code'));

        $clean = Accountability::assemble(1, '2026-10', '2026-10-01', '2026-10-31', ['status' => 'closed'], ['rows' => [], 'totals' => []], ['totals' => []], [], []);
        self::assertSame([], $clean['warnings'], 'Sem pendência, sem aviso.');
        $many = array_map(static fn (int $i): array => ['unit_id' => $i, 'unit_label' => (string)$i, 'amount_cents' => $i, 'oldest_due_on' => '2026-01-01', 'max_days_late' => 1],
            range(1, Accountability::MAX_UNITS + 1));
        $big = Accountability::assemble(1, '2026-10', '2026-10-01', '2026-10-31', [], [], [], [], ['units' => $many, 'totals' => ['amount_cents' => 1]]);
        self::assertTrue($big['truncated']);
        self::assertCount(Accountability::MAX_UNITS, $big['delinquency']['units']);
        self::assertSame(Accountability::MAX_UNITS + 1, $big['delinquency']['units'][0]['amount_cents'], 'Maior dívida primeiro.');
    }

    public function testAccountabilityScopeAndValidation(): void
    {
        $service = new Accountability($this->pdo, new CondominiumScope($this->pdo), static fn (): array => ['units' => [], 'totals' => []]);
        try {
            $service->build($this->user(), 3, '2026-10');
            self::fail('Respondeu fora do escopo.');
        } catch (CondominiumAccessDenied) {   // 404
            self::addToAssertionCount(1);
        }
        foreach ([[null, '2026-10'], [1, '2026-13'], [1, '10/2026'], [1, null]] as [$condominium, $month]) {
            try {
                $service->build($this->user(), $condominium, $month);
                self::fail('Aceitou ' . var_export([$condominium, $month], true));
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/Accountability.php');
        foreach (["'erp.entries.view'", "'erp.chart.view'", 'catch (CondominiumAccessDenied)', 'JsonResponse::send(422'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
    }

    public function testDeliveryLogFiltersSummaryAndScope(): void
    {
        $service = new DeliveryLog($this->pdo, new CondominiumScope($this->pdo));
        $all = $service->log($this->user(), null, [], '2026-10-11');
        self::assertSame([4, 3, 2, 1], array_column($all['rows'], 'id'), 'Todos do escopo (1 e 2), nunca o 3; mais recente primeiro.');
        self::assertSame(['waiting' => 2, 'picked_up' => 1, 'cancelled' => 1, 'average_pickup_days' => 3.0, 'oldest_waiting_days' => 10], $all['summary']);
        self::assertSame([10, 7], [$all['rows'][3]['waiting_days'], $all['rows'][3]['received_by_user_id']]);
        self::assertNull($all['rows'][2]['waiting_days']);
        $waiting = $service->log($this->user(), 1, ['status' => 'active'], '2026-10-11');
        self::assertSame([1], array_column($waiting['rows'], 'id'));
        self::assertSame([2], array_column($service->log($this->user(), 1, ['from' => '2026-10-02', 'to' => '2026-10-02'])['rows'], 'id'));
        self::assertSame([3, 1], array_column($service->log($this->user(), 1, ['unit_id' => '11'])['rows'], 'id'));
        self::assertFalse($all['truncated']);
        try {
            $service->log($this->user(), 3, []);
            self::fail('Respondeu fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::addToAssertionCount(1);
        }
        foreach ([['status' => 'perdida'], ['from' => '2026-10-31', 'to' => '2026-10-01'], ['from' => '2026-10-01']] as $bad) {
            try {
                DeliveryLog::filters($bad);
                self::fail('Aceitou ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDeliveryPickUp(): void
    {
        $service = new DeliveryLog($this->pdo, new CondominiumScope($this->pdo));
        $done = $service->pickUp($this->user(), 1, "  Ana\nSilva ", '2026-10-03', '2026-10-11');
        self::assertSame(['status' => 'picked_up', 'picked_up_on' => '2026-10-03', 'picked_up_by' => 'Ana Silva'], array_intersect_key($done, array_flip(['status', 'picked_up_on', 'picked_up_by'])));
        $row = $this->pdo->query('SELECT status, picked_up_on, picked_up_by, updated_by FROM erp_deliveries WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['picked_up', '2026-10-03', 'Ana Silva', 7], [$row['status'], $row['picked_up_on'], $row['picked_up_by'], (int)$row['updated_by']]);
        self::assertSame('2026-10-11', $service->pickUp($this->user(), 4, 'Dora', null, '2026-10-11')['picked_up_on'], 'Sem data = hoje.');
        foreach ([1, 2, 3] as $notWaiting) {
            try {
                $service->pickUp($this->user(), $notWaiting, 'Alguém', null, '2026-10-11');
                self::fail("Baixou a entrega {$notWaiting} de novo.");
            } catch (DomainException) {   // 409
                self::addToAssertionCount(1);
            }
        }
        foreach ([5, 999] as $outside) {
            try {
                $service->pickUp($this->user(), $outside, 'Alguém', null, '2026-10-11');
                self::fail('Baixou fora do escopo.');
            } catch (CondominiumAccessDenied) {   // 404, sem dizer se existe
                self::addToAssertionCount(1);
            }
        }
        $this->pdo->exec("INSERT INTO erp_deliveries (id, condominium_id, unit_id, recipient, carrier, received_on, status) VALUES (6, 1, 11, 'Eva', 'correios', '2026-10-05', 'active')");
        foreach ([['A', null], ['', null], ['Eva', '2026-10-04'], ['Eva', '2026-10-12'], ['Eva', '05/10/2026']] as [$by, $on]) {
            try {
                $service->pickUp($this->user(), 6, $by, $on, '2026-10-11');
                self::fail('Aceitou ' . json_encode([$by, $on]));
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/DeliveryLog.php');
        self::assertStringContainsString("Audit::record('update', 'erp_deliveries'", $source);
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/DeliveryLog.php');
        foreach (["'erp.deliveries.view'", "'erp.deliveries.manage'", 'catch (CondominiumAccessDenied)', 'JsonResponse::send(409'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
    }

    public function testChartOpeningsAnalyticOnlyAndRollup(): void
    {
        $tree = ChartOpenings::tree([
            ['chart_account_id' => 4, 'code' => '2.01', 'name' => 'Energia', 'nature' => 'despesa', 'opened_on' => '2026-01-01', 'amount_cents' => 500, 'notes' => null],
            ['chart_account_id' => 2, 'code' => '1.01', 'name' => 'Taxa', 'nature' => 'receita', 'opened_on' => '2026-01-01', 'amount_cents' => 1000, 'notes' => 'x'],
            ['chart_account_id' => 5, 'code' => '2.02', 'name' => 'Água', 'nature' => 'despesa', 'opened_on' => '2026-01-01', 'amount_cents' => -200, 'notes' => null],
        ], ['1' => 'Receitas', '2' => 'Despesas']);
        self::assertSame(['1', '1.01', '2', '2.01', '2.02'], array_column($tree['rows'], 'code'));
        self::assertSame([1000, 1000, 300, 500, -200], array_column($tree['rows'], 'amount_cents'), 'Sintética = soma das analíticas.');
        self::assertSame([false, true], [$tree['rows'][2]['analytic'], $tree['rows'][3]['analytic']]);
        self::assertSame([null, 4], [$tree['rows'][2]['chart_account_id'], $tree['rows'][3]['chart_account_id']]);
        self::assertSame(['revenue_cents' => 1000, 'expense_cents' => 300, 'asset_cents' => 0, 'liability_cents' => 0, 'equity_cents' => 0, 'total_cents' => 1300], $tree['totals']);

        $service = new ChartOpenings($this->pdo, new CondominiumScope($this->pdo));
        $service->set($this->user(), 1, 4, '2026-01-01', '1.234,56', ' saldo de abertura ');
        $list = $service->set($this->user(), 1, 4, '2026-01-02', '-10,00');
        self::assertSame(['2', '2.01'], array_column($list['rows'], 'code'), 'Atualiza a mesma conta, sem duplicar.');
        self::assertSame([-1000, '2026-01-02', 'Despesas'], [$list['rows'][1]['amount_cents'], $list['rows'][1]['opened_on'], $list['rows'][0]['name']]);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_chart_account_openings')->fetchColumn());
        self::assertSame([], $service->list($this->user(), 2)['rows'], 'Saldo é por condomínio.');

        foreach ([[3, '2026-01-01', '1,00', 'sintética'], [6, '2026-01-01', '1,00', 'conta de outro condomínio'], [7, '2026-01-01', '1,00', 'inativa'],
            [4, '2099-01-01', '1,00', 'data futura'], [4, '01/01/2026', '1,00', 'data'], [4, '2026-01-01', 'abc', 'valor']] as [$account, $date, $amount, $what]) {
            try {
                $service->set($this->user(), 1, $account, $date, $amount);
                self::fail("Aceitou {$what}.");
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        foreach ([fn () => $service->set($this->user(), 3, 6, '2026-01-01', '1,00'), fn () => $service->list($this->user(), 3)] as $call) {
            try {
                $call();
                self::fail('Respondeu fora do escopo.');
            } catch (CondominiumAccessDenied) {   // 404
                self::addToAssertionCount(1);
            }
        }
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/ChartOpenings.php');
        foreach (["'erp.settings.view'", "'erp.settings.manage'", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString("Audit::record(\$old === null ? 'create' : 'update', 'erp_chart_account_openings'",
            (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/ChartOpenings.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
