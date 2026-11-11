<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ChartOpenings;
use Source\Services\Erp\ClosingReadiness;

/** ADR 0181: conferência antes de fechar o mês. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). */
final class ClosingReadinessTest extends TestCase
{
    private PDO $pdo;

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
            "CREATE TABLE erp_bank_statement_lines (id INTEGER PRIMARY KEY, condominium_id INTEGER, bank_account_id INTEGER, posted_on TEXT, amount_cents INTEGER, status TEXT)",
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
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 50000, '2026-01-01'),
            (11, 1, 'Reserva', 0, '2026-01-01')");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on) VALUES
            (1, 'receita', 2, 10, 'Taxa set', 80000, '2026-09', '2026-09-10', '2026-09-10'),
            (1, 'receita', 2, 10, 'Taxa out', 100000, '2026-10', '2026-10-10', '2026-10-10'),
            (1, 'receita', 2, NULL, 'Taxa out 102', 40000, '2026-10', '2026-10-10', NULL),
            (1, 'despesa', 4, 11, 'Energia out', 30000, '2026-10', '2026-10-20', '2026-10-20'),
            (1, 'despesa', 4, NULL, 'Água out paga sem banco', 5000, '2026-10', '2026-10-21', '2026-10-21')");
        $this->pdo->exec("INSERT INTO erp_bank_statement_lines (condominium_id, bank_account_id, posted_on, amount_cents, status) VALUES
            (1, 10, '2026-10-15', -1234, 'unmatched'), (1, 10, '2026-10-16', 999, 'matched'), (1, 10, '2026-09-30', -1, 'unmatched'), (3, 10, '2026-10-15', -5, 'unmatched')");
    }

    public function testChecksBeforeClosingTheMonth(): void
    {
        $service = new ClosingReadiness($this->pdo, new CondominiumScope($this->pdo));
        $result = $service->check($this->user(), 1, '2026-10');
        $checks = array_column($result['checks'], null, 'code');
        self::assertSame(['2026-10', '2026-10-31'], [$result['competence'], $result['as_of']]);
        self::assertFalse($result['ready']);
        self::assertSame(['balance_sheet', 'paid_without_bank', 'previous_open', 'open_entries', 'unmatched_statement', 'negative_bank'], array_keys($checks));
        self::assertSame('error', $checks['balance_sheet']['level'], 'O saldo do banco sem saldo inicial do PL faz o Balanço não fechar.');
        // 500,00 do banco sem saldo inicial do PL + 50,00 da água paga sem banco (a despesa baixa o resultado, mas o caixa não muda).
        self::assertSame(50000 + 5000, $checks['balance_sheet']['amount_cents']);
        self::assertSame([1, 'error'], [$checks['paid_without_bank']['count'], $checks['paid_without_bank']['level']]);
        self::assertSame(1, $checks['previous_open']['count'], 'Setembro tem lançamento e não foi fechado.');
        self::assertSame(40000, $checks['open_entries']['amount_cents']);
        self::assertSame([1, -1234], [$checks['unmatched_statement']['count'], $checks['unmatched_statement']['amount_cents']], 'Só o extrato do mês e do condomínio.');
        self::assertStringContainsString('Reserva', $checks['negative_bank']['message'], 'A energia saiu da conta reserva, que não tinha saldo.');
        self::assertSame([2, 4], [$result['errors'], $result['warnings']]);

        // Corrige: saldo inicial do PL igual ao do banco, conta bancária na água, setembro fechado, extrato conciliado.
        (new ChartOpenings($this->pdo, new CondominiumScope($this->pdo)))->set($this->user(), 1, 91, '2026-01-01', 50000);
        $this->pdo->exec("UPDATE erp_entries SET bank_account_id = 10 WHERE description = 'Água out paga sem banco'");
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-09', 'closed')");
        $this->pdo->exec("UPDATE erp_bank_statement_lines SET status = 'matched'");
        $this->pdo->exec("UPDATE erp_entries SET bank_account_id = 10 WHERE description = 'Energia out'");
        $fixed = $service->check($this->user(), 1, '2026-10');
        self::assertTrue($fixed['ready']);
        self::assertSame(['open_entries'], array_column($fixed['checks'], 'code'), 'Só sobra o aviso das receitas em aberto.');

        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-10', 'closed')");
        self::assertSame('info', array_column($service->check($this->user(), 1, '2026-10')['checks'], null, 'code')['already_closed']['level']);
    }

    public function testScopeValidationAndPureRule(): void
    {
        $service = new ClosingReadiness($this->pdo, new CondominiumScope($this->pdo));
        try {
            $service->check($this->user(), 3, '2026-10');
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[null, '2026-10'], [1, '2026-13'], [1, null]] as [$condominium, $month]) {
            try {
                $service->check($this->user(), $condominium, $month);
                self::fail('Entrada inválida passou: ' . json_encode([$condominium, $month]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $clean = ClosingReadiness::evaluate(['status' => 'open', 'previous_competence' => '2026-09', 'previous_status' => null, 'previous_entries' => 0,
            'open_revenue_cents' => 0, 'open_expense_cents' => 0, 'unmatched_lines' => 0, 'unmatched_cents' => 0, 'negative_banks' => [],
            'balance_difference_cents' => 0, 'paid_without_bank' => 0]);
        self::assertSame(['ready' => true, 'errors' => 0, 'warnings' => 0, 'checks' => []], $clean, 'Primeiro mês, tudo certo: pronto, sem aviso.');
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/ClosingReadiness.php');
        foreach (["Access::can('erp.closing.view', \$user)", "Access::can('erp.chart.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
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
