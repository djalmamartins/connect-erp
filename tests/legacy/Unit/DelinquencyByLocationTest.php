<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DelinquencyByLocation;

/**
 * ADR 0222: inadimplência por bloco ou andar, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2)
 * para a regra pura, a validação, o escopo e o contrato de reuso; o cálculo pelo Agreements::delinquency (encargos do
 * MariaDB) é conferido em Integration\DelinquencyByLocationTest.
 */
final class DelinquencyByLocationTest extends TestCase
{
    private DelinquencyByLocation $service;

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
        $this->service = new DelinquencyByLocation($pdo, new CondominiumScope($pdo));
    }

    public function testLabelAndGroupPureRules(): void
    {
        self::assertSame(['A', 'Sem bloco', 'Sem andar', '3'], [DelinquencyByLocation::label(' A ', 'block'), DelinquencyByLocation::label(null, 'block'),
            DelinquencyByLocation::label('', 'floor'), DelinquencyByLocation::label('3', 'floor')]);
        $unit = static fn (?int $id, int $amount, int $total, int $days, int $entries = 1): array => ['unit_id' => $id, 'entries' => array_fill(0, $entries, []),
            'amount_cents' => $amount, 'charges_cents' => $total - $amount, 'total_cents' => $total, 'max_days_late' => $days];
        $rows = DelinquencyByLocation::group([$unit(11, 10000, 10500, 61, 2), $unit(12, 5000, 5100, 20), $unit(21, 30000, 30900, 40), $unit(null, 1000, 1000, 5)],
            [11 => 'A', 12 => 'A', 13 => 'A', 21 => 'B'], ['A' => 3, 'B' => 2]);
        self::assertSame(['B', 'A', 'Sem unidade'], array_column($rows, 'group'), 'Maior total primeiro.');
        self::assertSame(['group' => 'A', 'units_total' => 3, 'units_late' => 2, 'rate_pct' => 66.7, 'entries' => 3, 'amount_cents' => 15000, 'charges_cents' => 600,
            'total_cents' => 15600, 'max_days_late' => 61, 'units_by_bucket' => ['1-30' => 1, '31-60' => 0, '61-90' => 1, '90+' => 0]], $rows[1]);
        self::assertSame([50.0, null], [$rows[0]['rate_pct'], $rows[2]['rate_pct']], 'Conta sem unidade não tem taxa.');
        self::assertSame([], DelinquencyByLocation::group([], [], ['A' => 3]));
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [1, 'tower', null], [1, 'block', '10/11/2026'], [1, 'floor', '2026-02-30']] as [$c, $by, $asOf]) {
            try {
                $this->service->build($this->user(), $c, $by, $asOf);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $by, $asOf]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3, 'block');
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/DelinquencyByLocation.php');
        foreach (['(new Agreements($this->pdo, $this->scope))->delinquency($user, (int)$id, $asOf)', 'Agreements::bucket(', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'erp_residents', 'unit_label', "'description'", "'entries' =>  \$unit"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado da unidade: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/DelinquencyByLocation.php');
        foreach (["Access::can('erp.agreements.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/delinquency-by-location", "DelinquencyByLocation:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
