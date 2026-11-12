<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MoveSettlement;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0247: baixa em lote dos agendamentos de mudança com a data já passada. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, os tipos, a validação, o escopo, as recusas antes do
 * formulário e o contrato de reuso; a regravação pelo cadastro e a auditoria são conferidas no MariaDB
 * (Integration\MoveSettlementTest).
 */
final class MoveSettlementTest extends TestCase
{
    private PDO $pdo;
    private MoveSettlement $service;

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
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_moves (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, resident_name TEXT, kind TEXT, scheduled_on TEXT, period TEXT,
                notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, status, deleted_at) VALUES (11, 1, 'Apto 101', 'active', NULL), (12, 1, 'Apto 102', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_moves (id, condominium_id, unit_id, resident_name, kind, scheduled_on, period, status, deleted_at) VALUES
            (1, 1, 11, 'Fulano Morador', 'saida', '2026-10-15', 'manha', 'active', NULL),
            (2, 1, 11, 'Beltrano', 'entrada', '2026-10-16', 'manha', 'done', NULL),
            (3, 1, 11, 'Ciclano', 'entrada', '2026-11-01', 'manha', 'active', NULL),
            (4, 1, 12, 'Deltrano', 'saida', '2026-10-10', 'tarde', 'active', NULL),
            (5, 3, NULL, 'Outro', 'saida', '2026-10-10', 'tarde', 'active', NULL),
            (6, 1, 11, 'Excluído', 'saida', '2026-10-10', 'tarde', 'active', '2026-10-11 00:00:00')");
        $this->service = new MoveSettlement($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRuleAndKinds(): void
    {
        $row = ['status' => 'active', 'scheduled_on' => '2026-10-31'];
        self::assertNull(MoveSettlement::problem($row, '2026-11-01'), 'Dia anterior ao corte.');
        self::assertNull(MoveSettlement::problem(['scheduled_on' => '2026-10-31 00:00:00'] + $row, '2026-11-01'));
        self::assertStringContainsString('não encontrado', (string)MoveSettlement::problem(null, '2026-11-01'));
        self::assertStringContainsString('já foi realizado', (string)MoveSettlement::problem(['status' => 'done'] + $row, '2026-11-01'));
        self::assertStringContainsString('já foi realizado', (string)MoveSettlement::problem(['status' => 'inactive'] + $row, '2026-11-01'));
        self::assertStringContainsString('ainda não passou', (string)MoveSettlement::problem(['scheduled_on' => '2026-11-01'] + $row, '2026-11-01'));
        self::assertStringContainsString('unidade', (string)MoveSettlement::problem($row, '2026-11-01', false));
        self::assertSame(['entrada', 'saida'], MoveSettlement::kindOptions(), 'Os tipos vêm do cadastro.');
        self::assertSame(['entrada', 'saida'], MoveSettlement::kinds(null));
        self::assertSame(['saida'], MoveSettlement::kinds(['saida', 'saida']));
        self::assertNull(MoveSettlement::kinds(['mudanca']));
        self::assertNull(MoveSettlement::kinds('saida'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Baixa da portaria', 'before' => '2026-11-01'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before' => '01/11/2026'] + $ok,
            ['before' => date('Y-m-d', strtotime('+2 days'))] + $ok, ['outcome' => 'active'] + $ok, ['outcome' => 1] + $ok, ['kinds' => ['x']] + $ok,
            ['move_ids' => []] + $ok, ['move_ids' => ['x']] + $ok, ['move_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->settle($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->settle($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'já foi realizado'], [3, 'ainda não passou'], [4, 'unidade'], [5, 'não encontrado'], [6, 'não encontrado'], [99, 'não encontrado']]
            as [$id, $message]) {
            try {
                $this->service->settle($this->user(), ['condominium_id' => 1, 'move_ids' => [1, $id], 'reason' => 'Baixa da portaria', 'before' => '2026-11-01']);
                self::fail("Agendamento {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_move']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_moves WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/MoveSettlement.php');
        foreach (["\$registry->save(\$user, 'moves', array_merge(\$data, ['status' => \$outcome])", ' FOR UPDATE', "Audit::record('settle'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', 'AND scheduled_on < ? AND kind IN', 'SELECT COUNT(DISTINCT unit_id) FROM erp_residents'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_moves', 'UPDATE erp_residents', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'resident_name' =>", "['resident_name']",
            "'unit_id' =>", "'scheduled_on' => \$before"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, mantém a data, sem aviso e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/MoveSettlement.php');
        foreach (["Access::can('erp.moves.view', \$user)", "Access::can('erp.moves.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/moves/settle-past", "MoveSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
