<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\UnitStatement;

/** ADR 0184: extrato da unidade. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). */
final class UnitStatementTest extends TestCase
{
    private PDO $pdo;
    private UnitStatement $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, deleted_at TEXT)',
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, description TEXT, amount_cents INTEGER, competence TEXT,
                due_on TEXT, paid_on TEXT, agreement_id INTEGER, agreement_role TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, deleted_at) VALUES (12, 1, '102', NULL), (13, 1, '103', '2026-01-01'), (31, 3, '301', NULL)");
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, unit_id, nature, description, amount_cents, competence, due_on, paid_on, agreement_id, agreement_role, status, deleted_at) VALUES
            (1, 1, 12, 'receita', 'Taxa jun', 50000, '2026-06', '2026-06-10', NULL, NULL, NULL, 'active', NULL),
            (2, 1, 12, 'receita', 'Taxa jun (2)', 30000, '2026-06', '2026-06-10', '2026-07-05', NULL, NULL, 'active', NULL),
            (3, 1, 12, 'receita', 'Taxa jul', 65000, '2026-07', '2026-07-10', '2026-07-10', NULL, NULL, 'active', NULL),
            (4, 1, 12, 'receita', 'Taxa ago', 65000, '2026-08', '2026-08-10', NULL, 5, 'original', 'inactive', NULL),
            (5, 1, 12, 'receita', 'Acordo 5 — parcela 1/2', 22000, '2026-09', '2026-09-10', '2026-09-12', 5, 'installment', 'active', NULL),
            (6, 1, 12, 'receita', '=Acordo 5 — parcela 2/2', 22000, '2026-10', '2026-10-10', NULL, 5, 'installment', 'active', NULL),
            (7, 1, 12, 'receita', 'Taxa nov', 65000, '2026-11', '2026-11-10', NULL, NULL, NULL, 'active', NULL),
            (8, 1, 12, 'receita', 'Lançada por engano', 99999, '2026-07', '2026-07-20', NULL, NULL, NULL, 'inactive', NULL),
            (9, 1, 12, 'receita', 'Excluída', 88888, '2026-07', '2026-07-21', NULL, NULL, NULL, 'active', '2026-07-22'),
            (10, 3, 31, 'receita', 'Fora', 77777, '2026-07', '2026-07-10', NULL, NULL, NULL, 'active', NULL),
            (11, 1, 12, 'despesa', 'Despesa na unidade', 66666, '2026-07', '2026-07-10', NULL, NULL, NULL, 'active', NULL)");
        $this->service = new UnitStatement($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testStatementReconcilesOpeningChargedPaidAndOpen(): void
    {
        $s = $this->service->build($this->user(), '12', '2026-07-01', '2026-10-31', '2026-11-11');
        self::assertSame(['102', '2026-10-31'], [$s['unit']['label'], $s['as_of']]);
        self::assertSame([[3, 'paid'], [4, 'renegotiated'], [5, 'paid'], [6, 'overdue']], array_map(static fn (array $r): array => [$r['entry_id'], $r['situation']], $s['rows']),
            'Cancelada, excluída, despesa, fora do período e outra unidade não entram.');
        $t = $s['totals'];
        self::assertSame([80000, 109000, 117000, 72000, 72000], [$t['opening_cents'], $t['charged_cents'], $t['paid_cents'], $t['open_cents'], $t['overdue_cents']]);
        self::assertSame($t['open_cents'], $t['opening_cents'] + $t['charged_cents'] - $t['paid_cents'], 'Saldo anterior + cobrado − pago = em aberto no fim.');
        self::assertSame([2, 0, 1, 1], [$t['paid'], $t['open'], $t['overdue'], $t['renegotiated']]);

        $now = $this->service->build($this->user(), 12, '2026-10-01', '2026-11-30', '2026-11-11');
        self::assertSame('2026-11-11', $now['as_of'], 'Período que passa de hoje: data de corte hoje.');
        self::assertSame(['overdue', 'overdue'], array_column($now['rows'], 'situation'), 'A taxa de novembro venceu ontem.');

        $table = UnitStatement::csvTable($s);
        self::assertSame(['Vencimento', 'Competência', 'Descrição', 'Pago em', 'Situação', 'Acordo', 'Valor'], $table['header']);
        self::assertSame(['', '', 'Saldo anterior em 01/07/2026', '', '', '', '800,00'], $table['rows'][0]);
        self::assertContains(['10/08/2026', '2026-08', 'Taxa ago', '', 'Renegociada (acordo)', '5', '650,00'], $table['rows']);
        self::assertContains(['10/10/2026', '2026-10', "'=Acordo 5 — parcela 2/2", '', 'Vencida', '5', '220,00'], $table['rows'], 'Fórmula neutralizada.');
        self::assertContains(['', '', 'Em aberto no fim do período', '', '', '', '720,00'], $table['rows']);
        self::assertStringStartsWith("\xEF\xBB\xBF", CollectionReports::csv($table));
    }

    public function testScopeValidationAndController(): void
    {
        foreach ([31, 13, 999] as $unit) {
            try {
                $this->service->build($this->user(), $unit, '2026-07-01', '2026-07-31', '2026-11-11');
                self::fail("A unidade {$unit} passou.");
            } catch (CondominiumAccessDenied) {
                self::assertTrue(true);   // outro condomínio, excluída ou inexistente: 404 igual
            }
        }
        foreach ([[null, '2026-07-01', '2026-07-31'], ['abc', '2026-07-01', '2026-07-31'], [12, '2026-07-31', '2026-07-01'], [12, '2025-01-01', '2026-07-31']] as [$u, $f, $t]) {
            try {
                $this->service->build($this->user(), $u, $f, $t, '2026-11-11');
                self::fail('Entrada inválida passou: ' . json_encode([$u, $f, $t]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('paid', UnitStatement::situation(['status' => 'active', 'paid_on' => '2026-07-10', 'due_on' => '2026-07-10', 'agreement_role' => null], '2026-07-31'));
        self::assertSame('overdue', UnitStatement::situation(['status' => 'active', 'paid_on' => '2026-08-05', 'due_on' => '2026-07-10', 'agreement_role' => null], '2026-07-31'),
            'Pago depois da data de corte: vencida na data.');
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/UnitStatement.php');
        foreach (["Access::can('erp.entries.view', \$user)", '(new ReportExportLimit())->assertAllowed($user)', "'report' => 'unit-statement'", 'X-Content-Type-Options: nosniff',
            'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
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
