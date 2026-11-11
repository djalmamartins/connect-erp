<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ExpenseReview;

/** ADR 0188: conferência de despesas para o conselho fiscal. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). */
final class ExpenseReviewTest extends TestCase
{
    private PDO $pdo;
    private ExpenseReview $service;

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
            'CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, legal_name TEXT, trade_name TEXT, document TEXT, pix_key TEXT)',
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, supplier_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, document_number TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_suppliers (id, legal_name, trade_name, document, pix_key) VALUES
            (1, 'Limpa Tudo Serviços Ltda', 'LimpaTudo', '12345678000199', 'pix-limpa@example.test'), (2, 'Elevadores Sobe SA', '', '98765432000188', NULL)");
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'), (4, NULL, '2.01', 'Limpeza', 'despesa', 'analitica'), (5, NULL, '2.02', 'Manutenção', 'despesa', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, nature, chart_account_id, supplier_id, description, amount_cents, competence, due_on, paid_on, document_number, status, deleted_at) VALUES
            (1, 1, 'despesa', 4, 1, 'Limpeza fev', 50000, '2026-02', '2026-02-10', '2026-02-10', 'NF 123', 'active', NULL),
            (2, 1, 'despesa', 4, 1, 'Limpeza fev (de novo?)', 50000, '2026-02', '2026-02-15', '2026-02-15', 'NF-124', 'active', NULL),
            (3, 1, 'despesa', 4, 1, 'Limpeza mar', 50000, '2026-03', '2026-03-10', '2026-03-10', 'nf125', 'active', NULL),
            (4, 1, 'despesa', 5, 2, 'Reparo elevador', 80000, '2026-04', '2026-04-05', '2026-04-05', '777', 'active', NULL),
            (5, 1, 'despesa', 5, 2, 'Peça elevador', 30000, '2026-04', '2026-04-20', '2026-04-20', '7.7.7', 'active', NULL),
            (6, 1, 'despesa', 4, NULL, 'Material de limpeza', 12000, '2026-05', '2026-05-10', '2026-05-10', NULL, 'active', NULL),
            (7, 1, 'despesa', 4, 1, 'Em aberto sem documento', 9000, '2026-05', '2026-05-12', NULL, NULL, 'active', NULL),
            (8, 1, 'despesa', 4, 1, 'Cancelada', 50000, '2026-02', '2026-02-12', '2026-02-12', NULL, 'inactive', NULL),
            (9, 1, 'despesa', 4, 1, 'Excluída', 50000, '2026-02', '2026-02-11', '2026-02-11', NULL, 'active', '2026-03-01'),
            (10, 3, 'despesa', 4, 1, 'Outro condomínio', 50000, '2026-02', '2026-02-11', '2026-02-11', NULL, 'active', NULL),
            (11, 1, 'despesa', 4, 1, 'Antes do período', 20000, '2025-12', '2026-01-05', '2026-01-05', 'nf1', 'active', NULL),
            (12, 1, 'receita', 2, NULL, 'Taxa', 50000, '2026-02', '2026-02-10', '2026-02-10', NULL, 'active', NULL),
            (13, 1, 'despesa', 5, 2, 'Documento em branco', 1000, '2026-06', '2026-06-01', '2026-06-01', '   ', 'active', NULL),
            (14, 1, 'despesa', 5, NULL, 'Histórico ago', 10000, '2025-08', '2025-08-10', NULL, NULL, 'active', NULL),
            (15, 1, 'despesa', 5, NULL, 'Histórico set', 10000, '2025-09', '2025-09-10', NULL, NULL, 'active', NULL),
            (16, 1, 'despesa', 5, NULL, 'Histórico out', 10000, '2025-10', '2025-10-10', NULL, NULL, 'active', NULL)");
        $this->service = new ExpenseReview($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testReviewSections(): void
    {
        $r = $this->service->build($this->user(), 1, '2026-01', '2026-06');
        self::assertSame([8, 282000], [$r['expenses_count'], $r['expenses_cents']], 'Só despesas válidas do condomínio no período.');
        self::assertSame(['possible_duplicates' => 2, 'paid_without_supplier' => 1, 'paid_without_document' => 2, 'spikes' => 1], $r['summary']);
        $dup = $r['possible_duplicates']['items'];
        self::assertSame([['same_value', [1, 2], 100000], ['same_document', [4, 5], 110000]],
            array_map(static fn (array $g): array => [$g['reason'], $g['ids'], $g['total_cents']], $dup), 'Mesmo valor em 5 dias; mesmo documento sem pontuação.');
        self::assertSame(['LimpaTudo', 'Elevadores Sobe SA'], [$dup[0]['entries'][0]['supplier_name'], $dup[1]['entries'][0]['supplier_name']], 'Nome fantasia ou razão social.');
        self::assertSame([6], array_column($r['paid_without_supplier']['items'], 'id'));
        self::assertSame([6, 13], array_column($r['paid_without_document']['items'], 'id'), 'Em aberto não entra; documento em branco conta como sem documento.');
        self::assertSame([['2026-04', '2.02', 110000, 10000, 11.0]], array_map(static fn (array $s): array => [$s['competence'], $s['code'], $s['month_cents'],
            $s['average_cents'], $s['ratio']], $r['spikes']['items']), 'Pico pela média dos meses com valor nos 12 anteriores, que vêm de antes do período.');
        self::assertFalse($r['possible_duplicates']['truncated']);
        $json = (string)json_encode($r);
        foreach (['12345678000199', '98765432000188', 'pix-limpa'] as $secret) {
            self::assertStringNotContainsString($secret, $json, 'Documento e Pix do fornecedor não saem.');
        }
    }

    public function testPureRules(): void
    {
        $e = static fn (int $id, ?int $s, int $v, string $due, ?string $doc = null): array => ['id' => $id, 'supplier_id' => $s, 'amount_cents' => $v, 'due_on' => $due, 'document_number' => $doc];
        self::assertSame([['reason' => 'same_value', 'ids' => [1, 2, 3]]], ExpenseReview::duplicates([$e(3, 1, 100, '2026-01-12'), $e(1, 1, 100, '2026-01-01'), $e(2, 1, 100, '2026-01-06')]),
            'Encadeia vencimentos a até 7 dias.');
        self::assertSame([], ExpenseReview::duplicates([$e(1, 1, 100, '2026-01-01'), $e(2, 1, 100, '2026-01-09'), $e(3, 2, 100, '2026-01-02'), $e(4, null, 100, '2026-01-01'), $e(5, null, 100, '2026-01-01')]),
            '8 dias, outro fornecedor e sem fornecedor não contam.');
        self::assertSame([['reason' => 'same_document', 'ids' => [1, 9]]], ExpenseReview::duplicates([$e(9, 1, 200, '2026-05-01', 'ab-1'), $e(1, 1, 100, '2026-01-01', 'AB 1'), $e(5, 2, 300, '2026-01-01', 'AB1')]));
        $h = [0, 0, 0, 0, 0, 0, 0, 0, 0, 10000, 10000, 10000];
        self::assertNull(ExpenseReview::spike(100000, [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 10000, 10000]), 'Menos de 3 meses de histórico.');
        self::assertSame(['average_cents' => 10000, 'ratio' => 2.5], ExpenseReview::spike(25000, $h), 'Média só dos meses com valor.');
        self::assertNull(ExpenseReview::spike(19999, $h), 'Diferença menor que R$ 100,00.');
        self::assertNull(ExpenseReview::spike(12000, [0, 0, 0, 0, 0, 0, 0, 0, 0, 11000, 12000, 13000]), 'Histórico curto e estável não é pico.');
        self::assertNull(ExpenseReview::spike(300000, array_fill(0, 12, 250000)), 'Até 1,5 vez a média não é pico.');
        self::assertSame(1.5, ExpenseReview::spike(375000, array_fill(0, 12, 250000))['ratio']);
    }

    public function testScopeAndValidation(): void
    {
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
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/ExpenseReview.php');
        foreach (["Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/expense-review", "ExpenseReview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents(dirname(__DIR__, 3) . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
