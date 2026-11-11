<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AnnualAccountability;
use Source\Services\Erp\ChartOpenings;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\PatrimonialEntries;

/** ADR 0182: prestação de contas anual. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). */
final class AnnualAccountabilityTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private PDO $pdo;
    private AnnualAccountability $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER,
                opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER,
                transferred_on TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT,
                updated_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE (condominium_id, chart_account_id))',
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_date TEXT, description TEXT, amount_cents INTEGER, debit_account_id INTEGER,
                credit_account_id INTEGER, bank_account_id INTEGER, linked_entry_id INTEGER, import_id INTEGER, status TEXT DEFAULT 'active', reversal_of_id INTEGER UNIQUE,
                reversal_reason TEXT, created_by INTEGER)",
            'CREATE TABLE erp_budget_lines (id INTEGER PRIMARY KEY, condominium_id INTEGER, year INTEGER, chart_account_id INTEGER, month INTEGER, amount_cents INTEGER)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (4, NULL, '2.01', 'Energia', 'despesa', 'analitica'),
            (70, NULL, '7', 'Ativo', 'asset', 'sintetica'), (71, NULL, '7.1', 'Disponível', 'asset', 'sintetica'), (72, NULL, '7.2', 'Contas a receber', 'asset', 'analitica'),
            (80, NULL, '8', 'Passivo', 'liability', 'sintetica'), (81, NULL, '8.1', 'Contas a pagar', 'liability', 'analitica'),
            (82, NULL, '8.2', 'Fundo de reserva', 'liability', 'analitica'),
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 100000, '2025-01-01')");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, unit_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on) VALUES
            (1, NULL, 'receita', 2, 10, 'Taxa set', 300000, '2026-09', '2026-09-10', '2026-09-10'),
            (1, 12, 'receita', 2, NULL, 'Taxa out 102', 50000, '2026-10', '2026-10-10', NULL),
            (1, NULL, 'despesa', 4, 10, 'Energia out', 70000, '2026-10', '2026-10-20', '2026-10-20'),
            (1, NULL, 'receita', 2, 10, 'Taxa 2025', 999, '2025-12', '2025-12-10', '2025-12-10'),
            (3, NULL, 'receita', 2, NULL, 'Fora', 5000, '2026-10', '2026-10-10', '2026-10-10')");
        $this->pdo->exec("INSERT INTO erp_budget_lines (condominium_id, year, chart_account_id, month, amount_cents) VALUES
            (1, 2026, 2, 9, 250000), (1, 2026, 2, 10, 250000), (1, 2026, 4, 10, 60000), (1, 2025, 2, 12, 1)");
        $scope = new CondominiumScope($this->pdo);
        (new ChartOpenings($this->pdo, $scope))->set($this->user(), 1, 91, '2025-01-01', 100000);
        (new PatrimonialEntries($this->pdo, $scope))->create($this->user(), ['condominium_id' => 1, 'entry_date' => '2026-09-10', 'description' => 'Apropriação ao fundo',
            'amount_cents' => 30000, 'debit_account_id' => 92, 'credit_account_id' => 82], self::TODAY);
        // Fecha setembro depois de lançar (o mês fechado recusa lançamento, como deve).
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-09', 'closed'), (1, '2026-10', 'reopened'), (3, '2026-10', 'closed')");
        $delinquency = static fn (User $u, int $c, string $asOf): array => ['as_of' => $asOf, 'units' => [['unit_id' => 12, 'unit_label' => '102', 'amount_cents' => 50000,
            'oldest_due_on' => '2026-10-10', 'max_days_late' => 32]], 'totals' => ['amount_cents' => 50000]];
        $this->service = new AnnualAccountability($this->pdo, $scope, $delinquency);
    }

    public function testYearPackage(): void
    {
        $pack = $this->service->build($this->user(), 1, '2026', self::TODAY);
        self::assertSame([2026, '2026-01-01', '2026-12-31', '2026-11-11'], [$pack['year'], $pack['from'], $pack['to'], $pack['as_of']], 'Ano corrente: corte em hoje.');
        self::assertSame([350000, 70000, 280000], [$pack['summary']['revenue_cents'], $pack['summary']['expense_cents'], $pack['summary']['result_cents']],
            'DRE do ano por competência: a taxa em aberto entra; 2025 e outro condomínio não.');
        self::assertSame(0, $pack['balance_sheet']['diferenca'], 'O Balanço fecha com o saldo inicial do PL.');
        self::assertSame(100000 + 999 + 300000 - 70000, $pack['summary']['cash_cents'], 'Inclui a taxa de 2025, que entrou no banco.');
        self::assertSame([['8.2', 0, 30000, 0, 30000]], array_map(static fn (array $f): array => [$f['code'], $f['opening_cents'], $f['in_cents'], $f['out_cents'],
            $f['closing_cents']], $pack['funds']['accounts']), 'Fundo de reserva no ano.');
        $budget = array_column($pack['budget']['rows'], null, 'code');
        self::assertSame([500000, 350000, -150000, -30.0], [$budget['1.01']['budget_cents'], $budget['1.01']['actual_cents'], $budget['1.01']['difference_cents'],
            $budget['1.01']['percent']], 'Orçamento do ano inteiro.');
        self::assertSame([60000, 70000, 10000], [$budget['2.01']['budget_cents'], $budget['2.01']['actual_cents'], $budget['2.01']['difference_cents']]);
        self::assertCount(11, $pack['months']['expected']);
        self::assertSame(['2026-09'], $pack['months']['closed'], 'Reaberto não conta como fechado; outro condomínio não entra.');
        self::assertSame([1, 11], [$pack['summary']['months_closed'], $pack['summary']['months_expected']]);
        self::assertSame(['months_open', 'delinquency'], array_column($pack['warnings'], 'code'));
        self::assertSame('102', $pack['delinquency']['units'][0]['unit_label']);

        $past = $this->service->build($this->user(), 1, 2025, self::TODAY);
        self::assertSame(['2025-12-31', 12, 999], [$past['as_of'], count($past['months']['expected']), $past['summary']['revenue_cents']], 'Ano fechado: corte em 31/12.');

        $table = AnnualAccountability::csvTable($pack);
        $sections = array_values(array_unique(array_column($table['rows'], 0)));
        self::assertSame(['Resumo', 'DRE', 'Balanço: Ativo', 'Balanço: Passivo', 'Balanço: Patrimônio Líquido', 'Balanço', 'Fundos', 'Orçamento (ano)', 'Inadimplência', 'Avisos'], $sections);
        self::assertContains(['Resumo', '', 'Meses fechados', '', '', '', '1 de 11'], $table['rows']);
        self::assertContains(['Orçamento (ano)', '1.01', 'Taxa condominial', '5000,00', '3500,00', '', '-1500,00'], $table['rows']);
        self::assertStringStartsWith("\xEF\xBB\xBF", CollectionReports::csv($table));
    }

    public function testScopeValidationAndSafeCsv(): void
    {
        try {
            $this->service->build($this->user(), 3, 2026, self::TODAY);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[null, 2026], [1, 1999], [1, 'abc'], [1, 2027]] as [$condominium, $year]) {
            try {
                $this->service->build($this->user(), $condominium, $year, self::TODAY);
                self::fail('Entrada inválida passou: ' . json_encode([$condominium, $year]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $hostile = AnnualAccountability::csvTable(['summary' => [], 'truncated' => true,
            'income_statement' => ['rows' => [['code' => '=1', 'name' => '=HYPERLINK("x")', 'level' => 1, 'amount_cents' => 1]]],
            'balance_sheet' => ['assets' => ['rows' => [['code' => '7', 'name' => '+ativo', 'level' => 1, 'amount_cents' => 1]]], 'diferenca' => 0],
            'funds' => ['accounts' => [['code' => '8.2', 'name' => '@fundo', 'opening_cents' => 0, 'in_cents' => 0, 'out_cents' => 0, 'closing_cents' => 0]]],
            'budget' => ['rows' => [['code' => '1.01', 'name' => '-taxa', 'budget_cents' => 0, 'actual_cents' => 0, 'difference_cents' => 0]]],
            'delinquency' => ['units' => []], 'warnings' => [['message' => '=aviso']]]);
        foreach ($hostile['rows'] as $row) {
            foreach ([1, 2] as $col) {
                self::assertDoesNotMatchRegularExpression('/^\s*[=+\-@]/', $row[$col], json_encode($row));
            }
        }
        self::assertSame('Relatório parcial: algum bloco passou do limite de linhas.', end($hostile['rows'])[2]);
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/AnnualAccountability.php');
        foreach (['(new ReportExportLimit())->assertAllowed($user)', "Audit::record('export'", "'report' => 'annual-accountability'", 'X-Content-Type-Options: nosniff',
            "Access::can('erp.chart.view', \$user)", 'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
