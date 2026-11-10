<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AccountingReports;
use Source\Services\Erp\BalanceSheet;
use Source\Services\Erp\CollectionReports;

/** ADR 0167: Balanço Patrimonial (AP-081) e o saldo inicial do plano (AP-161) como abertura do Livro Razão. */
final class BalanceSheetTest extends TestCase
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
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER, transferred_on TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT, updated_by INTEGER)',
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_date TEXT, description TEXT, amount_cents INTEGER, debit_account_id INTEGER,
                credit_account_id INTEGER, bank_account_id INTEGER, linked_entry_id INTEGER, status TEXT DEFAULT 'active', reversal_of_id INTEGER UNIQUE, reversal_reason TEXT, created_by INTEGER)",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Bosque'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        // Plano padrão: receitas e despesas (1 e 2) e as patrimoniais da migration 20261109_0704 (7, 8 e 9).
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (4, NULL, '2.01', 'Energia', 'despesa', 'analitica'),
            (70, NULL, '7', 'Ativo', 'asset', 'sintetica'), (71, NULL, '7.1', 'Disponível', 'asset', 'sintetica'), (72, NULL, '7.2', 'Contas a receber', 'asset', 'analitica'),
            (80, NULL, '8', 'Passivo', 'liability', 'sintetica'), (81, NULL, '8.1', 'Contas a pagar', 'liability', 'analitica'),
            (82, NULL, '8.2', 'Fundo de reserva', 'liability', 'analitica'), (83, NULL, '8.3', 'Outros fundos', 'liability', 'analitica'),
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica'),
            (99, 3, '8.4', 'Fundo do condomínio 3', 'liability', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 50000, '2026-01-01'), (30, 3, 'Fora', 1, NULL)");
        $insert = $this->pdo->prepare('INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 'receita', 2, 10, 'Taxa dez/25', 100000, '2025-12', '2025-12-10', '2025-12-10'],   // ano anterior
            [1, 'receita', 2, 10, 'Taxa out paga', 100000, '2026-10', '2026-10-10', '2026-10-12'],
            [1, 'receita', 2, null, 'Taxa out vencida', 100000, '2026-10', '2026-10-10', null],
            [1, 'receita', 2, null, 'Taxa out a vencer', 100000, '2026-10', '2026-11-10', null],
            [1, 'despesa', 4, 10, 'Energia paga', 30000, '2026-10', '2026-10-20', '2026-10-20'],
            [1, 'despesa', 4, null, 'Energia a pagar', 20000, '2026-10', '2026-11-05', null],
            [1, 'receita', 2, null, 'Taxa nov (depois da data)', 100000, '2026-11', '2026-11-10', null],
            [3, 'receita', 2, 30, 'Fora', 999999, '2026-10', '2026-10-10', '2026-10-10'],
        ] as $row) {
            $insert->execute($row);
        }
    }

    public function testBalanceSheetWithDifferenceThenBalanced(): void
    {
        $service = new BalanceSheet($this->pdo, new CondominiumScope($this->pdo));
        $sheet = $service->build($this->user(), 1, '2026-10-31');
        self::assertSame([220000], array_column($sheet['assets']['rows'][1]['lines'], 'amount_cents'), 'Disponível = saldo bancário na data.');
        $byCode = static fn (array $group): array => array_column($group['rows'], 'amount_cents', 'code');
        self::assertSame(['7' => 420000, '7.1' => 220000, '7.2' => 200000], $byCode($sheet['assets']));
        self::assertSame(100000, $sheet['assets']['rows'][2]['overdue_cents'], 'Vencidas dentro de contas a receber.');
        self::assertSame(['8' => 20000, '8.1' => 20000, '8.2' => 0, '8.3' => 0], $byCode($sheet['liabilities']), 'A conta 8.4 do condomínio 3 não aparece.');
        self::assertSame(['9' => 350000, '9.1' => 100000, '9.2' => 250000], $byCode($sheet['equity']), 'Exercício = 2026; acumulados = 2025.');
        self::assertSame(50000, $sheet['diferenca'], 'O saldo inicial do banco ainda não está no PL.');
        self::assertFalse($sheet['balanced']);
        self::assertContains('unbalanced', array_column($sheet['warnings'], 'code'));

        // Saldo inicial do PL (AP-161) igual ao saldo inicial do banco: fecha.
        $this->opening(91, '2025-01-01', 50000);
        $closed = $service->build($this->user(), 1, '2026-10-31');
        self::assertSame([0, true, 420000], [$closed['diferenca'], $closed['balanced'], $closed['totals']['liabilities_and_equity_cents']]);
        self::assertSame([], $closed['warnings']);

        // Fundo com saldo inicial entra no passivo; saldo com data depois do balanço não; saldo em conta calculada é ignorado com aviso.
        $this->opening(82, '2026-01-01', 10000);
        $this->opening(83, '2026-11-15', 5000);
        $this->opening(72, '2026-01-01', 7777);
        $funds = $service->build($this->user(), 1, '2026-10-31');
        self::assertSame(['8' => 30000, '8.1' => 20000, '8.2' => 10000, '8.3' => 0], $byCode($funds['liabilities']));
        self::assertSame(200000, $funds['assets']['rows'][2]['amount_cents'], 'Contas a receber continuam calculadas.');
        self::assertSame(-10000, $funds['diferenca']);
        self::assertSame(['opening_ignored', 'unbalanced'], array_column($funds['warnings'], 'code'));
        self::assertFalse($funds['truncated']);
    }

    public function testYearTurnMovesResultIntoAccumulated(): void
    {
        $this->opening(91, '2025-01-01', 50000);
        $service = new BalanceSheet($this->pdo, new CondominiumScope($this->pdo));
        $next = $service->build($this->user(), 1, '2027-01-15');
        $equity = array_column($next['equity']['rows'], 'amount_cents', 'code');
        // 50.000 do saldo do PL + 100.000 de 2025 + 350.000 de 2026 (400.000 de receitas − 50.000 de despesas).
        self::assertSame([0, 500000], [$equity['9.2'], $equity['9.1']], 'Na virada, o resultado de 2026 entra nos acumulados (virtual).');
        self::assertSame('2027-01-01', $next['year_start']);
        self::assertSame(0, $next['diferenca']);
        self::assertSame(300000, $next['assets']['rows'][2]['overdue_cents'], 'Em 2027 as três receitas em aberto já venceram.');

        // Saldo do PL com data dentro do ano: substitui o histórico anterior e o exercício começa no mês dele.
        $this->pdo->exec('DELETE FROM erp_chart_account_openings');
        $this->opening(91, '2026-07-01', 1000);
        $mid = array_column($service->build($this->user(), 1, '2026-10-31')['equity']['rows'], 'amount_cents', 'code');
        self::assertSame([1000, 250000], [$mid['9.1'], $mid['9.2']]);
    }

    public function testScopeValidationCsvAndController(): void
    {
        $service = new BalanceSheet($this->pdo, new CondominiumScope($this->pdo));
        try {
            $service->build($this->user(), 3, '2026-10-31');
            self::fail('Respondeu fora do escopo.');
        } catch (CondominiumAccessDenied) {   // 404
            self::addToAssertionCount(1);
        }
        foreach ([[null, '2026-10-31'], [1, '2026-02-30'], [1, '31/10/2026'], [1, '1999-12-31'], [1, null]] as [$condominium, $date]) {
            try {
                $service->build($this->user(), $condominium, $date);
                self::fail('Aceitou ' . json_encode([$condominium, $date]));
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        $pure = BalanceSheet::compose([['code' => '8.3', 'name' => '=HYPERLINK("x")', 'nature' => 'liability', 'kind' => 'analitica', 'opening_cents' => 500],
            ['code' => '1.01', 'name' => 'Receita ignorada', 'nature' => 'receita', 'kind' => 'analitica', 'opening_cents' => 999]],
            ['banks' => [['name' => 'Banco', 'closing_cents' => 500]]], '2026-10-31');
        self::assertSame([0, true], [$pure['diferenca'], $pure['balanced']], 'Só naturezas patrimoniais entram.');
        self::assertSame(['7', '7.1', '7.2'], array_column($pure['assets']['rows'], 'code'), 'Contas calculadas aparecem mesmo sem estar no plano.');
        $csv = CollectionReports::csv(BalanceSheet::csvTable($pure));
        self::assertStringStartsWith("\xEF\xBB\xBFGrupo;Código;Conta;Valor\r\n", $csv);
        self::assertStringContainsString("\"'  =HYPERLINK", $csv, 'Fórmula neutralizada.');
        self::assertStringContainsString(';;Diferença;0,00', $csv);
        self::assertStringContainsString('"    Banco";5,00', $csv);
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/BalanceSheet.php');
        foreach (["'erp.entries.view'", "'erp.chart.view'", '(new ReportExportLimit())->assertAllowed($user)', 'catch (CondominiumAccessDenied)', "Audit::record('export'"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $migration = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261109_0704_chart_patrimonial_natures.sql');
        self::assertStringContainsString("enum('receita','despesa','asset','liability','equity')", $migration);
        self::assertSame(2, substr_count($migration, 'WHERE NOT EXISTS'), 'Contas padrão sem duplicar.');
    }

    public function testLedgerUsesChartOpeningAsBalanceBroughtForward(): void
    {
        $this->opening(2, '2026-01-01', 500000);            // 1.01 do condomínio 1
        $this->opening(4, '2026-10-15', 1234);              // 2.01 com data dentro do período: fica de fora
        $this->opening(2, '2026-01-01', 777, 2);            // outro condomínio não interfere
        $service = new AccountingReports($this->pdo, new CondominiumScope($this->pdo));
        $ledger = $service->ledger($this->user(), 1, '2026-10-01', '2026-10-31');
        $taxa = $ledger['accounts'][0];
        self::assertSame('1.01', $taxa['code']);
        self::assertSame([500000, 800000], [$taxa['opening_cents'], $taxa['closing_cents']], 'Saldo inicial substitui dez/25; outubro soma normalmente.');
        self::assertSame(['2.01'], $ledger['openings_ignored']);
        self::assertSame(0, $ledger['accounts'][1]['opening_cents']);
        $cash = $service->ledger($this->user(), 1, '2026-10-01', '2026-10-31', 'cash');
        self::assertSame([500000, 600000], [$cash['accounts'][0]['opening_cents'], $cash['accounts'][0]['closing_cents']]);
        $before = $service->ledger($this->user(), 1, '2025-12-01', '2025-12-31');
        self::assertSame([0, 100000], [$before['accounts'][0]['opening_cents'], $before['accounts'][0]['closing_cents']], 'Período antes do saldo: sem abertura.');
        $later = $service->ledger($this->user(), 1, '2026-11-01', '2026-11-30');
        self::assertSame(800000, $later['accounts'][0]['opening_cents'], 'Lançamentos depois da data do saldo entram no saldo anterior.');
        $all = $service->ledger($this->user(), null, '2026-10-01', '2026-10-31');
        self::assertSame(500777, $all['accounts'][0]['opening_cents'], 'Todos do escopo: soma os saldos de 1 e 2.');
    }

    private function opening(int $accountId, string $date, int $cents, int $condominium = 1): void
    {
        $this->pdo->prepare('INSERT INTO erp_chart_account_openings (condominium_id, chart_account_id, opened_on, amount_cents) VALUES (?, ?, ?, ?)')
            ->execute([$condominium, $accountId, $date, $cents]);
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
