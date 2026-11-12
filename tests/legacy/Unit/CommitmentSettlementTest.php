<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommitmentSettlement;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0253: liquidação em lote dos empenhos com o lançamento já pago. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do formulário, as
 * contagens dos que ficam de fora e o contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB
 * (Integration\CommitmentSettlementTest).
 */
final class CommitmentSettlementTest extends TestCase
{
    private PDO $pdo;
    private CommitmentSettlement $service;

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
            'CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, legal_name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, description TEXT, amount_cents INTEGER, paid_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_commitments (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, description TEXT, amount_cents INTEGER, committed_on TEXT,
                settled_on TEXT, entry_id INTEGER, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_suppliers (id, legal_name, status, deleted_at) VALUES (50, 'Ativo Ltda', 'active', NULL), (51, 'Inativo Ltda', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (70, 1, NULL), (71, 1, '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, description, amount_cents, paid_on, status, deleted_at) VALUES
            (900, 1, 'Pago', 10500, '2026-06-10', 'active', NULL), (901, 1, 'Em aberto', 1000, NULL, 'active', NULL),
            (902, 1, 'Cancelado', 1000, '2026-06-10', 'inactive', NULL), (903, 3, 'De outro', 1000, '2026-06-10', 'active', NULL),
            (904, 1, 'Pago antes', 1000, '2026-01-01', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_commitments (id, condominium_id, supplier_id, description, amount_cents, committed_on, entry_id, document_id, status, deleted_at)
            VALUES (1, 1, 50, 'Reforma da fachada', 10000, '2026-05-01', 900, 70, 'active', NULL), (2, 1, NULL, 'Liquidado', 1000, '2026-05-01', 900, NULL, 'settled', NULL),
            (3, 1, NULL, 'Sem lançamento', 1000, '2026-05-01', NULL, NULL, 'active', NULL), (4, 1, NULL, 'Em aberto', 1000, '2026-05-01', 901, NULL, 'active', NULL),
            (5, 1, NULL, 'Cancelado', 1000, '2026-05-01', 902, NULL, 'active', NULL), (6, 1, NULL, 'Outro condomínio', 1000, '2026-05-01', 903, NULL, 'active', NULL),
            (7, 1, NULL, 'Pago antes', 1000, '2026-05-01', 904, NULL, 'active', NULL), (8, 1, 51, 'Fornecedor inativo', 1000, '2026-05-01', 900, NULL, 'active', NULL),
            (9, 1, NULL, 'Documento removido', 1000, '2026-05-01', 900, 71, 'active', NULL), (10, 3, NULL, 'De outro', 1000, '2026-05-01', 903, NULL, 'active', NULL),
            (11, 1, NULL, 'Excluído', 1000, '2026-05-01', 900, NULL, 'active', '2026-01-01 00:00:00')");
        $this->service = new CommitmentSettlement($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'entry_id' => 900, 'committed_on' => '2026-05-01'];
        $paid = ['paid_on' => '2026-06-10', 'amount_cents' => 100];
        self::assertNull(CommitmentSettlement::problem($row, $paid));
        self::assertNull(CommitmentSettlement::problem($row, ['paid_on' => '2026-05-01'] + $paid), 'Pago no dia do empenho entra.');
        self::assertStringContainsString('não encontrado', (string)CommitmentSettlement::problem(null, $paid));
        self::assertStringContainsString('não está em aberto', (string)CommitmentSettlement::problem(['status' => 'settled'] + $row, $paid));
        self::assertStringContainsString('não está em aberto', (string)CommitmentSettlement::problem(['status' => 'inactive'] + $row, $paid));
        self::assertStringContainsString('não tem lançamento', (string)CommitmentSettlement::problem(['entry_id' => null] + $row, null));
        self::assertStringContainsString('cancelado, removido', (string)CommitmentSettlement::problem($row, null));
        self::assertStringContainsString('ainda não foi pago', (string)CommitmentSettlement::problem($row, ['paid_on' => null] + $paid));
        self::assertStringContainsString('pago antes', (string)CommitmentSettlement::problem($row, ['paid_on' => '2026-04-30'] + $paid));
        self::assertStringContainsString('fornecedor', (string)CommitmentSettlement::problem($row, $paid, false));
    }

    public function testValidationAndScope(): void
    {
        foreach ([['condominium_id' => 0], ['condominium_id' => 'x'], ['condominium_id' => 1, 'commitment_ids' => []], ['condominium_id' => 1, 'commitment_ids' => ['x']],
            ['condominium_id' => 1, 'commitment_ids' => 'abc'], ['condominium_id' => 1, 'commitment_ids' => range(1, 501)]] as $input) {
            try {
                $this->service->settle($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->settle($this->user(), ['condominium_id' => 3]);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'não está em aberto'], [3, 'não tem lançamento'], [4, 'ainda não foi pago'], [5, 'cancelado, removido'], [6, 'cancelado, removido'],
            [7, 'pago antes'], [8, 'fornecedor'], [9, 'documento'], [10, 'não encontrado'], [11, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->settle($this->user(), ['condominium_id' => 1, 'commitment_ids' => [1, $id]]);
                self::fail("Empenho {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_commitment']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Reforma', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_commitments WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_commitments SET deleted_at = '2026-01-01 00:00:00' WHERE id = 1");
        $result = $this->service->settle($this->user(), ['condominium_id' => '1', 'dry_run' => true]);
        self::assertSame([1, 3, 2, 0, 0, 0, 0, 0], [$result['awaiting_payment'], $result['skipped_entry'], $result['skipped_inactive_link'], $result['count'],
            $result['changed'], $result['amount_cents'], $result['entry_amount_cents'], $result['difference_cents']]);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_commitments WHERE status = 'settled' AND id <> 2")->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/CommitmentSettlement.php');
        foreach (["\$registry->save(\$user, 'commitments', array_merge(\$data, ['status' => 'settled', 'settled_on' => \$entry['paid_on']])", ' FOR UPDATE',
            "Audit::record('settle'", '$this->pdo->rollBack()', '$this->scope->assertAccess(', "deleted_at IS NULL AND status = 'active'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'description' =>", "['description']", 'legal_name', 'SELECT description'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem alterar o lançamento, sem aviso e sem descrição nem fornecedor: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CommitmentSettlement.php');
        foreach (["Access::can('erp.commitments.view', \$user)", "Access::can('erp.commitments.manage', \$user)", "Access::can('erp.entries.view', \$user)",
            'catch (CondominiumAccessDenied)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/commitments/settle-paid", "CommitmentSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
