<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BatchReversal;
use Source\Services\Erp\BatchSettlementRejected;

/**
 * ADR 0192: estorno em lote da baixa. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). O PDO de teste tira
 * o FOR UPDATE e o LOCK IN SHARE MODE da competência fechada (ADR 0100) e, quando $race está ligado, muda a baixa da conta 2 "por outra pessoa" logo antes do UPDATE do lote.
 */
final class BatchReversalTest extends TestCase
{
    public static bool $race = false;

    private PDO $pdo;
    private BatchReversal $service;

    protected function setUp(): void
    {
        self::$race = false;
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    BatchReversalTest::race($this, $query);
                    return parent::prepare(str_replace([' FOR UPDATE', ' LOCK IN SHARE MODE'], '', $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    BatchReversalTest::race($this, $query);
                    return parent::prepare(str_replace([' FOR UPDATE', ' LOCK IN SHARE MODE'], '', $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, description TEXT, amount_cents INTEGER, competence TEXT, due_on TEXT,
                paid_on TEXT, bank_account_id INTEGER, updated_by INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
            'CREATE TABLE erp_unit_documents (id INTEGER PRIMARY KEY, status TEXT)',
            'CREATE TABLE erp_unit_document_entries (document_id INTEGER, entry_id INTEGER)',
            'CREATE TABLE erp_bank_statement_lines (id INTEGER PRIMARY KEY, status TEXT, entry_id INTEGER)',
            'CREATE TABLE erp_bank_charges (id INTEGER PRIMARY KEY, status TEXT, entry_id INTEGER)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $e = static fn (int $id, int $c, string $n, int $v, string $comp, ?string $paid, string $status = 'active', ?string $del = null): string =>
            sprintf("(%d, %d, '%s', 'Conta %d', %d, '%s', '%s-10', %s, 10, '%s', %s)", $id, $c, $n, $id, $v, $comp, $comp,
                $paid === null ? 'NULL' : "'{$paid}'", $status, $del === null ? 'NULL' : "'{$del}'");
        $this->pdo->exec('INSERT INTO erp_entries (id, condominium_id, nature, description, amount_cents, competence, due_on, paid_on, bank_account_id, status, deleted_at) VALUES ' . implode(', ', [
            $e(1, 1, 'despesa', 30000, '2026-10', '2026-11-10'), $e(2, 1, 'receita', 50000, '2026-11', '2026-11-10'), $e(3, 1, 'despesa', 1000, '2026-11', null),
            $e(4, 1, 'despesa', 1000, '2026-11', '2026-11-10', 'inactive'), $e(5, 1, 'despesa', 1000, '2026-11', '2026-11-10', 'active', '2026-11-11'),
            $e(6, 1, 'despesa', 1000, '2026-09', '2026-09-10'), $e(7, 1, 'receita', 1000, '2026-11', '2026-11-10'), $e(8, 1, 'receita', 20000, '2026-11', '2026-11-10'),
            $e(9, 1, 'receita', 1000, '2026-11', '2026-11-10'), $e(11, 1, 'receita', 1000, '2026-11', '2026-11-10'), $e(12, 1, 'receita', 15000, '2026-11', '2026-11-10'),
            $e(13, 3, 'despesa', 1000, '2026-11', '2026-11-10'),
        ]));
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-09', 'closed'), (1, '2026-10', 'reopened'), (3, '2026-11', 'closed')");
        $this->pdo->exec("INSERT INTO erp_unit_documents (id, status) VALUES (70, 'issued'), (80, 'cancelled')");
        $this->pdo->exec('INSERT INTO erp_unit_document_entries (document_id, entry_id) VALUES (70, 7), (80, 8)');
        $this->pdo->exec("INSERT INTO erp_bank_statement_lines (status, entry_id) VALUES ('matched', 9), ('unmatched', NULL)");
        $this->pdo->exec("INSERT INTO erp_bank_charges (status, entry_id) VALUES ('paid', 11), ('cancelled', 12)");
        $this->service = new BatchReversal($this->pdo, new CondominiumScope($this->pdo));
    }

    /** Simula outra pessoa mudando a baixa da conta 2 entre a conferência e a gravação. */
    public static function race(PDO $pdo, string $query): void
    {
        if (self::$race && str_starts_with(ltrim($query), 'UPDATE erp_entries SET paid_on = NULL')) {
            self::$race = false;
            $pdo->exec("UPDATE erp_entries SET paid_on = '2026-11-09' WHERE id = 2");
        }
    }

    public function testDryRunThenReverse(): void
    {
        $input = ['condominium_id' => 1, 'entry_ids' => [1, 2, '8', 12, 2], 'reason' => '  Lote baixado no dia errado  '];
        $preview = $this->service->reverse($this->user(), $input + ['dry_run' => '1']);
        self::assertSame([true, 0, 4, 'Lote baixado no dia errado'], [$preview['dry_run'], $preview['reversed'], $preview['count'], $preview['reason']]);
        self::assertSame(['receita_cents' => 85000, 'despesa_cents' => 30000, 'net_cents' => 55000], $preview['totals'],
            'Recibo cancelado e boleto cancelado não travam; mês reaberto também não.');
        self::assertSame(4, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries WHERE paid_on IS NOT NULL AND id IN (1, 2, 8, 12)')->fetchColumn(), 'dry_run não grava.');

        $done = $this->service->reverse($this->user(), $input);
        self::assertSame([false, 4], [$done['dry_run'], $done['reversed']]);
        self::assertSame(['2026-11-10', 10], [$done['entries'][0]['paid_on'], $done['entries'][0]['bank_account_id']], 'A resposta mostra a baixa desfeita.');
        self::assertSame([['1', '', '10', '7'], ['2', '', '10', '7'], ['8', '', '10', '7'], ['12', '', '10', '7']],
            array_map(static fn (array $r): array => array_map('strval', $r), $this->pdo->query('SELECT id, paid_on, bank_account_id, updated_by FROM erp_entries WHERE id IN (1, 2, 8, 12) ORDER BY id')->fetchAll(PDO::FETCH_NUM)),
            'Em aberto de novo; a conta bancária fica como sugestão.');
    }

    public function testRejectsWholeBatchWithReasons(): void
    {
        try {
            $this->service->reverse($this->user(), ['condominium_id' => 1, 'entry_ids' => [1, 3, 4, 5, 6, 7, 9, 11, 13, 99], 'reason' => 'Conferência do caixa']);
            self::fail('Lote com problema passou.');
        } catch (BatchSettlementRejected $e) {
            self::assertSame([[3, 'not_paid'], [4, 'cancelled'], [5, 'not_found'], [6, 'closed_competence'], [7, 'receipt'], [9, 'reconciled'], [11, 'bank_confirmed'],
                [13, 'not_found'], [99, 'not_found']], array_map(static fn (array $p): array => [$p['entry_id'], $p['code']], $e->problems));
        }
        self::assertSame('2026-11-10', $this->pdo->query('SELECT paid_on FROM erp_entries WHERE id = 1')->fetchColumn(), 'Tudo ou nada: a conta 1 continua paga.');

        self::$race = true;
        try {
            $this->service->reverse($this->user(), ['condominium_id' => 1, 'entry_ids' => [1, 2], 'reason' => 'Conferência do caixa']);
            self::fail('Corrida passou.');
        } catch (BatchSettlementRejected $e) {
            self::assertSame([[2, 'changed']], array_map(static fn (array $p): array => [$p['entry_id'], $p['code']], $e->problems));
        }
        self::assertSame(['2026-11-10', '2026-11-10'], $this->pdo->query('SELECT paid_on FROM erp_entries WHERE id IN (1, 2) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
            'A corrida desfaz o lote inteiro (rollback).');
    }

    public function testValidationScopeAndController(): void
    {
        $base = ['condominium_id' => 1, 'entry_ids' => [1], 'reason' => 'Baixa em duplicidade'];
        foreach ([['condominium_id' => null], ['reason' => null], ['reason' => '  abc  '], ['reason' => str_repeat('x', 256)], ['reason' => ['motivo longo']],
            ['entry_ids' => []], ['entry_ids' => ['x']], ['entry_ids' => range(1, 201)]] as $change) {
            try {
                $this->service->reverse($this->user(), array_merge($base, $change));
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException $e) {
                self::assertNotInstanceOf(BatchSettlementRejected::class, $e, json_encode($change));
            }
        }
        try {
            $this->service->reverse($this->user(), ['condominium_id' => 3] + $base);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/BatchReversal.php');
        foreach (["Access::can('erp.entries.manage', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'problems' => \$exception->problems", "'reversal_rejected'"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/entries/unsettle", "BatchReversal:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents(dirname(__DIR__, 3) . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
