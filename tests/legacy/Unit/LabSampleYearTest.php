<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AccountingReports;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\BalanceSheet;
use Source\Services\Erp\LabSampleData;
use Source\Services\Erp\LabSampleYear;

/**
 * ADR 0173: o ano contábil de exemplo do laboratório. Unit com SQLite; o PDO de teste só traduz o que é
 * dialeto do MySQL nos services (FOR UPDATE, INSERT IGNORE e <=>), sem mudar a regra.
 */
final class LabSampleYearTest extends TestCase
{
    private PDO $pdo;
    private string $today;

    protected function setUp(): void
    {
        $this->today = date('Y-m-d');
        // PHP 8.2/8.3 (CI) não tem Pdo\Sqlite; 8.4+ deprecia sqliteCreateFunction. Mesma tradução de SQL nos dois.
        $translate = static fn (string $query): string => str_replace(['INSERT IGNORE', ' FOR UPDATE', ' <=> '], ['INSERT OR IGNORE', '', ' IS '], $query);
        $now = static fn (): string => date('Y-m-d H:i:s');
        if (class_exists(\Pdo\Sqlite::class)) {
            $this->pdo = new class ('sqlite::memory:', $translate) extends \Pdo\Sqlite {
                public function __construct(string $dsn, private \Closure $translate)
                {
                    parent::__construct($dsn);
                }

                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(($this->translate)($query), $options);
                }
            };
            $this->pdo->createFunction('NOW', $now, 0);
        } else {
            $this->pdo = new class ('sqlite::memory:', $translate) extends PDO {
                public function __construct(string $dsn, private \Closure $translate)
                {
                    parent::__construct($dsn);
                }

                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(($this->translate)($query), $options);
                }
            };
            $this->pdo->sqliteCreateFunction('NOW', $now, 0);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, level INTEGER)',
            'CREATE TABLE access_user_roles (user_id INTEGER PRIMARY KEY, role_id INTEGER, assigned_by INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, group_name TEXT, description TEXT)',
            'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT, assigned_by INTEGER)',
            "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, status TEXT DEFAULT 'confirmed')",
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, legal_name TEXT, document TEXT, status TEXT DEFAULT 'active', city TEXT, state TEXT, deleted_at TEXT)",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER, granted_by INTEGER, PRIMARY KEY (user_id, condominium_id))',
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, block TEXT, number TEXT, label TEXT GENERATED ALWAYS AS (number) VIRTUAL,
                type TEXT, notes TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, relationship TEXT, name TEXT, document TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, notes TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_cost_centers (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, condominium_id INTEGER, legal_name TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, cost_center_id INTEGER, bank_account_id INTEGER,
                supplier_id INTEGER, unit_id INTEGER, description TEXT, amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, document_number TEXT, notes TEXT,
                invoice_item_id INTEGER, recurring_entry_id INTEGER, agreement_id INTEGER, agreement_role TEXT, agreement_installment INTEGER, extra_charge_id INTEGER,
                status TEXT DEFAULT 'active', created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, bank_code TEXT, branch TEXT, account_number TEXT,
                kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER, opening_balance_cents INTEGER DEFAULT 0, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER,
                transferred_on TEXT, description TEXT, status TEXT DEFAULT 'active', created_by INTEGER, cancelled_by INTEGER, cancelled_at TEXT, cancel_reason TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)",
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT,
                updated_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE (condominium_id, chart_account_id))',
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_date TEXT, description TEXT, amount_cents INTEGER, debit_account_id INTEGER,
                credit_account_id INTEGER, bank_account_id INTEGER, linked_entry_id INTEGER, import_id INTEGER, status TEXT DEFAULT 'active', reversal_of_id INTEGER UNIQUE,
                reversal_reason TEXT, created_by INTEGER)",
            "CREATE TABLE erp_patrimonial_imports (id INTEGER PRIMARY KEY, condominium_id INTEGER, idempotency_key TEXT, sha256 TEXT, original_name TEXT, content BLOB,
                status TEXT DEFAULT 'previewed', rows_count INTEGER, error_count INTEGER, summary_json TEXT, created_by INTEGER, applied_by INTEGER, applied_at TEXT,
                UNIQUE (condominium_id, idempotency_key))",
            "CREATE TABLE erp_agreements (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, status TEXT, chart_account_id INTEGER, original_cents INTEGER,
                fees_cents INTEGER, discount_cents INTEGER, total_cents INTEGER, installments INTEGER, first_due_on TEXT, notes TEXT, created_by INTEGER,
                cancelled_by INTEGER, cancelled_at TEXT, cancel_reason TEXT, broken_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)",
            'CREATE TABLE erp_agreement_originals (id INTEGER PRIMARY KEY, agreement_id INTEGER, entry_id INTEGER, amount_cents INTEGER)',
            'CREATE TABLE erp_bank_charges (id INTEGER PRIMARY KEY, entry_id INTEGER, status TEXT)',
            'CREATE TABLE erp_notification_outbox (id INTEGER PRIMARY KEY, condominium_id INTEGER, event_type TEXT, event_key TEXT UNIQUE, subject_table TEXT, subject_id INTEGER,
                payload_json TEXT, created_by INTEGER, available_at TEXT)',
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
            'CREATE TABLE erp_billing_rules (id INTEGER PRIMARY KEY, condominium_id INTEGER)',
            'CREATE TABLE erp_price_indexes (id INTEGER PRIMARY KEY, code TEXT, competence TEXT, rate_percent REAL)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO users (id, first_name, email) VALUES (7, 'Ana', 'ana@x.test')");
        $this->pdo->exec("INSERT INTO access_roles (id, name, slug, level) VALUES (1, 'Desenvolvedor', 'developer', 100)");
        $this->pdo->exec('INSERT INTO access_user_roles (user_id, role_id) VALUES (7, 1)');
        // Plano padrão patrimonial (migration 0704).
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (70, NULL, '7', 'Ativo', 'asset', 'sintetica'), (71, NULL, '7.1', 'Disponível', 'asset', 'sintetica'), (72, NULL, '7.2', 'Contas a receber', 'asset', 'analitica'),
            (80, NULL, '8', 'Passivo', 'liability', 'sintetica'), (81, NULL, '8.1', 'Contas a pagar', 'liability', 'analitica'),
            (82, NULL, '8.2', 'Fundo de reserva', 'liability', 'analitica'), (83, NULL, '8.3', 'Outros fundos', 'liability', 'analitica'),
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica')");
    }

    public function testPlanAlwaysHasCompleteMonthsBeforeToday(): void
    {
        foreach (['2026-11-11' => [2026, 10], '2026-08-01' => [2026, 7], '2026-07-31' => [2025, 12], '2027-01-05' => [2026, 12]] as $today => [$year, $count]) {
            $plan = LabSampleYear::plan($today);
            self::assertSame([$year, $count], [$plan['year'], count($plan['months'])], $today);
            self::assertLessThan(substr($today, 0, 7), $plan['months'][$count - 1], 'Só meses completos.');
            self::assertSame($plan['opening']['bank'], $plan['opening']['fund'] + $plan['opening']['equity'], 'Abertura fechada.');
            self::assertCount(3, $plan['events']['unpaid_months']);
        }
    }

    public function testYearIsIdempotentAndTheBalanceSheetCloses(): void
    {
        $user = new User();
        $user->id = 7;
        (new LabSampleData($this->pdo))->create($this->today);
        $year = new LabSampleYear($this->pdo);
        $first = $year->create($user, $this->today);
        $months = count(LabSampleYear::plan($this->today)['months']);
        self::assertSame(1, $first['agreements']);
        self::assertSame(1, $first['imports']);
        self::assertSame(2, $first['transfers']);
        self::assertGreaterThan($months * 9, $first['entries']);

        $state = $this->snapshot();
        $second = $year->create($user, $this->today);
        self::assertSame(0, array_sum($second), 'A segunda vez não cria nada: ' . json_encode($second));
        self::assertSame($state, $this->snapshot(), 'Mesmo estado depois de rodar de novo.');

        $condominium = (int)$this->pdo->query("SELECT id FROM condominiums WHERE name = 'EXEMPLO Residencial Aurora'")->fetchColumn();
        $scope = new CondominiumScope($this->pdo);
        $sheet = (new BalanceSheet($this->pdo, $scope))->build($user, $condominium, $this->today);
        self::assertSame([0, true], [$sheet['diferenca'], $sheet['balanced']], 'O balanço do condomínio de exemplo fecha.');
        $liabilities = array_column($sheet['liabilities']['rows'], 'amount_cents', 'code');
        self::assertGreaterThan(0, $liabilities['8.1'], 'Há conta a pagar (limpeza do último mês).');
        self::assertSame(100000, $liabilities['8.3'], 'Só a importação ficou em 8.3: o lançamento errado foi estornado.');
        $assets = array_column($sheet['assets']['rows'], null, 'code');
        self::assertGreaterThan(0, $assets['7.1']['investments_cents'], 'Há saldo aplicado.');

        $plan = LabSampleYear::plan($this->today);
        $dre = (new AccountingReports($this->pdo, $scope))->incomeStatement($user, $condominium, $plan['start'], $plan['year'] . '-12-31');
        self::assertGreaterThan(0, $dre['totals']['revenue_cents']);
        self::assertGreaterThan(0, $dre['totals']['expense_cents']);
        $late = (new Agreements($this->pdo, $scope))->delinquency($user, $condominium, $this->today);
        self::assertGreaterThanOrEqual(3, $late['totals']['units'], 'Inadimplência: 101, 102 e 106 (a 103 entrou no acordo).');
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $counts = [];
        foreach (['erp_entries', 'erp_patrimonial_entries', 'erp_bank_transfers', 'erp_chart_account_openings', 'erp_agreements', 'erp_patrimonial_imports',
            'erp_chart_accounts', 'erp_bank_accounts', 'erp_notification_outbox'] as $table) {
            $counts[$table] = (int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        $counts['sum_entries'] = (int)$this->pdo->query('SELECT SUM(amount_cents) FROM erp_entries')->fetchColumn();
        return $counts;
    }
}
