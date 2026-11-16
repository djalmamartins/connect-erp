<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryUnits;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Lançamento para várias unidades num único cadastro (ADR 0313, revisão de 15/11 item 15). */
final class EntryUnitsTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $user;
    private RegistryService $service;
    private int $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->user = $this->user([$this->alpha, $this->beta]);
        $this->service = new RegistryService($this->pdo);
        $this->pdo->exec("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES ('1.09', 'Controle do portão', 'receita', 'analitica')");
        $this->fee = (int)$this->pdo->lastInsertId();
    }

    public function testOneEntryPerUnitWithEachOrSplitAmount(): void
    {
        [$u01, $u303, $u404] = [$this->unit($this->alpha, '01'), $this->unit($this->alpha, '303'), $this->unit($this->alpha, '404')];
        $change = new EntryUnits($this->pdo, null, $this->service);

        $saved = $change->create($this->user, 'receivables', $this->input(['unit_id' => (string)$u01, 'extra_unit_ids' => [(string)$u303, (string)$u01, 'x'], 'amount_cents' => '85,00']));
        self::assertCount(2, $saved, 'A principal repetida e o valor inválido saem da lista.');
        $rows = $this->pdo->query("SELECT unit_id, amount_cents, nature, description FROM erp_entries ORDER BY id")->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([[$u01, 8500, 'receita', 'Controle do portão'], [$u303, 8500, 'receita', 'Controle do portão']],
            array_map(static fn (array $r): array => [(int)$r[0], (int)$r[1], $r[2], $r[3]], $rows));
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_entries' AND action = 'create'")->fetchColumn(), 'Cada um auditado.');

        $split = $change->create($this->user, 'entries', $this->input(['nature' => 'receita', 'unit_id' => (string)$u01, 'extra_unit_ids' => [(string)$u303, (string)$u404],
            'amount_cents' => '100,00', 'unit_amount_mode' => 'split']));
        self::assertSame([3334, 3333, 3333], array_map(fn (array $r): int => (int)$this->pdo->query('SELECT amount_cents FROM erp_entries WHERE id = ' . (int)$r['id'])->fetchColumn(), $split),
            'Total dividido; o centavo que sobra fica com a primeira.');
        self::assertSame([3334, 3333, 3333], EntryUnits::amounts(10000, 3, 'split'));
        self::assertSame([500, 500], EntryUnits::amounts(500, 2, 'each'));
        self::assertTrue(EntryUnits::applies('payables', ['extra_unit_ids' => ['5']], null));
        self::assertFalse(EntryUnits::applies('payables', ['extra_unit_ids' => ['5']], 9), 'Editar não multiplica.');
        self::assertFalse(EntryUnits::applies('suppliers', ['extra_unit_ids' => ['5']], null));
    }

    public function testRefusalsSaveNothing(): void
    {
        $u01 = $this->unit($this->alpha, '01');
        $u303 = $this->unit($this->alpha, '303');
        $other = $this->unit($this->beta, '101');
        $change = new EntryUnits($this->pdo, null, $this->service);

        $this->assertInvalid('extra_unit_ids', fn () => $change->create($this->user, 'receivables', $this->input(['unit_id' => (string)$u01, 'extra_unit_ids' => [(string)$u303, (string)$other]])));
        $this->assertInvalid('unit_id', fn () => $change->create($this->user, 'receivables', $this->input(['unit_id' => '', 'extra_unit_ids' => [(string)$u303]])));
        $this->assertInvalid('amount_cents', fn () => $change->create($this->user, 'receivables', $this->input(['unit_id' => (string)$u01, 'extra_unit_ids' => [(string)$u303],
            'amount_cents' => '0,01', 'unit_amount_mode' => 'split'])));
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2026-11', 'closed')")->execute([$this->alpha]);
        $this->assertInvalid('competence', fn () => $change->create($this->user, 'receivables', $this->input(['unit_id' => (string)$u01, 'extra_unit_ids' => [(string)$u303]])));
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn(), 'Tudo ou nada: a unidade de outro condomínio desfaz a primeira.');
    }

    private function input(array $over): array
    {
        return $over + ['condominium_id' => (string)$this->alpha, 'chart_account_id' => (string)$this->fee, 'description' => 'Controle do portão', 'amount_cents' => '85,00',
            'competence' => '2026-11', 'due_on' => '2026-11-10', 'status' => 'active'];
    }

    private function unit(int $condominium, string $number): int
    {
        return (int)$this->service->save($this->user, 'units', ['condominium_id' => $condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
    }

    private function assertInvalid(string $field, callable $call): void
    {
        try {
            $call();
            self::fail('Aceitou dado inválido em ' . $field . '.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors);
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
