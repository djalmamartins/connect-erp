<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\FeeSimulation;

/**
 * ADR 0189: simulação da taxa condominial a partir da previsão. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2). O condomínio 1 tem histórico como o do laboratório (começa no meio do ano); o 2, histórico curto.
 */
final class FeeSimulationTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private PDO $pdo;
    private FeeSimulation $service;

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
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, ideal_fraction TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            'CREATE TABLE erp_budget_lines (id INTEGER PRIMARY KEY, condominium_id INTEGER, year INTEGER, chart_account_id INTEGER, month INTEGER, amount_cents INTEGER)',
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, chart_account_id INTEGER, description TEXT, amount_cents INTEGER,
                competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'), (4, NULL, '2.01', 'Limpeza', 'despesa', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, ideal_fraction, status, deleted_at) VALUES
            (11, 1, '101', '0.250000', 'active', NULL), (12, 1, '102', '0.250000', 'active', NULL), (13, 1, '201', '0.500000', 'active', NULL),
            (19, 1, '999', NULL, 'inactive', NULL), (18, 1, '998', NULL, 'active', '2026-01-01'),
            (21, 2, '301', '0.500000', 'active', NULL), (22, 2, '302', NULL, 'active', NULL), (31, 3, 'X', '1', 'active', NULL)");
        $lines = [];
        for ($m = 1; $m <= 12; $m++) {
            $lines[] = "(1, 2027, 4, {$m}, 100000)";
            $lines[] = "(1, 2027, 2, {$m}, 90000)";
            $lines[] = "(3, 2027, 4, {$m}, 999999)";
            if ($m <= 6) {
                $lines[] = "(2, 2027, 4, {$m}, 60000)";
            }
        }
        $this->pdo->exec('INSERT INTO erp_budget_lines (condominium_id, year, chart_account_id, month, amount_cents) VALUES ' . implode(', ', $lines));
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, unit_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on, status, deleted_at) VALUES
            (1, 11, 'receita', 2, 'Taxa jul', 25000, '2026-07', '2026-07-10', '2026-07-10', 'active', NULL),
            (1, 11, 'receita', 2, 'Taxa ago', 25000, '2026-08', '2026-08-10', '2026-08-10', 'active', NULL),
            (1, 11, 'receita', 2, 'Taxa set', 25000, '2026-09', '2026-09-10', '2026-09-10', 'active', NULL),
            (1, 11, 'receita', 2, 'Taxa out', 25000, '2026-10', '2026-10-10', '2026-10-10', 'active', NULL),
            (1, 11, 'receita', 2, 'Água out', 3000, '2026-10', '2026-10-10', NULL, 'active', NULL),
            (1, 12, 'receita', 2, 'Taxa out', 25000, '2026-10', '2026-10-10', NULL, 'active', NULL),
            (1, 12, 'receita', 2, 'Cancelada', 99000, '2026-10', '2026-10-10', NULL, 'inactive', NULL),
            (1, 12, 'receita', 2, 'Excluída', 98000, '2026-10', '2026-10-10', NULL, 'active', '2026-10-20'),
            (1, 12, 'receita', 2, 'Antes da base', 97000, '2025-10', '2025-10-10', NULL, 'active', NULL),
            (1, 12, 'receita', 2, 'Vence depois', 96000, '2026-11', '2026-11-05', NULL, 'active', NULL),
            (1, NULL, 'despesa', 4, 'Despesa', 95000, '2026-10', '2026-10-10', NULL, 'active', NULL),
            (3, 31, 'receita', 2, 'Outro condomínio', 94000, '2026-10', '2026-10-10', NULL, 'active', NULL),
            (2, 21, 'receita', 2, 'Taxa set', 15000, '2026-09', '2026-09-10', '2026-09-10', 'active', NULL),
            (2, 21, 'receita', 2, 'Taxa ago', 15000, '2026-08', '2026-08-10', NULL, 'active', NULL)");
        $this->service = new FeeSimulation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testFeeByFractionComparedWithLastMonth(): void
    {
        $s = $this->service->build($this->user(), 1, 2027, '10', '5', null, self::TODAY);
        self::assertFalse($s['blocked']);
        self::assertSame([1200000, 1080000, 12, 115789, 10000], [$s['budget_expense_cents'], $s['budget_revenue_cents'], $s['budget_expense_months'],
            $s['monthly_total_cents'], $s['reserve_cents']], '100.000 ao mês, +10% de reserva, ÷ 0,95 de margem.');
        self::assertSame([['101', 28947, 28000, 3.38], ['102', 28947, 25000, 15.79], ['201', 57895, null, null]],
            array_map(static fn (array $r): array => [$r['unit_label'], $r['fee_cents'], $r['billed_last_month_cents'], $r['variation_percent']], $s['rows']),
            'Pela fração, sobra pelo maior resto; inativa e excluída fora; cobrado em 2026-10 (cancelada, excluída e despesa fora).');
        self::assertSame(115789, array_sum(array_column($s['rows'], 'fee_cents')));
        self::assertSame(['2026-10', 53000], [$s['compared_competence'], $s['billed_last_month_cents']]);
        self::assertSame(['2025-11-01', '2026-10-31', 4, 128000, 28000, 21.88], array_values($s['observed_delinquency']),
            'Inadimplência observada nos 12 meses completos por vencimento; antes, depois e outro condomínio fora.');
        self::assertSame([], $s['warnings']);

        $e = $this->service->build($this->user(), 1, '2027', '10', '5', 'equal', self::TODAY);
        self::assertSame([38597, 38596, 38596], array_column($e['rows'], 'fee_cents'), 'Partes iguais; o centavo vai para a primeira.');
    }

    public function testShortHistoryMissingFractionAndNoBudget(): void
    {
        $s = $this->service->build($this->user(), 2, 2027, 0, 0, 'fraction', self::TODAY);
        self::assertTrue($s['blocked']);
        self::assertSame(['302'], $s['missing_fraction']);
        self::assertSame(30000, $s['monthly_total_cents'], 'Previsão parcial: o ano ÷ 12.');
        self::assertNull($s['observed_delinquency']['percent'], 'Só 2 meses de faturamento: não sugere.');
        self::assertSame(['short_history', 'partial_budget'], array_column($s['warnings'], 'code'));

        $e = $this->service->build($this->user(), 2, 2027, 0, 0, 'equal', self::TODAY);
        self::assertFalse($e['blocked']);
        self::assertSame([[15000, null], [15000, null]], array_map(static fn (array $r): array => [$r['fee_cents'], $r['billed_last_month_cents']], $e['rows']));
        self::assertSame(['short_history', 'partial_budget', 'no_billing_last_month'], array_column($e['warnings'], 'code'));

        $n = $this->service->build($this->user(), 1, 2028, 0, 0, null, self::TODAY);
        self::assertTrue($n['blocked']);
        self::assertSame([0, []], [$n['budget_expense_cents'], $n['rows']]);
    }

    public function testPureRuleScopeAndValidation(): void
    {
        self::assertSame(100000, FeeSimulation::monthlyTotal(1200000, 0, 0));
        self::assertSame(115789, FeeSimulation::monthlyTotal(1200000, 10, 5));
        self::assertSame(0, FeeSimulation::monthlyTotal(1, 0, 0));
        try {
            $this->service->build($this->user(), 3, 2027, 0, 0, null, self::TODAY);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[null, 2027, 0, 0, null], [1, 1999, 0, 0, null], [1, 2032, 0, 0, null], [1, 2027, 31, 0, null], [1, 2027, -1, 0, null], [1, 2027, ['x'], 0, null],
            [1, 2027, 0, '50,5', null], [1, 2027, 0, 'abc', null], [1, 2027, 0, 0, 'unit']] as [$c, $y, $r, $d, $split]) {
            try {
                $this->service->build($this->user(), $c, $y, $r, $d, $split, self::TODAY);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $y, $r, $d, $split]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(7.5, $this->service->build($this->user(), 1, 2027, '7,5', null, null, self::TODAY)['reserve_percent'], 'Vírgula decimal.');
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/FeeSimulation.php');
        foreach (["Access::can('erp.settings.view', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/budgets/fee-simulation", "FeeSimulation:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents(dirname(__DIR__, 3) . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
