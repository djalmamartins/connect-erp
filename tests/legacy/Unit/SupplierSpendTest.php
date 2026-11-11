<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\SupplierSpend;

/**
 * ADR 0190: gastos por fornecedor. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). O condomínio 1 tem
 * período anterior; o 2 começa no meio do ano, como o laboratório (sem período anterior).
 */
final class SupplierSpendTest extends TestCase
{
    private PDO $pdo;
    private SupplierSpend $service;

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
            'CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, legal_name TEXT, trade_name TEXT, document TEXT, pix_key TEXT, bank_account TEXT)',
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, supplier_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'), (4, NULL, '2.01', 'Limpeza', 'despesa', 'analitica'), (5, NULL, '2.02', 'Manutenção', 'despesa', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_suppliers (id, legal_name, trade_name, document, pix_key, bank_account) VALUES
            (1, 'Limpa Tudo Serviços Ltda', 'LimpaTudo', '12345678000199', 'pix-limpa@example.test', '0001-9'),
            (2, 'Elevadores Sobe SA', '', '98765432000188', NULL, NULL),
            (3, 'João Encanador', NULL, '12345678909', NULL, NULL),
            (4, 'Antiga Portaria Ltda', NULL, '11222333000144', NULL, NULL),
            (5, '=HYPERLINK(\"http://x\")', NULL, '55666777000188', NULL, NULL)");
        $e = static fn (int $c, ?int $s, int $acct, int $v, string $comp, ?string $paid, string $nature = 'despesa', string $status = 'active', ?string $del = null): string =>
            sprintf("(%d, '%s', %d, %s, 'x', %d, '%s', '%s-10', %s, '%s', %s)", $c, $nature, $acct, $s === null ? 'NULL' : (string)$s, $v, $comp, $comp,
                $paid === null ? 'NULL' : "'{$paid}'", $status, $del === null ? 'NULL' : "'{$del}'");
        $rows = [
            $e(1, 1, 4, 50000, '2026-07', '2026-07-10'), $e(1, 1, 4, 50000, '2026-08', '2026-08-10'), $e(1, 1, 4, 50000, '2026-09', null), $e(1, 1, 5, 10000, '2026-10', '2026-10-10'),
            $e(1, 2, 5, 80000, '2026-08', '2026-08-10'), $e(1, 3, 5, 20000, '2026-10', '2026-10-10'), $e(1, null, 4, 5000, '2026-09', '2026-09-10'),
            $e(1, 2, 5, 99999, '2026-08', null, 'despesa', 'inactive'), $e(1, 3, 5, 88888, '2026-08', null, 'despesa', 'active', '2026-08-20'),
            $e(3, 1, 4, 77777, '2026-08', null), $e(1, null, 2, 66666, '2026-08', null, 'receita'), $e(1, 1, 4, 55555, '2026-11', null),
            $e(1, 1, 4, 100000, '2026-05', '2026-05-10'), $e(1, 4, 5, 30000, '2026-04', '2026-04-10'), $e(1, null, 4, 1000, '2026-03', '2026-03-10'),
            $e(2, 5, 4, 12000, '2026-02', null), $e(2, 1, 4, 8000, '2026-03', '2026-03-10'),
        ];
        $this->pdo->exec('INSERT INTO erp_entries (condominium_id, nature, chart_account_id, supplier_id, description, amount_cents, competence, due_on, paid_on, status, deleted_at) VALUES '
            . implode(', ', $rows));
        $this->service = new SupplierSpend($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testRankingConcentrationAndPreviousPeriod(): void
    {
        $r = $this->service->build($this->user(), 1, '2026-07', '2026-10');
        self::assertSame(['2026-03', '2026-06', 265000, 260000, 3, 1], [$r['previous_from'], $r['previous_to'], $r['expenses_cents'], $r['identified_cents'],
            $r['suppliers'], $r['previous_only_suppliers']], 'Cancelada, excluída, receita, outro condomínio e fora do período não entram.');
        self::assertSame([[1, 'LimpaTudo', 4, 160000, 110000, 50000, 61.54, '2026-07', '2026-10', 4, 100000, 60.0],
            [2, 'Elevadores Sobe SA', 1, 80000, 80000, 0, 30.77, '2026-08', '2026-08', 1, 0, null],
            [3, 'João Encanador', 1, 20000, 20000, 0, 7.69, '2026-10', '2026-10', 1, 0, null]],
            array_map(static fn (array $x): array => [$x['supplier_id'], $x['name'], $x['entries'], $x['total_cents'], $x['paid_cents'], $x['open_cents'], $x['share_percent'],
                $x['first_competence'], $x['last_competence'], $x['months_active'], $x['previous_cents'], $x['variation_percent']], $r['rows']));
        self::assertSame([['2.01', 150000], ['2.02', 10000]], array_map(static fn (array $a): array => [$a['code'], $a['amount_cents']], $r['rows'][0]['top_accounts']));
        self::assertSame(['************99', '*********09'], [$r['rows'][0]['document_masked'], $r['rows'][2]['document_masked']]);
        self::assertSame([null, 5000, null, 1000], [$r['without_supplier']['supplier_id'], $r['without_supplier']['total_cents'], $r['without_supplier']['share_percent'],
            $r['without_supplier']['previous_cents']], 'Sem fornecedor à parte, fora da participação.');
        self::assertSame(['top1_percent' => 61.54, 'top5_percent' => 100.0, 'hhi' => 4793], $r['concentration']);
        self::assertSame([], $r['warnings']);
        $json = (string)json_encode($r);
        foreach (['12345678000199', '12345678909', 'pix-limpa', '0001-9'] as $secret) {
            self::assertStringNotContainsString($secret, $json, 'Documento inteiro, Pix e banco do fornecedor não saem.');
        }
    }

    public function testShortHistoryAndCsv(): void
    {
        $r = $this->service->build($this->user(), 2, '2026-01', '2026-03');
        self::assertSame(['no_previous_period'], array_column($r['warnings'], 'code'), 'Sem nada no período anterior: avisa em vez de +100%.');
        self::assertSame([[5, 12000, null, null], [1, 8000, null, null]],
            array_map(static fn (array $x): array => [$x['supplier_id'], $x['total_cents'], $x['previous_cents'], $x['variation_percent']], $r['rows']));
        self::assertNull($r['without_supplier']);
        $table = SupplierSpend::csvTable($r);
        self::assertCount(13, $table['header']);
        self::assertSame(["'=HYPERLINK(\"http://x\")", '************88', '1', '120,00', '0,00', '120,00', '60,00'], array_slice($table['rows'][0], 0, 7), 'Fórmula neutralizada.');
        self::assertSame(['', ''], array_slice($table['rows'][0], 11, 2));

        $t = SupplierSpend::csvTable($this->service->build($this->user(), 1, '2026-07', '2026-10'));
        self::assertSame(['Sem fornecedor', '', '1', '50,00'], array_slice(end($t['rows']), 0, 4));
        self::assertSame(['1000,00', '60,00'], array_slice($t['rows'][0], 11, 2));
    }

    public function testPureRulesScopeAndValidation(): void
    {
        self::assertSame(['2025-10', '2025-12'], SupplierSpend::previousPeriod('2026-01', '2026-03'));
        self::assertSame(['2026-06', '2026-06'], SupplierSpend::previousPeriod('2026-07', '2026-07'));
        self::assertSame(['2024-01', '2025-12'], SupplierSpend::previousPeriod('2026-01', '2027-12'));
        self::assertSame(['top1_percent' => null, 'top5_percent' => null, 'hhi' => null], SupplierSpend::concentration([]));
        self::assertSame(['top1_percent' => 100.0, 'top5_percent' => 100.0, 'hhi' => 10000], SupplierSpend::concentration([500]));
        self::assertSame(['top1_percent' => 25.0, 'top5_percent' => 100.0, 'hhi' => 2500], SupplierSpend::concentration([1, 1, 1, 1]));
        try {
            $this->service->build($this->user(), 3, '2026-01', '2026-06');
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[null, '2026-01', '2026-06'], [1, '2026-13', '2026-06'], [1, '2026-06', '2026-01'], [1, null, '2026-06'], [1, ['2026-01'], '2026-06'], [1, '2020-01', '2026-06']] as [$c, $f, $t]) {
            try {
                $this->service->build($this->user(), $c, $f, $t);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $f, $t]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/SupplierSpend.php');
        foreach (["Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store', '(new ReportExportLimit())->assertAllowed($user)',
            "Audit::record('export'", "'report' => 'supplier-spend'", 'X-Content-Type-Options: nosniff', 'JsonResponse::send(429'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/supplier-spend", "SupplierSpend:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents(dirname(__DIR__, 3) . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
