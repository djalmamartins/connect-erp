<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VacantUnits;
use Source\Services\Erp\VehicleUnitRelease;

/** ADR 0270: unidades vagas e o que ficou nelas, com os cadastros; somente leitura, sem dado pessoal e igual aos lotes. */
final class VacantUnitsTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['101', '102', '103'] as $number) {
            $this->units[$number] = (int)$this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        // A 102 tem morador ativo; a 101 ficou vazia (o vínculo foi encerrado); a 103 nunca teve vínculo.
        $resident = (int)$this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['101'], 'relationship' => 'tenant',
            'name' => 'Morador de teste', 'document' => '529.982.247-25'])['id'];
        $this->pdo->prepare("UPDATE erp_residents SET status = 'inactive' WHERE id = ?")->execute([$resident]);
        $this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['102'], 'relationship' => 'owner',
            'name' => 'Morador de teste', 'document' => '111.444.777-35']);
    }

    public function testVacantUnitsFromTheRegistriesMatchTheBatches(): void
    {
        foreach (['101', '102'] as $unit) {
            $this->registry->save($this->admin, 'vehicles', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit],
                'plate' => $unit === '101' ? 'QWE9R87' : 'ASD5F67', 'kind' => 'carro', 'owner_name' => 'Dono de teste']);
        }
        $this->registry->save($this->admin, 'pets', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['103'], 'name' => 'Rex de teste', 'species' => 'cao']);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new VacantUnits($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['active' => 3, 'occupied' => 1, 'vacant' => 2, 'with_leftovers' => 2, 'clean' => 0], $report['units']);
        self::assertSame(['never_linked' => 1, 'unknown_date' => 1], array_intersect_key(array_combine(array_column($report['vacancy'], 'bucket'),
            array_column($report['vacancy'], 'units')), ['never_linked' => 0, 'unknown_date' => 0]));
        self::assertSame(['visitors' => 0, 'vehicles' => 1, 'pets' => 1], array_combine(array_column($report['rows'], 'registry'), array_column($report['rows'], 'records')));
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Morador de teste', 'Dono de teste', 'Rex de teste', 'QWE9R87', 'unit_id'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $preview = (new VehicleUnitRelease($this->pdo))->release($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Família se mudou', 'dry_run' => true]);
        self::assertSame($report['rows'][1]['records'], $preview['count'], 'O relatório mostra o que a inativação dos veículos pegaria.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VacantUnits($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }
}
