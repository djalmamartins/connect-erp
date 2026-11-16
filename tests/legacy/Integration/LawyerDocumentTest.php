<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Revisão de 15/11, etapa 3 (ADR 0312): advogado com CPF ou CNPJ, conferido e único. */
final class LawyerDocumentTest extends TestCase
{
    public function testLawyerAcceptsCpfOrCnpjAndRefusesInvalidOrRepeated(): void
    {
        $user = (new User())->findById($this->createUser(['level' => 2]));
        $service = new RegistryService($this->pdo);
        $firm = $service->save($user, 'lawyers', ['name' => 'Escritório Exemplo', 'oab' => 'SP 123456', 'document' => '11.222.333/0001-81'])['id'];
        self::assertSame('11222333000181', $this->pdo->query("SELECT document FROM erp_lawyers WHERE id = {$firm}")->fetchColumn());
        $service->save($user, 'lawyers', ['name' => 'Ana Advogada', 'oab' => 'RJ 654321', 'document' => '529.982.247-25']);
        $service->save($user, 'lawyers', ['name' => 'Sem Documento', 'oab' => 'MG 111111']);
        $service->save($user, 'lawyers', ['name' => 'Outro Sem Documento', 'oab' => 'MG 222222']);
        foreach ([['document' => '11222333000181', 'oab' => 'SP 999999'], ['document' => '11222333000180', 'oab' => 'SP 888888'], ['document' => '123', 'oab' => 'SP 777777']] as $bad) {
            try {
                $service->save($user, 'lawyers', ['name' => 'Repetido'] + $bad);
                self::fail('Aceitou documento ' . $bad['document']);
            } catch (RegistryValidationException $exception) {
                self::assertArrayHasKey('document', $exception->errors);
            }
        }
    }
}
