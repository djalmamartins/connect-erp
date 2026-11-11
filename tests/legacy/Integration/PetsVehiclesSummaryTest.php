<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PetsVehiclesSummary;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0236: resumo dos animais e veículos com os registros dos cadastros; somente leitura e sem dado pessoal. */
final class PetsVehiclesSummaryTest extends TestCase
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
            $this->units[$number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
    }

    public function testSummaryFromTheRegistries(): void
    {
        $this->registry->save($this->admin, 'pets', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['101'], 'name' => 'Animal de teste',
            'species' => 'cao', 'size' => 'grande', 'vaccinated' => '1']);
        $this->registry->save($this->admin, 'pets', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['101'], 'name' => 'Animal de teste',
            'species' => 'gato', 'vaccinated' => '0']);
        $this->registry->save($this->admin, 'vehicles', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['102'], 'plate' => 'ABC1D23',
            'kind' => 'carro', 'parking_spot' => 'G-12', 'owner_name' => 'Dono de teste']);
        $this->registry->save($this->admin, 'vehicles', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['102'], 'plate' => 'XYZ9K87',
            'kind' => 'moto', 'owner_name' => 'Dono de teste']);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new PetsVehiclesSummary($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['pets' => 2, 'by_size' => ['pequeno' => 0, 'medio' => 0, 'grande' => 1, 'sem_porte' => 1], 'vaccinated' => 1, 'not_vaccinated' => 1],
            $report['pets']['totals']);
        self::assertSame(['vehicles' => 2, 'with_spot' => 1, 'without_spot' => 1], $report['vehicles']['totals']);
        self::assertSame(['active_units' => 3, 'with_pets' => ['um' => 0, 'dois' => 1, 'tres_ou_mais' => 0, 'units' => 1],
            'with_vehicles' => ['um' => 0, 'dois' => 1, 'tres_ou_mais' => 0, 'units' => 1]], $report['units']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Animal de teste', 'Dono de teste', 'ABC1D23', 'G-12'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new PetsVehiclesSummary($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }
}
