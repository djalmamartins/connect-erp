<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PetsVehiclesSummary;

/**
 * ADR 0236: resumo dos animais de estimação e dos veículos cadastrados, somente leitura. Unit com SQLite (Pdo\Sqlite
 * quando existe; PDO no PHP 8.2) para a regra pura (opções do cadastro, porte, vacinação, vaga, unidades por quantidade,
 * totais iguais à soma), a montagem sem dado pessoal, a validação e o escopo; os registros dos cadastros são conferidos no
 * MariaDB (Integration\PetsVehiclesSummaryTest).
 */
final class PetsVehiclesSummaryTest extends TestCase
{
    private PDO $pdo;
    private PetsVehiclesSummary $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_pets (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, name TEXT, species TEXT, size TEXT, breed TEXT,
                tutor_name TEXT, vaccinated INTEGER, notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_vehicles (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, plate TEXT, kind TEXT, model TEXT, color TEXT,
                parking_spot TEXT, owner_name TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new PetsVehiclesSummary($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{species:string, size:?string, vaccinated:bool, unit:?string}> */
    private static function pets(): array
    {
        $p = static fn (string $species, ?string $size, bool $vaccinated, ?string $unit): array => ['species' => $species, 'size' => $size, 'vaccinated' => $vaccinated,
            'unit' => $unit];
        return [$p('cao', 'pequeno', true, 'u1'), $p('cao', 'grande', false, 'u1'), $p('cao', null, true, 'u2'), $p('gato', 'medio', true, 'tapto 9'),
            $p('gato', null, false, 'tapto 9'), $p('peixe', null, false, null)];
    }

    /** @return list<array{kind:string, has_spot:bool, unit:?string}> */
    private static function vehicles(): array
    {
        $v = static fn (string $kind, bool $spot, ?string $unit): array => ['kind' => $kind, 'has_spot' => $spot, 'unit' => $unit];
        return [$v('carro', true, 'u1'), $v('carro', false, 'u1'), $v('moto', true, 'u1'), $v('utilitario', false, 'u3'), $v('van', true, 'tbloco b')];
    }

    public function testSummarizePureRule(): void
    {
        $summary = PetsVehiclesSummary::summarize(self::pets(), self::vehicles(), 10);
        self::assertSame([['cao', 'Cão', 3, 2, 1], ['gato', 'Gato', 2, 1, 1], ['ave', 'Ave', 0, 0, 0], ['outro', 'Outro', 1, 0, 1]],
            array_map(static fn (array $r): array => [$r['species'], $r['species_label'], $r['pets'], $r['vaccinated'], $r['not_vaccinated']], $summary['pets']['rows']));
        self::assertSame(['pequeno' => 1, 'medio' => 0, 'grande' => 1, 'sem_porte' => 1], $summary['pets']['rows'][0]['by_size']);
        self::assertSame(['pets' => 6, 'by_size' => ['pequeno' => 1, 'medio' => 1, 'grande' => 1, 'sem_porte' => 3], 'vaccinated' => 3, 'not_vaccinated' => 3],
            $summary['pets']['totals']);
        foreach (['pets', 'vaccinated', 'not_vaccinated'] as $field) {
            self::assertSame($summary['pets']['totals'][$field], array_sum(array_column($summary['pets']['rows'], $field)), 'Total = soma das linhas: ' . $field);
        }
        self::assertSame([['carro', 2, 1, 1], ['moto', 1, 1, 0], ['utilitario', 1, 0, 1], ['outro', 1, 1, 0]],
            array_map(static fn (array $r): array => [$r['kind'], $r['vehicles'], $r['with_spot'], $r['without_spot']], $summary['vehicles']['rows']));
        self::assertSame(['vehicles' => 5, 'with_spot' => 3, 'without_spot' => 2], $summary['vehicles']['totals']);
        self::assertSame(5, array_sum(array_column($summary['vehicles']['rows'], 'vehicles')));
        self::assertSame(['active_units' => 10, 'with_pets' => ['um' => 1, 'dois' => 2, 'tres_ou_mais' => 0, 'units' => 3],
            'with_vehicles' => ['um' => 2, 'dois' => 0, 'tres_ou_mais' => 1, 'units' => 3]], $summary['units']);
        self::assertSame(['u5', 'tapto 9', null, 'tx'], [PetsVehiclesSummary::unitKey(5, 'qualquer'), PetsVehiclesSummary::unitKey(null, ' Apto  9 '),
            PetsVehiclesSummary::unitKey(null, '  '), PetsVehiclesSummary::unitKey(0, 'X')]);
        $empty = PetsVehiclesSummary::summarize([], [], 0);
        self::assertSame([4, 0, 0, 0], [count($empty['pets']['rows']), $empty['pets']['totals']['pets'], $empty['vehicles']['totals']['vehicles'],
            $empty['units']['with_pets']['units']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, status, deleted_at) VALUES (1, 1, 'Apto 101', 'active', NULL), (2, 1, 'Apto 102', 'active', NULL),
            (3, 1, 'Apto 103', 'inactive', NULL), (4, 3, 'Fora', 'active', NULL)");
        $pet = $this->pdo->prepare('INSERT INTO erp_pets (condominium_id, unit_id, unit_label, name, species, size, breed, tutor_name, vaccinated, notes, status, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $pet->execute([1, 1, null, 'Rex', 'cao', 'grande', 'Labrador', 'Fulano Tutor', 1, 'Late muito', 'active', null]);
        $pet->execute([1, null, ' Apto 9 ', 'Mimi', 'gato', null, 'SRD', 'Beltrana', 0, null, 'active', null]);
        $pet->execute([1, 1, null, 'Antigo', 'cao', null, null, null, 0, null, 'inactive', null]);                // inativo
        $pet->execute([1, 1, null, 'Excluído', 'cao', null, null, null, 0, null, 'active', '2026-01-01 00:00:00']); // excluído
        $pet->execute([3, 4, null, 'Outro', 'cao', null, null, null, 0, null, 'active', null]);                    // outro condomínio
        $car = $this->pdo->prepare('INSERT INTO erp_vehicles (condominium_id, unit_id, unit_label, plate, kind, model, color, parking_spot, owner_name, notes, status,
            deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $car->execute([1, 1, null, 'ABC1D23', 'carro', 'Gol', 'Prata', 'G-12', 'Fulano Dono', null, 'active', null]);
        $car->execute([1, 2, null, 'XYZ9K87', 'moto', 'CG', 'Preta', '  ', 'Ciclano', null, 'active', null]);
        $car->execute([3, 4, null, 'OUT0R00', 'carro', null, null, null, null, null, 'active', null]);
        $report = $this->service->build($this->user(), '1');
        self::assertSame([2, 1, 2, 1, 2], [$report['pets']['totals']['pets'], $report['pets']['totals']['vaccinated'], $report['vehicles']['totals']['vehicles'],
            $report['vehicles']['totals']['with_spot'], $report['units']['active_units']]);
        self::assertSame(['um' => 2, 'dois' => 0, 'tres_ou_mais' => 0, 'units' => 2], $report['units']['with_pets']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Rex', 'Mimi', 'Labrador', 'Fulano', 'Beltrana', 'ABC1D23', 'Gol', 'Prata', 'G-12', 'Apto', 'Late', 'unit_id', 'plate'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([null, 'x', 0, '-1'] as $c) {
            try {
                $this->service->build($this->user(), $c);
                self::fail('Entrada inválida passou: ' . json_encode($c));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/PetsVehiclesSummary.php');
        foreach (["RegistryService::definition('pets')", "RegistryService::definition('vehicles')", '$this->scope->assertAccess(', "deleted_at IS NULL"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'SELECT name', 'breed', 'tutor_name', 'plate', 'model', 'color', 'owner_name', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/PetsVehiclesSummary.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.pets.view', \$user)", "Access::can('erp.vehicles.view', \$user)",
            'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/pets-vehicles", "PetsVehiclesSummary:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
