<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BoardTermExpiry;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0243: encerramento em lote dos mandatos vencidos. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO de
 * teste tira o FOR UPDATE) para a regra pura, os cargos, a validação, o escopo, as recusas antes do formulário e o contrato
 * de reuso; a regravação pelo cadastro e a auditoria são conferidas no MariaDB (Integration\BoardTermExpiryTest).
 */
final class BoardTermExpiryTest extends TestCase
{
    private PDO $pdo;
    private BoardTermExpiry $service;

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
            'CREATE TABLE erp_board_members (id INTEGER PRIMARY KEY, condominium_id INTEGER, role TEXT, name TEXT, document TEXT, unit_id INTEGER, unit_label TEXT,
                term_starts_on TEXT, term_ends_on TEXT, email TEXT, phone TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, status, deleted_at) VALUES (11, 1, 'Apto 101', 'active', NULL), (12, 1, 'Apto 102', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_board_members (id, condominium_id, role, name, unit_id, term_starts_on, term_ends_on, email, phone, status, deleted_at) VALUES
            (1, 1, 'sindico', 'Fulano Síndico', 11, '2024-11-01', '2026-10-31', 'a@exemplo.com', '11988887777', 'active', NULL),
            (2, 1, 'fiscal', 'Beltrano', 11, '2024-11-01', '2026-10-31', 'b@exemplo.com', '11988887777', 'inactive', NULL),
            (3, 1, 'fiscal', 'Ciclano', 11, '2024-11-01', NULL, 'c@exemplo.com', '11988887777', 'active', NULL),
            (4, 1, 'fiscal', 'Deltrano', 11, '2024-11-01', '2026-11-20', 'd@exemplo.com', '11988887777', 'active', NULL),
            (5, 1, 'consultivo', 'Ermano', 12, '2024-11-01', '2026-10-01', 'e@exemplo.com', '11988887777', 'active', NULL),
            (6, 1, 'suplente', 'Sem contato', NULL, '2024-11-01', '2026-10-01', '', '11988887777', 'active', NULL),
            (7, 3, 'fiscal', 'Outro', NULL, '2024-11-01', '2026-10-01', 'f@exemplo.com', '11988887777', 'active', NULL),
            (8, 1, 'fiscal', 'Excluído', NULL, '2024-11-01', '2026-10-01', 'g@exemplo.com', '11988887777', 'active', '2026-10-02 00:00:00')");
        $this->service = new BoardTermExpiry($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRuleAndRoles(): void
    {
        $row = ['status' => 'active', 'term_ends_on' => '2026-10-31', 'email' => 'a@exemplo.com', 'phone' => '11988887777'];
        self::assertNull(BoardTermExpiry::problem($row, '2026-11-01'), 'Fim no dia anterior ao corte.');
        self::assertNull(BoardTermExpiry::problem(['term_ends_on' => '2026-10-31 00:00:00'] + $row, '2026-11-01'));
        self::assertStringContainsString('não encontrado', (string)BoardTermExpiry::problem(null, '2026-11-01'));
        self::assertStringContainsString('já está inativo', (string)BoardTermExpiry::problem(['status' => 'inactive'] + $row, '2026-11-01'));
        self::assertStringContainsString('não tem fim', (string)BoardTermExpiry::problem(['term_ends_on' => null] + $row, '2026-11-01'));
        self::assertStringContainsString('ainda não terminou', (string)BoardTermExpiry::problem(['term_ends_on' => '2026-11-01'] + $row, '2026-11-01'));
        self::assertStringContainsString('unidade', (string)BoardTermExpiry::problem($row, '2026-11-01', false));
        self::assertStringContainsString('e-mail ou telefone', (string)BoardTermExpiry::problem(['phone' => ' '] + $row, '2026-11-01'));
        self::assertSame(BoardTermExpiry::roleOptions(), BoardTermExpiry::roles(null));
        self::assertSame(['sindico', 'subsindico', 'fiscal', 'consultivo', 'suplente'], BoardTermExpiry::roleOptions(), 'Os cargos vêm do cadastro.');
        self::assertSame(['subsindico', 'fiscal'], BoardTermExpiry::roles(['fiscal', 'subsindico', 'fiscal']));
        self::assertNull(BoardTermExpiry::roles(['presidente']));
        self::assertNull(BoardTermExpiry::roles('fiscal'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Nova eleição', 'before' => '2026-11-01'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before' => '01/11/2026'] + $ok,
            ['before' => date('Y-m-d', strtotime('+2 days'))] + $ok, ['roles' => ['x']] + $ok, ['member_ids' => []] + $ok, ['member_ids' => ['x']] + $ok,
            ['member_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->expire($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->expire($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'já está inativo'], [3, 'não tem fim'], [4, 'ainda não terminou'], [5, 'unidade'], [6, 'e-mail ou telefone'], [7, 'não encontrado'],
            [8, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->expire($this->user(), ['condominium_id' => 1, 'member_ids' => [1, $id], 'reason' => 'Nova eleição', 'before' => '2026-11-01']);
                self::fail("Membro {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_member']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_board_members WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/BoardTermExpiry.php');
        foreach (["\$registry->save(\$user, 'board', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('expire'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', 'AND term_ends_on IS NOT NULL AND term_ends_on < ?'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_board_members', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'name' =>", "['name']", "['document']",
            "'email' =>", "'phone' =>", "'term_ends_on' => \$before"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, mantém o fim, sem aviso e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/BoardTermExpiry.php');
        foreach (["Access::can('erp.board.view', \$user)", "Access::can('erp.board.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/board-members/expire-terms", "BoardTermExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
