<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MaintenanceCompletion;

/**
 * ADR 0208: conclusão de manutenções com a próxima agendada. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2)
 * para as regras puras, a validação, o escopo e o contrato; a gravação pelo formulário é conferida no MariaDB
 * (Integration\MaintenanceCompletionTest).
 */
final class MaintenanceCompletionTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private MaintenanceCompletion $service;

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
        $this->service = new MaintenanceCompletion($pdo, new CondominiumScope($pdo));
    }

    public function testPureRules(): void
    {
        self::assertSame(['2026-02-28', '2026-03-31', '2027-01-31', '2026-12-15'], [MaintenanceCompletion::addMonths('2026-01-31', 1),
            MaintenanceCompletion::addMonths('2026-01-31', 2), MaintenanceCompletion::addMonths('2026-01-31', 12), MaintenanceCompletion::addMonths('2026-11-15', 1)],
            'Fim de mês ajustado.');
        self::assertSame('2024-02-28', MaintenanceCompletion::addMonths('2023-02-28', 12), 'Dia 28 continua 28 no bissexto.');
        self::assertSame('2025-02-28', MaintenanceCompletion::addMonths('2024-02-29', 12));
        self::assertSame('2026-12-10', MaintenanceCompletion::nextPlannedOn('2026-11-10', '2026-11-08', 'mensal'), 'Feita antes: a próxima segue o calendário.');
        self::assertSame('2026-12-10', MaintenanceCompletion::nextPlannedOn('2026-11-10', '2026-11-10', 'mensal'));
        self::assertSame('2027-01-10', MaintenanceCompletion::nextPlannedOn('2026-11-10', '2026-12-20', 'mensal'), 'Atrasada: pula o mês perdido.');
        self::assertSame('2027-02-28', MaintenanceCompletion::nextPlannedOn('2026-11-30', '2026-11-30', 'trimestral'));
        self::assertSame('2027-05-30', MaintenanceCompletion::nextPlannedOn('2026-11-30', '2026-11-30', 'semestral'));
        self::assertSame('2027-11-10', MaintenanceCompletion::nextPlannedOn('2026-11-10', '2026-11-11', 'anual'));
        self::assertNull(MaintenanceCompletion::nextPlannedOn('2026-11-10', '2026-11-11', 'unica'));
        self::assertNull(MaintenanceCompletion::nextPlannedOn('2026-11-10', '2026-11-11', 'quinzenal'));

        self::assertSame('Manutenção não encontrada neste condomínio.', MaintenanceCompletion::problem(null));
        self::assertSame('Manutenção já concluída.', MaintenanceCompletion::problem(['status' => 'done']));
        self::assertSame('Manutenção cancelada.', MaintenanceCompletion::problem(['status' => 'inactive']));
        self::assertNull(MaintenanceCompletion::problem(['status' => 'active']));
        self::assertNull(MaintenanceCompletion::problem(['status' => 'running']));
        self::assertSame([5 => '2026-11-10', 2 => '2026-11-11'], MaintenanceCompletion::items([['id' => 5, 'done_on' => '2026-11-10'], ['id' => '2', 'done_on' => '2026-11-11']], self::TODAY));
    }

    public function testValidationScopeAndContract(): void
    {
        $base = ['condominium_id' => 1, 'items' => [['id' => 1, 'done_on' => '2026-11-10']]];
        foreach ([['condominium_id' => null], ['items' => []], ['items' => 'x'], ['items' => [['id' => 1]]], ['items' => [['done_on' => '2026-11-10']]],
            ['items' => [['id' => 1, 'done_on' => '2026-11-12']]], ['items' => [['id' => 1, 'done_on' => '2026-02-30']]], ['items' => [['id' => 0, 'done_on' => '2026-11-10']]],
            ['items' => [['id' => 1, 'done_on' => '2026-11-10'], ['id' => 1, 'done_on' => '2026-11-09']]], ['items' => [5]],
            ['items' => array_fill(0, MaintenanceCompletion::MAX_ITEMS + 1, ['id' => 1, 'done_on' => '2026-11-10'])]] as $change) {
            try {
                $this->service->complete($this->user(), array_merge($base, $change), self::TODAY);
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->complete($this->user(), ['condominium_id' => 3] + $base, self::TODAY);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/MaintenanceCompletion.php');
        foreach (["\$registry->save(\$user, 'maintenances'", ' FOR UPDATE', "Audit::record('complete'", '$this->pdo->rollBack()',
            'SET previous_id = ? WHERE id = ? AND previous_id IS NULL', 'WHERE previous_id = ? AND deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        self::assertStringNotContainsString('NotificationOutbox', $service);
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/MaintenanceCompletion.php');
        foreach (["Access::can('erp.maintenances.manage', \$user)", "Access::can('erp.maintenances.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/maintenances/complete", "MaintenanceCompletion:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
