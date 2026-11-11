<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CollectionProgress;

/**
 * ADR 0199: acompanhamento da arrecadação. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). O condomínio 1
 * tem meses salteados de cobrança; o 2 começou agora (histórico curto).
 */
final class CollectionProgressTest extends TestCase
{
    private const AS_OF = '2026-11-20';

    private CollectionProgress $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER DEFAULT 1, nature TEXT, amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)"] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $e = static fn (int $c, int $v, string $comp, string $due, ?string $paid, string $nature = 'receita', string $status = 'active', ?string $del = null): string =>
            sprintf("(%d, '%s', %d, '%s', '%s', %s, '%s', %s)", $c, $nature, $v, $comp, $due, $paid === null ? 'NULL' : "'{$paid}'", $status, $del === null ? 'NULL' : "'{$del}'");
        $pdo->exec('INSERT INTO erp_entries (condominium_id, nature, amount_cents, competence, due_on, paid_on, status, deleted_at) VALUES ' . implode(', ', [
            $e(1, 10000, '2026-11', '2026-11-10', '2026-11-08'), $e(1, 10000, '2026-11', '2026-11-10', '2026-11-10'), $e(1, 10000, '2026-11', '2026-11-10', '2026-11-13'),
            $e(1, 10000, '2026-11', '2026-11-10', '2026-11-19'), $e(1, 10000, '2026-11', '2026-11-10', '2026-11-25'), $e(1, 10000, '2026-11', '2026-11-25', null),
            $e(1, 30000, '2026-10', '2026-10-10', '2026-10-10'), $e(1, 10000, '2026-10', '2026-10-10', '2026-11-15'),
            $e(1, 20000, '2026-08', '2026-08-10', '2026-08-05'), $e(1, 5000, '2026-06', '2026-06-10', '2026-09-01'), $e(1, 7000, '2026-05', '2026-05-10', null),
            $e(1, 99999, '2026-11', '2026-11-10', null, 'receita', 'inactive'), $e(1, 88888, '2026-11', '2026-11-10', null, 'receita', 'active', '2026-11-11'),
            $e(1, 77777, '2026-11', '2026-11-10', null, 'despesa'), $e(3, 66666, '2026-11', '2026-11-10', null),
            $e(2, 40000, '2026-11', '2026-11-10', '2026-11-10'),
        ]));
        // Receita sem unidade (rendimento, multa avulsa) não é arrecadação das unidades: fica de fora.
        $pdo->exec("INSERT INTO erp_entries (condominium_id, unit_id, nature, amount_cents, competence, due_on, paid_on) VALUES (1, NULL, 'receita', 55555, '2026-11', '2026-11-10', '2026-11-10')");
        $this->service = new CollectionProgress($pdo, new CondominiumScope($pdo));
    }

    public function testProgressBucketsPreviousAndTrend(): void
    {
        $r = $this->service->build($this->user(), 1, '2026-11', self::AS_OF);
        self::assertSame([60000, 6, 40000, 20000, 10000, 66.67, 33.33], [$r['billed_cents'], $r['billed_count'], $r['received_cents'], $r['open_cents'],
            $r['overdue_cents'], $r['received_percent'], $r['on_time_percent']], 'Pago depois da data de referência conta como em aberto; cancelada, excluída, despesa, receita sem unidade e outro condomínio fora.');
        self::assertSame(['on_time' => 20000, 'late_1_5' => 10000, 'late_6_15' => 10000, 'late_16_30' => 0, 'late_31_60' => 0, 'late_over_60' => 0, 'open' => 20000],
            array_map(static fn (array $b): int => $b['cents'], $r['buckets']));
        self::assertSame([40000, 75.0, 100.0, 10000], [$r['previous']['billed_cents'], $r['previous']['on_time_percent'], $r['previous']['received_percent'],
            $r['previous']['buckets']['late_31_60']['cents']]);
        self::assertSame(-41.67, $r['on_time_delta_points']);
        self::assertSame([['2026-06', 5000, 0.0, 100.0], ['2026-08', 20000, 100.0, 100.0], ['2026-10', 40000, 75.0, 100.0], ['2026-11', 60000, 33.33, 66.67]],
            array_map(static fn (array $t): array => [$t['competence'], $t['billed_cents'], $t['on_time_percent'], $t['received_percent']], $r['trend']),
            'Seis meses (05 fica fora); meses sem cobrança não entram.');
        self::assertSame([], $r['warnings']);
        self::assertStringNotContainsString('unit', (string)json_encode($r), 'Só números agregados.');
    }

    public function testShortHistoryNoBillingPureRuleAndValidation(): void
    {
        $r = $this->service->build($this->user(), 2, '2026-11', self::AS_OF);
        self::assertSame([[], ['short_history'], null, 100.0], [$r['trend'], array_column($r['warnings'], 'code'), $r['previous'], $r['on_time_percent']]);
        $next = $this->service->build($this->user(), 2, '2026-12', self::AS_OF);
        self::assertSame([0, null, ['short_history', 'no_billing'], null], [$next['billed_cents'], $next['received_percent'], array_column($next['warnings'], 'code'),
            $next['on_time_delta_points']]);

        foreach ([['2026-11-10', 'on_time'], ['2026-11-09', 'on_time'], ['2026-11-15', 'late_1_5'], ['2026-11-16', 'late_6_15'], ['2026-11-25', 'late_6_15'],
            ['2026-11-26', 'late_16_30'], ['2026-12-10', 'late_16_30'], ['2026-12-11', 'late_31_60'], ['2027-01-09', 'late_31_60'], ['2027-01-10', 'late_over_60']] as [$paid, $bucket]) {
            self::assertSame($bucket, CollectionProgress::bucket('2026-11-10', $paid, '2027-12-31'), $paid);
        }
        self::assertSame('open', CollectionProgress::bucket('2026-11-10', null, '2027-12-31'));
        self::assertSame('open', CollectionProgress::bucket('2026-11-10', '2026-11-12', '2026-11-11'), 'Pago depois da data de referência.');
        try {
            $this->service->build($this->user(), 3, '2026-11', self::AS_OF);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[null, '2026-11', self::AS_OF], [1, '2026-13', self::AS_OF], [1, null, self::AS_OF], [1, '2026-11', '2026-02-30'], [1, '2026-11', ['x']]] as [$c, $m, $d]) {
            try {
                $this->service->build($this->user(), $c, $m, $d);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $m, $d]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $root = dirname(__DIR__, 3);
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CollectionProgress.php');
        foreach (["Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/collection-progress", "CollectionProgress:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
