<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\UnitMoveOut;

/**
 * ADR 0206: saída de moradores da unidade. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para as regras
 * puras, a validação, o escopo e o contrato; a regravação pelo formulário é conferida no MariaDB (Integration\UnitMoveOutTest).
 */
final class UnitMoveOutTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private UnitMoveOut $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, deleted_at TEXT)'] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $pdo->exec("INSERT INTO erp_units (id, condominium_id, deleted_at) VALUES (10, 1, NULL), (20, 2, NULL), (11, 1, '2026-01-01')");
        $this->service = new UnitMoveOut($pdo, new CondominiumScope($pdo));
    }

    public function testPureRules(): void
    {
        self::assertSame(['tenant', 'resident'], UnitMoveOut::relationships(null));
        self::assertSame(['tenant', 'resident'], UnitMoveOut::relationships([]));
        self::assertSame(['owner', 'tenant'], UnitMoveOut::relationships(['tenant', 'owner', 'tenant']), 'Ordem fixa, sem repetir.');
        self::assertSame(['owner', 'tenant', 'resident', 'financial'], UnitMoveOut::relationships(['financial', 'resident', 'tenant', 'owner']));
        foreach (['tenant', ['tenant', 'visitor'], [1], [['tenant']], ['Tenant']] as $bad) {
            self::assertNull(UnitMoveOut::relationships($bad), json_encode($bad));
        }
        self::assertTrue(UnitMoveOut::validEndsOn('2026-11-11', self::TODAY));
        self::assertTrue(UnitMoveOut::validEndsOn('2026-02-28', self::TODAY));
        foreach (['2026-11-12', '2026-02-30', '11/11/2026', '2026-1-01', '', null, ['2026-11-01'], 20261101] as $bad) {
            self::assertFalse(UnitMoveOut::validEndsOn($bad, self::TODAY), json_encode($bad));
        }
    }

    public function testValidationScopeAndContract(): void
    {
        $base = ['condominium_id' => 1, 'unit_id' => 10, 'ends_on' => '2026-11-10', 'reason' => 'Inquilino entregou as chaves'];
        foreach ([['condominium_id' => null], ['unit_id' => null], ['unit_id' => 'x'], ['relationships' => ['visitor']], ['relationships' => 'tenant'],
            ['ends_on' => null], ['ends_on' => '2026-11-12'], ['ends_on' => '2026-13-01'], ['reason' => 'abc'], ['reason' => null]] as $change) {
            try {
                $this->service->moveOut($this->user(), array_merge($base, $change), self::TODAY);
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->moveOut($this->user(), ['condominium_id' => 3] + $base, self::TODAY);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([20, 11, 999] as $unit) {
            try {
                $this->service->moveOut($this->user(), ['unit_id' => $unit] + $base, self::TODAY);
                self::fail("Unidade {$unit} de outro condomínio, excluída ou inexistente passou.");
            } catch (RegistryNotFound) {
                self::assertTrue(true);
            }
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/UnitMoveOut.php');
        foreach (['$registry->save($user, $slug', ' FOR UPDATE', "Audit::record('move_out'", '$this->pdo->rollBack()', "SET status = 'revoked'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        self::assertStringNotContainsString('NotificationOutbox', $service, 'Saída não manda aviso nem e-mail.');
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/UnitMoveOut.php');
        foreach (["'erp.residents.manage'", "'erp.residents.view'", "'erp.vehicles.manage'", "'erp.pets.manage'", 'catch (CondominiumAccessDenied | RegistryNotFound)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/units/move-out", "UnitMoveOut:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
