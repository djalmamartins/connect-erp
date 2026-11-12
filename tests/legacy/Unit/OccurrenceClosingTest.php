<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OccurrenceClosing;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0255: encerramento em lote das ocorrências antigas sem chamado. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do formulário, as
 * contagens das que ficam de fora e o contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no
 * MariaDB (Integration\OccurrenceClosingTest).
 */
final class OccurrenceClosingTest extends TestCase
{
    private PDO $pdo;
    private OccurrenceClosing $service;

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
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE erp_occurrences (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, subject TEXT, priority TEXT, occurred_on TEXT, description TEXT,
                document_id INTEGER, ticket_id INTEGER, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, status, deleted_at) VALUES (40, 1, 'active', NULL), (41, 1, 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (70, 1, NULL), (71, 1, '2026-01-01 00:00:00')");
        $old = date('Y-m-d', strtotime('-200 days'));
        $recent = date('Y-m-d', strtotime('-10 days'));
        $this->pdo->exec("INSERT INTO erp_occurrences (id, condominium_id, unit_id, subject, priority, occurred_on, description, document_id, ticket_id, status, deleted_at)
            VALUES (1, 1, 40, 'Vazamento na garagem', 'alta', '{$old}', 'Relato', 70, NULL, 'active', NULL), (2, 1, NULL, 'Resolvida', 'baixa', '{$old}', 'Relato', NULL, NULL, 'done', NULL),
            (3, 1, NULL, 'Recente', 'baixa', '{$recent}', 'Relato', NULL, NULL, 'active', NULL), (4, 1, NULL, 'Com chamado', 'media', '{$old}', 'Relato', NULL, 900, 'running', NULL),
            (5, 1, 41, 'Unidade inativa', 'baixa', '{$old}', 'Relato', NULL, NULL, 'active', NULL), (6, 1, NULL, 'Documento removido', 'baixa', '{$old}', 'Relato', 71, NULL, 'running', NULL),
            (7, 3, NULL, 'De outro', 'baixa', '{$old}', 'Relato', NULL, NULL, 'active', NULL), (8, 1, NULL, 'Excluída', 'baixa', '{$old}', 'Relato', NULL, NULL, 'active', '2026-01-01 00:00:00')");
        $this->service = new OccurrenceClosing($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'ticket_id' => null, 'occurred_on' => '2026-05-01'];
        self::assertNull(OccurrenceClosing::problem($row, '2026-08-13'));
        self::assertNull(OccurrenceClosing::problem(['status' => 'running'] + $row, '2026-08-13'), 'Em andamento também entra.');
        self::assertNull(OccurrenceClosing::problem(['occurred_on' => '2026-08-13'] + $row, '2026-08-13'), 'O último dia do prazo entra.');
        self::assertStringContainsString('não encontrada', (string)OccurrenceClosing::problem(null, '2026-08-13'));
        self::assertStringContainsString('já está resolvida', (string)OccurrenceClosing::problem(['status' => 'done'] + $row, '2026-08-13'));
        self::assertStringContainsString('Help Desk', (string)OccurrenceClosing::problem(['ticket_id' => 900] + $row, '2026-08-13'));
        self::assertStringContainsString('dentro do prazo', (string)OccurrenceClosing::problem(['occurred_on' => '2026-08-14'] + $row, '2026-08-13'));
        self::assertStringContainsString('unidade', (string)OccurrenceClosing::problem($row, '2026-08-13', false));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Problema já resolvido'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['older_than_days' => 29] + $ok, ['older_than_days' => 'x'] + $ok,
            ['older_than_days' => 3651] + $ok, ['priorities' => []] + $ok, ['priorities' => ['urgente']] + $ok, ['priorities' => 'alta'] + $ok,
            ['occurrence_ids' => []] + $ok, ['occurrence_ids' => ['x']] + $ok, ['occurrence_ids' => range(1, 501)] + $ok] as $input) {
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
        foreach ([[2, 'já está resolvida'], [3, 'dentro do prazo'], [4, 'Help Desk'], [5, 'unidade'], [6, 'documento'], [7, 'não encontrada'],
            [8, 'não encontrada'], [99, 'não encontrada']] as [$id, $message]) {
            try {
                $this->service->close($this->user(), ['condominium_id' => 1, 'occurrence_ids' => [1, $id], 'reason' => 'Problema já resolvido']);
                self::fail("Ocorrência {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_occurrence']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Vazamento', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_occurrences WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_occurrences SET deleted_at = '2026-01-01 00:00:00' WHERE id = 1");
        $result = $this->service->close($this->user(), ['condominium_id' => '1', 'reason' => 'Problema já resolvido', 'dry_run' => true]);
        self::assertSame([1, 2, 0, 0, ['baixa', 'media', 'alta']], [$result['skipped_with_ticket'], $result['skipped_inactive_link'], $result['count'], $result['changed'],
            $result['priorities']]);
        $only = $this->service->close($this->user(), ['condominium_id' => 1, 'priorities' => ['alta'], 'reason' => 'Problema já resolvido', 'dry_run' => true]);
        self::assertSame([0, 0, ['alta']], [$only['skipped_with_ticket'], $only['skipped_inactive_link'], $only['priorities']], 'Só a prioridade escolhida.');
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/OccurrenceClosing.php');
        foreach (["\$registry->save(\$user, 'occurrences', array_merge(\$data, ['status' => 'done'])", ' FOR UPDATE', "Audit::record('close_stale'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('occurrences')"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', 'TicketService', "'subject' =>", "['subject']", "['description']"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem Help Desk, sem aviso e sem assunto nem descrição: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/OccurrenceClosing.php');
        foreach (["Access::can('erp.occurrences.view', \$user)", "Access::can('erp.occurrences.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/occurrences/close-stale", "OccurrenceClosing:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
