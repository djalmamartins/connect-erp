<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\UnitOccupancy;

/**
 * ADR 0240: perfil de ocupação das unidades numa data, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2) para a regra pura (as cinco situações, vínculo valendo na data, vínculos sem unidade ativa, totais iguais à
 * soma), a montagem sem dado pessoal, a validação e o escopo; os cadastros são conferidos no MariaDB
 * (Integration\UnitOccupancyTest).
 */
final class UnitOccupancyTest extends TestCase
{
    private PDO $pdo;
    private UnitOccupancy $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, type TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, relationship TEXT, name TEXT, document TEXT,
                email TEXT, phone TEXT, starts_on TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new UnitOccupancy($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{id:int, type:string}> */
    private static function units(): array
    {
        return array_map(static fn (array $u): array => ['id' => $u[0], 'type' => $u[1]],
            [[1, 'apartamento'], [2, 'apartamento'], [3, 'apartamento'], [4, 'casa'], [5, 'loja'], [6, 'garagem'], [7, 'apartamento']]);
    }

    /** @return list<array{unit_id:?int, relationship:string, status:string, starts_on:?string, ends_on:?string}> */
    private static function links(): array
    {
        $l = static fn (?int $unit, string $relationship, string $status = 'active', ?string $starts = null, ?string $ends = null): array => ['unit_id' => $unit,
            'relationship' => $relationship, 'status' => $status, 'starts_on' => $starts, 'ends_on' => $ends];
        return [
            $l(1, 'owner'), $l(1, 'tenant', 'active', '2026-01-01'),             // alugada
            $l(2, 'owner'), $l(2, 'resident'),                                    // com morador
            $l(3, 'owner'), $l(3, 'tenant', 'active', null, '2026-10-31'),       // inquilino saiu: só proprietário
            $l(4, 'financial'),                                                   // só responsável financeiro
            $l(5, 'tenant', 'inactive'), $l(5, 'owner', 'active', '2026-12-01'),  // inativo e futuro: sem vínculo
            $l(7, 'resident'), $l(7, 'tenant', 'active', null, '2026-11-11'),    // fim hoje ainda vale: alugada
            $l(null, 'owner'), $l(99, 'owner'),                                   // sem unidade ativa
        ];
    }

    public function testSummarizePureRule(): void
    {
        $summary = UnitOccupancy::summarize(self::units(), self::links(), '2026-11-11');
        self::assertSame([['rented', 2], ['occupied', 1], ['owner_only', 1], ['other_links', 1], ['no_links', 2]],
            array_map(static fn (array $r): array => [$r['situation'], $r['units']], $summary['rows']));
        self::assertSame(['apartamento' => 2, 'casa' => 0, 'sala' => 0, 'loja' => 0, 'garagem' => 0, 'outro' => 0], $summary['rows'][0]['by_type']);
        self::assertSame(['units' => 7, 'by_type' => ['apartamento' => 4, 'casa' => 1, 'sala' => 0, 'loja' => 1, 'garagem' => 1, 'outro' => 0]], $summary['totals']);
        self::assertSame($summary['totals']['units'], array_sum(array_column($summary['rows'], 'units')), 'Total = soma das linhas.');
        foreach ($summary['rows'] as $row) {
            self::assertSame($row['units'], array_sum($row['by_type']));
        }
        self::assertSame([['owner', 'Proprietário', 3], ['tenant', 'Inquilino', 2], ['resident', 'Morador', 2], ['financial', 'Responsável financeiro', 1]],
            array_map(static fn (array $r): array => [$r['relationship'], $r['relationship_label'], $r['links']], $summary['links']['rows']));
        self::assertSame([8, 2], [$summary['links']['linked'], $summary['links']['unlinked']]);
        self::assertSame(['rented', 'occupied', 'owner_only', 'other_links', 'no_links'], [UnitOccupancy::classify(['owner', 'tenant']),
            UnitOccupancy::classify(['resident', 'owner']), UnitOccupancy::classify(['owner']), UnitOccupancy::classify(['financial']), UnitOccupancy::classify([])]);
        self::assertFalse(UnitOccupancy::valid(['status' => 'active', 'starts_on' => '2026-11-12'], '2026-11-11'));
        self::assertTrue(UnitOccupancy::valid(['status' => 'active', 'starts_on' => '2026-11-11', 'ends_on' => '2026-11-11'], '2026-11-11'));
        $empty = UnitOccupancy::summarize([], [], '2026-11-11');
        self::assertSame([5, 0, 0], [count($empty['rows']), $empty['totals']['units'], $empty['links']['linked']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $unit = $this->pdo->prepare('INSERT INTO erp_units (id, condominium_id, label, type, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (self::units() as $u) {
            $unit->execute([$u['id'], 1, 'Apto ' . $u['id'] . '01', $u['type'], 'active', null]);
        }
        $unit->execute([8, 1, 'Apto 801', 'apartamento', 'inactive', null]);
        $unit->execute([9, 1, 'Apto 901', 'apartamento', 'active', '2026-01-01 00:00:00']);
        $unit->execute([10, 3, 'Fora', 'apartamento', 'active', null]);
        $link = $this->pdo->prepare('INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document, email, phone, starts_on, ends_on, status,
            deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::links() as $l) {
            $link->execute([1, $l['unit_id'], 'Apto x', $l['relationship'], 'Fulano de Tal', '12345678909', 'fulano@exemplo.com', '11988887777', $l['starts_on'], $l['ends_on'],
                $l['status'], null]);
        }
        $link->execute([1, 6, 'Apto 601', 'tenant', 'Excluído', null, null, null, null, null, 'active', '2026-01-01 00:00:00']);   // excluído não conta
        $link->execute([3, 10, 'Fora', 'tenant', 'Outro', null, null, null, null, null, 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-11-11');
        self::assertSame([7, 2, 2, 8, 2], [$report['totals']['units'], $report['rows'][0]['units'], $report['rows'][4]['units'], $report['links']['linked'],
            $report['links']['unlinked']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', '12345678909', 'exemplo.com', '11988887777', 'Apto', 'unit_id', 'unit_label'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null], ['x', null], [1, '11/11/2026'], [1, '2026-02-30']] as [$c, $on]) {
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/UnitOccupancy.php');
        foreach (["RegistryService::definition('units')", "RegistryService::definition('residents')", '$this->scope->assertAccess(', 'deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'SELECT name', 'document', 'email', 'phone', 'SELECT id, label', 'unit_label', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/UnitOccupancy.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.units.view', \$user)", "Access::can('erp.residents.view', \$user)",
            'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/unit-occupancy", "UnitOccupancy:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
