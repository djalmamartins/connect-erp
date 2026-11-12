<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LegalCaseSettlement;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0251: encerramento em lote dos processos de cobrança quitados. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura (quitado, recusas, data do encerramento), a prévia completa
 * (dry_run grava e desfaz), a validação, o escopo e o contrato; a gravação com a auditoria é conferida no MariaDB
 * (Integration\LegalCaseSettlementTest).
 */
final class LegalCaseSettlementTest extends TestCase
{
    private PDO $pdo;
    private LegalCaseSettlement $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, amount_cents INTEGER, paid_on TEXT, description TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_legal_cases (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, lawyer_id INTEGER, status TEXT, case_number TEXT, court TEXT,
                opened_on TEXT, claimed_cents INTEGER, closed_on TEXT, close_reason TEXT)',
            'CREATE TABLE erp_legal_case_entries (case_id INTEGER, entry_id INTEGER, amount_cents INTEGER)',
            'CREATE TABLE erp_legal_case_events (id INTEGER PRIMARY KEY, case_id INTEGER, kind TEXT, from_status TEXT, to_status TEXT, note TEXT, happened_on TEXT,
                created_by INTEGER)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, amount_cents, paid_on, description, status, deleted_at) VALUES
            (1, 1, 100000, '2026-03-01', 'Cota', 'active', NULL), (2, 1, 60000, '2026-05-02', 'Cota', 'active', NULL), (3, 1, 70000, NULL, 'Cota', 'active', NULL),
            (4, 1, 80000, NULL, 'Cota', 'inactive', NULL), (5, 1, 30000, '2026-05-20', 'Cota', 'active', NULL), (6, 1, 20000, '2026-05-20', 'Cota', 'active', '2026-06-01 00:00:00'),
            (7, 3, 10000, '2026-05-20', 'Cota', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_legal_cases (id, condominium_id, unit_id, status, case_number, court, opened_on, claimed_cents) VALUES
            (1, 1, 11, 'filed', 'Proc 0001', 'Vara', '2026-01-10', 150000), (2, 1, 12, 'suspended', NULL, NULL, '2026-01-10', 70000),
            (3, 1, 13, 'agreement', NULL, NULL, '2026-01-10', 80000), (4, 1, 14, 'preparing', NULL, NULL, '2026-01-10', 0),
            (5, 1, 15, 'closed', NULL, NULL, '2026-01-10', 0), (6, 3, 31, 'filed', NULL, NULL, '2026-01-10', 10000),
            (7, 1, 16, 'preparing', NULL, NULL, '2026-06-01', 30000), (8, 1, 17, 'filed', NULL, NULL, '2026-01-10', 20000)");
        $this->pdo->exec('INSERT INTO erp_legal_case_entries (case_id, entry_id, amount_cents) VALUES (1, 1, 100000), (1, 2, 50000), (2, 3, 70000), (3, 4, 80000),
            (6, 7, 10000), (7, 5, 30000), (8, 6, 20000)');
        $this->service = new LegalCaseSettlement($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testPureRules(): void
    {
        $e = static fn (int $total, int $paid, int $open, int $cancelled): array => ['total' => $total, 'paid' => $paid, 'open' => $open, 'cancelled' => $cancelled];
        self::assertSame(['settled', 'open', 'cancelled', 'no_entries', 'open'], [LegalCaseSettlement::settled($e(2, 2, 0, 0)), LegalCaseSettlement::settled($e(2, 1, 1, 0)),
            LegalCaseSettlement::settled($e(2, 1, 0, 1)), LegalCaseSettlement::settled($e(0, 0, 0, 0)), LegalCaseSettlement::settled($e(3, 1, 1, 1))]);
        self::assertNull(LegalCaseSettlement::problem(['status' => 'suspended'], $e(1, 1, 0, 0)));
        self::assertStringContainsString('não encontrado', (string)LegalCaseSettlement::problem(null, $e(1, 1, 0, 0)));
        self::assertStringContainsString('já está encerrado', (string)LegalCaseSettlement::problem(['status' => 'closed'], $e(1, 1, 0, 0)));
        self::assertStringContainsString('em aberto', (string)LegalCaseSettlement::problem(['status' => 'filed'], $e(2, 1, 1, 0)));
        self::assertStringContainsString('cancelada', (string)LegalCaseSettlement::problem(['status' => 'agreement'], $e(1, 0, 0, 1)));
        self::assertStringContainsString('não tem contas', (string)LegalCaseSettlement::problem(['status' => 'preparing'], $e(0, 0, 0, 0)));
        self::assertSame(['2026-05-02', '2026-06-01', '2026-06-01', '2026-11-11'], [LegalCaseSettlement::closingDate('2026-01-10', '2026-05-02', '2026-11-11'),
            LegalCaseSettlement::closingDate('2026-06-01', '2026-05-20', '2026-11-11'), LegalCaseSettlement::closingDate('2026-06-01', null, '2026-11-11'),
            LegalCaseSettlement::closingDate('2026-01-10', '2026-12-01', '2026-11-11')], 'Último pagamento, nunca antes da abertura nem depois de hoje.');
    }

    public function testDryRunComputesEverythingAndUndoes(): void
    {
        $preview = $this->service->close($this->user(), ['condominium_id' => 1, 'reason' => 'Conferência da carteira', 'dry_run' => true]);
        self::assertSame([true, 0, 2, 1, 2, 1, 180000], [$preview['dry_run'], $preview['changed'], $preview['count'], $preview['skipped_open'], $preview['skipped_cancelled'],
            $preview['skipped_no_entries'], $preview['recovered_cents']]);
        self::assertSame([['id' => 1, 'from_status' => 'filed', 'closed_on' => '2026-05-02', 'recovered_cents' => 150000, 'entries' => 2],
            ['id' => 7, 'from_status' => 'preparing', 'closed_on' => '2026-06-01', 'recovered_cents' => 30000, 'entries' => 1]], $preview['cases']);
        self::assertSame(['filed', 0], [$this->pdo->query('SELECT status FROM erp_legal_cases WHERE id = 1')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM erp_legal_case_events')->fetchColumn()], 'dry_run desfaz o encerramento e o andamento.');
        self::assertStringNotContainsString('Proc', (string)json_encode($preview, JSON_UNESCAPED_UNICODE));
    }

    public function testValidationScopeAndRefusals(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Conferência da carteira'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['case_ids' => []] + $ok, ['case_ids' => ['x']] + $ok,
            ['case_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->close($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->close($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[2, 'em aberto'], [3, 'cancelada'], [8, 'cancelada'], [4, 'não tem contas'], [5, 'já está encerrado'], [6, 'não encontrado'], [99, 'não encontrado']]
            as [$id, $message]) {
            try {
                $this->service->close($this->user(), ['case_ids' => [1, $id], 'dry_run' => true] + $ok);
                self::fail("Processo {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_case']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('filed', $this->pdo->query('SELECT status FROM erp_legal_cases WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/LegalCaseSettlement.php');
        foreach (["Collections::canTransition((string)\$case['status'], 'closed')", "UPDATE erp_legal_cases SET status = 'closed', closed_on = ?, close_reason = ?",
            'INSERT INTO erp_legal_case_events', ' FOR UPDATE', "Audit::record('close_settled'", '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_entries', 'DELETE ', 'NotificationOutbox', 'mail_queue', 'case_number', 'court', 'unit_id', 'e.description'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Não toca as contas, sem aviso e sem dado do processo: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/LegalCaseSettlement.php');
        foreach (["Access::can('erp.collections.view', \$user)", "Access::can('erp.collections.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/legal-cases/close-settled", "LegalCaseSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
