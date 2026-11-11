<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryReclassification;

/**
 * ADR 0196: reclassificação em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a validação, o
 * escopo e o contrato; a regravação pelo formulário é conferida no MariaDB (Integration\EntryReclassificationTest).
 */
final class EntryReclassificationTest extends TestCase
{
    private EntryReclassification $service;

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
        $this->service = new EntryReclassification($pdo, new CondominiumScope($pdo));
    }

    public function testValidationAndScope(): void
    {
        $base = ['condominium_id' => 1, 'entry_ids' => [1, 2], 'chart_account_id' => 4, 'reason' => 'Revisão do mês'];
        foreach ([['condominium_id' => null], ['chart_account_id' => null], ['chart_account_id' => 'x'], ['chart_account_id' => 0], ['cost_center_id' => -1],
            ['cost_center_id' => 'abc'], ['reason' => null], ['reason' => ' abc '], ['reason' => str_repeat('x', 256)], ['reason' => ['Revisão']],
            ['entry_ids' => []], ['entry_ids' => ['x']], ['entry_ids' => range(1, 201)]] as $change) {
            try {
                $this->service->reclassify($this->user(), array_merge($base, $change));
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->reclassify($this->user(), ['condominium_id' => 3] + $base);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/EntryReclassification.php');
        foreach (["\$registry->save(\$user, 'entries'", ' FOR UPDATE', "Audit::record('reclassify'", '$this->pdo->rollBack()', "'_entry' => (string)\$id"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/EntryReclassification.php');
        foreach (["Access::can('erp.entries.manage', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/entries/reclassify", "EntryReclassification:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
