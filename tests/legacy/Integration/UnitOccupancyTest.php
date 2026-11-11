<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\UnitOccupancy;

/** ADR 0240: perfil de ocupação com as unidades e os vínculos dos cadastros; somente leitura e sem dado pessoal. */
final class UnitOccupancyTest extends TestCase
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
        foreach (['101' => 'apartamento', '102' => 'apartamento', '103' => 'apartamento', 'Loja 1' => 'loja'] as $number => $type) {
            $this->units[$number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => $type])['id'];
        }
    }

    public function testOccupancyFromTheRegistries(): void
    {
        $this->link('101', 'owner');
        $this->link('101', 'tenant');
        $this->link('102', 'owner');
        $this->link('102', 'resident');
        $this->link('103', 'owner');
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new UnitOccupancy($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['rented' => 1, 'occupied' => 1, 'owner_only' => 1, 'other_links' => 0, 'no_links' => 1],
            array_combine(array_column($report['rows'], 'situation'), array_column($report['rows'], 'units')));
        self::assertSame(1, $report['rows'][4]['by_type']['loja']);
        self::assertSame(4, $report['totals']['units']);
        self::assertSame([3, 1, 1, 0], array_column($report['links']['rows'], 'links'));
        self::assertSame([5, 0], [$report['links']['linked'], $report['links']['unlinked']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Pessoa de teste', 'Loja 1', 'unit_label'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new UnitOccupancy($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function link(string $unit, string $relationship): void
    {
        $this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit], 'relationship' => $relationship,
            'name' => 'Pessoa de teste']);
    }
}
