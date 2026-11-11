<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BatchSettlement;
use Source\Services\Erp\EntryCancellation;

/**
 * ADR 0203: cancelamento de contas em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura,
 * a validação, o escopo e o contrato; a regravação pelo formulário é conferida no MariaDB (Integration\EntryCancellationTest).
 */
final class EntryCancellationTest extends TestCase
{
    private EntryCancellation $service;

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
        $this->service = new EntryCancellation($pdo, new CondominiumScope($pdo));
    }

    public function testPureRuleAndIds(): void
    {
        self::assertSame('Lançamento não encontrado neste condomínio.', EntryCancellation::problem(null, false));
        self::assertSame('Conta já cancelada.', EntryCancellation::problem(['status' => 'inactive', 'paid_on' => null], true));
        self::assertSame('Conta já paga: estorne a baixa antes de cancelar.', EntryCancellation::problem(['status' => 'active', 'paid_on' => '2026-11-09'], true));
        self::assertSame('Conta com boleto pendente ou registrado no banco: cancele pela cobrança.', EntryCancellation::problem(['status' => 'active', 'paid_on' => null], true));
        self::assertNull(EntryCancellation::problem(['status' => 'active', 'paid_on' => null], false));
        self::assertSame([3, 1], EntryCancellation::ids([3, '1', 3]), 'Repetido conta uma vez.');
    }

    public function testValidationScopeAndContract(): void
    {
        $base = ['condominium_id' => 1, 'entry_ids' => [1, 2], 'reason' => 'Importação em dobro'];
        foreach ([['condominium_id' => null], ['condominium_id' => 'x'], ['reason' => 'abc'], ['reason' => null], ['reason' => str_repeat('x', 256)],
            ['entry_ids' => []], ['entry_ids' => 'x'], ['entry_ids' => ['x']], ['entry_ids' => [0]], ['entry_ids' => [[1]]],
            ['entry_ids' => range(1, BatchSettlement::MAX_ENTRIES + 1)]] as $change) {
            try {
                $this->service->cancel($this->user(), array_merge($base, $change));
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->cancel($this->user(), ['condominium_id' => 3] + $base);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/EntryCancellation.php');
        foreach (["\$registry->save(\$user, 'entries', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('cancel'", '$this->pdo->rollBack()',
            "status IN ('pending', 'registered')"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/EntryCancellation.php');
        foreach (["Access::can('erp.entries.manage', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/entries/cancel", "EntryCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
