<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContractRenewal;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0217: renovação de contratos em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO de teste
 * tira o FOR UPDATE) para as regras puras, a validação, o escopo, a recusa antes do formulário e o contrato de reuso; a
 * regravação pelo formulário é conferida no MariaDB (Integration\ContractRenewalTest).
 */
final class ContractRenewalTest extends TestCase
{
    private PDO $pdo;
    private ContractRenewal $service;

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
            'CREATE TABLE erp_contracts (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, object TEXT, starts_on TEXT, ends_on TEXT,
                monthly_cents INTEGER, readjustment TEXT, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_contracts (id, condominium_id, supplier_id, object, starts_on, ends_on, monthly_cents, readjustment, status) VALUES
            (1, 1, 5, 'Portaria', '2025-12-01', '2026-11-30', 1000000, 'ipca', 'active'),
            (2, 1, 5, 'Limpeza', '2025-01-01', NULL, 500000, 'igpm', 'active'),
            (3, 1, 5, 'Jardim', '2025-01-01', '2026-12-31', 200000, 'nenhum', 'active'),
            (4, 1, 5, 'Antigo', '2024-01-01', '2025-01-01', 100000, 'ipca', 'ended'),
            (5, 3, 5, 'Outro', '2025-01-01', '2026-12-31', 100000, 'ipca', 'active')");
        $this->service = new ContractRenewal($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testPureRules(): void
    {
        self::assertSame('2027-11-30', ContractRenewal::renewedEnd('2026-11-30', 12));
        self::assertSame('2027-02-28', ContractRenewal::renewedEnd('2027-01-31', 1));
        self::assertSame('2028-02-29', ContractRenewal::renewedEnd('2027-08-31', 6));
        self::assertSame('2026-06-15', ContractRenewal::renewedEnd('2025-06-15 00:00:00', 12));
        $row = ['status' => 'active', 'ends_on' => '2026-11-30', 'readjustment' => 'ipca'];
        self::assertNull(ContractRenewal::problem($row, 462));
        self::assertNull(ContractRenewal::problem(['readjustment' => 'nenhum'] + $row, null));
        self::assertStringContainsString('não encontrado', (string)ContractRenewal::problem(null, null));
        self::assertStringContainsString('encerrado', (string)ContractRenewal::problem(['status' => 'ended'] + $row, null));
        self::assertStringContainsString('indeterminado', (string)ContractRenewal::problem(['ends_on' => null] + $row, null));
        self::assertStringContainsString('sem reajuste', (string)ContractRenewal::problem(['readjustment' => 'nenhum'] + $row, 462));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'contract_ids' => [1], 'months' => 12, 'reason' => 'Renovação anual'];
        foreach ([['condominium_id' => 0] + $ok, ['months' => 0] + $ok, ['months' => 61] + $ok, ['months' => 'x'] + $ok, ['percent' => '0'] + $ok,
            ['percent' => '150'] + $ok, ['percent' => 'abc'] + $ok, ['reason' => 'abc'] + $ok, ['contract_ids' => []] + $ok, ['contract_ids' => ['x']] + $ok,
            ['contract_ids' => range(1, 201)] + $ok] as $input) {
            try {
                $this->service->renew($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->renew($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testRefusedBeforeTheForm(): void
    {
        foreach ([[2, null, 'indeterminado'], [3, '4,62', 'sem reajuste'], [4, null, 'encerrado'], [5, null, 'não encontrado'], [99, null, 'não encontrado']]
            as [$id, $percent, $message]) {
            try {
                $this->service->renew($this->user(), ['condominium_id' => 1, 'contract_ids' => [$id], 'months' => 12, 'percent' => $percent, 'reason' => 'Renovação anual']);
                self::fail("Contrato {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_contract']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ContractRenewal.php');
        foreach (['RecurringReadjustment::basisPoints(', 'RecurringReadjustment::readjusted(', "\$registry->save(\$user, 'contracts'", ' FOR UPDATE',
            "Audit::record('renew'", '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        foreach (['UPDATE erp_', 'INSERT ', 'NotificationOutbox'] as $needle) {
            self::assertStringNotContainsString($needle, $service, 'Só pelo formulário: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ContractRenewal.php');
        foreach (["Access::can('erp.contracts.view', \$user)", "Access::can('erp.contracts.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/contracts/renew", "ContractRenewal:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
