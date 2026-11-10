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
use Source\Services\Erp\AccountingReports;
use Source\Services\Erp\BalanceSheet;
use Source\Services\Erp\PatrimonialEntries;

/** ADR 0169: lançamentos patrimoniais com partida dobrada — balanço, estorno, Razão e sem dupla contagem na DRE. */
final class PatrimonialEntriesTest extends TestCase
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
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER, opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
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
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (4, NULL, '2.01', 'Energia', 'despesa', 'analitica'),
            (70, NULL, '7', 'Ativo', 'asset', 'sintetica'), (71, NULL, '7.1', 'Disponível', 'asset', 'sintetica'), (72, NULL, '7.2', 'Contas a receber', 'asset', 'analitica'),
            (80, NULL, '8', 'Passivo', 'liability', 'sintetica'), (81, NULL, '8.1', 'Contas a pagar', 'liability', 'analitica'),
            (82, NULL, '8.2', 'Fundo de reserva', 'liability', 'analitica'), (83, NULL, '8.3', 'Outros fundos', 'liability', 'analitica'),
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica'), (99, 3, '8.4', 'Fundo do condomínio 3', 'liability', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 50000, '2026-01-01'), (30, 3, 'Fora', 1, NULL)");
        $insert = $this->pdo->prepare('INSERT INTO erp_entries (id, condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 1, 'receita', 2, 10, 'Taxa dez/25', 100000, '2025-12', '2025-12-10', '2025-12-10'],
            [2, 1, 'receita', 2, 10, 'Fundo de reserva out', 100000, '2026-10', '2026-10-10', '2026-10-12'],
            [3, 1, 'receita', 2, null, 'Taxa out vencida', 100000, '2026-10', '2026-10-10', null],
            [4, 1, 'receita', 2, null, 'Taxa out a vencer', 100000, '2026-10', '2026-11-10', null],
            [5, 1, 'despesa', 4, 10, 'Energia paga', 30000, '2026-10', '2026-10-20', '2026-10-20'],
            [6, 1, 'despesa', 4, null, 'Energia a pagar', 20000, '2026-10', '2026-11-05', null],
            [7, 1, 'receita', 2, null, 'Taxa nov', 100000, '2026-11', '2026-11-10', null],
            [8, 3, 'receita', 2, 30, 'Fora', 999999, '2026-10', '2026-10-10', '2026-10-10'],
        ] as $row) {
            $insert->execute($row);
        }
        // Saldo inicial do PL igual ao do banco (ADR 0167): o balanço de 31/10 fecha antes de qualquer lançamento.
        $this->pdo->exec("INSERT INTO erp_chart_account_openings (condominium_id, chart_account_id, opened_on, amount_cents) VALUES (1, 91, '2025-01-01', 50000)");
    }

    public function testFundAppropriationUseAndTransferKeepTheBalanceClosedWithoutTouchingTheIncomeStatement(): void
    {
        $sheet = new BalanceSheet($this->pdo, new CondominiumScope($this->pdo));
        $before = $sheet->build($this->user(), 1, '2026-10-31');
        self::assertSame([0, 420000], [$before['diferenca'], $before['totals']['assets_cents']]);

        $entries = $this->service();
        $made = $entries->create($this->user(), ['condominium_id' => 1, 'entry_date' => '2026-10-15', 'description' => 'Apropriação do fundo de reserva de outubro',
            'amount_cents' => '300,00', 'debit_account_id' => 92, 'credit_account_id' => 82, 'bank_account_id' => 10, 'linked_entry_id' => 2], '2026-11-10');
        self::assertSame(['9.2', '8.2', 30000, 'active', 2, 10], [$made['debit']['code'], $made['credit']['code'], $made['amount_cents'], $made['status'],
            $made['linked_entry_id'], $made['bank_account_id']]);
        $entries->create($this->user(), ['condominium_id' => 1, 'entry_date' => '2026-10-20', 'description' => 'Uso do fundo na energia', 'amount_cents' => 10000,
            'debit_account_id' => 82, 'credit_account_id' => 92, 'linked_entry_id' => 5], '2026-11-10');
        $entries->create($this->user(), ['condominium_id' => 1, 'entry_date' => '2026-10-25', 'description' => 'Transferência entre fundos', 'amount_cents' => 5000,
            'debit_account_id' => 82, 'credit_account_id' => 83], '2026-11-10');

        $after = $sheet->build($this->user(), 1, '2026-10-31');
        $liabilities = array_column($after['liabilities']['rows'], 'amount_cents', 'code');
        $equity = array_column($after['equity']['rows'], 'amount_cents', 'code');
        self::assertSame([15000, 5000, 40000], [$liabilities['8.2'], $liabilities['8.3'], $liabilities['8']], 'Fundo = 300 − 100 − 50; outros fundos = 50.');
        self::assertSame([150000, 230000], [$equity['9.1'], $equity['9.2']], 'O resultado apropriado sai do PL.');
        self::assertSame([0, true, 420000], [$after['diferenca'], $after['balanced'], $after['totals']['assets_cents']], 'O ativo não muda e o balanço fecha.');

        $dre = (new AccountingReports($this->pdo, new CondominiumScope($this->pdo)))->incomeStatement($this->user(), 1, '2026-10-01', '2026-10-31');
        self::assertSame([300000, 30000 + 20000, 250000], [$dre['totals']['revenue_cents'], $dre['totals']['expense_cents'], $dre['totals']['result_cents']],
            'A DRE não muda: o lançamento patrimonial não conta duas vezes.');

        $nextYear = $sheet->build($this->user(), 1, '2027-01-15');
        $equityNext = array_column($nextYear['equity']['rows'], 'amount_cents', 'code');
        self::assertSame([0, 0], [$equityNext['9.2'], $nextYear['diferenca']], 'Na virada, o movimento na 9.2 de 2026 vai para os acumulados.');
        // Saldo do PL + 2025 + resultado de 2026 (400.000 − 50.000) − o que foi para o fundo pela 9.2 (300 − 100; a transferência entre fundos não passa pelo PL).
        self::assertSame(50000 + 100000 + 350000 - 20000, $equityNext['9.1']);

        $list = $entries->list($this->user(), 1, '2026-10-01', '2026-10-31');
        self::assertSame([3, 30000 + 10000 + 5000], [$list['totals']['entries'], $list['totals']['amount_cents']]);
        $this->expectScope(fn () => $entries->list($this->user(), 3, '2026-10-01', '2026-10-31'));
    }

    public function testReversalUndoesWithoutDeleting(): void
    {
        $entries = $this->service();
        $made = $entries->create($this->user(), ['condominium_id' => 1, 'entry_date' => '2026-10-15', 'description' => 'Apropriação', 'amount_cents' => 30000,
            'debit_account_id' => 92, 'credit_account_id' => 82], '2026-11-10');
        $result = $entries->reverse($this->user(), $made['id'], 'Lançado em duplicidade', null, '2026-11-10');
        self::assertSame('reversed', $result['original']['status']);
        self::assertSame(['8.2', '9.2', 30000, $made['id'], '2026-11-10', 'Lançado em duplicidade'], [$result['reversal']['debit']['code'],
            $result['reversal']['credit']['code'], $result['reversal']['amount_cents'], $result['reversal']['reversal_of_id'], $result['reversal']['entry_date'],
            $result['reversal']['reversal_reason']]);
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_patrimonial_entries')->fetchColumn(), 'Nada é apagado.');

        $sheet = new BalanceSheet($this->pdo, new CondominiumScope($this->pdo));
        self::assertSame(30000, array_column($sheet->build($this->user(), 1, '2026-10-31')['liabilities']['rows'], 'amount_cents', 'code')['8.2'], 'Antes do estorno.');
        $later = $sheet->build($this->user(), 1, '2026-11-10');
        self::assertSame(0, array_column($later['liabilities']['rows'], 'amount_cents', 'code')['8.2'], 'Depois do estorno, zera.');

        foreach ([$made['id'], $result['reversal']['id']] as $again) {
            try {
                $entries->reverse($this->user(), $again, 'De novo', null, '2026-11-10');
                self::fail('Estornou duas vezes.');
            } catch (DomainException) {   // 409
                self::addToAssertionCount(1);
            }
        }
        $other = $entries->create($this->user(), ['condominium_id' => 1, 'entry_date' => '2026-10-15', 'description' => 'Outro', 'amount_cents' => 100,
            'debit_account_id' => 82, 'credit_account_id' => 83], '2026-11-10');
        foreach ([['x', null], ['Motivo válido', '2026-10-14'], ['Motivo válido', '2026-12-01']] as [$reason, $date]) {
            try {
                $entries->reverse($this->user(), $other['id'], $reason, $date, '2026-11-10');
                self::fail('Aceitou ' . json_encode([$reason, $date]));
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        $this->pdo->exec("INSERT INTO erp_patrimonial_entries (id, condominium_id, entry_date, description, amount_cents, debit_account_id, credit_account_id)
            VALUES (500, 3, '2026-10-01', 'Fora', 1, 99, 99)");
        $this->expectScope(fn () => $entries->reverse($this->user(), 500, 'Motivo válido', null, '2026-11-10'));
        $this->expectScope(fn () => $entries->reverse($this->user(), 999, 'Motivo válido', null, '2026-11-10'));
    }

    public function testValidationScopeAndClosedMonth(): void
    {
        $entries = $this->service();
        $valid = ['condominium_id' => 1, 'entry_date' => '2026-10-15', 'description' => 'Apropriação', 'amount_cents' => 100, 'debit_account_id' => 92, 'credit_account_id' => 82];
        foreach ([
            ['debit_account_id' => 72], ['debit_account_id' => 71], ['credit_account_id' => 81], ['debit_account_id' => 2], ['credit_account_id' => 99],
            ['credit_account_id' => 92], ['amount_cents' => 0], ['amount_cents' => '-1,00'], ['entry_date' => '2026-11-11'], ['entry_date' => '15/10/2026'],
            ['description' => 'x'], ['bank_account_id' => 30], ['linked_entry_id' => 8], ['condominium_id' => null],
        ] as $override) {
            try {
                $entries->create($this->user(), $override + $valid, '2026-11-10');
                self::fail('Aceitou ' . json_encode($override));
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        $this->expectScope(fn () => $entries->create($this->user(), ['condominium_id' => 3] + $valid, '2026-11-10'));
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-10', 'closed')");
        try {
            $entries->create($this->user(), $valid, '2026-11-10');
            self::fail('Lançou em competência fechada.');
        } catch (DomainException) {   // 409
            self::addToAssertionCount(1);
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_patrimonial_entries')->fetchColumn());
        self::assertSame([30000, -30000, -30000, 30000], [PatrimonialEntries::signed('asset', 'debit', 30000), PatrimonialEntries::signed('asset', 'credit', 30000),
            PatrimonialEntries::signed('liability', 'debit', 30000), PatrimonialEntries::signed('equity', 'credit', 30000)]);
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/PatrimonialEntries.php');
        foreach (["'erp.entries.view'", "'erp.chart.view'", "'erp.entries.manage'", 'catch (CondominiumAccessDenied)', 'JsonResponse::send(409'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertDoesNotMatchRegularExpression('/DELETE\s+FROM/i', (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/PatrimonialEntries.php'), 'Sem exclusão.');
    }

    public function testLedgerShowsBothSidesOfPatrimonialEntries(): void
    {
        $entries = $this->service();
        $entries->create($this->user(), ['condominium_id' => 1, 'entry_date' => '2026-09-10', 'description' => 'Apropriação de setembro', 'amount_cents' => 20000,
            'debit_account_id' => 92, 'credit_account_id' => 82], '2026-11-10');
        $entries->create($this->user(), ['condominium_id' => 1, 'entry_date' => '2026-10-15', 'description' => 'Apropriação de outubro', 'amount_cents' => 30000,
            'debit_account_id' => 92, 'credit_account_id' => 82], '2026-11-10');
        $ledger = (new AccountingReports($this->pdo, new CondominiumScope($this->pdo)))->ledger($this->user(), 1, '2026-10-01', '2026-10-31', 'competence', '8');
        self::assertSame(['8.2'], array_column($ledger['accounts'], 'code'), 'Filtro por conta: só o fundo.');
        $fund = $ledger['accounts'][0];
        self::assertSame([20000, 0, 30000, 50000], [$fund['opening_cents'], $fund['debit_cents'], $fund['credit_cents'], $fund['closing_cents']],
            'Setembro é saldo anterior; o crédito soma no passivo.');
        self::assertSame(['patrimonial', 'Apropriação de outubro', 50000], [$fund['lines'][0]['source'], $fund['lines'][0]['description'], $fund['lines'][0]['balance_cents']]);
        $all = (new AccountingReports($this->pdo, new CondominiumScope($this->pdo)))->ledger($this->user(), 1, '2026-10-01', '2026-10-31', 'cash');
        $result = array_values(array_filter($all['accounts'], static fn (array $a): bool => $a['code'] === '9.2'))[0];
        self::assertSame([-20000, 30000, -50000], [$result['opening_cents'], $result['debit_cents'], $result['closing_cents']], 'Débito no PL reduz o saldo.');
        self::assertContains('1.01', array_column($all['accounts'], 'code'), 'As receitas continuam no Razão.');
    }

    private function service(): PatrimonialEntries
    {
        return new PatrimonialEntries($this->pdo, new CondominiumScope($this->pdo));
    }

    private function expectScope(callable $call): void
    {
        try {
            $call();
            self::fail('Respondeu fora do escopo.');
        } catch (CondominiumAccessDenied) {   // 404
            self::addToAssertionCount(1);
        }
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
