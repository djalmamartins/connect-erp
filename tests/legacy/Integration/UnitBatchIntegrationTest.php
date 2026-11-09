<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitBatch;

/** Cadastro rápido de unidades e fração ideal/área em grade (ADR 0140). */
final class UnitBatchIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private UnitBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->batch = new UnitBatch($this->pdo);
    }

    public function testPreviewMarksExistingAndCreateSkipsThemWithAudit(): void
    {
        $user = $this->user([$this->alpha]);
        (new RegistryService($this->pdo))->save($user, 'units', ['condominium_id' => $this->alpha, 'block' => 'a', 'number' => '102', 'type' => 'apartamento']);
        $input = ['condominium_id' => $this->alpha, 'blocks' => 'A', 'floor_from' => 1, 'floor_to' => 2, 'units_per_floor' => 2, 'private_area' => '60,5'];

        $preview = $this->batch->preview($user, $input);
        self::assertSame([4, 3, 1], [$preview['total'], $preview['new'], $preview['existing']]);
        self::assertTrue($preview['units'][1]['exists'], 'A - 102 já existe (bloco sem diferença de maiúscula).');

        $audits = (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_units' AND action = 'create'")->fetchColumn();
        $result = $this->batch->create($user, $input);
        self::assertSame(3, $result['created']);
        self::assertSame(1, $result['skipped']);
        self::assertSame($audits + 3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_units' AND action = 'create'")->fetchColumn(), 'Cada unidade criada é auditada.');
        $rows = $this->pdo->query("SELECT label, floor, type, private_area, created_by FROM erp_units WHERE condominium_id = {$this->alpha} ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['A - 101', 'a - 102', 'A - 201', 'A - 202'], array_column($rows, 'label'));
        self::assertSame('2', $rows[2]['floor']);
        self::assertSame('60.50', $rows[0]['private_area']);
        self::assertSame((string)$user->id, (string)$rows[0]['created_by']);

        $again = $this->batch->create($user, $input);
        self::assertSame(0, $again['created'], 'Rodar de novo não duplica.');
    }

    public function testCreateIsAllOrNothingAndRespectsScope(): void
    {
        $user = $this->user([$this->alpha]);
        try {
            $this->batch->preview($user, ['condominium_id' => $this->beta, 'floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 1]);
            self::fail('Pré-visualizou em condomínio fora do escopo.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
        // Falha no meio (gatilho recusa a 102): a 101, já gravada, volta junto.
        $this->pdo->exec("CREATE TRIGGER test_unit_batch_fail BEFORE INSERT ON erp_units FOR EACH ROW BEGIN IF NEW.number = '102' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falha simulada'; END IF; END");
        try {
            $this->batch->create($user, ['condominium_id' => $this->alpha, 'floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 3]);
            self::fail('A falha simulada não apareceu.');
        } catch (\PDOException) {
            self::addToAssertionCount(1);
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS test_unit_batch_fail');
        }
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_units WHERE condominium_id = {$this->alpha}")->fetchColumn(), 'Tudo ou nada.');

        $this->pdo->exec('DELETE FROM erp_units');
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type, status, deleted_at) VALUES (?, '101', 'apartamento', 'active', NOW())")->execute([$this->alpha]);
        $created = $this->batch->create($user, ['condominium_id' => $this->alpha, 'floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 2]);
        self::assertSame(2, $created['created'], 'Unidade excluída não impede recriar o número.');
    }

    public function testMeasuresGridSavesPartialButRequiresOneWhenComplete(): void
    {
        $user = $this->user([$this->alpha]);
        $ids = $this->batch->create($user, ['condominium_id' => $this->alpha, 'floor_from' => 1, 'floor_to' => 1, 'units_per_floor' => 3])['ids'];

        $grid = $this->batch->measures($user, $this->alpha);
        self::assertSame(['101', '102', '103'], array_column($grid['units'], 'label'));
        self::assertSame(3, $grid['missing_fraction']);

        $saved = $this->batch->saveMeasures($user, $this->alpha, [['id' => $ids[0], 'ideal_fraction' => '25%', 'private_area' => '50'], ['id' => $ids[1], 'private_area' => '100,00']]);
        self::assertSame(2, $saved['updated']);
        self::assertSame(0.25, $saved['fraction_sum']);
        self::assertFalse($saved['complete']);
        self::assertSame(150.0, $saved['area_sum']);

        $this->assertInvalid('ideal_fraction', fn () => $this->batch->saveMeasures($user, $this->alpha, [['id' => $ids[1], 'ideal_fraction' => '0,5'], ['id' => $ids[2], 'ideal_fraction' => '0,3']]), 'Todas preenchidas somando 105%.');
        $this->assertInvalid('ideal_fraction', fn () => $this->batch->saveMeasures($user, $this->alpha, [['id' => $ids[1], 'ideal_fraction' => '0,9']]), 'Soma parcial acima de 100%.');
        $this->assertInvalid('units.0.ideal_fraction', fn () => $this->batch->saveMeasures($user, $this->alpha, [['id' => $ids[1], 'ideal_fraction' => '1,5']]));
        $this->assertInvalid('units.0', fn () => $this->batch->saveMeasures($user, $this->alpha, [['id' => 999999, 'ideal_fraction' => '0,1']]));
        self::assertNull($this->pdo->query("SELECT ideal_fraction FROM erp_units WHERE id = {$ids[1]}")->fetchColumn() ?: null, 'Recusa não grava nada.');

        $areas = array_map(static fn (array $u): array => ['id' => $u['id'], 'private_area' => $u['private_area'] ?? 50], $saved['units']);
        $proposal = UnitBatch::distribute($areas, 'area');
        $done = $this->batch->saveMeasures($user, $this->alpha, array_map(static fn (int $id, string $f): array => ['id' => $id, 'ideal_fraction' => $f, 'private_area' => '50'], array_keys($proposal), $proposal));
        self::assertTrue($done['complete']);
        self::assertSame(1.0, $done['fraction_sum']);
        $audit = $this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'erp_units' AND action = 'update' AND entity_id = {$ids[1]} ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringContainsString('ideal_fraction', (string)$audit);

        $other = $this->user([$this->beta]);
        try {
            $this->batch->saveMeasures($other, $this->alpha, [['id' => $ids[0], 'ideal_fraction' => '0,1']]);
            self::fail('Gravou fração em condomínio fora do escopo.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
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
