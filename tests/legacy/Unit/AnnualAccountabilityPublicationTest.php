<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Accountability;
use Source\Services\Erp\AccountabilityPublications;
use Source\Services\Erp\AnnualAccountability;

/** ADR 0183: publicação congelada da prestação de contas anual. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). */
final class AnnualAccountabilityPublicationTest extends TestCase
{
    private PDO $pdo;
    private AccountabilityPublications $service;

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
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER,
                opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER,
                transferred_on TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT, revenue_cents INTEGER, expense_cents INTEGER,
                open_revenue_cents INTEGER, open_expense_cents INTEGER, summary_json TEXT, closed_by INTEGER, closed_at TEXT, reopened_by INTEGER, reopened_at TEXT, reopen_reason TEXT)',
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT,
                updated_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE (condominium_id, chart_account_id))',
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_date TEXT, description TEXT, amount_cents INTEGER, debit_account_id INTEGER,
                credit_account_id INTEGER, bank_account_id INTEGER, linked_entry_id INTEGER, import_id INTEGER, status TEXT DEFAULT 'active', reversal_of_id INTEGER, created_by INTEGER)",
            'CREATE TABLE erp_budget_lines (id INTEGER PRIMARY KEY, condominium_id INTEGER, year INTEGER, chart_account_id INTEGER, month INTEGER, amount_cents INTEGER)',
            "CREATE TABLE erp_accountability_publications (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, kind TEXT NOT NULL DEFAULT 'monthly', competence TEXT NOT NULL,
                version INTEGER NOT NULL, sha256 TEXT NOT NULL, payload_json TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'current', notes TEXT, published_by INTEGER,
                published_at TEXT NOT NULL, withdrawn_by INTEGER, withdrawn_at TEXT, withdraw_reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (condominium_id, kind, competence, version))",
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
            (90, NULL, '9', 'Patrimônio Líquido', 'equity', 'sintetica'), (91, NULL, '9.1', 'Resultados acumulados', 'equity', 'analitica'),
            (92, NULL, '9.2', 'Resultado do exercício', 'equity', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 100000, '2025-01-01')");
        $this->pdo->exec("INSERT INTO erp_chart_account_openings (condominium_id, chart_account_id, opened_on, amount_cents) VALUES (1, 91, '2025-01-01', 100000)");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on) VALUES
            (1, 'receita', 2, 10, 'Taxa jun', 200000, '2025-06', '2025-06-10', '2025-06-10'), (1, 'despesa', 4, 10, 'Energia dez', 40000, '2025-12', '2025-12-20', '2025-12-20')");
        // Fecha 2025 inteiro, menos dezembro.
        $close = $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status, closed_by, closed_at) VALUES (1, ?, 'closed', 7, '2026-01-05 10:00:00')");
        for ($m = 1; $m <= 11; $m++) {
            $close->execute([sprintf('2025-%02d', $m)]);
        }
        $scope = new CondominiumScope($this->pdo);
        $none = static fn (User $u, int $c, string $asOf): array => ['as_of' => $asOf, 'units' => [], 'totals' => []];
        $this->service = new AccountabilityPublications($this->pdo, $scope, new Accountability($this->pdo, $scope, $none), new AnnualAccountability($this->pdo, $scope, $none));
    }

    public function testAnnualPublicationNeedsEveryMonthClosedAndKeepsItsOwnVersions(): void
    {
        try {
            $this->service->publishAnnual($this->user(), 1, 2025);
            self::fail('Publicou o ano com dezembro aberto.');
        } catch (DomainException $error) {
            self::assertStringContainsString('2025-12', $error->getMessage());
        }
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status, closed_by, closed_at) VALUES (1, '2025-12', 'closed', 7, '2026-01-05 10:00:00')");
        $annual = $this->service->publishAnnual($this->user(), 1, '2025', 'Para a AGO de 2026');
        self::assertSame([true, 'annual', '2025', 1, 'current'], [$annual['created'], $annual['kind'], $annual['competence'], $annual['version'], $annual['status']]);
        self::assertFalse($this->service->publishAnnual($this->user(), 1, 2025)['created'], 'Mesmo conteúdo: devolve a versão atual.');

        // O mensal de dezembro é outra publicação, com a própria versão 1.
        $monthly = $this->service->publish($this->user(), 1, '2025-12');
        self::assertSame(['monthly', '2025-12', 1], [$monthly['kind'], $monthly['competence'], $monthly['version']]);
        self::assertSame([[$annual['id'], 'annual']], array_map(static fn (array $r): array => [$r['id'], $r['kind']],
            $this->service->list($this->user(), 1, '2025', 'annual')['rows']));
        self::assertCount(2, $this->service->list($this->user(), 1)['rows'], 'Sem filtro: os dois tipos.');
        self::assertSame([$monthly['id']], array_column($this->service->list($this->user(), 1, '2025-12')['rows'], 'id'), 'Mês sem tipo = mensal.');

        $frozen = $this->service->find($this->user(), $annual['id']);
        self::assertTrue($frozen['intact']);
        self::assertSame([2025, 200000, 40000, 12], [$frozen['payload']['year'], $frozen['payload']['summary']['revenue_cents'],
            $frozen['payload']['summary']['expense_cents'], $frozen['payload']['summary']['months_closed']]);
        $sections = array_values(array_unique(array_column(AnnualAccountability::csvTable($frozen['payload'])['rows'], 0)));
        self::assertContains('Balanço: Ativo', $sections, 'O CSV da versão anual usa o formato anual.');

        // Retira e publica de novo: versão 2 do anual; o mensal não muda.
        $this->service->withdraw($this->user(), $annual['id'], 'Faltou a energia de dezembro');
        $again = $this->service->publishAnnual($this->user(), 1, 2025);
        self::assertSame(['annual', 2], [$again['kind'], $again['version']]);
        self::assertSame('current', $this->service->find($this->user(), $monthly['id'])['status']);
    }

    public function testValidation(): void
    {
        foreach ([fn () => $this->service->list($this->user(), 1, null, 'weekly'), fn () => $this->service->list($this->user(), 1, '2025-13', 'annual'),
            fn () => $this->service->publishAnnual($this->user(), null, 2025), fn () => $this->service->publishAnnual($this->user(), 1, 1999)] as $call) {
            try {
                $call();
                self::fail('Entrada inválida passou.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $migration = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261111_0713_accountability_publication_kinds.sql');
        self::assertStringContainsString("ADD COLUMN IF NOT EXISTS `kind` enum('monthly','annual') NOT NULL DEFAULT 'monthly'", $migration);
        self::assertStringContainsString('(`condominium_id`, `kind`, `competence`, `version`)', $migration);
        self::assertFileExists(dirname(__DIR__, 3) . '/database/migrations/20261111_0713_accountability_publication_kinds.down.sql');
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/AccountabilityPublications.php');
        self::assertStringContainsString("publishAnnual(\$user", $controller);
        self::assertStringContainsString("AnnualAccountability::csvTable(\$publication['payload'])", $controller);
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
