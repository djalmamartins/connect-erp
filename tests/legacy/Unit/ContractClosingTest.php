<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContractClosing;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0245: encerramento em lote dos contratos com a vigência terminada. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do formulário e o
 * contrato de reuso; a regravação pelo cadastro, o valor mantido e a auditoria são conferidos no MariaDB
 * (Integration\ContractClosingTest).
 */
final class ContractClosingTest extends TestCase
{
    private PDO $pdo;
    private ContractClosing $service;

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
            'CREATE TABLE erp_contracts (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, object TEXT, starts_on TEXT, ends_on TEXT,
                monthly_cents INTEGER, readjustment TEXT, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_suppliers (id, legal_name, status, deleted_at) VALUES (21, 'Portaria Ltda', 'active', NULL), (22, 'Antiga Ltda', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (51, 1, NULL), (52, 1, '2026-10-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_contracts (id, condominium_id, supplier_id, object, starts_on, ends_on, monthly_cents, readjustment, document_id, status, deleted_at) VALUES
            (1, 1, 21, 'Portaria 24h', '2025-11-01', '2026-10-31', 1000000, 'ipca', 51, 'active', NULL),
            (2, 1, 21, 'Limpeza', '2025-11-01', '2026-10-31', 500000, 'ipca', NULL, 'ended', NULL),
            (3, 1, 21, 'Jardim', '2025-11-01', NULL, 100000, 'ipca', NULL, 'active', NULL),
            (4, 1, 21, 'Elevador', '2025-11-01', '2026-11-20', 200000, 'ipca', NULL, 'active', NULL),
            (5, 1, 22, 'Piscina', '2025-11-01', '2026-10-01', 300000, 'ipca', NULL, 'active', NULL),
            (6, 1, 21, 'Câmeras', '2025-11-01', '2026-10-01', 300000, 'ipca', 52, 'active', NULL),
            (7, 3, 21, 'Fora', '2025-11-01', '2026-10-01', 300000, 'ipca', NULL, 'active', NULL),
            (8, 1, 21, 'Excluído', '2025-11-01', '2026-10-01', 300000, 'ipca', NULL, 'active', '2026-10-02 00:00:00')");
        $this->service = new ContractClosing($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'ends_on' => '2026-10-31'];
        self::assertNull(ContractClosing::problem($row, '2026-11-01'), 'Fim no dia anterior ao corte.');
        self::assertNull(ContractClosing::problem(['ends_on' => '2026-10-31 00:00:00'] + $row, '2026-11-01'));
        self::assertStringContainsString('não encontrado', (string)ContractClosing::problem(null, '2026-11-01'));
        self::assertStringContainsString('já está encerrado', (string)ContractClosing::problem(['status' => 'ended'] + $row, '2026-11-01'));
        self::assertStringContainsString('já está encerrado', (string)ContractClosing::problem(['status' => 'inactive'] + $row, '2026-11-01'));
        self::assertStringContainsString('indeterminado', (string)ContractClosing::problem(['ends_on' => null] + $row, '2026-11-01'));
        self::assertStringContainsString('ainda não terminou', (string)ContractClosing::problem(['ends_on' => '2026-11-01'] + $row, '2026-11-01'));
        self::assertStringContainsString('fornecedor', (string)ContractClosing::problem($row, '2026-11-01', false));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Troca de empresa', 'before' => '2026-11-01'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before' => '01/11/2026'] + $ok,
            ['before' => date('Y-m-d', strtotime('+2 days'))] + $ok, ['contract_ids' => []] + $ok, ['contract_ids' => ['x']] + $ok,
            ['contract_ids' => range(1, 501)] + $ok] as $input) {
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
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'já está encerrado'], [3, 'indeterminado'], [4, 'ainda não terminou'], [5, 'fornecedor'], [6, 'documento'], [7, 'não encontrado'],
            [8, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->close($this->user(), ['condominium_id' => 1, 'contract_ids' => [1, $id], 'reason' => 'Troca de empresa', 'before' => '2026-11-01']);
                self::fail("Contrato {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_contract']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Portaria', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_contracts WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ContractClosing.php');
        foreach (["\$registry->save(\$user, 'contracts', array_merge(\$data, ['status' => 'ended'])", ' FOR UPDATE', "Audit::record('close'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', 'AND ends_on IS NOT NULL AND ends_on < ?',
            "number_format((int)\$value / 100, 2, ',', '')", 'SELECT COUNT(*) FROM erp_recurring_entries'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_contracts', 'UPDATE erp_recurring', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'object' =>", "['object']",
            "'supplier_id' =>", "'ends_on' => \$before"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, mantém fim e valor, sem aviso e sem dado do contrato: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ContractClosing.php');
        foreach (["Access::can('erp.contracts.view', \$user)", "Access::can('erp.contracts.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/contracts/close-ended", "ContractClosing:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
