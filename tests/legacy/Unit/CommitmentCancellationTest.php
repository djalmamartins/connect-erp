<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommitmentCancellation;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0239: cancelamento em lote dos empenhos em aberto há muito tempo. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do formulário e o
 * contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB
 * (Integration\CommitmentCancellationTest).
 */
final class CommitmentCancellationTest extends TestCase
{
    private PDO $pdo;
    private CommitmentCancellation $service;

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
            'CREATE TABLE erp_commitments (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, description TEXT, amount_cents INTEGER, committed_on TEXT,
                settled_on TEXT, entry_id INTEGER, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_suppliers (id, legal_name, status, deleted_at) VALUES (50, 'Ativo Ltda', 'active', NULL), (51, 'Inativo Ltda', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (70, 1, NULL), (71, 1, '2026-01-01 00:00:00')");
        $old = date('Y-m-d', strtotime('-200 days'));
        $recent = date('Y-m-d', strtotime('-10 days'));
        $this->pdo->exec("INSERT INTO erp_commitments (id, condominium_id, supplier_id, description, amount_cents, committed_on, entry_id, document_id, status, deleted_at)
            VALUES (1, 1, 50, 'Reforma da fachada', 10000, '{$old}', NULL, 70, 'active', NULL), (2, 1, NULL, 'Liquidado', 1000, '{$old}', NULL, NULL, 'settled', NULL),
            (3, 1, NULL, 'Recente', 1000, '{$recent}', NULL, NULL, 'active', NULL), (4, 1, NULL, 'Com lançamento', 1000, '{$old}', 900, NULL, 'active', NULL),
            (5, 1, 51, 'Fornecedor inativo', 1000, '{$old}', NULL, NULL, 'active', NULL), (6, 1, NULL, 'Documento removido', 1000, '{$old}', NULL, 71, 'active', NULL),
            (7, 3, NULL, 'De outro', 1000, '{$old}', NULL, NULL, 'active', NULL), (8, 1, NULL, 'Excluído', 1000, '{$old}', NULL, NULL, 'active', '2026-01-01 00:00:00')");
        $this->service = new CommitmentCancellation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'entry_id' => null, 'committed_on' => '2026-05-01'];
        self::assertNull(CommitmentCancellation::problem($row, '2026-08-13'));
        self::assertNull(CommitmentCancellation::problem(['committed_on' => '2026-08-13 00:00:00'] + $row, '2026-08-13'), 'O último dia do prazo entra.');
        self::assertStringContainsString('não encontrado', (string)CommitmentCancellation::problem(null, '2026-08-13'));
        self::assertStringContainsString('não está em aberto', (string)CommitmentCancellation::problem(['status' => 'settled'] + $row, '2026-08-13'));
        self::assertStringContainsString('não está em aberto', (string)CommitmentCancellation::problem(['status' => 'inactive'] + $row, '2026-08-13'));
        self::assertStringContainsString('lançamento ligado', (string)CommitmentCancellation::problem(['entry_id' => 900] + $row, '2026-08-13'));
        self::assertStringContainsString('dentro do prazo', (string)CommitmentCancellation::problem(['committed_on' => '2026-08-14'] + $row, '2026-08-13'));
        self::assertStringContainsString('fornecedor', (string)CommitmentCancellation::problem($row, '2026-08-13', false));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Orçamento recusado'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['older_than_days' => 29] + $ok, ['older_than_days' => 'x'] + $ok,
            ['older_than_days' => 3651] + $ok, ['commitment_ids' => []] + $ok, ['commitment_ids' => ['x']] + $ok, ['commitment_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->cancel($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->cancel($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'não está em aberto'], [3, 'dentro do prazo'], [4, 'lançamento ligado'], [5, 'fornecedor'], [6, 'documento'], [7, 'não encontrado'],
            [8, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->cancel($this->user(), ['condominium_id' => 1, 'commitment_ids' => [1, $id], 'reason' => 'Orçamento recusado']);
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

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/CommitmentCancellation.php');
        foreach (["\$registry->save(\$user, 'commitments', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('cancel'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "\$r['entry_id'] !== null"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'description' =>", "['description']", 'legal_name'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem descrição nem fornecedor: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CommitmentCancellation.php');
        foreach (["Access::can('erp.commitments.view', \$user)", "Access::can('erp.commitments.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/commitments/cancel-stale", "CommitmentCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
