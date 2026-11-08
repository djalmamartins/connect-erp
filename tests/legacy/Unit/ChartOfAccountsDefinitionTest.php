<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;

/** Plano de contas (AP-160), sem banco (ADR 0019). */
final class ChartOfAccountsDefinitionTest extends TestCase
{
    public function testDefinitionIsOptionalScopeInConfiguracoes(): void
    {
        $definition = Definitions::get('chart');
        self::assertSame('configuracoes', $definition['group']);
        self::assertSame('optional', $definition['scope']);
        self::assertSame('/configuracoes/plano-de-contas', $definition['route']);
        self::assertSame('code', $definition['order']);
        self::assertSame('code', $definition['hierarchy']['field']);
    }

    public function testRequiredFieldsAndCodeFormat(): void
    {
        $definition = Definitions::get('chart');
        [, $errors] = RegistryValidator::validate($definition, []);
        self::assertSame(['code', 'nature', 'name', 'kind'], array_keys($errors));

        foreach (['1', '1.01', '2.01.003', '9.9999.1.1.1.1'] as $code) {
            [, $errors] = RegistryValidator::validate($definition, ['code' => $code, 'nature' => 'despesa', 'name' => 'X', 'kind' => 'analitica']);
            self::assertSame([], $errors, $code);
        }
        foreach (['', '1.', '.1', '1..2', 'A.01', '1.01.', '1234', '1.12345', '1.1.1.1.1.1.1', '1,01'] as $code) {
            [, $errors] = RegistryValidator::validate($definition, ['code' => $code, 'nature' => 'despesa', 'name' => 'X', 'kind' => 'analitica']);
            self::assertArrayHasKey('code', $errors, "Aceitou código {$code}");
        }
        [, $errors] = RegistryValidator::validate($definition, ['code' => '1', 'nature' => 'outra', 'name' => 'X', 'kind' => 'grupo']);
        self::assertArrayHasKey('nature', $errors);
        self::assertArrayHasKey('kind', $errors);
    }
}
