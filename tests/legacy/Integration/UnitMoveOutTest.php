<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitMoveOut;

/** ADR 0206: a saída encerra os vínculos pelo formulário, revoga convites pendentes e, se pedido, inativa veículos e animais. */
final class UnitMoveOutTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private int $condominium;
    private int $unit;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->unit = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testDryRunThenMoveOutTenantKeepsOwner(): void
    {
        $owner = $this->resident('owner', 'Ana Proprietária', '2020-01-01');
        $tenant = $this->resident('tenant', 'Bruno Inquilino', '2025-03-01');
        $resident = $this->resident('resident', 'Carla Moradora', '2025-03-01');
        $vehicle = $this->registry->save($this->admin, 'vehicles', ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'plate' => 'ABC1D23', 'kind' => 'carro'])['id'];
        $pet = $this->registry->save($this->admin, 'pets', ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'name' => 'Rex', 'species' => 'cao'])['id'];
        $this->pdo->prepare("INSERT INTO erp_resident_invitations (condominium_id, resident_id, token_hash, status, expires_at) VALUES (?, ?, ?, 'pending', ?)")
            ->execute([$this->condominium, $tenant, str_repeat('a', 64), '2026-11-20 00:00:00']);
        $input = ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'ends_on' => '2026-11-10', 'reason' => 'Inquilino entregou as chaves',
            'include_vehicles' => true, 'include_pets' => true];

        $preview = (new UnitMoveOut($this->pdo))->moveOut($this->admin, $input + ['dry_run' => true], self::TODAY);
        self::assertSame([true, 0, [$tenant, $resident], 1, 1, 1], [$preview['dry_run'], $preview['changed'], array_column($preview['residents'], 'id'),
            $preview['vehicles'], $preview['pets'], $preview['invitations_revoked']]);
        self::assertSame([], $preview['warnings'], 'A proprietária continua.');
        self::assertSame(['active', 'active', 'active'], $this->statuses('erp_residents'), 'dry_run não grava.');
        $json = (string)json_encode($preview);
        foreach (['Bruno', 'Carla', 'ABC1D23', 'Rex'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }

        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $done = (new UnitMoveOut($this->pdo))->moveOut($this->admin, $input, self::TODAY);
        self::assertSame(4, $done['changed']);
        self::assertSame(['active', 'inactive', 'inactive'], $this->statuses('erp_residents'));
        self::assertSame([null, '2026-11-10', '2026-11-10'], array_map(static fn ($v) => $v === null ? null : substr((string)$v, 0, 10),
            $this->pdo->query('SELECT ends_on FROM erp_residents ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(['inactive'], $this->statuses('erp_vehicles'));
        self::assertSame(['inactive'], $this->statuses('erp_pets'));
        self::assertSame('revoked', $this->pdo->query('SELECT status FROM erp_resident_invitations')->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_units' AND action = 'move_out'")->fetchColumn());
        self::assertSame($outbox, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(), 'Nenhum aviso nem e-mail.');
        self::assertGreaterThan(0, $owner);
        self::assertGreaterThan(0, $vehicle + $pet);
    }

    public function testRefusalRollsBackAndWarnings(): void
    {
        $this->resident('owner', 'Ana Proprietária', '2020-01-01');
        $tenant = $this->resident('tenant', 'Bruno Inquilino', '2026-11-05');
        $other = $this->resident('resident', 'Carla Moradora', '2025-03-01');
        try {
            (new UnitMoveOut($this->pdo))->moveOut($this->admin, ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'ends_on' => '2026-11-01',
                'reason' => 'Saída registrada com atraso'], self::TODAY);
            self::fail('Fim antes do início passou.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('ends_on', $exception->errors, json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
            self::assertSame(['residents', (string)$tenant], [$exception->errors['_registry'] ?? null, $exception->errors['_record'] ?? null]);
        }
        self::assertSame(['active', 'active', 'active'], $this->statuses('erp_residents'), 'Tudo ou nada: a moradora também continua.');
        self::assertGreaterThan(0, $other);

        $result = (new UnitMoveOut($this->pdo))->moveOut($this->admin, ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'ends_on' => '2026-11-10',
            'relationships' => ['owner', 'tenant', 'resident'], 'reason' => 'Unidade vendida e desocupada', 'dry_run' => true], self::TODAY);
        self::assertSame(['unit_without_residents'], array_column($result['warnings'], 'code'));
    }

    /** @return list<string> */
    private function statuses(string $table): array
    {
        return array_map('strval', $this->pdo->query("SELECT status FROM {$table} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function resident(string $relationship, string $name, string $startsOn): int
    {
        return $this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'relationship' => $relationship,
            'name' => $name, 'starts_on' => $startsOn])['id'];
    }
}
