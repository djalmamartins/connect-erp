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
use Source\Services\Erp\VisitorUnitBlock;

/**
 * ADR 0265: bloqueio em lote dos visitantes liberados de unidades sem morador ativo. Unit com SQLite (Pdo\Sqlite quando
 * existe; PDO no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do
 * formulário, as contagens dos que ficam de fora e o contrato de reuso; a regravação pelo cadastro, com auditoria, é
 * conferida no MariaDB (Integration\VisitorUnitBlockTest).
 */
final class VisitorUnitBlockTest extends TestCase
{
    private PDO $pdo;
    private VisitorUnitBlock $service;

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
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, name TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_visitors (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, name TEXT, document TEXT, phone TEXT,
                permanent_access INTEGER, valid_until TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $past = date('Y-m-d', strtotime('-30 days'));
        $future = date('Y-m-d', strtotime('+30 days'));
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, status, deleted_at) VALUES (40, 1, 'active', NULL), (41, 1, 'active', NULL), (42, 1, 'active', NULL),
            (43, 1, 'inactive', NULL), (44, 1, 'active', NULL)");
        // 40: sem morador (saiu); 41: com morador ativo; 42: só morador com fim passado; 43: inativa; 44: só morador inativo.
        $this->pdo->exec("INSERT INTO erp_residents (condominium_id, unit_id, name, ends_on, status, deleted_at) VALUES (1, 41, 'Morador', NULL, 'active', NULL),
            (1, 42, 'Saiu', '{$past}', 'active', NULL), (1, 44, 'Inativo', NULL, 'inactive', NULL), (1, 40, 'Excluído', NULL, 'active', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_visitors (id, condominium_id, unit_id, unit_label, name, document, permanent_access, valid_until, status, deleted_at) VALUES
            (1, 1, 40, '101', 'Diarista Fulana', '12345678909', 1, NULL, 'active', NULL), (2, 1, 42, '102', 'Personal', NULL, 0, '{$future}', 'active', NULL),
            (3, 1, 41, '103', 'Com morador', NULL, 1, NULL, 'active', NULL), (4, 1, NULL, '104', 'Só texto', NULL, 1, NULL, 'active', NULL),
            (5, 1, 43, '105', 'Unidade inativa', NULL, 1, NULL, 'active', NULL), (6, 1, 44, '106', 'Sem data', NULL, 0, NULL, 'active', NULL),
            (7, 1, 40, '101', 'Já bloqueado', NULL, 1, NULL, 'inactive', NULL), (8, 3, 50, '1', 'De fora', NULL, 1, NULL, 'active', NULL),
            (9, 1, 40, '101', 'Excluído', NULL, 1, NULL, 'active', '2026-01-01 00:00:00')");
        $this->service = new VisitorUnitBlock($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'unit_id' => 40, 'permanent_access' => 1, 'valid_until' => null];
        self::assertNull(VisitorUnitBlock::problem($row, false));
        self::assertNull(VisitorUnitBlock::problem(['permanent_access' => 0, 'valid_until' => '2027-01-01'] + $row, false), 'Com data também entra.');
        self::assertStringContainsString('não encontrado', (string)VisitorUnitBlock::problem(null, false));
        self::assertStringContainsString('já está bloqueado', (string)VisitorUnitBlock::problem(['status' => 'inactive'] + $row, false));
        self::assertStringContainsString('não tem unidade vinculada', (string)VisitorUnitBlock::problem(['unit_id' => null] + $row, false));
        self::assertStringContainsString('ainda tem morador ativo', (string)VisitorUnitBlock::problem($row, true));
        self::assertStringContainsString('unidade do visitante está inativa', (string)VisitorUnitBlock::problem($row, false, false));
        self::assertStringContainsString('sem data nem acesso permanente', (string)VisitorUnitBlock::problem(['permanent_access' => 0] + $row, false));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Família se mudou'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['visitor_ids' => []] + $ok, ['visitor_ids' => ['x']] + $ok,
            ['visitor_ids' => 'abc'] + $ok, ['visitor_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->block($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->block($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[3, 'ainda tem morador ativo'], [4, 'não tem unidade vinculada'], [5, 'inativa'], [6, 'sem data'], [7, 'já está bloqueado'], [8, 'não encontrado'],
            [9, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->block($this->user(), ['condominium_id' => 1, 'visitor_ids' => [1, $id], 'reason' => 'Família se mudou']);
                self::fail("Visitante {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_visitor']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Diarista', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_visitors WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_visitors SET deleted_at = '2026-01-01 00:00:00' WHERE id IN (1, 2)");
        $result = $this->service->block($this->user(), ['condominium_id' => '1', 'reason' => 'Família se mudou', 'dry_run' => true]);
        self::assertSame([1, 1, 1, 0, 0, 0], [$result['skipped_unlinked'], $result['skipped_inactive_unit'], $result['skipped_incomplete'], $result['count'],
            $result['permanent'], $result['changed']]);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/VisitorUnitBlock.php');
        foreach (["\$registry->save(\$user, 'visitors', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('block_unattached'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('visitors')", "(ends_on IS NULL OR ends_on >= ?)"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'name' =>", "['name']", "['document']", "'unit_id' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem dado do visitante na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VisitorUnitBlock.php');
        foreach (["Access::can('erp.visitors.view', \$user)", "Access::can('erp.visitors.manage', \$user)", "Access::can('erp.residents.view', \$user)",
            'catch (CondominiumAccessDenied)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/visitors/block-unattached", "VisitorUnitBlock:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
