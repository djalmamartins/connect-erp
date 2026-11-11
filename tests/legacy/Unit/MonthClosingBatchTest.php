<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MonthClosingBatch;

/**
 * ADR 0205: fechamento do mês em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura, a
 * validação, o escopo e o contrato; a conferência e o fechamento são conferidos no MariaDB (Integration\MonthClosingBatchTest).
 */
final class MonthClosingBatchTest extends TestCase
{
    private MonthClosingBatch $service;

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
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $this->service = new MonthClosingBatch($pdo, new CondominiumScope($pdo));
    }

    public function testReasonsPureRule(): void
    {
        $check = static fn (string $code, string $level): array => ['code' => $code, 'level' => $level, 'message' => $code, 'count' => 0, 'amount_cents' => 0];
        $readiness = ['checks' => [$check('open_entries', 'warning'), $check('negative_bank', 'warning')]];
        self::assertSame(['open_entries', 'negative_bank'], array_column(MonthClosingBatch::reasons($readiness, false), 'code'), 'Aviso bloqueia sem allow_warnings.');
        self::assertSame([], MonthClosingBatch::reasons($readiness, true), 'Com allow_warnings, aviso passa.');
        self::assertSame(['paid_without_bank'], array_column(MonthClosingBatch::reasons(['checks' => [$check('paid_without_bank', 'error'), $check('open_entries', 'warning')]], true), 'code'),
            'Erro nunca passa.');
        self::assertSame(['already_closed'], array_column(MonthClosingBatch::reasons(['checks' => [$check('already_closed', 'info')]], true), 'code'), 'Já fechada não fecha de novo.');
        self::assertSame([], MonthClosingBatch::reasons(['checks' => []], false));
        self::assertSame(['code' => 'x', 'level' => 'error', 'message' => 'x'], MonthClosingBatch::reasons(['checks' => [$check('x', 'error')]], false)[0], 'Sem contagem nem valor na recusa.');
        self::assertSame([2, 1], MonthClosingBatch::ids([2, '1', 2]));
    }

    public function testValidationScopeAndContract(): void
    {
        $base = ['competence' => '2026-11', 'condominium_ids' => [1, 2]];
        foreach ([['competence' => null], ['competence' => '2026-13'], ['competence' => ['2026-11']], ['condominium_ids' => []], ['condominium_ids' => 'x'],
            ['condominium_ids' => ['x']], ['condominium_ids' => [0]], ['condominium_ids' => range(1, MonthClosingBatch::MAX_CONDOMINIUMS + 1)]] as $change) {
            try {
                $this->service->close($this->user(), array_merge($base, $change));
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->close($this->user(), ['condominium_ids' => [1, 3]] + $base);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/MonthClosingBatch.php');
        foreach (['$readiness->check($user, $id, $competence)', '$closing->close($user, $id, $competence)', "Audit::record('close_batch'", '$this->pdo->rollBack()',
            'throw new MonthClosingBatchRefused($refused)'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $closing = (string)file_get_contents($root . '/source/Services/Erp/MonthClosing.php');
        self::assertStringContainsString('$own = !$this->pdo->inTransaction();', $closing, 'O fechamento de um grava na transação do lote.');
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/MonthClosingBatch.php');
        foreach (["Access::can('erp.closing.manage', \$user)", "Access::can('erp.closing.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'refused' => \$exception->refused"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/closings/batch", "MonthClosingBatch:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
