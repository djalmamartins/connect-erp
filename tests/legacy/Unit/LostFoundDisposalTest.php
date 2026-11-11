<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LostFoundDisposal;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0233: destino em lote dos achados e perdidos fora do prazo de guarda. Unit com SQLite (Pdo\Sqlite quando existe;
 * PDO no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do
 * formulário e o contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB
 * (Integration\LostFoundDisposalTest).
 */
final class LostFoundDisposalTest extends TestCase
{
    private PDO $pdo;
    private LostFoundDisposal $service;

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
            'CREATE TABLE erp_lost_found (id INTEGER PRIMARY KEY, condominium_id INTEGER, item TEXT, location TEXT, found_on TEXT, found_by TEXT, returned_on TEXT,
                returned_to TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $old = date('Y-m-d', strtotime('-200 days'));
        $recent = date('Y-m-d', strtotime('-10 days'));
        $this->pdo->exec("INSERT INTO erp_lost_found (id, condominium_id, item, found_on, status) VALUES
            (1, 1, 'Carteira marrom', '{$old}', 'active'), (2, 1, 'Relógio', '{$old}', 'returned'), (3, 1, 'Guarda-chuva', '{$recent}', 'active'),
            (4, 3, 'De outro', '{$old}', 'active'), (5, 1, 'Excluído', '{$old}', 'active')");
        $this->pdo->exec("UPDATE erp_lost_found SET deleted_at = '2026-01-01 00:00:00' WHERE id = 5");
        $this->service = new LostFoundDisposal($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'found_on' => '2026-05-01'];
        self::assertNull(LostFoundDisposal::problem($row, '2026-08-13'));
        self::assertNull(LostFoundDisposal::problem(['found_on' => '2026-08-13 00:00:00'] + $row, '2026-08-13'), 'O último dia do prazo entra.');
        self::assertStringContainsString('não encontrado', (string)LostFoundDisposal::problem(null, '2026-08-13'));
        self::assertStringContainsString('não está guardado', (string)LostFoundDisposal::problem(['status' => 'returned'] + $row, '2026-08-13'));
        self::assertStringContainsString('não está guardado', (string)LostFoundDisposal::problem(['status' => 'discarded'] + $row, '2026-08-13'));
        self::assertStringContainsString('dentro do prazo', (string)LostFoundDisposal::problem(['found_on' => '2026-08-14'] + $row, '2026-08-13'));
        self::assertSame(['doacao', 'descarte'], array_keys(LostFoundDisposal::DESTINATIONS));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'destination' => 'doacao', 'reason' => 'Prazo do regimento'];
        foreach ([['condominium_id' => 0] + $ok, ['destination' => 'venda'] + $ok, ['destination' => null] + $ok, ['reason' => 'abc'] + $ok,
            ['older_than_days' => 29] + $ok, ['older_than_days' => 'x'] + $ok, ['older_than_days' => 3651] + $ok, ['item_ids' => []] + $ok,
            ['item_ids' => ['x']] + $ok, ['item_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->dispose($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->dispose($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'não está guardado'], [3, 'dentro do prazo'], [4, 'não encontrado'], [5, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->dispose($this->user(), ['condominium_id' => 1, 'destination' => 'descarte', 'item_ids' => [1, $id], 'reason' => 'Prazo do regimento']);
                self::fail("Item {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_item']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Carteira', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_lost_found WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/LostFoundDisposal.php');
        foreach (["\$registry->save(\$user, 'lost_found', array_merge(\$data, ['status' => 'discarded'])", ' FOR UPDATE', "Audit::record('dispose'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "'destination' => \$destination"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'NotificationOutbox', 'mail_queue', "'item' =>", "['item']", "['location']", "['found_by']"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem dado do item: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/LostFoundDisposal.php');
        foreach (["Access::can('erp.lost_found.view', \$user)", "Access::can('erp.lost_found.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/lost-found/dispose", "LostFoundDisposal:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
