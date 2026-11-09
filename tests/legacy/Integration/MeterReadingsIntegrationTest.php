<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MeterReadings;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Relógios, tarifas e leituras: consumo, faixas vigentes, correção, cobrança sem duplicar e escopo (ADR 0045). */
final class MeterReadingsIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private MeterReadings $readings;
    private int $meter;
    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->readings = new MeterReadings($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->account = $this->service->save($this->admin, 'chart', ['code' => '1.05', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Água individual'])['id'];
        $unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->meter = $this->service->save($this->admin, 'meters', ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'kind' => 'agua',
            'number' => 'H-101', 'initial_reading' => '100,000'])['id'];
        // Padrão da Connect: 0–10 m³ a R$ 5,00; acima de 10 a R$ 8,00.
        foreach ([['Faixa 1', '0', '10', '5,00'], ['Faixa 2', '10', '', '8,00']] as [$name, $from, $to, $price]) {
            $this->service->save($this->admin, 'tariffs', ['kind' => 'agua', 'name' => $name, 'from_amount' => $from, 'to_amount' => $to,
                'price_cents' => $price, 'valid_from' => '2026-01-01']);
        }
    }

    public function testConsumptionChargeCorrectionAndBilling(): void
    {
        $first = $this->readings->record($this->admin, ['meter_id' => $this->meter, 'competence' => '2026-10', 'reading' => '112,000', 'read_on' => '2026-10-31']);
        self::assertSame([100.0, 12.0, 6600], [$first['previous_reading'], $first['consumption'], $first['amount_cents']], '10×5 + 2×8 = 66,00');
        $fixed = $this->readings->record($this->admin, ['meter_id' => $this->meter, 'competence' => '2026-10', 'reading' => '108', 'read_on' => '2026-10-31']);
        self::assertSame([$first['id'], 4000], [$fixed['id'], $fixed['amount_cents']], 'Corrige a mesma competência.');

        $november = $this->readings->record($this->admin, ['meter_id' => $this->meter, 'competence' => '2026-11', 'reading' => '113', 'read_on' => '2026-11-09']);
        self::assertSame([108.0, 5.0], [$november['previous_reading'], $november['consumption']]);
        foreach ([
            [['competence' => '2026-12', 'reading' => '110'], InvalidArgumentException::class],     // menor que a anterior
            [['competence' => '2026-10', 'reading' => '109'], DomainException::class],              // há leitura posterior
            [['competence' => '2026-13', 'reading' => '120'], InvalidArgumentException::class],
            [['competence' => '2026-12', 'reading' => '120', 'read_on' => '2099-01-01'], InvalidArgumentException::class],
        ] as [$input, $expected]) {
            try {
                $this->readings->record($this->admin, $input + ['meter_id' => $this->meter, 'read_on' => '2026-11-09']);
                self::fail('Leitura indevida aceita: ' . json_encode($input));
            } catch (\Throwable $error) {
                self::assertInstanceOf($expected, $error);
            }
        }

        $bill = ['condominium_id' => $this->alpha, 'competence' => '2026-11', 'kind' => 'agua', 'chart_account_id' => $this->account, 'due_on' => '2026-12-10'];
        $result = $this->readings->bill($this->admin, $bill);
        self::assertCount(1, $result['created']);
        self::assertSame(2500, $this->service->find($this->admin, 'entries', $result['created'][0])['amount_cents']);
        self::assertSame([], $this->readings->bill($this->admin, $bill)['created'], 'Cobrar de novo não duplica.');
        $this->expectException(DomainException::class);
        $this->readings->record($this->admin, ['meter_id' => $this->meter, 'competence' => '2026-11', 'reading' => '114', 'read_on' => '2026-11-09']);
    }

    public function testCondominiumTariffReplacesDefaultAndScope(): void
    {
        $this->service->save($this->admin, 'tariffs', ['condominium_id' => $this->alpha, 'kind' => 'agua', 'name' => 'Única', 'from_amount' => '0',
            'price_cents' => '3,00', 'valid_from' => '2026-06-01']);
        $reading = $this->readings->record($this->admin, ['meter_id' => $this->meter, 'competence' => '2026-11', 'reading' => '120', 'read_on' => '2026-11-09']);
        self::assertSame([6000, '2026-06-01'], [$reading['amount_cents'], $reading['tariff_valid_from']], '20 m³ × 3,00 pela tarifa do condomínio.');
        self::assertSame(6000, $this->readings->list($this->admin, $this->alpha, ['competence' => '2026-11'])['totals']['amount_cents']);

        $outsider = $this->user([$this->beta]);
        self::assertSame(0, $this->readings->list($outsider)['total']);
        $this->expectException(RegistryNotFound::class);
        $this->readings->record($outsider, ['meter_id' => $this->meter, 'competence' => '2026-12', 'reading' => '130']);
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
