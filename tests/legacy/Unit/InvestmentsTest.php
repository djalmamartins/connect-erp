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
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\Investments;
use Source\Services\Erp\Registry\RegistryNotFound;

/** ADR 0170: aplicação financeira separada da conta corrente — saldo total, rendimento na DRE, estorno, Razão e escopo. */
final class InvestmentsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // NOW() do MySQL, usado pelo cancelamento da transferência (EntryShortcuts::cancelTransfer).
        $now = static fn (): string => '2026-11-10 10:00:00';
        $this->pdo instanceof \Pdo\Sqlite ? $this->pdo->createFunction('NOW', $now, 0) : $this->pdo->sqliteCreateFunction('NOW', $now, 0);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER,
                opening_balance_cents INTEGER DEFAULT 0, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER,
                transferred_on TEXT, description TEXT, status TEXT DEFAULT 'active', created_by INTEGER, cancelled_by INTEGER, cancelled_at TEXT, cancel_reason TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)",
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT, updated_by INTEGER)',
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_date TEXT, description TEXT, amount_cents INTEGER, debit_account_id INTEGER,
                credit_account_id INTEGER, status TEXT DEFAULT 'active', reversal_of_id INTEGER)",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (5, NULL, '1.02', 'Rendimentos de aplicação', 'receita', 'analitica'),
            (3, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (6, NULL, '2.02', 'IR/IOF sobre aplicação', 'despesa', 'analitica'),
            (70, NULL, '7', 'Ativo', 'asset', 'sintetica'), (71, NULL, '7.1', 'Disponível', 'asset', 'sintetica'), (72, NULL, '7.2', 'Contas a receber', 'asset', 'analitica'),
            (80, NULL, '8', 'Passivo', 'liability', 'sintetica'), (81, NULL, '8.1', 'Contas a pagar', 'liability', 'analitica'),
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, kind, opening_balance_cents, opened_on) VALUES
            (10, 1, 'Movimento', 'corrente', 100000, '2026-01-01'), (11, 1, 'CDB', 'aplicacao', 0, '2026-01-01'), (12, 1, 'Outra corrente', 'corrente', 0, '2026-01-01'),
            (13, 1, 'Fundo sem vínculo', 'aplicacao', 0, '2026-01-01'), (30, 3, 'Fora', 'aplicacao', 0, NULL), (31, 3, 'Corrente fora', 'corrente', 0, NULL)");
        // Saldo inicial do PL igual ao do banco (ADR 0167): o balanço começa fechado.
        $this->pdo->exec("INSERT INTO erp_chart_account_openings (condominium_id, chart_account_id, opened_on, amount_cents) VALUES (1, 91, '2026-01-01', 100000)");
    }

    public function testLinkRules(): void
    {
        $service = $this->service();
        self::assertSame(10, $service->link($this->user(), 11, 10)['parent_bank_account_id']);
        foreach ([[11, 13], [11, 31], [10, 12], [11, 999]] as [$investment, $parent]) {
            try {
                $service->link($this->user(), $investment, $parent);
                self::fail("Ligou {$investment} a {$parent}");
            } catch (InvalidArgumentException) {   // 422: destino não é corrente, é de outro condomínio ou a conta não é aplicação
                self::addToAssertionCount(1);
            }
        }
        $this->expectScope(fn () => $service->link($this->user(), 30, 31));
        self::assertNull($service->link($this->user(), 11, null)['parent_bank_account_id'], 'Desligar.');
    }

    public function testApplyAndYieldKeepTheTotalAndShowOnlyTheYieldInTheIncomeStatement(): void
    {
        $service = $this->service();
        $service->link($this->user(), 11, 10);
        $applied = $service->apply($this->user(), 11, ['amount_cents' => '400,00', 'transferred_on' => '2026-10-05'], '2026-11-10');
        self::assertSame([10, 11, 40000], [$applied['transfer']['from_bank_account_id'], $applied['transfer']['to_bank_account_id'], $applied['transfer']['amount_cents']]);
        self::assertSame('Aplicação — CDB', $applied['transfer']['description']);

        $positions = $service->positions($this->user(), 1, '2026-10-31');
        self::assertSame(['checking_cents' => 60000, 'savings_cents' => 0, 'investments_cents' => 40000, 'total_cents' => 100000], $positions['totals'],
            'A aplicação não muda o saldo total.');

        $accounting = new AccountingReports($this->pdo, new CondominiumScope($this->pdo));
        self::assertSame([0, 0], [($dre = $accounting->incomeStatement($this->user(), 1, '2026-10-01', '2026-10-31'))['totals']['revenue_cents'], $dre['totals']['expense_cents']],
            'A transferência não entra na DRE.');

        $insert = $this->pdo->prepare('INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (1, ?, ?, 11, ?, ?, ?, ?, ?)');
        $insert->execute(['receita', 5, 'Rendimento de outubro', 500, '2026-10', '2026-10-31', '2026-10-31']);
        $insert->execute(['despesa', 6, 'IR sobre o rendimento', 75, '2026-10', '2026-10-31', '2026-10-31']);
        $dre = $accounting->incomeStatement($this->user(), 1, '2026-10-01', '2026-10-31');
        self::assertSame([500, 75, 425], [$dre['totals']['revenue_cents'], $dre['totals']['expense_cents'], $dre['totals']['result_cents']], 'O rendimento e o IR entram na DRE.');
        self::assertSame(40425, $service->positions($this->user(), 1, '2026-10-31')['totals']['investments_cents']);

        $sheet = (new BalanceSheet($this->pdo, new CondominiumScope($this->pdo)))->build($this->user(), 1, '2026-10-31');
        $available = $sheet['assets']['rows'][1];
        self::assertSame(['7.1', 100425, 60000, 40425], [$available['code'], $available['amount_cents'], $available['checking_cents'], $available['investments_cents']]);
        self::assertEqualsCanonicalizing(['corrente', 'aplicacao'], array_values(array_unique(array_column($available['lines'], 'kind'))));
        self::assertSame(0, $sheet['diferenca'], 'O balanço fecha: o rendimento entra no resultado e no Disponível.');
        self::assertStringContainsString('CDB (aplicação)', implode("\n", array_map(static fn (array $r): string => implode(';', $r), BalanceSheet::csvTable($sheet)['rows'])));
    }

    public function testRedeemReversalAndBankLedgerShowBothSides(): void
    {
        $service = $this->service();
        $service->link($this->user(), 11, 10);
        $service->apply($this->user(), 11, ['amount_cents' => 40000, 'transferred_on' => '2026-10-05'], '2026-11-10');
        $redeemed = $service->redeem($this->user(), 11, ['amount_cents' => 10000, 'transferred_on' => '2026-10-20', 'description' => 'Resgate parcial'], '2026-11-10');
        self::assertSame([11, 10], [$redeemed['transfer']['from_bank_account_id'], $redeemed['transfer']['to_bank_account_id']]);
        self::assertSame(['checking_cents' => 70000, 'savings_cents' => 0, 'investments_cents' => 30000, 'total_cents' => 100000],
            $service->positions($this->user(), 1, '2026-10-31')['totals']);

        $ledger = (new AccountingReports($this->pdo, new CondominiumScope($this->pdo)))->bankLedger($this->user(), 1, '2026-10-01', '2026-10-31');
        $byId = array_column($ledger['accounts'], null, 'bank_account_id');
        self::assertSame([100000, 10000, 40000, 70000], [$byId[10]['opening_cents'], $byId[10]['debit_cents'], $byId[10]['credit_cents'], $byId[10]['closing_cents']]);
        self::assertSame([0, 40000, 10000, 30000, 'aplicacao'], [$byId[11]['opening_cents'], $byId[11]['debit_cents'], $byId[11]['credit_cents'], $byId[11]['closing_cents'], $byId[11]['kind']]);
        self::assertSame([11, 10], [$byId[10]['lines'][0]['counterpart_bank_account_id'], $byId[11]['lines'][0]['counterpart_bank_account_id']], 'A mesma transferência nos dois lados.');
        self::assertSame($byId[10]['lines'][0]['id'], $byId[11]['lines'][0]['id']);
        self::assertSame(['transfer', 30000], [$byId[11]['lines'][1]['source'], $byId[11]['lines'][1]['balance_cents']]);
        self::assertSame([50000, 50000, 100000], [$ledger['totals']['debit_cents'], $ledger['totals']['credit_cents'], $ledger['totals']['closing_cents']],
            'No total, as transferências somam zero.');

        // Estorno: cancela a transferência pela rota que já existe (AP-029); nada é apagado.
        $shortcuts = new EntryShortcuts($this->pdo, new CondominiumScope($this->pdo));
        $cancelled = $shortcuts->cancelTransfer($this->user(), $redeemed['transfer']['id'], 'Resgate lançado por engano');
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(['checking_cents' => 60000, 'savings_cents' => 0, 'investments_cents' => 40000, 'total_cents' => 100000],
            $service->positions($this->user(), 1, '2026-10-31')['totals']);
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_bank_transfers')->fetchColumn());
        $after = (new AccountingReports($this->pdo, new CondominiumScope($this->pdo)))->bankLedger($this->user(), 1, '2026-10-01', '2026-10-31');
        self::assertCount(1, array_column($after['accounts'], null, 'bank_account_id')[11]['lines'], 'O cancelado sai do Razão.');
    }

    public function testValidationScopeAndClosedMonth(): void
    {
        $service = $this->service();
        $service->link($this->user(), 11, 10);
        foreach ([[13, ['amount_cents' => 100, 'transferred_on' => '2026-10-05']], [10, ['amount_cents' => 100, 'transferred_on' => '2026-10-05']],
            [11, ['amount_cents' => 0, 'transferred_on' => '2026-10-05']], [11, ['amount_cents' => 100, 'transferred_on' => '2099-01-01']]] as [$account, $input]) {
            try {
                $service->apply($this->user(), $account, $input, '2026-11-10');
                self::fail('Aceitou ' . json_encode([$account, $input]));
            } catch (InvalidArgumentException) {   // 422: sem vínculo, não é aplicação, valor ou data inválidos
                self::addToAssertionCount(1);
            }
        }
        $this->expectScope(fn () => $service->apply($this->user(), 30, ['amount_cents' => 100, 'transferred_on' => '2026-10-05']));
        $this->expectScope(fn () => $service->positions($this->user(), 3, '2026-10-31'));
        $this->expectScope(fn () => (new AccountingReports($this->pdo, new CondominiumScope($this->pdo)))->bankLedger($this->user(), 3, '2026-10-01', '2026-10-31'));
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-10', 'closed')");
        try {
            $service->apply($this->user(), 11, ['amount_cents' => 100, 'transferred_on' => '2026-10-05']);
            self::fail('Aplicou em competência fechada.');
        } catch (DomainException) {   // 409
            self::addToAssertionCount(1);
        }
        self::assertSame(['checking_cents' => 10, 'savings_cents' => 0, 'investments_cents' => 20, 'total_cents' => 30],
            Investments::split([['bank_account_id' => 1, 'closing_cents' => 10], ['bank_account_id' => 2, 'closing_cents' => 20]], [1 => 'corrente', 2 => 'aplicacao']));
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/Investments.php');
        foreach (["'erp.entries.view', 'erp.entries.manage'", "'erp.bank_accounts.view', 'erp.bank_accounts.manage'", 'catch (CondominiumAccessDenied | RegistryNotFound)',
            'JsonResponse::send(409'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
    }

    private function service(): Investments
    {
        return new Investments($this->pdo, new CondominiumScope($this->pdo));
    }

    private function expectScope(callable $call): void
    {
        try {
            $call();
            self::fail('Respondeu fora do escopo.');
        } catch (CondominiumAccessDenied | RegistryNotFound) {   // 404
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
