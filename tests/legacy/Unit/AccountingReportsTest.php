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
use Source\Services\Erp\CollectionReports;

/** Relatórios contábeis (ADR 0165): Livro Razão, Saldos de Contas e DRE — regra pura, escopo, bases, 404, 422 e CSV. */
final class AccountingReportsTest extends TestCase
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
            'CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, deleted_at TEXT)',
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER, transferred_on TEXT, status TEXT DEFAULT 'active')",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Bosque'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (4, NULL, '2.01', 'Energia', 'despesa', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 50000, '2026-01-01'), (30, 3, 'Fora', 1, NULL)");
        $insert = $this->pdo->prepare('INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on, status, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 'receita', 2, 10, 'Taxa set', 100000, '2026-09', '2026-09-10', '2026-09-10', 'active', null],   // antes do período
            [1, 'receita', 2, 10, 'Taxa out', 100000, '2026-10', '2026-10-10', '2026-10-12', 'active', null],
            [1, 'receita', 2, null, 'Taxa nov', 100000, '2026-11', '2026-11-10', null, 'active', null],        // em aberto
            [1, 'despesa', 4, 10, 'Energia out', 30000, '2026-10', '2026-10-20', '2026-10-20', 'active', null],
            [1, 'despesa', 4, 10, 'Cancelada', 99999, '2026-10', '2026-10-21', '2026-10-21', 'inactive', null],
            [1, 'despesa', 4, 10, 'Excluída', 88888, '2026-10', '2026-10-22', '2026-10-22', 'active', '2026-10-23'],
            [2, 'receita', 2, null, 'Taxa Bosque', 40000, '2026-10', '2026-10-10', '2026-10-10', 'active', null],
            [3, 'receita', 2, 30, 'Fora', 999999, '2026-10', '2026-10-10', '2026-10-10', 'active', null],
        ] as $row) {
            $insert->execute($row);
        }
    }

    public function testLedgerOpeningDebitsCreditsAndRunningBalance(): void
    {
        $accounts = AccountingReports::ledgerAccounts(['receita|1.01' => 100000], [
            ['code' => '1.01', 'name' => 'Taxa', 'nature' => 'receita', 'id' => 2, 'on_date' => '2026-10-10', 'competence' => '2026-10', 'description' => 'Taxa out', 'cents' => 100000],
            ['code' => '2.01', 'name' => 'Energia', 'nature' => 'despesa', 'id' => 4, 'on_date' => '2026-10-20', 'competence' => '2026-10', 'description' => 'Energia', 'cents' => '30000'],
        ]);
        self::assertSame(['1.01', '2.01'], array_column($accounts, 'code'));
        self::assertSame([100000, 0, 100000, 200000], [$accounts[0]['opening_cents'], $accounts[0]['debit_cents'], $accounts[0]['credit_cents'], $accounts[0]['closing_cents']], 'Receita a crédito.');
        self::assertSame([0, 30000, 0, 30000], [$accounts[1]['opening_cents'], $accounts[1]['debit_cents'], $accounts[1]['credit_cents'], $accounts[1]['closing_cents']], 'Despesa a débito.');
        self::assertSame(200000, $accounts[0]['lines'][0]['balance_cents'], 'Saldo acumulado na linha.');

        $service = new AccountingReports($this->pdo, new CondominiumScope($this->pdo));
        $cash = $service->ledger($this->user(), 1, '2026-10-01', '2026-10-31', 'cash');
        $taxa = $cash['accounts'][0];
        self::assertSame(['1.01', 100000, 100000, 200000], [$taxa['code'], $taxa['opening_cents'], $taxa['credit_cents'], $taxa['closing_cents']], 'Caixa: setembro é saldo anterior; o aberto de novembro não entra.');
        self::assertSame(['Taxa out'], array_column($taxa['lines'], 'description'));
        self::assertSame(['Energia out'], array_column($cash['accounts'][1]['lines'], 'description'), 'Sem cancelado nem excluído.');
        $competence = $service->ledger($this->user(), 1, '2026-10-01', '2026-11-30');
        self::assertSame(['Taxa out', 'Taxa nov'], array_column($competence['accounts'][0]['lines'], 'description'), 'Competência: o aberto de novembro entra.');
        $filtered = $service->ledger($this->user(), 1, '2026-10-01', '2026-10-31', 'cash', '2');
        self::assertSame(['2.01'], array_column($filtered['accounts'], 'code'), 'Filtro por conta (e subcontas).');
        self::assertFalse($cash['truncated']);
    }

    public function testIncomeStatementRollupResultAndMargin(): void
    {
        $pure = AccountingReports::income([['code' => '1.01', 'name' => 'Taxa', 'nature' => 'receita', 'cents' => 1000], ['code' => '2.01', 'name' => 'Energia', 'nature' => 'despesa', 'cents' => '250']],
            ['1' => 'Receitas', '2' => 'Despesas']);
        self::assertSame(['1', '1.01', '2', '2.01'], array_column($pure['rows'], 'code'));
        self::assertSame(['revenue_cents' => 1000, 'expense_cents' => 250, 'result_cents' => 750, 'margin_percent' => 75.0], $pure['totals']);
        self::assertNull(AccountingReports::income([])['totals']['margin_percent'], 'Sem receita não divide por zero.');

        $service = new AccountingReports($this->pdo, new CondominiumScope($this->pdo));
        $dre = $service->incomeStatement($this->user(), 1, '2026-10-01', '2026-10-31');
        self::assertSame([100000, 30000, 70000], [$dre['totals']['revenue_cents'], $dre['totals']['expense_cents'], $dre['totals']['result_cents']]);
        self::assertSame('Receitas', $dre['rows'][0]['name'], 'Nome da sintética vem do plano de contas.');
        $all = $service->incomeStatement($this->user(), null, '2026-10-01', '2026-10-31', 'cash');
        self::assertSame(140000, $all['totals']['revenue_cents'], 'Todos = condomínios 1 e 2, nunca o 3.');
    }

    public function testAccountBalancesAtDateAndPeriod(): void
    {
        $service = new AccountingReports($this->pdo, new CondominiumScope($this->pdo));
        $period = $service->accountBalances($this->user(), 1, null, '2026-10-01', '2026-10-31');
        self::assertSame([['bank_account_id' => 10, 'condominium_id' => 1, 'condominium_name' => 'Aurora', 'name' => 'Movimento',
            'opening_cents' => 150000, 'received_cents' => 100000, 'paid_cents' => 30000, 'closing_cents' => 220000]], $period['accounts']);
        $date = $service->accountBalances($this->user(), 1, '2026-09-30');
        self::assertSame(['2026-09-30', 150000], [$date['as_of'], $date['accounts'][0]['closing_cents']], 'Na data: saldo inicial + o pago até a data.');
        $all = $service->accountBalances($this->user(), null, '2026-10-31');
        self::assertSame([10], array_column($all['accounts'], 'bank_account_id'), 'A conta do condomínio 3 não aparece.');
    }

    public function testScopeValidationAndCsv(): void
    {
        $service = new AccountingReports($this->pdo, new CondominiumScope($this->pdo));
        foreach ([fn () => $service->ledger($this->user(), 3, '2026-10-01', '2026-10-31'), fn () => $service->incomeStatement($this->user(), 3, '2026-10-01', '2026-10-31'),
            fn () => $service->accountBalances($this->user(), 3, '2026-10-31')] as $call) {
            try {
                $call();
                self::fail('Respondeu fora do escopo.');
            } catch (CondominiumAccessDenied) {   // o controller responde 404
                self::addToAssertionCount(1);
            }
        }
        foreach ([['2026-10-31', '2026-10-01'], ['2026-1-01', '2026-10-31'], ['', ''], ['2025-01-01', '2026-12-31']] as [$from, $to]) {
            try {
                AccountingReports::period($from, $to);
                self::fail("Aceitou {$from} a {$to}");
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        try {
            AccountingReports::basis('mensal');
            self::fail('Aceitou base inválida.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $ledger = $service->ledger($this->user(), 1, '2026-10-01', '2026-10-31', 'cash');
        $ledger['accounts'][0]['lines'][0]['description'] = '=HYPERLINK("x")';
        $csv = CollectionReports::csv(AccountingReports::csvTable('ledger', $ledger));
        self::assertStringStartsWith("\xEF\xBB\xBFCódigo;Conta;Data;Histórico;Débito;Crédito;Saldo\r\n", $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv, 'Fórmula neutralizada.');
        self::assertStringContainsString('12/10/2026', $csv);
        self::assertStringContainsString(';Total;1500,00;1000,00;300,00;2200,00', CollectionReports::csv(AccountingReports::csvTable('account-balances', $service->accountBalances($this->user(), 1, null, '2026-10-01', '2026-10-31'))));
        self::assertStringContainsString(';Resultado;;700,00', CollectionReports::csv(AccountingReports::csvTable('income-statement', $service->incomeStatement($this->user(), 1, '2026-10-01', '2026-10-31'))));
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/AccountingReports.php');
        foreach (["'erp.entries.view'", "'erp.chart.view'", '(new ReportExportLimit())->assertAllowed($user)', 'catch (CondominiumAccessDenied)', "Audit::record('export'"] as $needle) {
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
