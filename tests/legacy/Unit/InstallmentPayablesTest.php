<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InstallmentPayables;

/**
 * ADR 0195: despesa parcelada. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para as regras puras, a
 * validação, o escopo e o dry_run; a gravação pelo formulário é conferida no MariaDB (Integration\InstallmentPayablesTest).
 */
final class InstallmentPayablesTest extends TestCase
{
    private InstallmentPayables $service;

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
        $this->service = new InstallmentPayables($pdo, new CondominiumScope($pdo));
    }

    public function testPureRules(): void
    {
        self::assertSame([33334, 33333, 33333], InstallmentPayables::split(100000, 3), 'A sobra dos centavos vai para a primeira.');
        self::assertSame([5, 5], InstallmentPayables::split(10, 2));
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], InstallmentPayables::schedule('2026-01-31', 4), 'Último dia quando o mês não tem o dia.');
        self::assertSame(['2027-11-10', '2027-12-10', '2028-01-10'], InstallmentPayables::schedule('2027-11-10', 3), 'Virada do ano.');
        self::assertSame(['2027-12-29', '2028-01-29', '2028-02-29', '2028-03-29'], InstallmentPayables::schedule('2027-12-29', 4), 'Ano bissexto.');
    }

    public function testDryRunPlan(): void
    {
        $base = ['condominium_id' => '1', 'chart_account_id' => 4, 'description' => '  Reforma do hall  ', 'total' => '1.000,00', 'installments' => '3',
            'first_due_on' => '2026-11-30', 'dry_run' => true];
        $plan = $this->service->create($this->user(), $base);
        self::assertSame([true, 3, 100000, []], [$plan['dry_run'], $plan['installments'], $plan['total_cents'], $plan['entries']]);
        self::assertSame([['Reforma do hall (1/3)', 33334, '2026-11-30', '2026-11'], ['Reforma do hall (2/3)', 33333, '2026-12-30', '2026-12'],
            ['Reforma do hall (3/3)', 33333, '2027-01-30', '2027-01']],
            array_map(static fn (array $p): array => [$p['description'], $p['amount_cents'], $p['due_on'], $p['competence']], $plan['plan']),
            'Sem competência: cada parcela no mês do próprio vencimento.');
        $same = $this->service->create($this->user(), ['competence' => '2026-11', 'total' => 100000] + $base);
        self::assertSame(['2026-11', '2026-11', '2026-11'], array_column($same['plan'], 'competence'), 'Com competência: a despesa é do mês da compra.');
    }

    public function testValidationAndScope(): void
    {
        $base = ['condominium_id' => 1, 'chart_account_id' => 4, 'description' => 'Obra', 'total' => '600,00', 'installments' => 6, 'first_due_on' => '2026-12-10', 'dry_run' => true];
        foreach ([['condominium_id' => null], ['installments' => 1], ['installments' => 61], ['installments' => 'x'], ['total' => '0,00'], ['total' => 'abc'],
            ['total' => 5], ['total' => [600]], ['first_due_on' => '2026-02-30'], ['first_due_on' => null], ['competence' => '2026-13'], ['competence' => ['2026-11']],
            ['description' => '   '], ['description' => str_repeat('x', 151)], ['description' => null]] as $change) {
            try {
                $this->service->create($this->user(), array_merge($base, $change));
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->create($this->user(), ['condominium_id' => 3] + $base);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/InstallmentPayables.php');
        foreach (["Access::can('erp.entries.manage', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString("\$registry->save(\$user, 'payables'", (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/InstallmentPayables.php'),
            'Cada parcela passa pelas regras do formulário de contas a pagar.');
        self::assertStringContainsString('$route->post("/entries/installments", "InstallmentPayables:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents(dirname(__DIR__, 3) . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
