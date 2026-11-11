<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Support\TicketReassignment;

/**
 * ADR 0227: reatribuição em lote dos chamados. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO de teste
 * tira o FOR UPDATE) para a regra pura, a validação, o escopo, a prévia, as recusas e o contrato; a gravação com auditoria
 * é conferida no MariaDB (Integration\TicketReassignmentTest).
 */
final class TicketReassignmentTest extends TestCase
{
    private PDO $pdo;
    private TicketReassignment $service;

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
            'CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, level INTEGER)',
            'CREATE TABLE studio_support_tickets (id INTEGER PRIMARY KEY, condominium_id INTEGER, protocol TEXT, subject TEXT, priority TEXT, status TEXT, due_at TEXT,
                assigned_to INTEGER)',
            'CREATE TABLE studio_support_ticket_events (id INTEGER PRIMARY KEY, ticket_id INTEGER, user_id INTEGER, event_type TEXT, old_value TEXT, new_value TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO users (id, first_name, level) VALUES (20, 'Ana Férias', 5), (21, 'Bruno Plantão', 5), (22, 'Morador', 1)");
        $this->pdo->exec("INSERT INTO studio_support_tickets (id, condominium_id, protocol, subject, priority, status, due_at, assigned_to) VALUES
            (1, 1, 'CH1', 'Vazamento do Fulano', 'high', 'open', '2026-11-12 10:00:00', 20),
            (2, 1, 'CH2', 'Portão', 'low', 'waiting_customer', '2026-11-20 10:00:00', 20),
            (3, 1, 'CH3', 'Resolvido', 'low', 'resolved', '2026-11-01 10:00:00', 20),
            (4, 1, 'CH4', 'De outro', 'medium', 'open', '2026-11-15 10:00:00', 21),
            (5, 3, 'CH5', 'Outro condomínio', 'medium', 'open', '2026-11-15 10:00:00', 20)");
        $this->service = new TicketReassignment($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'open', 'assigned_to' => 20];
        self::assertNull(TicketReassignment::problem($row, 20));
        self::assertNull(TicketReassignment::problem(['status' => 'waiting_customer'] + $row, 20));
        self::assertStringContainsString('não encontrado', (string)TicketReassignment::problem(null, 20));
        self::assertStringContainsString('concluído', (string)TicketReassignment::problem(['status' => 'closed'] + $row, 20));
        self::assertStringContainsString('outro responsável', (string)TicketReassignment::problem(['assigned_to' => 21] + $row, 20));
        self::assertStringContainsString('outro responsável', (string)TicketReassignment::problem(['assigned_to' => null] + $row, 20));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'from_user_id' => 20, 'to_user_id' => 21, 'reason' => 'Férias programadas'];
        foreach ([['condominium_id' => 0] + $ok, ['from_user_id' => 'x'] + $ok, ['to_user_id' => null] + $ok, ['to_user_id' => 20] + $ok, ['reason' => 'abc'] + $ok,
            ['ticket_ids' => []] + $ok, ['ticket_ids' => ['x']] + $ok, ['ticket_ids' => range(1, 501)] + $ok, ['to_user_id' => 22] + $ok, ['to_user_id' => 99] + $ok]
            as $input) {
            try {
                $this->service->reassign($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->reassign($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testPreviewAndRefusals(): void
    {
        $preview = $this->service->reassign($this->user(), ['condominium_id' => 1, 'from_user_id' => 20, 'to_user_id' => 21, 'reason' => 'Férias programadas', 'dry_run' => true]);
        self::assertSame([true, 0, [1, 2]], [$preview['dry_run'], $preview['changed'], array_column($preview['tickets'], 'id')], 'Só os abertos da Ana neste condomínio.');
        self::assertSame([20, 0], [(int)$this->pdo->query('SELECT assigned_to FROM studio_support_tickets WHERE id = 1')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM studio_support_ticket_events')->fetchColumn()], 'dry_run não grava.');
        $json = (string)json_encode($preview);
        foreach (['Fulano', 'Vazamento', 'CH1', 'Ana', 'Bruno'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do chamado nem nome: ' . $needle);
        }
        foreach ([[3, 'concluído'], [4, 'outro responsável'], [5, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->reassign($this->user(), ['condominium_id' => 1, 'from_user_id' => 20, 'to_user_id' => 21, 'reason' => 'Férias programadas', 'ticket_ids' => [1, $id]]);
                self::fail("Chamado {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_ticket']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame(20, (int)$this->pdo->query('SELECT assigned_to FROM studio_support_tickets WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Support/TicketReassignment.php');
        foreach (["'UPDATE studio_support_tickets SET assigned_to = ? WHERE id = ? AND assigned_to = ?'", "'assigned_to', (string)\$from, (string)\$to",
            ' FOR UPDATE', "Audit::record('reassign'", '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['notify', 'mail_queue', 'NotificationOutbox', 'SET status', 'SET priority', 'due_at =', "'subject'", "'protocol'", 'first_name'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só o responsável muda, sem aviso e sem nome: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/TicketReassignment.php');
        foreach (["Access::can('studio.access', \$user)", "Access::can('support.manage', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/tickets/reassign", "TicketReassignment:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
