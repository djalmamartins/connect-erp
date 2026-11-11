<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\RecurringReadjustment;

/**
 * ADR 0204: reajuste de lançamentos fixos. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para as regras
 * puras, a validação, o escopo e o contrato; a regravação pelo formulário é conferida no MariaDB
 * (Integration\RecurringReadjustmentTest).
 */
final class RecurringReadjustmentTest extends TestCase
{
    private RecurringReadjustment $service;

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
        $this->service = new RecurringReadjustment($pdo, new CondominiumScope($pdo));
    }

    public function testPureRules(): void
    {
        foreach (['4,62' => 462, '4.62' => 462, '4,5' => 450, '4,05' => 405, ' 10 ' => 1000, '-10' => -1000, '100' => 10000, '-50' => -5000, '0,01' => 1] as $in => $bp) {
            self::assertSame($bp, RecurringReadjustment::basisPoints((string)$in), (string)$in);
        }
        self::assertSame([500, 462], [RecurringReadjustment::basisPoints(5), RecurringReadjustment::basisPoints(4.62)]);
        foreach (['0', '0,00', '-0', '100,01', '-50,01', '1000', '4,625', 'abc', '', '4,', ',5', '1e2', null, true, [], 1e10] as $bad) {
            self::assertNull(RecurringReadjustment::basisPoints($bad), json_encode($bad));
        }
        self::assertSame(10462, RecurringReadjustment::readjusted(10000, 462));
        self::assertSame(12915,RecurringReadjustment::readjusted(12345, 462), '12345 × 1,0462 = 12915,39 → 12915.');
        self::assertSame(2, RecurringReadjustment::readjusted(1, 5000), '1,5 centavo sobe.');
        self::assertSame(1, RecurringReadjustment::readjusted(1, -5000), '0,5 centavo sobe.');
        self::assertSame(90000, RecurringReadjustment::readjusted(100000, -1000));
        self::assertSame(123456789012 * 2, RecurringReadjustment::readjusted(123456789012, 10000), 'Inteiro, sem perder centavo.');

        self::assertSame('Lançamento fixo não encontrado neste condomínio.', RecurringReadjustment::problem(null, '2026-11'));
        self::assertSame('Lançamento fixo inativo: reative antes de reajustar.', RecurringReadjustment::problem(['status' => 'inactive', 'ends_competence' => null], '2026-11'));
        self::assertSame('Lançamento fixo encerrado em 2026-10, antes da competência do reajuste.',
            RecurringReadjustment::problem(['status' => 'active', 'ends_competence' => '2026-10'], '2026-11'));
        self::assertNull(RecurringReadjustment::problem(['status' => 'active', 'ends_competence' => '2026-11'], '2026-11'));
        self::assertNull(RecurringReadjustment::problem(['status' => 'active', 'ends_competence' => null], '2026-11'));
        self::assertSame([3, 1], RecurringReadjustment::ids([3, '1', 3]));
    }

    public function testValidationScopeAndContract(): void
    {
        $base = ['condominium_id' => 1, 'recurring_ids' => [1, 2], 'percent' => '4,62', 'from_competence' => '2027-01', 'reason' => 'Reajuste anual pelo IPCA'];
        foreach ([['condominium_id' => null], ['percent' => null], ['percent' => '0'], ['percent' => '101'], ['percent' => 'x'], ['from_competence' => null],
            ['from_competence' => '2027-13'], ['from_competence' => '2027-1'], ['from_competence' => ['2027-01']], ['reason' => 'abc'], ['reason' => null],
            ['recurring_ids' => []], ['recurring_ids' => ['x']], ['recurring_ids' => [0]], ['recurring_ids' => range(1, RecurringReadjustment::MAX_TEMPLATES + 1)]] as $change) {
            try {
                $this->service->readjust($this->user(), array_merge($base, $change));
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->readjust($this->user(), ['condominium_id' => 3] + $base);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/RecurringReadjustment.php');
        foreach (["\$registry->save(\$user, 'recurring_entries'", ' FOR UPDATE', "Audit::record('readjust'", '$this->pdo->rollBack()', 'paid_on IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/RecurringReadjustment.php');
        foreach (["Access::can('erp.recurring_entries.manage', \$user)", "Access::can('erp.recurring_entries.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/recurring-entries/readjust", "RecurringReadjustment:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
