<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CashForecast;

/** ADR 0186: previsão de caixa do condomínio. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). */
final class CashForecastTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private PDO $pdo;
    private CashForecast $service;

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
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, recurring_entry_id INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT DEFAULT 'corrente', parent_bank_account_id INTEGER,
                opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER,
                transferred_on TEXT, status TEXT DEFAULT 'active')",
            "CREATE TABLE erp_recurring_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, amount_cents INTEGER, day_of_month INTEGER, starts_competence TEXT,
                ends_competence TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'), (4, NULL, '2.01', 'Limpeza', 'despesa', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 100000, '2026-01-01')");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on, recurring_entry_id, status, deleted_at) VALUES
            (1, 'receita', 2, 10, 'Taxa out paga', 50000, '2026-10', '2026-10-10', '2026-10-10', NULL, 'active', NULL),
            (1, 'despesa', 4, 10, 'Limpeza out paga', 30000, '2026-10', '2026-10-20', '2026-10-20', NULL, 'active', NULL),
            (1, 'receita', 2, NULL, 'Taxa nov', 40000, '2026-11', '2026-11-20', NULL, NULL, 'active', NULL),
            (1, 'receita', 2, NULL, 'Taxa vencida', 10000, '2026-11', '2026-11-05', NULL, NULL, 'active', NULL),
            (1, 'despesa', 4, NULL, 'Limpeza dez (gerada do fixo)', 25000, '2026-12', '2026-12-15', NULL, 1, 'active', NULL),
            (1, 'despesa', 4, NULL, 'Conta vencida', 7000, '2026-11', '2026-11-01', NULL, NULL, 'active', NULL),
            (1, 'receita', 2, NULL, 'Taxa jan', 65000, '2027-01', '2027-01-10', NULL, NULL, 'active', NULL),
            (1, 'despesa', 4, NULL, 'Cancelada', 99999, '2026-11', '2026-11-25', NULL, NULL, 'inactive', NULL),
            (1, 'receita', 2, NULL, 'Excluída', 88888, '2026-11', '2026-11-25', NULL, NULL, 'active', '2026-11-01'),
            (3, 'despesa', 4, NULL, 'Fora', 77777, '2026-11', '2026-11-25', NULL, NULL, 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_recurring_entries (id, condominium_id, nature, amount_cents, day_of_month, starts_competence, ends_competence, status, deleted_at) VALUES
            (1, 1, 'despesa', 150000, 15, '2026-01', NULL, 'active', NULL),
            (2, 1, 'receita', 1000, 5, '2026-11', '2026-12', 'active', NULL),
            (3, 1, 'despesa', 50000, 10, '2026-01', NULL, 'inactive', NULL),
            (4, 3, 'despesa', 50000, 10, '2026-01', NULL, 'active', NULL)");
        $this->service = new CashForecast($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testForecastMonthByMonth(): void
    {
        $f = $this->service->build($this->user(), 1, 3, self::TODAY);
        self::assertSame([120000, 10000, 7000], [$f['opening_cash_cents'], $f['overdue_receivables_cents'], $f['overdue_payables_cents']],
            'Banco hoje; vencidos à parte (cancelada, excluída e outro condomínio não entram).');
        $months = array_column($f['months'], null, 'competence');
        self::assertSame(['2026-11', '2026-12', '2027-01'], array_keys($months));
        self::assertSame(['2026-11-11', '2026-11-30'], [$months['2026-11']['from'], $months['2026-11']['to']], 'O mês corrente começa hoje.');
        self::assertSame([40000, 0, 0, 150000, 157000, 3000], [$months['2026-11']['receivables_cents'], $months['2026-11']['payables_cents'],
            $months['2026-11']['recurring_in_cents'], $months['2026-11']['recurring_out_cents'], $months['2026-11']['out_cents'], $months['2026-11']['closing_cents']],
            'Novembro: o fixo de receita do dia 5 já passou; o vencido a pagar entra no primeiro mês.');
        self::assertSame([25000, 1000, 0, -21000], [$months['2026-12']['payables_cents'], $months['2026-12']['recurring_in_cents'],
            $months['2026-12']['recurring_out_cents'], $months['2026-12']['closing_cents']], 'Dezembro: o fixo de despesa já foi gerado e não conta duas vezes.');
        self::assertSame([65000, 0, 150000, -106000], [$months['2027-01']['receivables_cents'], $months['2027-01']['recurring_in_cents'],
            $months['2027-01']['recurring_out_cents'], $months['2027-01']['closing_cents']], 'Janeiro: o fixo de receita terminou em dezembro.');
        self::assertSame(['competence' => '2027-01', 'closing_cents' => -106000], $f['lowest']);
        self::assertSame(['negative_balance', 'overdue_payables'], array_column($f['warnings'], 'code'));
        self::assertSame('2026-12', $f['warnings'][0]['competence'], 'Avisa o primeiro mês negativo.');
        self::assertCount(6, $this->service->build($this->user(), 1, null, self::TODAY)['months'], 'Padrão: 6 meses.');
    }

    public function testPureRulesScopeAndValidation(): void
    {
        self::assertSame(['competence' => '2027-02', 'from' => '2027-02-01', 'to' => '2027-02-28'], CashForecast::windows('2026-12-31', 3)[2], 'Virada do ano e fevereiro.');
        $calm = CashForecast::project(1000, 0, [['competence' => '2026-11', 'from' => '2026-11-11', 'to' => '2026-11-30', 'receivables_cents' => 500,
            'payables_cents' => 200, 'recurring_in_cents' => 0, 'recurring_out_cents' => 0]]);
        self::assertSame([1300, []], [$calm['months'][0]['closing_cents'], $calm['warnings']]);
        try {
            $this->service->build($this->user(), 3, 3, self::TODAY);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[null, 3], [1, 0], [1, 13], [1, 'abc']] as [$c, $m]) {
            try {
                $this->service->build($this->user(), $c, $m, self::TODAY);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $m]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/CashForecast.php');
        foreach (["Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
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
