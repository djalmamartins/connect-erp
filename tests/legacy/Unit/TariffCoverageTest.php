<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\TariffCoverage;

/**
 * ADR 0258: cobertura das tarifas de consumo. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura
 * (a tarifa do condomínio substitui a padrão, a vigência mais recente até a data, faixas, início em zero, sem limite,
 * buracos, próxima vigência e tipos em falta), a consulta sem dado do relógio, a validação, o escopo e o contrato; as
 * tarifas e os relógios gravados pelo cadastro são conferidos no MariaDB (Integration\TariffCoverageTest).
 */
final class TariffCoverageTest extends TestCase
{
    private PDO $pdo;
    private TariffCoverage $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_meters (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, kind TEXT, number TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_tariffs (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, name TEXT, from_amount REAL, to_amount REAL, price_cents INTEGER, valid_from TEXT,
                notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new TariffCoverage($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $t = static fn (string $owner, string $kind, string $from, float $start, ?float $end): array => ['owner' => $owner, 'kind' => $kind, 'valid_from' => $from,
            'from_amount' => $start, 'to_amount' => $end];
        $summary = TariffCoverage::summarize(['agua' => 3, 'gas' => 1, 'energia' => 2], [
            $t('condominium', 'agua', '2026-01-01', 10, 20), $t('condominium', 'agua', '2026-01-01', 0, 10), $t('condominium', 'agua', '2026-01-01', 25, null),
            $t('condominium', 'agua', '2025-01-01', 0, null), $t('default', 'agua', '2026-06-01', 0, null), $t('condominium', 'agua', '2027-01-01', 0, null),
            $t('default', 'gas', '2026-03-01', 5, 50), $t('default', 'gas', '2026-12-01', 0, null), $t('default', 'energia', '2026-12-15', 0, null),
        ], '2026-11-12');
        self::assertSame(['agua', 'gas', 'energia'], array_column($summary['rows'], 'kind'), 'Os tipos vêm do cadastro.');
        self::assertSame(['condominium', 'default', null], array_column($summary['rows'], 'source'), 'A tarifa do condomínio substitui a padrão.');
        self::assertSame(['2026-01-01', '2026-03-01', null], array_column($summary['rows'], 'valid_from'), 'A vigência mais recente até a data.');
        self::assertSame([3, 1, 0], array_column($summary['rows'], 'bands'));
        self::assertSame([true, false, false], array_column($summary['rows'], 'starts_at_zero'));
        self::assertSame([true, false, false], array_column($summary['rows'], 'open_ended'));
        self::assertSame([1, 0, 0], array_column($summary['rows'], 'gaps'), 'De 20 a 25 fica sem faixa.');
        self::assertSame(['2027-01-01', '2026-12-01', '2026-12-15'], array_column($summary['rows'], 'next_valid_from'));
        self::assertSame([false, false, true], array_column($summary['rows'], 'missing'));
        self::assertSame([['meters' => 6, 'bands' => 4], ['energia']], [$summary['totals'], $summary['missing_kinds']]);
        foreach (['meters', 'bands'] as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame([], TariffCoverage::summarize([], [], '2026-11-12')['missing_kinds'], 'Sem relógio não falta tarifa.');
    }

    public function testBuildWithoutMeterData(): void
    {
        $this->pdo->exec("INSERT INTO erp_meters (condominium_id, unit_id, kind, number, status, deleted_at) VALUES (1, 40, 'agua', 'H-101', 'active', NULL),
            (1, 41, 'agua', 'H-102', 'inactive', NULL), (1, 42, 'gas', 'G-1', 'active', '2026-01-01 00:00:00'), (3, 50, 'gas', 'G-9', 'active', NULL),
            (1, 43, 'energia', 'E-1', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_tariffs (condominium_id, kind, name, from_amount, to_amount, price_cents, valid_from, notes, status, deleted_at) VALUES
            (NULL, 'agua', 'Faixa padrão', 0, NULL, 500, '2026-01-01', 'Nota interna', 'active', NULL),
            (3, 'energia', 'De outro', 0, NULL, 100, '2026-01-01', NULL, 'active', NULL),
            (1, 'energia', 'Inativa', 0, NULL, 100, '2026-01-01', NULL, 'inactive', NULL)");
        $report = $this->service->build($this->user(), '1', '2026-11-12');
        self::assertSame([1, '2026-11-12', ['meters' => 2, 'bands' => 1], ['energia'], 'default'], [$report['condominium_id'], $report['on'], $report['totals'],
            $report['missing_kinds'], $report['rows'][0]['source']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['H-101', 'Faixa padrão', 'Nota interna', 'unit', 'number', 'price'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do relógio nem da faixa: ' . $needle);
        }
        self::assertSame(date('Y-m-d'), $this->service->build($this->user(), 1)['on'], 'Data padrão: hoje.');
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null], ['x', null], [0, null], ['1.5', null], [1, '2026-02-30'], [1, '12/11/2026'], [1, ['x']]] as [$c, $on]) {
            try {
                $this->service->build($this->user(), $c, $on);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $on]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/TariffCoverage.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('tariffs')", "(condominium_id = ? OR condominium_id IS NULL) AND status = 'active' AND deleted_at IS NULL"]
            as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'number', 'unit_id', 'notes', 'price_cents', 'SELECT name', ', name'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado do relógio nem da faixa: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/TariffCoverage.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.tariffs.view', \$user)", "Access::can('erp.meters.view', \$user)", 'catch (CondominiumAccessDenied)']
            as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/tariff-coverage", "TariffCoverage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
