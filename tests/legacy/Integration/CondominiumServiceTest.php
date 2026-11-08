<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Services\Erp\CondominiumService;
use Source\Services\Erp\CondominiumValidationException;

/** Cadastro de condomínio (AP-004): gravação, CNPJ único, alteração e auditoria. */
final class CondominiumServiceTest extends TestCase
{
    public function testCreateAndUpdateAreStoredAndAudited(): void
    {
        $service = new CondominiumService($this->pdo);
        $id = $service->save(['name' => 'Residencial Alpha', 'document' => '11.222.333/0001-81', 'status' => 'active']);

        self::assertSame(['id' => $id, 'name' => 'Residencial Alpha', 'document' => '11222333000181', 'status' => 'active'], $service->find($id));

        self::assertSame($id, $service->save(['name' => 'Residencial Alpha II', 'document' => '', 'status' => 'inactive'], $id));
        self::assertSame(['id' => $id, 'name' => 'Residencial Alpha II', 'document' => null, 'status' => 'inactive'], $service->find($id));
        self::assertSame([$id], array_column($service->all(), 'id'), 'all() inclui inativos.');

        $actions = $this->pdo->query("SELECT action FROM system_audit_logs WHERE entity = 'condominiums' AND entity_id = {$id} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['create', 'update'], $actions);
    }

    public function testDuplicateDocumentIsRejected(): void
    {
        $service = new CondominiumService($this->pdo);
        $first = $service->save(['name' => 'Alpha', 'document' => '11222333000181']);
        $second = $service->save(['name' => 'Beta']);

        foreach ([[null], [$second]] as [$target]) {
            try {
                $service->save(['name' => 'Gama', 'document' => '11.222.333/0001-81'], $target);
                self::fail('CNPJ repetido foi aceito.');
            } catch (CondominiumValidationException $exception) {
                self::assertArrayHasKey('document', $exception->errors);
            }
        }
        // O próprio condomínio pode manter o CNPJ ao ser alterado.
        self::assertSame($first, $service->save(['name' => 'Alpha', 'document' => '11222333000181'], $first));
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM condominiums')->fetchColumn());
    }

    public function testInvalidInputAndUnknownIdWriteNothing(): void
    {
        $service = new CondominiumService($this->pdo);
        foreach ([[['name' => ''], null], [['name' => 'X'], 999]] as [$input, $id]) {
            try {
                $service->save($input, $id);
                self::fail('Entrada inválida foi gravada.');
            } catch (CondominiumValidationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertNull($service->find(999));
        self::assertNull($service->find(0));
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM condominiums')->fetchColumn());
    }
}
