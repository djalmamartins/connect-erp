<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Services\Erp\CondominiumService;

/** Validação do cadastro de condomínio (sem banco). */
final class CondominiumValidationTest extends TestCase
{
    public function testValidInputIsNormalized(): void
    {
        [$data, $errors] = CondominiumService::validate(['name' => "  Residencial   Exemplo ", 'document' => '11.222.333/0001-81', 'status' => 'inactive']);

        self::assertSame([], $errors);
        self::assertSame(['name' => 'Residencial Exemplo', 'document' => '11222333000181', 'status' => 'inactive'], $data);
    }

    public function testDocumentIsOptionalAndStatusDefaultsToActive(): void
    {
        [$data, $errors] = CondominiumService::validate(['name' => 'Edifício Sol', 'document' => '']);

        self::assertSame([], $errors);
        self::assertNull($data['document']);
        self::assertSame('active', $data['status']);
    }

    public function testInvalidFieldsAreReported(): void
    {
        [, $errors] = CondominiumService::validate(['name' => '   ', 'document' => '11.222.333/0001-82', 'status' => 'deleted']);
        self::assertSame(['name', 'document', 'status'], array_keys($errors));

        [, $errors] = CondominiumService::validate(['name' => str_repeat('a', 161)]);
        self::assertArrayHasKey('name', $errors);

        [, $errors] = CondominiumService::validate(['name' => ['array'], 'document' => ['x']]);
        self::assertArrayHasKey('name', $errors, 'Entrada não escalar não pode passar.');
        self::assertArrayNotHasKey('document', $errors);
    }
}
