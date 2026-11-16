<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Services\Erp\Registry\Definitions;
use Source\Support\Erp\PerPage;
use Source\Support\Erp\RegistryFormat;

/** Revisão de 15/11, etapa 1 (ADR 0310): itens por página, unidades do condomínio e coluna Ocupação. */
final class PerPageTest extends TestCase
{
    public function testOnlyTheFourChoicesAreAcceptedAndAnythingElseFallsBackTo50(): void
    {
        self::assertSame([50, 100, 200, 500], PerPage::OPTIONS);
        foreach ([50, 100, 200, 500, '200'] as $ok) {
            self::assertSame((int)$ok, PerPage::from($ok));
        }
        foreach ([null, '', 0, 25, 1000, '100000', 'abc', -50, '50.5'] as $bad) {
            self::assertSame(50, PerPage::from($bad), var_export($bad, true));
        }
    }

    public function testUnitsComeOnlyFromTheChosenCondominiumAndNoneWithoutIt(): void
    {
        $units = [['id' => 1, 'condominium_id' => 7, 'label' => '101'], ['id' => 2, 'condominium_id' => 8, 'label' => '102'], ['id' => 3, 'condominium_id' => 7, 'label' => '103']];
        self::assertSame([], Panel::contextUnits($units, null), 'Sem condomínio na barra superior, nenhum filtro de unidade.');
        self::assertSame([1, 3], array_column(Panel::contextUnits($units, 7), 'id'));
        self::assertSame([], Panel::contextUnits($units, 9));
    }

    public function testRegistryFormListsOnlyUnitsOfTheRecordOrTheChosenCondominium(): void
    {
        $units = [['id' => 1, 'condominium_id' => 7, 'label' => '101'], ['id' => 2, 'condominium_id' => 8, 'label' => '102']];
        \Source\Support\Erp\CondominiumContext::use([['id' => 7, 'name' => 'BNH 10'], ['id' => 8, 'name' => 'Outro']], 7);
        try {
            self::assertSame([1], array_column(\Source\Controllers\Erp\Connect\Registry::formUnits($units, []), 'id'), 'Novo registro: só as do condomínio da barra superior.');
            self::assertSame([2], array_column(\Source\Controllers\Erp\Connect\Registry::formUnits($units, ['condominium_id' => '8']), 'id'), 'Registro existente: as do condomínio dele.');
        } finally {
            \Source\Support\Erp\CondominiumContext::reset();
        }
        self::assertSame([1, 2], array_column(\Source\Controllers\Erp\Connect\Registry::formUnits($units, []), 'id'), 'Sem condomínio: a tela filtra pelo campo Condomínio.');
    }

    public function testUnitsDefinitionHasVacantFlagAndOccupancyFilter(): void
    {
        $units = Definitions::all()['units'];
        $field = $units['fields']['is_vacant'];
        self::assertSame('bool', $field['type']);
        self::assertTrue($field['list']);
        self::assertSame('Vazia', RegistryFormat::display($field, true));
        self::assertSame('Ocupada', RegistryFormat::display($field, false));
        self::assertSame(['field' => 'is_vacant', 'param' => 'ocupacao', 'label' => 'Ocupação', 'options' => ['ocupadas' => ['Ocupadas', 0], 'vazias' => ['Vazias', 1]]], $units['flag_filter']);
    }
}
