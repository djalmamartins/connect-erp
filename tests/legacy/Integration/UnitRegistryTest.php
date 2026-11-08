<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Unidades (AP-006) e troca de unit_label por unit_id nos cadastros (ADR 0017). */
final class UnitRegistryTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
    }

    public function testUnitLabelAndUniquenessWithAndWithoutBlock(): void
    {
        $user = $this->user([$this->alpha]);
        $a101 = $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'block' => 'A', 'number' => '101', 'type' => 'apartamento', 'ideal_fraction' => '0,0125', 'private_area' => '72,5']);
        self::assertSame('A - 101', $a101['label']);
        self::assertSame(0.0125, $a101['ideal_fraction']);
        self::assertSame(72.5, $a101['private_area']);

        $this->assertInvalid('number', fn () => $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'block' => 'a', 'number' => '101', 'type' => 'apartamento']));
        $b101 = $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'block' => 'B', 'number' => '101', 'type' => 'apartamento']);
        self::assertSame('B - 101', $b101['label']);

        $house = $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'number' => 'Casa 3', 'type' => 'casa']);
        self::assertSame('Casa 3', $house['label']);
        $this->assertInvalid('number', fn () => $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'number' => 'Casa 3', 'type' => 'casa']), 'Sem bloco também é único.');
    }

    public function testRegistryLinksToUnitOfSameCondominiumAndCopiesLabel(): void
    {
        $user = $this->user([$this->alpha, $this->beta]);
        $unit = $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'block' => 'A', 'number' => '102', 'type' => 'apartamento']);
        $other = $this->service->save($user, 'units', ['condominium_id' => $this->beta, 'number' => '1', 'type' => 'casa']);

        $pet = $this->service->save($user, 'pets', ['condominium_id' => $this->alpha, 'unit_id' => $unit['id'], 'unit_label' => 'texto antigo', 'name' => 'Rex', 'species' => 'cao']);
        self::assertSame($unit['id'], $pet['unit_id']);
        self::assertSame('A - 102', $pet['unit_label'], 'unit_label acompanha a unidade escolhida.');

        $this->assertInvalid('unit_id', fn () => $this->service->save($user, 'pets', ['condominium_id' => $this->alpha, 'unit_id' => $other['id'], 'name' => 'Mia', 'species' => 'gato']), 'Unidade de outro condomínio.');
        $this->assertInvalid('unit_label', fn () => $this->service->save($user, 'pets', ['condominium_id' => $this->alpha, 'name' => 'Mia', 'species' => 'gato']), 'Sem unidade e sem texto.');

        $byText = $this->service->save($user, 'vehicles', ['condominium_id' => $this->alpha, 'unit_label' => 'A - 102', 'plate' => 'ABC1D23', 'kind' => 'carro']);
        self::assertSame($unit['id'], $byText['unit_id'], 'Texto igual ao de uma unidade liga automaticamente.');
        $free = $this->service->save($user, 'vehicles', ['condominium_id' => $this->alpha, 'unit_label' => 'Garagem 9', 'plate' => 'XYZ9A88', 'kind' => 'moto']);
        self::assertNull($free['unit_id'], 'Texto sem unidade continua aceito durante a transição.');
    }

    public function testUnitInUseCannotBeDeletedButInactiveOrUnusedCan(): void
    {
        $user = $this->user([$this->alpha]);
        $unit = $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'number' => '201', 'type' => 'apartamento']);
        $resident = $this->service->save($user, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $unit['id'], 'relationship' => 'owner', 'name' => 'Ana Souza']);

        try {
            $this->service->delete($user, 'units', $unit['id']);
            self::fail('Excluiu unidade com proprietário ativo.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
        $this->service->delete($user, 'residents', $resident['id']);
        $this->service->delete($user, 'units', $unit['id']);
        self::assertSame(0, $this->service->list($user, 'units')['total']);
        self::assertSame([], $this->service->units($user), 'Unidade excluída sai das opções do formulário.');
    }

    public function testUnitOptionsRespectScope(): void
    {
        $owner = $this->user([$this->alpha]);
        $this->service->save($owner, 'units', ['condominium_id' => $this->alpha, 'number' => '301', 'type' => 'apartamento']);
        $outsider = $this->user([$this->beta]);

        self::assertCount(1, $this->service->units($owner));
        self::assertSame([], $this->service->units($outsider));
        self::assertSame(0, $this->service->list($outsider, 'units')['total']);
    }

    public function testBackfillMigrationCreatesUnitsFromTextAndIsIdempotent(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_label, relationship, name) VALUES (?, ?, 'owner', ?)");
        $insert->execute([$this->alpha, ' Bloco C - 12 ', 'Bruno']);
        $insert->execute([$this->alpha, 'Bloco C - 12', 'Carla']);
        $this->pdo->prepare("INSERT INTO erp_pets (condominium_id, unit_label, name, species) VALUES (?, 'Bloco C - 12', 'Tom', 'gato')")->execute([$this->alpha]);
        $this->pdo->prepare("INSERT INTO erp_vehicles (condominium_id, unit_label, plate, kind) VALUES (?, 'Casa 7', 'AAA1A11', 'carro')")->execute([$this->beta]);

        $migration = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261108_0402_unit_links.sql');
        $this->pdo->exec($migration);
        $this->pdo->exec($migration);

        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_units')->fetchColumn(), 'Uma unidade por texto e condomínio, sem duplicar ao reaplicar.');
        $unit = (int)$this->pdo->query("SELECT id FROM erp_units WHERE condominium_id = {$this->alpha} AND label = 'Bloco C - 12'")->fetchColumn();
        self::assertGreaterThan(0, $unit);
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_residents WHERE unit_id IS NULL OR unit_id <> {$unit}")->fetchColumn());
        self::assertSame($unit, (int)$this->pdo->query('SELECT unit_id FROM erp_pets')->fetchColumn());
        self::assertSame(' Bloco C - 12 ', (string)$this->pdo->query("SELECT unit_label FROM erp_residents WHERE name = 'Bruno'")->fetchColumn(), 'unit_label não é alterado pela migration.');
        self::assertSame('outro', (string)$this->pdo->query("SELECT type FROM erp_units WHERE condominium_id = {$this->beta}")->fetchColumn());
    }

    public function testLinkableAreaPointsToUnitAndBlocksUnitDeletion(): void
    {
        $user = $this->user([$this->alpha, $this->beta]);
        $unit = $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'block' => 'A', 'number' => '101', 'type' => 'apartamento']);
        $other = $this->service->save($user, 'units', ['condominium_id' => $this->beta, 'number' => '1', 'type' => 'casa']);

        $spot = $this->service->save($user, 'linkable_areas', ['condominium_id' => $this->alpha, 'kind' => 'vaga', 'identifier' => 'G1-023', 'unit_id' => $unit['id']]);
        self::assertSame($unit['id'], $spot['unit_id']);
        self::assertSame('A - 101', $spot['unit_label']);
        $free = $this->service->save($user, 'linkable_areas', ['condominium_id' => $this->alpha, 'kind' => 'box', 'identifier' => 'B-07']);
        self::assertNull($free['unit_id'], 'Vaga ou box livre fica sem unidade.');
        $this->assertInvalid('unit_id', fn () => $this->service->save($user, 'linkable_areas', ['condominium_id' => $this->alpha, 'kind' => 'deposito', 'identifier' => 'D-1', 'unit_id' => $other['id']]), 'Unidade de outro condomínio.');

        try {
            $this->service->delete($user, 'units', $unit['id']);
            self::fail('Excluiu unidade com vaga vinculada.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
    }

    public function testLinkableAreaBackfillIsIdempotent(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_linkable_areas (condominium_id, kind, identifier, unit_label) VALUES (?, ?, ?, ?)');
        $insert->execute([$this->alpha, 'vaga', 'G1', 'Bloco D - 3']);
        $insert->execute([$this->alpha, 'box', 'B1', ' Bloco D - 3 ']);
        $insert->execute([$this->alpha, 'vaga', 'G2', null]);

        $migration = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261108_0404_linkable_area_units.sql');
        $this->pdo->exec($migration);
        $this->pdo->exec($migration);

        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_units')->fetchColumn());
        $unit = (int)$this->pdo->query("SELECT id FROM erp_units WHERE label = 'Bloco D - 3'")->fetchColumn();
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_linkable_areas WHERE unit_id = {$unit}")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT unit_id FROM erp_linkable_areas WHERE identifier = 'G2'")->fetchColumn() ?: null, 'Vaga livre continua livre.');
    }

    private function assertInvalid(string $field, callable $call, string $message = ''): void
    {
        try {
            $call();
            self::fail('Aceitou dado inválido. ' . $message);
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors, $message);
        }
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        return (new User())->findById($id);
    }
}
