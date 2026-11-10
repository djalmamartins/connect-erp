<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Core\View;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidator;

/** Unidades (AP-006) e campo de unidade nos cadastros, sem banco (ADR 0017). */
final class UnitDefinitionTest extends TestCase
{
    public function testUnitRequiredFieldsAndDecimals(): void
    {
        $definition = Definitions::get('units');
        self::assertSame('condominios', $definition['group']);
        [, $errors] = RegistryValidator::validate($definition, []);
        self::assertSame(['condominium_id', 'number', 'type'], array_keys($errors));

        [$clean, $errors] = RegistryValidator::validate($definition, [
            'condominium_id' => '4', 'block' => ' A ', 'number' => '101', 'type' => 'apartamento',
            'ideal_fraction' => '0,012345', 'private_area' => '72,50',
        ]);
        self::assertSame([], $errors);
        self::assertSame('A', $clean['block']);
        self::assertSame(0.012345, $clean['ideal_fraction']);
        self::assertSame(72.5, $clean['private_area']);

        foreach (['ideal_fraction' => ['1,5', '0,1234567', 'abc', '-0,1'], 'private_area' => ['1,234', 'x']] as $field => $values) {
            foreach ($values as $value) {
                [, $errors] = RegistryValidator::validate($definition, ['condominium_id' => '4', 'number' => '1', 'type' => 'casa', $field => $value]);
                self::assertArrayHasKey($field, $errors, "{$field} = {$value}");
            }
        }
        [, $errors] = RegistryValidator::validate($definition, ['condominium_id' => '4', 'number' => '1', 'type' => 'casa', 'ideal_fraction' => '1']);
        self::assertSame([], $errors, 'Fração ideal 1 (unidade única) é aceita.');
    }

    public function testLinkedRegistriesAcceptUnitIdOrText(): void
    {
        foreach (['residents', 'vehicles', 'visitors', 'pets', 'board'] as $slug) {
            $definition = Definitions::get($slug);
            self::assertSame('unit', $definition['fields']['unit_id']['type'], $slug);
            self::assertFalse($definition['fields']['unit_label']['required'], "{$slug}: texto deixa de ser obrigatório sozinho");
            self::assertTrue(RegistryService::hasUnitField($definition));
        }
        self::assertFalse(RegistryService::hasUnitField(Definitions::get('suppliers')));

        $pets = Definitions::get('pets');
        [, $errors] = RegistryValidator::validate($pets, ['condominium_id' => '1', 'name' => 'Rex', 'species' => 'cao']);
        self::assertArrayHasKey('unit_label', $errors, 'Sem unidade nem texto.');
        [$clean, $errors] = RegistryValidator::validate($pets, ['condominium_id' => '1', 'unit_id' => '9', 'name' => 'Rex', 'species' => 'cao']);
        self::assertSame([], $errors, 'Unidade escolhida dispensa o texto (o serviço confere a unidade).');
        self::assertSame(9, $clean['unit_id']);
        [, $errors] = RegistryValidator::validate($pets, ['condominium_id' => '1', 'unit_id' => 'abc', 'name' => 'Rex', 'species' => 'cao']);
        self::assertArrayHasKey('unit_id', $errors);

        [, $errors] = RegistryValidator::validate(Definitions::get('board'), ['condominium_id' => '1', 'role' => 'sindico', 'name' => 'Síndica Profissional', 'term_starts_on' => '2026-01-01', 'term_ends_on' => '2027-12-31', 'email' => 'sindica@example.invalid', 'phone' => '11999990000']);
        self::assertSame([], $errors, 'Síndico profissional continua sem unidade.');
    }

    public function testUnitSelectGroupsByCondominiumAndEscapes(): void
    {
        $field = Definitions::get('pets')['fields']['unit_id'];
        $html = (new View(moves_container_path('erp', 'connect') . '/'))->render('components/forms/registry-field', [
            'field' => $field, 'value' => 7, 'error' => null, 'readonly' => false,
            'condominiums' => [['id' => 1, 'name' => 'Alpha <b>'], ['id' => 2, 'name' => 'Beta']],
            'units' => [['id' => 7, 'condominium_id' => 1, 'label' => 'A - 101'], ['id' => 8, 'condominium_id' => 2, 'label' => '<script>x</script>']],
        ]);
        self::assertStringContainsString('<optgroup label="Alpha &lt;b&gt;">', $html);
        self::assertStringContainsString('<option value="7" data-condominium="1" selected>A - 101</option>', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
    }

    public function testUnitMigrationsAreReversibleAndRepeatable(): void
    {
        $dir = dirname(__DIR__, 3) . '/database/migrations/';
        foreach (['20261108_0401_units', '20261108_0402_unit_links', '20261108_0403_unit_permissions'] as $name) {
            self::assertFileExists($dir . $name . '.sql');
            self::assertFileExists($dir . $name . '.down.sql');
        }
        $links = (string)file_get_contents($dir . '20261108_0402_unit_links.sql');
        foreach (['erp_residents', 'erp_board_members', 'erp_vehicles', 'erp_visitors', 'erp_pets'] as $table) {
            self::assertMatchesRegularExpression('/ALTER TABLE `' . $table . '`\s+ADD COLUMN IF NOT EXISTS `unit_id`[^;]*FOREIGN KEY IF NOT EXISTS/s', $links, $table);
            self::assertMatchesRegularExpression('/UPDATE `' . $table . '` t SET t\.unit_id = [^;]*WHERE t\.unit_id IS NULL/s', $links, "{$table}: backfill só preenche vazios");
        }
        self::assertStringNotContainsString('DROP COLUMN `unit_label`', $links, 'unit_label é mantido até a troca ser validada.');
        self::assertStringContainsString('WHERE NOT EXISTS', $links);
    }
}
