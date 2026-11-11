<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Source\Models\User;
use Source\Services\Erp\LabSampleData;
use Source\Services\Erp\LabSampleYear;

/**
 * ADR 0175: o --remover apaga também o ano contábil de exemplo (ADR 0173). Unit com SQLite (Pdo\Sqlite quando
 * existe; PDO no PHP 8.2), com chaves estrangeiras ligadas para conferir a ordem. O PDO de teste só traduz o
 * dialeto do MySQL dos services (FOR UPDATE, INSERT IGNORE e <=>).
 */
final class LabSampleYearRemovalTest extends TestCase
{
    private const MYSQL = ['INSERT IGNORE', ' FOR UPDATE', ' <=> '];
    private const SQLITE = ['INSERT OR IGNORE', '', ' IS '];

    private PDO $pdo;
    private string $today;
    private User $user;

    protected function setUp(): void
    {
        $this->today = date('Y-m-d');
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(LabSampleYearRemovalTest::dialect()[0], LabSampleYearRemovalTest::dialect()[1], $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(LabSampleYearRemovalTest::dialect()[0], LabSampleYearRemovalTest::dialect()[1], $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $now = static fn (): string => date('Y-m-d H:i:s');
        $this->pdo instanceof \Pdo\Sqlite ? $this->pdo->createFunction('NOW', $now, 0) : $this->pdo->sqliteCreateFunction('NOW', $now, 0);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, level INTEGER)',
            'CREATE TABLE access_user_roles (user_id INTEGER PRIMARY KEY, role_id INTEGER, assigned_by INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, group_name TEXT, description TEXT)',
            'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT, assigned_by INTEGER)',
            "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, status TEXT DEFAULT 'confirmed')",
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, legal_name TEXT, document TEXT, status TEXT DEFAULT 'active', city TEXT, state TEXT, deleted_at TEXT)",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER REFERENCES condominiums (id), granted_by INTEGER, PRIMARY KEY (user_id, condominium_id))',
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL REFERENCES condominiums (id), block TEXT, number TEXT,
                label TEXT GENERATED ALWAYS AS (number) VIRTUAL, type TEXT, notes TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER REFERENCES condominiums (id), unit_id INTEGER REFERENCES erp_units (id), unit_label TEXT,
                relationship TEXT, name TEXT, document TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER REFERENCES condominiums (id), code TEXT, name TEXT, nature TEXT, kind TEXT, notes TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_cost_centers (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, condominium_id INTEGER, legal_name TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL REFERENCES condominiums (id), name TEXT, bank_code TEXT, branch TEXT,
                account_number TEXT, kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER REFERENCES erp_bank_accounts (id), opening_balance_cents INTEGER DEFAULT 0,
                opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_agreements (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL REFERENCES condominiums (id), unit_id INTEGER REFERENCES erp_units (id), status TEXT,
                chart_account_id INTEGER REFERENCES erp_chart_accounts (id), original_cents INTEGER, fees_cents INTEGER, discount_cents INTEGER, total_cents INTEGER,
                installments INTEGER, first_due_on TEXT, notes TEXT, created_by INTEGER, cancelled_by INTEGER, cancelled_at TEXT, cancel_reason TEXT, broken_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL REFERENCES condominiums (id), nature TEXT,
                chart_account_id INTEGER REFERENCES erp_chart_accounts (id), cost_center_id INTEGER, bank_account_id INTEGER REFERENCES erp_bank_accounts (id),
                supplier_id INTEGER, unit_id INTEGER REFERENCES erp_units (id), description TEXT, amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT,
                document_number TEXT, notes TEXT, invoice_item_id INTEGER, recurring_entry_id INTEGER, agreement_id INTEGER REFERENCES erp_agreements (id),
                agreement_role TEXT, agreement_installment INTEGER, extra_charge_id INTEGER, status TEXT DEFAULT 'active', created_by INTEGER, updated_by INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL REFERENCES condominiums (id),
                from_bank_account_id INTEGER REFERENCES erp_bank_accounts (id), to_bank_account_id INTEGER REFERENCES erp_bank_accounts (id), amount_cents INTEGER,
                transferred_on TEXT, description TEXT, status TEXT DEFAULT 'active', created_by INTEGER, cancelled_by INTEGER, cancelled_at TEXT, cancel_reason TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)",
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER REFERENCES condominiums (id),
                chart_account_id INTEGER REFERENCES erp_chart_accounts (id), opened_on TEXT, amount_cents INTEGER, notes TEXT, updated_by INTEGER, created_at TEXT, updated_at TEXT,
                UNIQUE (condominium_id, chart_account_id))',
            "CREATE TABLE erp_patrimonial_imports (id INTEGER PRIMARY KEY, condominium_id INTEGER REFERENCES condominiums (id), idempotency_key TEXT, sha256 TEXT, original_name TEXT,
                content BLOB, status TEXT DEFAULT 'previewed', rows_count INTEGER, error_count INTEGER, summary_json TEXT, created_by INTEGER, applied_by INTEGER, applied_at TEXT,
                UNIQUE (condominium_id, idempotency_key))",
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER REFERENCES condominiums (id), entry_date TEXT, description TEXT, amount_cents INTEGER,
                debit_account_id INTEGER REFERENCES erp_chart_accounts (id), credit_account_id INTEGER REFERENCES erp_chart_accounts (id),
                bank_account_id INTEGER REFERENCES erp_bank_accounts (id), linked_entry_id INTEGER REFERENCES erp_entries (id),
                import_id INTEGER REFERENCES erp_patrimonial_imports (id), status TEXT DEFAULT 'active', reversal_of_id INTEGER UNIQUE REFERENCES erp_patrimonial_entries (id),
                reversal_reason TEXT, created_by INTEGER)",
            'CREATE TABLE erp_agreement_originals (id INTEGER PRIMARY KEY, agreement_id INTEGER REFERENCES erp_agreements (id), entry_id INTEGER REFERENCES erp_entries (id), amount_cents INTEGER)',
            'CREATE TABLE erp_bank_charges (id INTEGER PRIMARY KEY, entry_id INTEGER REFERENCES erp_entries (id), status TEXT)',
            'CREATE TABLE erp_unit_documents (id INTEGER PRIMARY KEY, status TEXT)',
            'CREATE TABLE erp_unit_document_entries (document_id INTEGER, entry_id INTEGER REFERENCES erp_entries (id))',
            'CREATE TABLE erp_notification_outbox (id INTEGER PRIMARY KEY, condominium_id INTEGER, event_type TEXT, event_key TEXT UNIQUE, subject_table TEXT, subject_id INTEGER,
                payload_json TEXT, created_by INTEGER, available_at TEXT)',
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
            'CREATE TABLE erp_accountability_publications (id INTEGER PRIMARY KEY, condominium_id INTEGER REFERENCES condominiums(id), competence TEXT, version INTEGER, status TEXT)',
            'CREATE TABLE erp_billing_rules (id INTEGER PRIMARY KEY, condominium_id INTEGER)',
            'CREATE TABLE erp_price_indexes (id INTEGER PRIMARY KEY, code TEXT, competence TEXT, rate_percent REAL)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO users (id, first_name, email) VALUES (7, 'Ana', 'ana@x.test')");
        $this->pdo->exec("INSERT INTO access_roles (id, name, slug, level) VALUES (1, 'Desenvolvedor', 'developer', 100)");
        $this->pdo->exec('INSERT INTO access_user_roles (user_id, role_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (70, NULL, '7', 'Ativo', 'asset', 'sintetica'), (71, NULL, '7.1', 'Disponível', 'asset', 'sintetica'), (72, NULL, '7.2', 'Contas a receber', 'asset', 'analitica'),
            (80, NULL, '8', 'Passivo', 'liability', 'sintetica'), (81, NULL, '8.1', 'Contas a pagar', 'liability', 'analitica'),
            (82, NULL, '8.2', 'Fundo de reserva', 'liability', 'analitica'), (83, NULL, '8.3', 'Outros fundos', 'liability', 'analitica'),
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica')");
        $this->user = new User();
        $this->user->id = 7;
    }

    /** @return array{0:list<string>, 1:list<string>} */
    public static function dialect(): array
    {
        return [self::MYSQL, self::SQLITE];
    }

    public function testGenerateRemoveAndGenerateAgain(): void
    {
        $first = $this->generate();
        self::assertSame(1, $first['agreements']);
        $before = $this->counts();
        // Mês fechado e prestação publicada no lab (ADR 0179): saem junto, senão a FK trava a remoção.
        $aurora = (int)$this->pdo->query("SELECT id FROM condominiums WHERE name = 'EXEMPLO Residencial Aurora'")->fetchColumn();
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES ({$aurora}, '2026-05', 'closed')");
        $this->pdo->exec("INSERT INTO erp_accountability_publications (condominium_id, competence, version, status) VALUES ({$aurora}, '2026-05', 1, 'current')");

        $removed = $this->remove();
        self::assertSame([1, 1], [$removed['accountability_publications'], $removed['month_closings']]);
        self::assertSame([1, 1, 2, 2], [$removed['agreements'], $removed['patrimonial_imports'], $removed['transfers'], $removed['openings']]);
        self::assertSame(1, $removed['investment_links']);
        self::assertSame(3, $removed['agreement_installments']);
        self::assertSame(2, $removed['condominiums']);
        self::assertSame(array_fill_keys(array_keys($before), 0), $this->counts(), 'Depois de remover, não sobra nada do exemplo.');
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM condominiums WHERE name LIKE 'EXEMPLO %'")->fetchColumn());
        self::assertSame(10, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_chart_accounts WHERE condominium_id IS NULL')->fetchColumn(), 'O plano padrão fica.');

        $second = $this->generate();
        self::assertSame($first, $second, 'A segunda geração cria tudo de novo.');
        self::assertSame($before, $this->counts());
    }

    public function testRefusesWhenSomethingWithoutTheMarkIsLinked(): void
    {
        $this->generate();
        $condominium = (int)$this->pdo->query("SELECT id FROM condominiums WHERE name = 'EXEMPLO Residencial Aurora'")->fetchColumn();
        $account = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE name = 'EXEMPLO Energia elétrica'")->fetchColumn();
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on)
            VALUES (9001, {$condominium}, 'despesa', {$account}, 'Conta de verdade lançada por alguém', 1000, '2026-01', '2026-01-10')");
        $before = $this->counts();
        try {
            $this->remove();
            self::fail('Apagou com um lançamento sem a marca.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('lançamento #9001', $error->getMessage());
        }
        self::assertSame($before, $this->counts(), 'Nada foi apagado.');
    }

    public function testRemoveWithoutTheYearStillWorks(): void
    {
        (new LabSampleData($this->pdo))->create($this->today);
        $removed = $this->remove();
        self::assertSame([0, 2], [$removed['agreements'], $removed['condominiums']]);
    }

    /** @return array<string, int> */
    private function generate(): array
    {
        (new LabSampleData($this->pdo))->create($this->today);
        return (new LabSampleYear($this->pdo))->create($this->user, $this->today);
    }

    /** O mesmo caminho do --remover: o ano sai primeiro, na transação dos cadastros. @return array<string, int> */
    private function remove(): array
    {
        $year = new LabSampleYear($this->pdo);
        return (new LabSampleData($this->pdo))->remove(static fn (): array => $year->purge());
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (['condominiums', 'erp_units', 'erp_residents', 'erp_entries', 'erp_patrimonial_entries', 'erp_patrimonial_imports', 'erp_bank_transfers',
            'erp_chart_account_openings', 'erp_agreements', 'erp_agreement_originals', 'erp_bank_accounts', 'erp_notification_outbox'] as $table) {
            $counts[$table] = (int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        $counts['example_chart_accounts'] = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_chart_accounts WHERE condominium_id IS NOT NULL')->fetchColumn();
        return $counts;
    }
}
