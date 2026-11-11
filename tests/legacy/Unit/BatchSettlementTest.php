<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BatchSettlement;
use Source\Services\Erp\BatchSettlementRejected;

/**
 * ADR 0191: baixa em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). O PDO de teste tira o FOR UPDATE
 * e o LOCK IN SHARE MODE da competência fechada (ADR 0100) e, quando $race está ligado, baixa a conta 2 "por outra
 * pessoa" logo antes do UPDATE do lote.
 */
final class BatchSettlementTest extends TestCase
{
    private const TODAY = '2026-11-11';

    public static bool $race = false;

    private PDO $pdo;
    private BatchSettlement $service;

    protected function setUp(): void
    {
        self::$race = false;
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    BatchSettlementTest::race($this, $query);
                    return parent::prepare(str_replace([' FOR UPDATE', ' LOCK IN SHARE MODE'], '', $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    BatchSettlementTest::race($this, $query);
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
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, description TEXT, amount_cents INTEGER, competence TEXT, due_on TEXT,
                paid_on TEXT, bank_account_id INTEGER, updated_by INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opened_on, status, deleted_at) VALUES
            (10, 1, 'Movimento', '2026-01-01', 'active', NULL), (11, 1, 'Encerrada', '2026-01-01', 'inactive', NULL),
            (12, 3, 'Do outro', '2026-01-01', 'active', NULL), (13, 1, 'Nova', '2026-11-01', 'active', NULL), (14, 1, 'Excluída', NULL, 'active', '2026-05-01')");
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, nature, description, amount_cents, competence, due_on, paid_on, bank_account_id, status, deleted_at) VALUES
            (1, 1, 'despesa', 'Limpeza out', 30000, '2026-10', '2026-11-05', NULL, NULL, 'active', NULL),
            (2, 1, 'despesa', 'Elevador nov', 20000, '2026-11', '2026-11-10', NULL, 13, 'active', NULL),
            (3, 1, 'receita', 'Taxa 101 nov', 50000, '2026-11', '2026-11-10', NULL, NULL, 'active', NULL),
            (4, 1, 'despesa', 'Já paga', 10000, '2026-10', '2026-10-05', '2026-10-05', 10, 'active', NULL),
            (5, 1, 'despesa', 'Cancelada', 10000, '2026-11', '2026-11-05', NULL, NULL, 'inactive', NULL),
            (6, 1, 'despesa', 'Excluída', 10000, '2026-11', '2026-11-05', NULL, NULL, 'active', '2026-11-06'),
            (7, 1, 'despesa', 'Mês fechado', 10000, '2026-09', '2026-09-05', NULL, NULL, 'active', NULL),
            (8, 3, 'despesa', 'Outro condomínio', 10000, '2026-11', '2026-11-05', NULL, NULL, 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-09', 'closed'), (1, '2026-10', 'reopened'), (3, '2026-11', 'closed')");
        $this->service = new BatchSettlement($this->pdo, new CondominiumScope($this->pdo));
    }

    /** Simula outra pessoa baixando a conta 2 entre a conferência e a gravação. */
    public static function race(PDO $pdo, string $query): void
    {
        if (self::$race && str_starts_with(ltrim($query), 'UPDATE erp_entries SET paid_on = :p')) {
            self::$race = false;
            $pdo->exec("UPDATE erp_entries SET paid_on = '2026-11-09', bank_account_id = 10 WHERE id = 2");
        }
    }

    public function testDryRunThenSettle(): void
    {
        $input = ['condominium_id' => '1', 'bank_account_id' => 10, 'paid_on' => '2026-11-10', 'entry_ids' => [1, '2', 3, 2]];
        $preview = $this->service->settle($this->user(), $input + ['dry_run' => true], self::TODAY);
        self::assertSame([true, 0, 3, 'Movimento'], [$preview['dry_run'], $preview['settled'], $preview['count'], $preview['bank_account']]);
        self::assertSame(['receita_cents' => 50000, 'despesa_cents' => 50000, 'net_cents' => 0], $preview['totals']);
        self::assertSame([1, 2, 3], array_column($preview['entries'], 'id'), 'Repetida conta uma vez.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries WHERE paid_on IS NOT NULL AND id IN (1, 2, 3)')->fetchColumn(), 'dry_run não grava.');

        $done = $this->service->settle($this->user(), $input, self::TODAY);
        self::assertSame([false, 3], [$done['dry_run'], $done['settled']]);
        self::assertSame([['1', '2026-11-10', '10', '7'], ['2', '2026-11-10', '10', '7'], ['3', '2026-11-10', '10', '7']],
            array_map(static fn (array $r): array => array_map('strval', $r), $this->pdo->query('SELECT id, paid_on, bank_account_id, updated_by FROM erp_entries WHERE id IN (1, 2, 3) ORDER BY id')->fetchAll(PDO::FETCH_NUM)),
            'Pago na data e na conta escolhidas; a conta sugerida (13) é trocada pela do pagamento.');
        self::assertSame('2026-10-05', $this->pdo->query('SELECT paid_on FROM erp_entries WHERE id = 4')->fetchColumn(), 'Fora do lote não muda.');
    }

    public function testRejectsWholeBatchWithReasons(): void
    {
        try {
            $this->service->settle($this->user(), ['condominium_id' => 1, 'bank_account_id' => 10, 'paid_on' => '2026-11-10', 'entry_ids' => [1, 4, 5, 6, 7, 8, 99]], self::TODAY);
            self::fail('Lote com problema passou.');
        } catch (BatchSettlementRejected $e) {
            self::assertSame([[4, 'already_paid'], [5, 'cancelled'], [6, 'not_found'], [7, 'closed_competence'], [8, 'not_found'], [99, 'not_found']],
                array_map(static fn (array $p): array => [$p['entry_id'], $p['code']], $e->problems), 'Mês reaberto (2026-10) e fechamento de outro condomínio não travam.');
        }
        self::assertNull($this->pdo->query('SELECT paid_on FROM erp_entries WHERE id = 1')->fetchColumn() ?: null, 'Tudo ou nada: a conta 1 continua em aberto.');

        self::$race = true;
        try {
            $this->service->settle($this->user(), ['condominium_id' => 1, 'bank_account_id' => 10, 'paid_on' => '2026-11-10', 'entry_ids' => [1, 2]], self::TODAY);
            self::fail('Corrida passou.');
        } catch (BatchSettlementRejected $e) {
            self::assertSame([[2, 'already_paid']], array_map(static fn (array $p): array => [$p['entry_id'], $p['code']], $e->problems));
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries WHERE paid_on IS NOT NULL AND id IN (1, 2)')->fetchColumn(),
            'A corrida desfaz o lote inteiro (rollback).');
    }

    public function testValidationScopeAndRules(): void
    {
        self::assertSame([3, 1], BatchSettlement::ids(['3', 1, 3]));
        $base = ['condominium_id' => 1, 'bank_account_id' => 10, 'paid_on' => '2026-11-10', 'entry_ids' => [1]];
        foreach ([['condominium_id' => null], ['bank_account_id' => null], ['bank_account_id' => 11], ['bank_account_id' => 12], ['bank_account_id' => 14],
            ['bank_account_id' => 13, 'paid_on' => '2026-10-31'], ['paid_on' => '2026-11-12'], ['paid_on' => '2026-02-30'], ['paid_on' => '10/11/2026'],
            ['entry_ids' => []], ['entry_ids' => ['x']], ['entry_ids' => [[1]]], ['entry_ids' => '1'], ['entry_ids' => range(1, 201)]] as $change) {
            try {
                $this->service->settle($this->user(), array_merge($base, $change), self::TODAY);
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException $e) {
                self::assertNotInstanceOf(BatchSettlementRejected::class, $e, json_encode($change));
            }
        }
        self::assertSame(1, $this->service->settle($this->user(), ['bank_account_id' => 13, 'paid_on' => '2026-11-01'] + $base, self::TODAY)['settled'], 'No dia da abertura pode.');
        try {
            $this->service->settle($this->user(), ['condominium_id' => 3] + $base, self::TODAY);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/BatchSettlement.php');
        foreach (["Access::can('erp.entries.manage', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'problems' => \$exception->problems"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/entries/settle", "BatchSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents(dirname(__DIR__, 3) . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
