<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;
use Source\Support\Erp\BrDocument;

/** Validação dos cadastros do ERP, sem banco (ADR 0014). */
final class RegistryValidatorTest extends TestCase
{
    public function testCpfAndCnpjCheckDigits(): void
    {
        self::assertTrue(BrDocument::isCpf('529.982.247-25'));
        self::assertFalse(BrDocument::isCpf('529.982.247-24'), 'DV errado.');
        self::assertFalse(BrDocument::isCpf('111.111.111-11'), 'Sequência repetida.');
        self::assertTrue(BrDocument::isCnpj('11.222.333/0001-81'));
        self::assertFalse(BrDocument::isCnpj('11.222.333/0001-82'), 'CNPJ com DV errado (bug do is_document legado) é recusado.');
        self::assertFalse(BrDocument::isCnpj('00.000.000/0000-00'));
        self::assertSame('11.222.333/0001-81', BrDocument::format('11222333000181'));
        self::assertSame('529.982.247-25', BrDocument::format('52998224725'));
    }

    public function testEmployeeRequiredFieldsAndNormalization(): void
    {
        $definition = Definitions::get('employees');
        [, $errors] = RegistryValidator::validate($definition, []);
        self::assertSame(['condominium_id', 'job', 'name', 'document', 'hired_on'], array_keys($errors));

        [$clean, $errors] = RegistryValidator::validate($definition, [
            'condominium_id' => '3', 'job' => 'porteiro', 'name' => "  Ana \t Souza ", 'document' => '529.982.247-25',
            'hired_on' => '10/02/2025', 'salary_cents' => '2.150,50', 'phone' => '(19) 99876-5432', 'email' => 'ANA@Exemplo.COM',
            'unknown_column' => 'ignorado', 'status' => 'inactive',
        ]);
        self::assertSame([], $errors);
        self::assertSame(3, $clean['condominium_id']);
        self::assertSame('Ana Souza', $clean['name']);
        self::assertSame('52998224725', $clean['document']);
        self::assertSame('2025-02-10', $clean['hired_on']);
        self::assertSame(215050, $clean['salary_cents']);
        self::assertSame('19998765432', $clean['phone']);
        self::assertSame('ana@exemplo.com', $clean['email']);
        self::assertSame('inactive', $clean['status']);
        self::assertArrayNotHasKey('unknown_column', $clean, 'Campos fora da definição nunca chegam ao SQL.');
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: string}> */
    public static function invalidInputs(): array
    {
        return [
            'select fora da lista' => ['employees', ['job' => 'diretor'], 'job'],
            'data inexistente' => ['employees', ['hired_on' => '2025-02-30'], 'hired_on'],
            'desligamento antes da admissão' => ['employees', ['hired_on' => '2025-02-10', 'terminated_on' => '2025-01-01'], 'terminated_on'],
            'CPF inválido' => ['employees', ['document' => '123.456.789-00'], 'document'],
            'CNPJ com DV errado' => ['suppliers', ['document' => '11.222.333/0001-82'], 'document'],
            'e-mail inválido' => ['suppliers', ['email' => 'nao-e-email'], 'email'],
            'banco com letras' => ['suppliers', ['bank_code' => 'ABC'], 'bank_code'],
            'placa inválida' => ['vehicles', ['plate' => 'AB12345'], 'plate'],
            'OAB com UF inexistente' => ['lawyers', ['oab' => 'XX 1234'], 'oab'],
            'percentual acima de 100' => ['lawyers', ['fee_percent' => '120'], 'fee_percent'],
            'telefone curto' => ['contacts', ['phone' => '12'], 'phone'],
            'fim do mandato antes do início' => ['board', ['term_starts_on' => '2026-01-01', 'term_ends_on' => '2025-12-31'], 'term_ends_on'],
            'boleto por e-mail sem e-mail' => ['residents', ['billing_by_email' => '1'], 'email'],
            'visitante sem validade nem acesso permanente' => ['visitors', ['permanent_access' => '0'], 'valid_until'],
            'texto acima do limite' => ['pets', ['name' => str_repeat('a', 81)], 'name'],
            'array no lugar de texto' => ['pets', ['name' => ['x']], 'name'],
            'situação desconhecida' => ['pets', ['status' => 'deleted'], 'status'],
            'capacidade zero' => ['common_areas', ['capacity' => '0'], 'capacity'],
            'capacidade com letras' => ['common_areas', ['capacity' => '10a'], 'capacity'],
            'antecedência máxima menor que a mínima' => ['common_areas', ['min_advance_days' => '10', 'max_advance_days' => '5'], 'max_advance_days'],
            'horário inválido' => ['common_areas', ['opening_time' => '25:00'], 'opening_time'],
            'tipo de área vinculável desconhecido' => ['linkable_areas', ['kind' => 'garagem'], 'kind'],
            'fração acima de 100' => ['linkable_areas', ['fraction' => '101'], 'fraction'],
            'data de aquisição inexistente' => ['assets', ['acquired_on' => '2025-13-01'], 'acquired_on'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidValuesAreRejected(string $slug, array $input, string $field): void
    {
        [, $errors] = RegistryValidator::validate(Definitions::get($slug), $input);
        self::assertArrayHasKey($field, $errors);
    }

    public function testSpecificFormats(): void
    {
        [$vehicle] = RegistryValidator::validate(Definitions::get('vehicles'), ['plate' => 'abc-1d23']);
        self::assertSame('ABC1D23', $vehicle['plate']);
        [$lawyer] = RegistryValidator::validate(Definitions::get('lawyers'), ['oab' => 'sp/012345', 'fee_percent' => '12,5']);
        self::assertSame('SP 12345', $lawyer['oab']);
        self::assertSame(12.5, $lawyer['fee_percent']);
        [$visitor, $errors] = RegistryValidator::validate(Definitions::get('visitors'), ['document' => '12.345.678-x', 'permanent_access' => 'on']);
        self::assertSame('12345678X', $visitor['document']);
        self::assertSame(1, $visitor['permanent_access']);
        self::assertArrayNotHasKey('valid_until', $errors, 'Acesso permanente dispensa a validade.');
        [$resident] = RegistryValidator::validate(Definitions::get('residents'), ['document' => '11.222.333/0001-81']);
        self::assertSame('11222333000181', $resident['document'], 'Proprietário pode ser pessoa jurídica.');
    }

    public function testCondominiumAreaFormats(): void
    {
        [$area, $errors] = RegistryValidator::validate(Definitions::get('common_areas'), [
            'condominium_id' => '2', 'name' => 'Salão de festas', 'booking_mode' => 'approval', 'capacity' => '80',
            'booking_fee_cents' => '150,00', 'min_advance_days' => '2', 'max_advance_days' => '60',
            'opening_time' => '08:00', 'closing_time' => '22:30',
        ]);
        self::assertSame([], $errors);
        self::assertSame(80, $area['capacity']);
        self::assertSame(15000, $area['booking_fee_cents']);
        self::assertSame(2, $area['min_advance_days']);
        self::assertSame('22:30', $area['closing_time']);
        [$asset] = RegistryValidator::validate(Definitions::get('assets'), ['acquired_on' => '05/03/2024', 'value_cents' => '1.299,90']);
        self::assertSame('2024-03-05', $asset['acquired_on']);
        self::assertSame(129990, $asset['value_cents']);
    }

    public function testDefinitionsMatchCatalogMigrationsAndPermissions(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = require $root . '/container/erp/connect/v1/components/layout/catalog.php';
        // Todas as migrations de avanço: tabelas criadas na 0201/0401 e colunas acrescentadas
        // depois por ALTER TABLE ... ADD COLUMN (ex.: unit_id da 0402, ADR 0017).
        $schema = '';
        foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
            if (!str_ends_with($file, '.down.sql')) {
                $schema .= (string)file_get_contents($file) . "\n";
            }
        }
        $permissions = $schema;
        $apis = [];
        foreach (Definitions::all() as $slug => $definition) {
            $page = $catalog['pages'][$definition['page']] ?? null;
            self::assertNotNull($page, "{$slug}: página do catálogo inexistente");
            self::assertSame($definition['group'], $page['group'], "{$slug}: grupo igual ao do catálogo");
            self::assertContains($definition['group'], ['cadastros', 'condominios', 'configuracoes', 'financeiro']);
            self::assertSame($page['route'], $definition['route'], "{$slug}: rota igual à do catálogo");
            self::assertMatchesRegularExpression('/CREATE TABLE IF NOT EXISTS `' . $definition['table'] . '`/', $schema);
            preg_match('/CREATE TABLE IF NOT EXISTS `' . $definition['table'] . '` \((.*?)\) ENGINE/s', $schema, $table);
            $columns = $table[1];
            preg_match_all('/ALTER TABLE `' . $definition['table'] . '`(.*?);/s', $schema, $alters);
            foreach ($alters[1] as $alter) {
                preg_match_all('/ADD COLUMN IF NOT EXISTS `(\w+)`/', $alter, $added);
                $columns .= ' `' . implode('` `', $added[1]) . '`';
            }
            foreach (array_merge(array_keys($definition['fields']), ['status', 'created_by', 'updated_by', 'deleted_at'], $definition['computed'] ?? []) as $column) {
                self::assertStringContainsString("`{$column}`", $columns, "{$definition['table']}.{$column} existe nas migrations");
            }
            self::assertSame($definition['scope'] !== 'none', str_contains($table[1], '`condominium_id`'));
            foreach ($definition['referenced_by'] ?? [] as $referencing) {
                self::assertMatchesRegularExpression('/ALTER TABLE `' . $referencing . '`[^;]*ADD COLUMN IF NOT EXISTS `unit_id`/s', $schema, "{$referencing}.unit_id existe");
            }
            foreach (['view', 'manage'] as $action) {
                self::assertStringContainsString("'{$definition['permission']}.{$action}'", $permissions);
            }
            $apis[] = $definition['api'];
        }
        $openapi = (string)file_get_contents($root . '/docs/api/registries.openapi.yaml');
        foreach ($apis as $api) {
            self::assertStringContainsString("  /{$api}/{id}:", $openapi, "OpenAPI dos cadastros desatualizado: rode tools/registry-openapi.php ({$api})");
        }
        self::assertCount(21, Definitions::all());
        self::assertSame($apis, array_unique($apis));
    }
}
