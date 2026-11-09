<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitBatch;

/** Cadastro rápido de unidades e divisão da fração ideal (ADR 0140): regras puras, rotas e telas. */
final class UnitBatchRulesTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testFloorsTimesUnitsPerFloor(): void
    {
        $plan = UnitBatch::plan(['floor_from' => '1', 'floor_to' => '12', 'units_per_floor' => '8']);
        $numbers = array_column($plan['units'], 'number');
        self::assertCount(96, $numbers);
        self::assertSame(['101', '102', '103', '104', '105', '106', '107', '108', '201'], array_slice($numbers, 0, 9));
        self::assertSame('1208', end($numbers));
        self::assertSame('12', $plan['units'][95]['floor']);
        self::assertNull($plan['units'][0]['block']);
        self::assertSame(['type' => 'apartamento', 'private_area' => null], $plan['options']);
    }

    public function testBlocksSkipsGroundFloorAndExtras(): void
    {
        $plan = UnitBatch::plan([
            'blocks' => 'A, B', 'floor_from' => 0, 'floor_to' => 3, 'units_per_floor' => 2, 'skip_floors' => '2',
            'skip_numbers' => '302', 'extra_numbers' => "Cobertura 1;Loja 1", 'type' => 'apartamento', 'private_area' => '72,5',
        ]);
        $labels = array_column($plan['units'], 'label');
        self::assertSame(['A - 01', 'A - 02', 'A - 101', 'A - 102', 'A - 301', 'A - Cobertura 1', 'A - Loja 1',
            'B - 01', 'B - 02', 'B - 101', 'B - 102', 'B - 301', 'B - Cobertura 1', 'B - Loja 1'], $labels);
        self::assertSame('Térreo', $plan['units'][0]['floor']);
        self::assertNull($plan['units'][5]['floor'], 'Avulsa não tem andar.');
        self::assertSame('72.50', $plan['options']['private_area']);
    }

    public function testOnlyExtrasAndDigitsOptions(): void
    {
        self::assertSame(['Casa 1', 'Casa 2'], array_column(UnitBatch::plan(['extra_numbers' => ['Casa 1', 'Casa 2', 'casa 1'], 'type' => 'casa'])['units'], 'label'));
        self::assertSame(['10', '11', '12'], array_column(UnitBatch::plan(['floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 3, 'digits' => 1, 'first_position' => 0])['units'], 'number'));
        self::assertSame(['1001', '1002'], array_column(UnitBatch::plan(['floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 2, 'digits' => 3])['units'], 'number'));
    }

    public function testInvalidParametersAreRefusedByField(): void
    {
        $cases = [
            'floor_from' => [],
            'floor_to' => ['floor_from' => 5, 'floor_to' => 2, 'units_per_floor' => 4],
            'units_per_floor' => ['floor_from' => 1, 'floor_to' => 2, 'units_per_floor' => 0],
            'digits' => ['floor_from' => 1, 'floor_to' => 2, 'units_per_floor' => 12, 'digits' => 1],
            'blocks' => ['blocks' => 'A, a', 'floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 1],
            'skip_floors' => ['floor_from' => 1, 'floor_to' => 2, 'units_per_floor' => 1, 'skip_floors' => 'treze'],
            'type' => ['floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 1, 'type' => 'castelo'],
            'private_area' => ['floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 1, 'private_area' => 'grande'],
            'extra_numbers' => ['extra_numbers' => str_repeat('x', 17)],
        ];
        foreach ($cases as $field => $input) {
            try {
                UnitBatch::plan($input);
                self::fail("Aceitou {$field} inválido.");
            } catch (RegistryValidationException $exception) {
                self::assertArrayHasKey($field, $exception->errors, $field);
            }
        }
    }

    public function testTooManyUnitsAndEmptySequenceAreRefused(): void
    {
        foreach ([['floor_from' => 0, 'floor_to' => 300, 'units_per_floor' => 99], ['blocks' => 'A,B,C', 'floor_from' => 1, 'floor_to' => 100, 'units_per_floor' => 8]] as $input) {
            try {
                UnitBatch::plan($input);
                self::fail('Gerou unidades demais.');
            } catch (RegistryValidationException $exception) {
                self::assertArrayHasKey('units_per_floor', $exception->errors);
            }
        }
        try {
            UnitBatch::plan(['floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 1, 'skip_floors' => '1']);
            self::fail('Aceitou sequência vazia.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('floor_from', $exception->errors);
        }
    }

    public function testEqualDistributionSumsExactlyOne(): void
    {
        $units = array_map(static fn (int $id): array => ['id' => $id], range(1, 114));
        $fractions = UnitBatch::distribute($units, 'equal');
        self::assertCount(114, $fractions);
        self::assertSame(1000000, array_sum(array_map(static fn (string $f): int => (int)round((float)$f * 1000000), $fractions)));
        foreach ($fractions as $fraction) {
            self::assertContains($fraction, ['0.008771', '0.008772']);
        }
        self::assertSame(['1' => '0.333334', '2' => '0.333333', '3' => '0.333333'], array_map('strval', UnitBatch::distribute([['id' => 1], ['id' => 2], ['id' => 3]], 'equal')));
    }

    public function testAreaDistributionIsProportionalAndNeedsEveryArea(): void
    {
        $fractions = UnitBatch::distribute([['id' => 7, 'private_area' => '50'], ['id' => 8, 'private_area' => '100,00'], ['id' => 9, 'private_area' => 50]], 'area');
        self::assertSame([7 => '0.250000', 8 => '0.500000', 9 => '0.250000'], $fractions);
        foreach ([[['id' => 1, 'private_area' => '50'], ['id' => 2, 'private_area' => '']], [['id' => 1, 'private_area' => '0']]] as $units) {
            try {
                UnitBatch::distribute($units, 'area');
                self::fail('Dividiu pela área sem área.');
            } catch (RegistryValidationException $exception) {
                self::assertArrayHasKey('private_area', $exception->errors);
            }
        }
        $this->expectException(RegistryValidationException::class);
        UnitBatch::distribute([['id' => 1]], 'sorteio');
    }

    public function testSummaryFlagsCompleteOnlyWhenEveryUnitSumsOne(): void
    {
        $unit = static fn (int $id, ?string $fraction, ?string $area = null): array => ['id' => $id, 'label' => (string)$id, 'block' => null, 'number' => (string)$id, 'floor' => null, 'type' => 'apartamento', 'ideal_fraction' => $fraction, 'private_area' => $area];
        $partial = UnitBatch::summary(1, [$unit(1, '0.500000', '10.00'), $unit(2, null)], 0.0001);
        self::assertFalse($partial['complete']);
        self::assertSame(1, $partial['missing_fraction']);
        self::assertSame(1, $partial['missing_area']);
        self::assertSame(10.0, $partial['area_sum']);
        self::assertTrue(UnitBatch::summary(1, [$unit(1, '0.500000'), $unit(2, '0.500050')], 0.0001)['complete']);
        self::assertFalse(UnitBatch::summary(1, [], 0.0001)['complete']);
    }

    public function testRoutesScreensAndBundle(): void
    {
        $routes = $this->file('container/erp/connect/v1/routes.php');
        self::assertLessThan(strpos($routes, 'foreach ($erpRegistries'), strpos($routes, 'UnitBatchScreen::ROUTE'), 'Cadastro rápido antes de /condominios/unidades/{id}.');
        $index = $this->file('index.php');
        foreach (['"/unit-batches/preview"', '"/unit-batches"', '$route->get("/unit-measures"', '$route->put("/unit-measures"', '"/unit-measures/distribute"'] as $needle) {
            self::assertStringContainsString($needle, $index);
        }
        self::assertLessThan(strpos($index, 'foreach (\Source\Services\Erp\Registry\Definitions::all()'), strpos($index, '"/unit-batches"'));
        $api = $this->file('source/Controllers/Api/V1/UnitBatches.php');
        self::assertStringContainsString("Access::can('erp.units.manage', \$user)", $api);
        self::assertSame('pages/condominiums/unit-measures', Panel::ownTemplate('AP-167', ['measuresReady' => true]));
        self::assertNull(Panel::ownTemplate('AP-167', ['measuresReady' => false]));
        self::assertStringContainsString('csrf_input()', $this->file('container/erp/connect/v1/pages/condominiums/unit-batch.php'));
        self::assertStringContainsString('csrf_input()', $this->file('container/erp/connect/v1/pages/condominiums/unit-measures.php'));
        self::assertStringContainsString("'/condominios/unidades/cadastro-rapido'", $this->file('container/erp/connect/v1/pages/registry/index.php'));
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = $this->file('container/erp/connect/v1/' . $file);
            foreach (['[data-erp-unit-batch]', "api + '/preview'", '[data-erp-unit-measures]', "api + '/distribute'", "call('PUT', api"] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
    }
}
