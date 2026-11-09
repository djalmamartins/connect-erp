<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Core\View;
use Source\Models\User;
use Source\Services\CondominiumValidator;

/** AP-004: validação do cadastro de condomínio, rotas e formulário (sem banco). */
final class CondominiumValidatorTest extends TestCase
{
    private function valid(array $override = []): array
    {
        return array_merge([
            'name' => 'Residencial Aurora', 'legal_name' => 'Condomínio Residencial Aurora',
            'document' => '11.222.333/0001-81', 'status' => 'active',
            'zip_code' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000', 'complement' => 'Torre A',
            'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'sp',
            'phone' => '(11) 98765-4321', 'email' => 'Sindico@Aurora.test',
            'blocks_count' => '2', 'units_count' => '120', 'contract_start_date' => '2026-01-15', 'fee_due_day' => '10',
            'bank_code' => '001', 'bank_branch' => '1234-5', 'bank_account' => '123456-x',
        ], $override);
    }

    public function testValidInputIsNormalized(): void
    {
        $result = (new CondominiumValidator())->validate($this->valid() + ['csrf' => 'x', 'id' => '9', 'created_by' => '1']);

        self::assertSame([], $result['errors']);
        $data = $result['data'];
        self::assertSame('11222333000181', $data['document']);
        self::assertSame('01310100', $data['zip_code']);
        self::assertSame('SP', $data['state']);
        self::assertSame('11987654321', $data['phone']);
        self::assertSame('sindico@aurora.test', $data['email']);
        self::assertSame(2, $data['blocks_count']);
        self::assertSame(10, $data['fee_due_day']);
        self::assertSame('123456-X', $data['bank_account']);
        self::assertArrayNotHasKey('manager_person_id', $data, 'Síndico fica em erp_board_members, não em condominiums.');
        self::assertSame(CondominiumValidator::FIELDS, array_keys($data), 'Somente campos do cadastro entram nos dados.');
    }

    public function testRequiredFieldsAreReported(): void
    {
        $errors = (new CondominiumValidator())->validate([])['errors'];
        foreach (['name', 'legal_name', 'zip_code', 'street', 'number', 'district', 'city', 'state'] as $field) {
            self::assertArrayHasKey($field, $errors, "Campo obrigatório sem erro: {$field}");
        }
        // ADR 0162: o CNPJ passou a ser opcional.
        foreach (['document', 'complement', 'phone', 'email', 'blocks_count', 'units_count', 'fee_due_day', 'bank_code', 'contract_start_date'] as $optional) {
            self::assertArrayNotHasKey($optional, $errors, "Campo opcional com erro: {$optional}");
        }
    }

    public static function cnpjs(): array
    {
        return [
            'válido formatado' => ['11.222.333/0001-81', true],
            'válido só dígitos' => ['11444777000161', true],
            'dígito verificador errado' => ['11222333000182', false],
            'sequência repetida' => ['11111111111111', false],
            'curto' => ['1122233300018', false],
            'CPF' => ['52998224725', false],
        ];
    }

    #[DataProvider('cnpjs')]
    public function testCnpjCheckDigits(string $cnpj, bool $expected): void
    {
        self::assertSame($expected, CondominiumValidator::isValidCnpj($cnpj));
    }

    public static function invalidValues(): array
    {
        return [
            ['fee_due_day', '0'], ['fee_due_day', '29'], ['fee_due_day', 'abc'],
            ['contract_start_date', '2026-02-30'], ['contract_start_date', '15/01/2026'],
            ['state', 'XX'], ['status', 'deleted'], ['email', 'nao-e-email'], ['phone', '1234'],
            ['zip_code', '0131010'], ['blocks_count', '-1'], ['units_count', '100001'],
            ['bank_code', '12'], ['bank_branch', 'ab12'], ['bank_account', '12.345'],
            ['name', 'AB'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(string $field, string $value): void
    {
        $errors = (new CondominiumValidator())->validate($this->valid([$field => $value]))['errors'];
        self::assertArrayHasKey($field, $errors);
    }

    public function testFeeDueDayBoundariesAndBankAllOrNothing(): void
    {
        $validator = new CondominiumValidator();
        self::assertSame([], $validator->validate($this->valid(['fee_due_day' => '1']))['errors']);
        self::assertSame([], $validator->validate($this->valid(['fee_due_day' => '28']))['errors']);
        self::assertSame([], $validator->validate($this->valid(['bank_code' => '', 'bank_branch' => '', 'bank_account' => '']))['errors']);
        self::assertArrayHasKey('bank_code', $validator->validate($this->valid(['bank_account' => '']))['errors']);
    }

    public function testRoutesRegisterCreateAndEditBeforeDetail(): void
    {
        $route = new class {
            public array $calls = [];
            public function __call(string $name, array $args): void { $this->calls[] = [$name, ...$args]; }
        };
        require moves_container_path('erp', 'connect') . '/routes.php';
        $paths = array_map(static fn(array $call): string => $call[0] . ' ' . ($call[1] ?? ''), $route->calls);

        foreach (['get /condominios/novo', 'post /condominios/novo', 'get /condominios/{id}/editar', 'post /condominios/{id}/editar'] as $expected) {
            self::assertContains($expected, $paths);
            self::assertLessThan(array_search('get /condominios/{id}', $paths, true), array_search($expected, $paths, true), "{$expected} precisa vir antes de /{id}.");
        }
        self::assertContains(['post', '/condominios/novo', 'Condominiums:store'], $route->calls);
        self::assertContains(['post', '/condominios/{id}/editar', 'Condominiums:update'], $route->calls);
    }

    public function testFormRendersCsrfEscapedValuesAndFieldErrors(): void
    {
        $user = new User();
        $user->id = 1;
        $user->first_name = 'Ana';
        $user->last_name = 'Teste';
        $html = (new View(moves_container_path('erp', 'connect') . '/'))->render('pages/condominiums/form', (new Panel())->layoutData($user, 'AP-004', [
            'formTitle' => 'Editar condomínio', 'action' => '/erp/condominios/3/editar', 'cancel' => '/erp/condominios/3',
            'values' => ['name' => '"><script>x</script>', 'state' => 'RJ', 'status' => 'inactive'],
            'errors' => ['document' => 'CNPJ inválido.'],
            'states' => CondominiumValidator::STATES, 'statuses' => CondominiumValidator::STATUSES,
        ], 'Teste'));

        self::assertStringContainsString("name='csrf'", $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="condominium-document-error"', $html);
        self::assertStringContainsString('CNPJ inválido.', $html);
        self::assertStringContainsString('<option value="RJ" selected>', $html);
        self::assertStringContainsString('<option value="inactive" selected>', $html);
        self::assertStringContainsString('action="/erp/condominios/3/editar"', $html);
    }
}
