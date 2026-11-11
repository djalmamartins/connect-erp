<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\SupplierDeactivation;

/**
 * ADR 0202: inativação de fornecedores em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2); o PDO de teste
 * tira o FOR UPDATE. O usuário 8 acessa todos os condomínios (developer); o 7 só o condomínio 1.
 */
final class SupplierDeactivationTest extends TestCase
{
    private PDO $pdo;
    private SupplierDeactivation $service;

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
        $pdo = $this->pdo;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, legal_name TEXT, trade_name TEXT, document TEXT, email TEXT, pix_key TEXT, status TEXT DEFAULT 'active',
                updated_by INTEGER, updated_at TEXT, deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, nature TEXT, amount_cents INTEGER, due_on TEXT, paid_on TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_recurring_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_commitments (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)"] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $pdo->exec("INSERT INTO access_roles (id, slug) VALUES (1, 'developer')");
        $pdo->exec('INSERT INTO access_user_roles (user_id, role_id) VALUES (8, 1)');
        $pdo->exec("INSERT INTO erp_suppliers (id, legal_name, trade_name, document, email, pix_key, status, deleted_at) VALUES
            (1, 'Alfa Limpeza', 'Alfa', '11222333000181', 'alfa@example.test', 'chave-alfa', 'active', NULL),
            (2, 'Beta Elevadores', NULL, '11444777000161', NULL, NULL, 'active', NULL),
            (3, 'Gama Portaria', NULL, '22333444000155', NULL, NULL, 'active', NULL),
            (4, 'Delta Jardins', NULL, '33444555000166', NULL, NULL, 'inactive', NULL),
            (5, 'Epsilon Gás', NULL, '44555666000177', NULL, NULL, 'active', NULL),
            (6, 'Zeta Obras', NULL, '55666777000188', NULL, NULL, 'active', NULL),
            (7, 'Eta Excluída', NULL, '66777888000199', NULL, NULL, 'active', '2026-01-01'),
            (8, 'Theta Serviços', NULL, '52998224725', NULL, NULL, 'active', NULL)");
        $pdo->exec("INSERT INTO erp_entries (condominium_id, supplier_id, nature, amount_cents, due_on, paid_on, status, deleted_at) VALUES
            (1, 2, 'despesa', 30000, '2024-01-10', '2024-01-10', 'active', NULL),
            (2, 3, 'despesa', 10000, '2026-12-10', NULL, 'active', NULL), (1, 3, 'despesa', 5000, '2026-11-10', NULL, 'active', NULL),
            (1, 8, 'despesa', 9000, '2025-03-10', NULL, 'inactive', NULL), (1, 8, 'despesa', 9000, '2025-04-10', NULL, 'active', '2025-05-01')");
        $pdo->exec("INSERT INTO erp_recurring_entries (condominium_id, supplier_id, status, deleted_at) VALUES (2, 5, 'active', NULL), (1, 8, 'inactive', NULL), (1, 8, 'active', '2025-01-01')");
        $pdo->exec("INSERT INTO erp_commitments (condominium_id, supplier_id, status, deleted_at) VALUES (2, 6, 'active', NULL), (1, 8, 'settled', NULL), (1, 8, 'inactive', NULL)");
        $this->service = new SupplierDeactivation($pdo, new CondominiumScope($pdo));
    }

    public function testDryRunThenDeactivateAllOrNothing(): void
    {
        $input = ['supplier_ids' => [1, '2', 8, 1], 'reason' => 'Sem uso há mais de 24 meses'];
        $preview = $this->service->deactivate($this->user(8), $input + ['dry_run' => true]);
        self::assertSame([true, 3, 0], [$preview['dry_run'], $preview['count'], $preview['changed']]);
        self::assertSame([[1, 'Alfa Limpeza', null], [2, 'Beta Elevadores', '2024-01-10'], [8, 'Theta Serviços', '2025-03-10']],
            array_map(static fn (array $s): array => [$s['supplier_id'], $s['legal_name'], $s['last_used_on']], $preview['suppliers']),
            'Cancelada, recorrência inativa ou excluída e empenho liquidado ou cancelado não impedem.');
        self::assertSame(['active', 'active', 'active'], $this->statuses([1, 2, 8]), 'dry_run desfaz.');
        $json = (string)json_encode($preview);
        foreach (['alfa@example.test', 'chave-alfa', '11222333000181'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }

        $done = $this->service->deactivate($this->user(8), $input);
        self::assertSame([false, 3], [$done['dry_run'], $done['changed']]);
        self::assertSame(['inactive', 'inactive', 'inactive', 'active'], $this->statuses([1, 2, 8, 3]));
        self::assertSame('8', (string)$this->pdo->query('SELECT updated_by FROM erp_suppliers WHERE id = 1')->fetchColumn());

        $this->pdo->exec("UPDATE erp_suppliers SET status = 'active' WHERE id = 1");
        $refused = $this->refusal(['supplier_ids' => [1, 3], 'reason' => 'Limpeza do cadastro']);
        self::assertSame('3', $refused['_supplier']);
        self::assertStringContainsString('2 conta(s) a pagar em aberto (R$ 150,00)', $refused['_form'], 'Contas de todos os condomínios.');
        self::assertSame(['active'], $this->statuses([1]), 'Tudo ou nada: o 1 volta.');
        foreach ([2 => 'já está inativo', 4 => 'já está inativo', 5 => 'recorrente ativo', 6 => 'empenho ativo', 7 => 'não encontrado', 999 => 'não encontrado'] as $id => $message) {
            $refused = $this->refusal(['supplier_ids' => [$id], 'reason' => 'Limpeza do cadastro']);
            self::assertSame([(string)$id, true], [$refused['_supplier'], str_contains($refused['_form'], $message)], (string)$id);
        }
    }

    public function testScopeValidationPureRuleAndWiring(): void
    {
        try {
            $this->service->deactivate($this->user(7), ['supplier_ids' => [1], 'reason' => 'Sem uso há anos']);
            self::fail('Usuário sem acesso a todos os condomínios inativou.');
        } catch (RegistryForbidden) {
            self::assertSame(['active'], $this->statuses([1]));
        }
        foreach ([[], ['supplier_ids' => []], ['supplier_ids' => 'x'], ['supplier_ids' => [0]], ['supplier_ids' => ['a']], ['supplier_ids' => [[1]]],
            ['supplier_ids' => range(1, 101)], ['supplier_ids' => [1]], ['supplier_ids' => [1], 'reason' => 'abc'], ['supplier_ids' => [1], 'reason' => str_repeat('x', 256)],
            ['supplier_ids' => [1], 'reason' => ['motivo longo']]] as $input) {
            try {
                $this->service->deactivate($this->user(8), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertNull(SupplierDeactivation::problem(['status' => 'active'], ['count' => 0, 'cents' => 0], false, false));
        self::assertSame('Fornecedor com 1 conta(s) a pagar em aberto (R$ 1.234,56): pague ou cancele antes.',
            SupplierDeactivation::problem(['status' => 'active'], ['count' => 1, 'cents' => 123456], true, true), 'Conta em aberto vem antes.');
        $root = dirname(__DIR__, 3);
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/SupplierDeactivation.php');
        foreach (["Access::can('erp.suppliers.manage', \$user)", "Access::can('erp.suppliers.view', \$user)", 'catch (RegistryForbidden', 'catch (RegistryValidationException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $service = (string)file_get_contents($root . '/source/Services/Erp/SupplierDeactivation.php');
        foreach ([' FOR UPDATE', '$this->pdo->rollBack()', "Audit::record('update', 'erp_suppliers'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        self::assertStringContainsString('$route->post("/suppliers/deactivate", "SupplierDeactivation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    /** @return array<string, string> */
    private function refusal(array $input): array
    {
        try {
            $this->service->deactivate($this->user(8), $input);
        } catch (RegistryValidationException $error) {
            return $error->errors;
        }
        self::fail('Recusa esperada: ' . json_encode($input));
    }

    /** @return list<string> */
    private function statuses(array $ids): array
    {
        return array_map(fn (int $id): string => (string)$this->pdo->query("SELECT status FROM erp_suppliers WHERE id = {$id}")->fetchColumn(), $ids);
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;
        return $user;
    }
}
