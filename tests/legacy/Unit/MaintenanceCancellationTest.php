<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MaintenanceCancellation;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0261: cancelamento em lote das manutenções agendadas e esquecidas. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do formulário,
 * as contagens das que ficam de fora e o contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no
 * MariaDB (Integration\MaintenanceCancellationTest).
 */
final class MaintenanceCancellationTest extends TestCase
{
    private PDO $pdo;
    private MaintenanceCancellation $service;

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
            'CREATE TABLE erp_maintenances (id INTEGER PRIMARY KEY, condominium_id INTEGER, service TEXT, supplier_id INTEGER, planned_on TEXT, frequency TEXT, done_on TEXT,
                document_id INTEGER, description TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_suppliers (id, legal_name, status, deleted_at) VALUES (50, 'Ativo Ltda', 'active', NULL), (51, 'Inativo Ltda', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (70, 1, NULL), (71, 1, '2026-01-01 00:00:00')");
        $old = date('Y-m-d', strtotime('-200 days'));
        $recent = date('Y-m-d', strtotime('-10 days'));
        $this->pdo->exec("INSERT INTO erp_maintenances (id, condominium_id, service, supplier_id, planned_on, frequency, document_id, status, deleted_at) VALUES
            (1, 1, 'Limpeza da caixa d’água', 50, '{$old}', 'unica', 70, 'active', NULL), (2, 1, 'Concluída', NULL, '{$old}', 'mensal', NULL, 'done', NULL),
            (3, 1, 'Recente', NULL, '{$recent}', 'mensal', NULL, 'active', NULL), (4, 1, 'Em execução', NULL, '{$old}', 'mensal', NULL, 'running', NULL),
            (5, 1, 'Fornecedor inativo', 51, '{$old}', 'mensal', NULL, 'active', NULL), (6, 1, 'Documento removido', NULL, '{$old}', 'anual', 71, 'active', NULL),
            (7, 3, 'De outro', NULL, '{$old}', 'unica', NULL, 'active', NULL), (8, 1, 'Excluída', NULL, '{$old}', 'unica', NULL, 'active', '2026-01-01 00:00:00'),
            (9, 1, 'Cancelada', NULL, '{$old}', 'unica', NULL, 'inactive', NULL)");
        $this->service = new MaintenanceCancellation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'planned_on' => '2026-05-01'];
        self::assertNull(MaintenanceCancellation::problem($row, '2026-08-13'));
        self::assertNull(MaintenanceCancellation::problem(['planned_on' => '2026-08-13'] + $row, '2026-08-13'), 'O último dia do prazo entra.');
        self::assertStringContainsString('não encontrada', (string)MaintenanceCancellation::problem(null, '2026-08-13'));
        self::assertStringContainsString('em execução', (string)MaintenanceCancellation::problem(['status' => 'running'] + $row, '2026-08-13'));
        self::assertStringContainsString('concluída ou cancelada', (string)MaintenanceCancellation::problem(['status' => 'done'] + $row, '2026-08-13'));
        self::assertStringContainsString('concluída ou cancelada', (string)MaintenanceCancellation::problem(['status' => 'inactive'] + $row, '2026-08-13'));
        self::assertStringContainsString('dentro do prazo', (string)MaintenanceCancellation::problem(['planned_on' => '2026-08-14'] + $row, '2026-08-13'));
        self::assertStringContainsString('fornecedor', (string)MaintenanceCancellation::problem($row, '2026-08-13', false));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Serviço duplicado'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['older_than_days' => 14] + $ok, ['older_than_days' => 'x'] + $ok,
            ['older_than_days' => 3651] + $ok, ['frequencies' => []] + $ok, ['frequencies' => ['diaria']] + $ok, ['frequencies' => 'mensal'] + $ok,
            ['maintenance_ids' => []] + $ok, ['maintenance_ids' => ['x']] + $ok, ['maintenance_ids' => range(1, 501)] + $ok] as $input) {
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
        foreach ([[2, 'concluída ou cancelada'], [3, 'dentro do prazo'], [4, 'em execução'], [5, 'fornecedor'], [6, 'documento'], [7, 'não encontrada'],
            [8, 'não encontrada'], [9, 'concluída ou cancelada'], [99, 'não encontrada']] as [$id, $message]) {
            try {
                $this->service->cancel($this->user(), ['condominium_id' => 1, 'maintenance_ids' => [1, $id], 'reason' => 'Serviço duplicado']);
                self::fail("Manutenção {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_maintenance']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Limpeza', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_maintenances WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_maintenances SET deleted_at = '2026-01-01 00:00:00' WHERE id = 1");
        $result = $this->service->cancel($this->user(), ['condominium_id' => '1', 'reason' => 'Serviço duplicado', 'dry_run' => true]);
        self::assertSame([2, 0, 0, 0, ['unica', 'mensal', 'trimestral', 'semestral', 'anual']], [$result['skipped_inactive_link'], $result['count'], $result['periodic'],
            $result['changed'], $result['frequencies']]);
        $only = $this->service->cancel($this->user(), ['condominium_id' => 1, 'frequencies' => ['mensal'], 'reason' => 'Serviço duplicado', 'dry_run' => true]);
        self::assertSame([1, ['mensal']], [$only['skipped_inactive_link'], $only['frequencies']], 'Só a periodicidade escolhida.');
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/MaintenanceCancellation.php');
        foreach (["\$registry->save(\$user, 'maintenances', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('cancel_overdue'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('maintenances')"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', 'MaintenanceCompletion', "'service' =>", "['service']", "['description']",
            'legal_name'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem agendar a próxima, sem aviso e sem serviço nem descrição: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/MaintenanceCancellation.php');
        foreach (["Access::can('erp.maintenances.view', \$user)", "Access::can('erp.maintenances.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/maintenances/cancel-overdue", "MaintenanceCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
