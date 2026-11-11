<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BillingCheck;

/**
 * ADR 0198: conferência do faturamento. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). O condomínio 1 tem
 * a competência anterior; o 2 começou agora (histórico curto, como o laboratório).
 */
final class BillingCheckTest extends TestCase
{
    private BillingCheck $service;

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
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, chart_account_id INTEGER, amount_cents INTEGER,
                competence TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)"] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $pdo->exec("INSERT INTO erp_units (id, condominium_id, label, status, deleted_at) VALUES (11, 1, '101', 'active', NULL), (12, 1, '102', 'active', NULL),
            (13, 1, '201', 'active', NULL), (14, 1, '202', 'inactive', NULL), (15, 1, '203', 'active', '2026-10-20'), (21, 2, '1A', 'active', NULL), (31, 3, 'X', 'active', NULL)");
        $pdo->exec("INSERT INTO erp_chart_accounts (id, code, name, nature, kind) VALUES (2, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, '1.02', 'Fundo de reserva', 'receita', 'analitica'), (4, '2.01', 'Limpeza', 'despesa', 'analitica')");
        $pdo->exec("INSERT INTO erp_entries (id, condominium_id, unit_id, nature, chart_account_id, amount_cents, competence, status, deleted_at) VALUES
            (1, 1, 11, 'receita', 2, 50000, '2026-10', 'active', NULL), (2, 1, 12, 'receita', 2, 50000, '2026-10', 'active', NULL), (3, 1, 13, 'receita', 2, 50000, '2026-10', 'active', NULL),
            (4, 1, 11, 'receita', 2, 50000, '2026-11', 'active', NULL), (5, 1, 11, 'receita', 3, 5000, '2026-11', 'active', NULL),
            (6, 1, 12, 'receita', 2, 50000, '2026-11', 'active', NULL), (7, 1, 12, 'receita', 2, 50000, '2026-11', 'active', NULL),
            (8, 1, 14, 'receita', 2, 30000, '2026-11', 'active', NULL), (9, 1, 15, 'receita', 2, 20000, '2026-11', 'active', NULL),
            (10, 1, NULL, 'receita', 2, 7000, '2026-11', 'active', NULL),
            (11, 1, 13, 'receita', 2, 99999, '2026-11', 'inactive', NULL), (12, 1, 13, 'receita', 2, 88888, '2026-11', 'active', '2026-11-05'),
            (13, 1, 13, 'despesa', 4, 77777, '2026-11', 'active', NULL), (14, 3, 31, 'receita', 2, 66666, '2026-11', 'active', NULL),
            (15, 2, 21, 'receita', 2, 40000, '2026-11', 'active', NULL)");
        $this->service = new BillingCheck($pdo, new CondominiumScope($pdo));
    }

    public function testBillingCheck(): void
    {
        $r = $this->service->build($this->user(), 1, '2026-11');
        self::assertSame(['2026-10', 20, 3, 2, 205000, 150000, 7000], [$r['previous_competence'], $r['tolerance_percent'], $r['active_units'], $r['billed_units'],
            $r['total_cents'], $r['previous_total_cents'], $r['without_unit_cents']], 'Cancelada, excluída, despesa e outro condomínio não entram.');
        self::assertSame(['missing' => 1, 'duplicates' => 1, 'variations' => 1, 'inactive_units' => 2, 'without_unit' => 1], $r['summary']);
        self::assertSame([['201', 50000]], array_map(static fn (array $m): array => [$m['unit_label'], $m['previous_cents']], $r['missing']['items']));
        self::assertSame([['102', '1.01 Taxa condominial', [6, 7], 100000]],
            array_map(static fn (array $d): array => [$d['unit_label'], $d['account'], $d['entry_ids'], $d['total_cents']], $r['duplicates']['items']),
            'Taxa e fundo de reserva da mesma unidade não são duplicidade.');
        self::assertSame([['102', 50000, 100000, 100.0]],
            array_map(static fn (array $v): array => [$v['unit_label'], $v['previous_cents'], $v['current_cents'], $v['variation_percent']], $r['variations']['items']),
            '101 subiu 10%: dentro da tolerância de 20%.');
        self::assertSame([['202', 30000], ['203', 20000]], array_map(static fn (array $i): array => [$i['unit_label'], $i['amount_cents']], $r['inactive_units']['items']),
            'Unidade inativa e unidade excluída.');
        self::assertSame([10], array_column($r['without_unit']['items'], 'entry_id'));
        self::assertSame([], $r['warnings']);

        $strict = $this->service->build($this->user(), 1, '2026-11', '5');
        self::assertSame(['102', '101'], array_column($strict['variations']['items'], 'unit_label'), 'Tolerância de 5%: a maior variação primeiro.');
    }

    public function testShortHistoryPureRuleScopeAndValidation(): void
    {
        $r = $this->service->build($this->user(), 2, '2026-11');
        self::assertSame([1, 1, 0, ['no_previous']], [$r['active_units'], $r['billed_units'], $r['summary']['variations'], array_column($r['warnings'], 'code')],
            'Sem competência anterior: sem variação e com aviso.');
        self::assertNull(BillingCheck::variation(0, 100));
        self::assertSame(-50.0, BillingCheck::variation(200, 100));
        try {
            $this->service->build($this->user(), 3, '2026-11');
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[null, '2026-11', null], [1, '2026-13', null], [1, null, null], [1, ['2026-11'], null], [1, '2026-11', 0], [1, '2026-11', 501], [1, '2026-11', 'x'],
            [1, '2026-11', [5]]] as [$c, $m, $t]) {
            try {
                $this->service->build($this->user(), $c, $m, $t);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $m, $t]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $root = dirname(__DIR__, 3);
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/BillingCheck.php');
        foreach (["Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/billing-check", "BillingCheck:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
