<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VehicleUnitRelease;

/** ADR 0267: veículos de unidades sem morador ativo inativados pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class VehicleUnitReleaseTest extends TestCase
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
        foreach (['101', '102'] as $number) {
            $this->units[$number] = (int)$this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        // A 102 tem morador ativo; a 101 ficou vazia (o vínculo foi encerrado).
        $resident = (int)$this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['101'], 'relationship' => 'tenant',
            'name' => 'Morador de teste', 'document' => '529.982.247-25'])['id'];
        $this->pdo->prepare("UPDATE erp_residents SET status = 'inactive' WHERE id = ?")->execute([$resident]);
        $this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['102'], 'relationship' => 'owner',
            'name' => 'Morador de teste', 'document' => '111.444.777-35']);
    }

    public function testDeactivatesVehiclesOfEmptyUnitsOnly(): void
    {
        $car = $this->vehicle('101', 'QWE9R87', 'carro');
        $bike = $this->vehicle('101', 'XYZ1234', 'moto');
        $kept = $this->vehicle('102', 'ASD5F67', 'carro');
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Família se mudou'];

        $preview = (new VehicleUnitRelease($this->pdo))->release($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$car, $bike], 1, 1], [$preview['dry_run'], $preview['changed'], array_column($preview['vehicles'], 'id'), $preview['by_kind']['carro'],
            $preview['by_kind']['moto']]);
        self::assertSame('active', $this->state($car), 'dry_run não grava.');

        $done = (new VehicleUnitRelease($this->pdo))->release($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'active'], [$this->state($car), $this->state($bike), $this->state($kept)]);
        self::assertSame('QWE9R87', (string)$this->pdo->query("SELECT plate FROM erp_vehicles WHERE id = {$car}")->fetchColumn(), 'Só a situação muda.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_vehicles' AND action = 'deactivate_unattached'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_vehicles' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['QWE9R87', 'XYZ1234', 'Dono de teste', 'Morador de teste', 'Sedã'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, (new VehicleUnitRelease($this->pdo))->release($this->admin, $input)['count'], 'Já inativos não entram de novo.');
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $empty = $this->vehicle('101', 'QWE9R87', 'carro');
        $inhabited = $this->vehicle('102', 'ASD5F67', 'carro');
        try {
            (new VehicleUnitRelease($this->pdo))->release($this->admin, ['condominium_id' => $this->condominium, 'vehicle_ids' => [$empty, $inhabited], 'reason' => 'Família se mudou']);
            self::fail('Veículo de unidade com morador entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$inhabited, $exception->errors['_vehicle']);
        }
        self::assertSame('active', $this->state($empty), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VehicleUnitRelease($this->pdo))->release($limited, ['condominium_id' => $this->condominium, 'reason' => 'Família se mudou']);
            self::fail('Inativação fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function vehicle(string $unit, string $plate, string $kind): int
    {
        return (int)$this->registry->save($this->admin, 'vehicles', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit], 'plate' => $plate,
            'kind' => $kind, 'model' => 'Sedã', 'owner_name' => 'Dono de teste'])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_vehicles WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
