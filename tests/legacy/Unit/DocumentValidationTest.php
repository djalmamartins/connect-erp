<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Source\Services\CondominiumValidator;

/**
 * is_document() (login por CPF/CNPJ em Auth::attempt e cadastro em User::save):
 * dígitos verificadores de CPF e CNPJ.
 */
final class DocumentValidationTest extends TestCase
{
    public static function documents(): array
    {
        return [
            'CPF válido' => ['52998224725', true],
            'CPF válido formatado' => ['529.982.247-25', true],
            'CPF 1º dígito errado' => ['52998224715', false],
            'CPF 2º dígito errado' => ['52998224724', false],
            'CPF repetido' => ['11111111111', false],
            'CPF curto' => ['5299822472', false],
            'CNPJ válido' => ['11222333000181', true],
            'CNPJ válido formatado' => ['11.222.333/0001-81', true],
            'CNPJ válido 2' => ['11444777000161', true],
            'CNPJ só 2º dígito errado (bug antigo)' => ['11222333000182', false],
            'CNPJ só 1º dígito errado' => ['11222333000191', false],
            'CNPJ dois dígitos errados' => ['11222333000199', false],
            'CNPJ repetido' => ['00000000000000', false],
            'tamanho 12' => ['112223330001', false],
            'tamanho 15' => ['112223330001811', false],
            'vazio' => ['', false],
        ];
    }

    #[DataProvider('documents')]
    public function testCheckDigits(string $document, bool $expected): void
    {
        self::assertSame($expected, is_document($document));
    }

    public function testAgreesWithCondominiumCnpjValidator(): void
    {
        foreach (['11222333000181', '11444777000161', '11222333000182', '11222333000191', '00000000000000'] as $cnpj) {
            self::assertSame(CondominiumValidator::isValidCnpj($cnpj), is_document($cnpj), $cnpj);
        }
    }
}
