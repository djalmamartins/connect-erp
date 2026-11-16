<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ListDocument;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Erp\RegistryExport;

/** Download das listas com os dados do banco (revisão de 15/11 item 18, ADR 0314). */
final class ListDownloadTest extends TestCase
{
    public function testCondominiumLabelAndUnitsListInTheModelLayout(): void
    {
        $this->pdo->prepare("INSERT INTO condominiums (name, status, city, district) VALUES ('CONDOMINIO AVENIDA', 'active', 'Conselheiro Lafaiete', 'CENTRO')")->execute();
        $alpha = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO condominiums (name, status) VALUES ('Beta', 'active')")->execute();
        $beta = (int)$this->pdo->lastInsertId();
        self::assertSame('CONDOMINIO AVENIDA (Conselheiro Lafaiete / CENTRO)', ListDocument::condominiumLabel($alpha, $this->pdo));
        self::assertSame('Beta', ListDocument::condominiumLabel($beta, $this->pdo));
        self::assertNull(ListDocument::condominiumLabel(null, $this->pdo));
        self::assertNull(ListDocument::condominiumLabel(999999, $this->pdo));

        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $alpha);
        $user = (new User())->findById($id);
        $service = new RegistryService($this->pdo);
        foreach ([[$alpha, 'Sala 501'], [$alpha, '=01'], [$beta, 'Beta 1']] as [$condominium, $number]) {
            $this->pdo->prepare("INSERT INTO erp_units (condominium_id, block, number, type, status) VALUES (?, 'Único', ?, 'apartamento', 'active')")->execute([$condominium, $number]);
        }
        $result = $service->list($user, 'units', ['condominium_id' => $alpha, 'per_page' => 500]);
        $table = RegistryExport::table(RegistryService::definition('units'), $result['data']);
        self::assertSame('Situação', end($table['header']));
        $cells = array_merge(...$table['rows']);
        self::assertContains('Sala 501', $cells);
        self::assertContains("'=01", $cells, 'Célula que começa com = sai protegida.');
        self::assertNotContains('Beta 1', $cells, 'Só o condomínio do filtro.');
        $html = ListDocument::html($table, ListDocument::meta(null, 'Unidades', $alpha));
        self::assertStringContainsString('CONDOMINIO AVENIDA (Conselheiro Lafaiete / CENTRO)', $html);
        self::assertStringContainsString('Sala 501', $html);
    }
}
