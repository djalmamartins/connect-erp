<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ExpenseBenchmark;

/**
 * ADR 0201: comparativo de despesas entre condomínios. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2).
 * Aurora, Brisa e Cedro têm histórico; Duna começou agora (histórico curto); Eco não tem unidade; Fora está fora do escopo.
 */
final class ExpenseBenchmarkTest extends TestCase
{
    private ExpenseBenchmark $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, nature TEXT, amount_cents INTEGER, competence TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)"] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Cedro'), (4, 'Duna'), (5, 'Eco'), (6, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2), (7, 3), (7, 4), (7, 5)');
        $units = [];
        foreach ([1 => 10, 2 => 20, 3 => 5, 4 => 8, 6 => 2] as $condo => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $units[] = "({$condo}, 'U{$i}', 'active', NULL)";
            }
        }
        $units[] = "(1, 'Inativa', 'inactive', NULL)";
        $units[] = "(1, 'Excluída', 'active', '2026-01-01')";
        $units[] = "(5, 'Inativa', 'inactive', NULL)";
        $pdo->exec('INSERT INTO erp_units (condominium_id, label, status, deleted_at) VALUES ' . implode(', ', $units));
        $pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (10, NULL, '2.01', 'Limpeza', 'despesa', 'analitica'), (11, NULL, '2.02', 'Elevador', 'despesa', 'analitica'),
            (12, 1, '2.90', 'Conta própria da Aurora', 'despesa', 'analitica'), (13, NULL, '2', 'Despesas', 'despesa', 'sintetica')");
        $e = static fn (int $c, int $a, int $v, string $comp, string $nature = 'despesa', string $status = 'active', ?string $del = null): string =>
            sprintf("(%d, %d, '%s', %d, '%s', '%s', %s)", $c, $a, $nature, $v, $comp, $status, $del === null ? 'NULL' : "'{$del}'");
        $pdo->exec('INSERT INTO erp_entries (condominium_id, chart_account_id, nature, amount_cents, competence, status, deleted_at) VALUES ' . implode(', ', [
            $e(1, 10, 30000, '2026-09'), $e(1, 10, 30000, '2026-10'), $e(1, 10, 30000, '2026-11'), $e(1, 11, 30000, '2026-11'),
            $e(1, 12, 500000, '2026-11'), $e(1, 13, 500000, '2026-11'), $e(1, 10, 999999, '2026-07'), $e(1, 10, 999999, '2026-11', 'receita'),
            $e(1, 10, 999999, '2026-11', 'despesa', 'inactive'), $e(1, 10, 999999, '2026-11', 'despesa', 'active', '2026-11-11'),
            $e(2, 10, 60000, '2026-09'), $e(2, 10, 60000, '2026-10'), $e(2, 10, 60000, '2026-11'), $e(2, 11, 300000, '2026-10'),
            $e(3, 10, 25000, '2026-08'), $e(3, 10, 25000, '2026-09'), $e(3, 10, 25000, '2026-10'), $e(3, 10, 25000, '2026-11'),
            $e(4, 10, 80000, '2026-11'),
            $e(5, 10, 70000, '2026-11'),
            $e(6, 10, 9999999, '2026-11'),
        ]));
        $this->service = new ExpenseBenchmark($pdo, new CondominiumScope($pdo));
    }

    public function testPerUnitMonthMedianAndOutliers(): void
    {
        $r = $this->service->build($this->user(), '2026-08', '2026-11');
        self::assertSame(50, $r['tolerance_percent']);
        self::assertSame([['Aurora', 10, 3, false], ['Brisa', 20, 3, false], ['Cedro', 5, 4, false], ['Duna', 8, 1, true]],
            array_map(static fn (array $c): array => [$c['name'], $c['units'], $c['months'], $c['short_history']], $r['condominiums']),
            'Unidades ativas não excluídas; meses com despesa na faixa; Eco (sem unidade) e Fora (fora do escopo) não aparecem.');
        self::assertSame(['no_units'], array_column($r['warnings'], 'code'));
        self::assertStringContainsString('Eco', $r['warnings'][0]['message']);
        self::assertSame(['2.01', '2.02'], array_column($r['accounts'], 'code'), 'Só contas analíticas do plano padrão.');
        $cleaning = $r['accounts'][0];
        self::assertSame([3000, 3], [$cleaning['median_per_unit_month_cents'], $cleaning['comparable_condominiums']], 'Duna (histórico curto) fora da mediana; Fora não pesa.');
        self::assertSame([['Duna', 10000, 233.33], ['Cedro', 5000, 66.67], ['Aurora', 3000, 0.0], ['Brisa', 3000, 0.0]],
            array_map(static fn (array $i): array => [$i['name'], $i['per_unit_month_cents'], $i['vs_median_percent']], $cleaning['items']));
        self::assertSame([3000, 2], [$r['accounts'][1]['median_per_unit_month_cents'], $r['accounts'][1]['comparable_condominiums']]);
        self::assertSame([['2.01', 'Cedro', 5000, 3000, 66.67]],
            array_map(static fn (array $o): array => [$o['code'], $o['name'], $o['per_unit_month_cents'], $o['median_cents'], $o['vs_median_percent']], $r['outliers']),
            'Duna marcado, não outlier; Elevador tem só 2 comparáveis (Brisa +66,67% não vira outlier).');
        $json = (string)json_encode($r);
        foreach (['U1', 'label', 'Fora', 'própria'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame([], $this->service->build($this->user(), '2026-08', '2026-11', '70')['outliers'], 'Tolerância maior.');
    }

    public function testScopeWarningsPureRulesAndValidation(): void
    {
        $none = $this->service->build($this->user(99), '2026-08', '2026-11');
        self::assertSame([[], [], ['not_enough_condominiums']], [$none['condominiums'], $none['accounts'], array_column($none['warnings'], 'code')]);
        $short = $this->service->build($this->user(), '2026-11', '2026-11');
        self::assertSame(['no_units', 'not_enough_condominiums'], array_column($short['warnings'], 'code'), 'Um mês só: todos com histórico curto.');
        self::assertSame([], $short['outliers']);
        self::assertSame([null, 5, 2, 3], [ExpenseBenchmark::median([]), ExpenseBenchmark::median([9, 5, 1]), ExpenseBenchmark::median([1, 3]), ExpenseBenchmark::median([4, 2, 1, 3])]);
        self::assertSame([333, 0, 0], [ExpenseBenchmark::perUnitMonth(1000, 3, 1), ExpenseBenchmark::perUnitMonth(1000, 0, 1), ExpenseBenchmark::perUnitMonth(1000, 3, 0)]);
        foreach ([['2025-11', '2026-11', null], ['2026-11', '2026-08', null], [null, '2026-11', null], ['2026-13', '2026-12', null], [['x'], '2026-11', null],
            ['2026-08', '2026-11', '5'], ['2026-08', '2026-11', '501'], ['2026-08', '2026-11', 'abc'], ['2026-08', '2026-11', ['50']], ['2026-08', '2026-11', '50.5']] as [$f, $t, $tol]) {
            try {
                $this->service->build($this->user(), $f, $t, $tol);
                self::fail('Entrada inválida passou: ' . json_encode([$f, $t, $tol]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $root = dirname(__DIR__, 3);
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ExpenseBenchmark.php');
        foreach (["Access::can('erp.entries.view', \$user)", "Access::can('erp.access', \$user)", 'catch (InvalidArgumentException', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/expense-benchmark", "ExpenseBenchmark:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(int $id = 7): User
    {
        $user = new User();
        $user->id = $id;
        return $user;
    }
}
