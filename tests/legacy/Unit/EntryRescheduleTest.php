<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryReschedule;

/**
 * ADR 0197: prorrogação de vencimento em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra
 * pura, a validação, o escopo e o contrato; a regravação pelo formulário é conferida no MariaDB.
 */
final class EntryRescheduleTest extends TestCase
{
    private EntryReschedule $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)'] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new EntryReschedule($pdo, new CondominiumScope($pdo));
    }

    public function testPureRule(): void
    {
        self::assertSame('2026-12-15', EntryReschedule::newDueOn('2026-11-10', '2026-12-15', null));
        self::assertSame('2026-12-10', EntryReschedule::newDueOn('2026-11-10', null, 30));
        self::assertSame('2026-02-27', EntryReschedule::newDueOn('2026-03-06', null, -7), 'Volta pelo fim de fevereiro.');
        self::assertSame('2027-01-04', EntryReschedule::newDueOn('2026-12-28', null, 7), 'Virada do ano.');
    }

    public function testValidationScopeAndContract(): void
    {
        $base = ['condominium_id' => 1, 'entry_ids' => [1, 2], 'days' => 10, 'reason' => 'Fornecedor aceitou nova data'];
        foreach ([['condominium_id' => null], ['days' => null], ['days' => 0], ['days' => 366], ['days' => -366], ['days' => 'x'], ['days' => [5]],
            ['due_on' => '2026-12-01'], ['days' => null, 'due_on' => '2026-02-30'], ['days' => null, 'due_on' => ['2026-12-01']], ['reason' => 'abc'],
            ['reason' => null], ['entry_ids' => []], ['entry_ids' => ['x']]] as $change) {
            try {
                $this->service->reschedule($this->user(), array_merge($base, $change));
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->reschedule($this->user(), ['condominium_id' => 3] + $base);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/EntryReschedule.php');
        foreach (["\$registry->save(\$user, 'entries'", ' FOR UPDATE', "Audit::record('reschedule'", '$this->pdo->rollBack()', "status IN ('pending', 'registered', 'paid')"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/EntryReschedule.php');
        foreach (["Access::can('erp.entries.manage', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/entries/reschedule", "EntryReschedule:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
