<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DelinquencyByLocation;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0222: inadimplência agrupada por bloco e por andar pelo Agreements::delinquency; somente leitura e sem morador. */
final class DelinquencyByLocationTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $account;
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
        $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->account = $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica',
            'name' => 'Taxa condominial'])['id'];
        foreach ([['A', '101', '1'], ['A', '102', '1'], ['A', '103', '2'], ['B', '201', '2'], ['B', '202', '2']] as [$block, $number, $floor]) {
            $this->units[$block . $number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'block' => $block, 'number' => $number,
                'floor' => $floor, 'type' => 'apartamento'])['id'];
        }
    }

    public function testGroupsByBlockAndFloor(): void
    {
        $this->receivable('A101', '100,00', '2026-09-10');
        $this->receivable('A102', '50,00', '2026-10-20');
        $this->receivable('B201', '300,00', '2026-10-01');
        $this->receivable('B202', '80,00', '2026-10-01', '2026-10-01');            // paga
        $this->receivable(null, '10,00', '2026-10-01');                             // sem unidade
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $blocks = (new DelinquencyByLocation($this->pdo))->build($this->admin, $this->condominium, 'block', '2026-11-10');
        self::assertSame([5, 3], [$blocks['units_total'], $blocks['units_late']]);
        self::assertSame([['B', 2, 1, 50.0, 30000], ['A', 3, 2, 66.7, 15000], ['Sem unidade', 0, 1, null, 1000]],
            array_map(static fn (array $r): array => [$r['group'], $r['units_total'], $r['units_late'], $r['rate_pct'], $r['amount_cents']], $blocks['rows']));
        self::assertSame(['1-30' => 1, '31-60' => 0, '61-90' => 1, '90+' => 0], $blocks['rows'][1]['units_by_bucket']);
        self::assertSame(46000, $blocks['totals']['amount_cents']);

        $floors = (new DelinquencyByLocation($this->pdo))->build($this->admin, $this->condominium, 'floor', '2026-11-10');
        $byFloor = array_column($floors['rows'], null, 'group');
        self::assertSame([[3, 1, 33.3], [2, 2, 100.0]], [[$byFloor['2']['units_total'], $byFloor['2']['units_late'], $byFloor['2']['rate_pct']],
            [$byFloor['1']['units_total'], $byFloor['1']['units_late'], $byFloor['1']['rate_pct']]]);
        $json = (string)json_encode([$blocks, $floors]);
        foreach (['unit_label', 'description', 'Taxa condominial', 'resident'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem unidade nem lançamento identificados: ' . $needle);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new DelinquencyByLocation($this->pdo))->build($limited, $this->condominium, 'block');
            self::fail('Consultou condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function receivable(?string $unit, string $amount, string $dueOn, ?string $paidOn = null): void
    {
        $data = ['condominium_id' => $this->condominium, 'nature' => 'receita', 'chart_account_id' => $this->account, 'amount_cents' => $amount,
            'competence' => substr($dueOn, 0, 7), 'description' => 'Taxa condominial', 'due_on' => $dueOn];
        if ($unit !== null) {
            $data['unit_id'] = $this->units[$unit];
        }
        if ($paidOn !== null) {
            $data['paid_on'] = $paidOn;
            $bank = $this->registry->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium, 'name' => 'Movimento', 'bank_code' => '001',
                'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
            $data['bank_account_id'] = $bank;
        }
        $this->registry->save($this->admin, 'entries', $data);
    }
}
