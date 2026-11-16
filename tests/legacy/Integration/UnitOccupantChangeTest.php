<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitOccupantChange;

/** Troca de inquilino/proprietário com datas e faturas (ADR 0311, modelo 6). */
final class UnitOccupantChangeTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $user;
    private RegistryService $service;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->user = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->unit = (int)$this->service->save($this->user, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testTenantChangeWithHistoryMovesInvoicesOnlyWhenTenantPays(): void
    {
        $old = $this->link('tenant', 'Inquilino Antigo', '52998224725');
        $owner = $this->link('owner', 'Dona da Unidade', null);
        $entries = $this->entries(['2026-10', '2026-11', '2026-12']);
        $change = new UnitOccupantChange($this->pdo);
        $today = new \DateTimeImmutable('2026-11-20');

        // O proprietário paga (responsável não definido): a troca do inquilino não mexe nas faturas.
        $result = $change->apply($this->user, $this->unit, ['role' => 'tenant', 'person' => ['name' => 'Novo Inquilino', 'document' => '111.444.777-35', 'email' => 'novo@example.test'],
            'exit_on' => '2026-11-15', 'entry_on' => '2026-11-16', 'invoices' => 'current_and_next'], $today);
        self::assertSame(['not_payer', [$old]], [$result['invoices'], $result['ended']]);
        $row = $this->pdo->query("SELECT ends_on, status FROM erp_residents WHERE id = {$old}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['2026-11-15', 'inactive'], [(string)$row['ends_on'], (string)$row['status']]);
        $new = $this->pdo->query("SELECT relationship, name, document, starts_on, status FROM erp_residents WHERE id = {$result['resident_id']}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['tenant', 'Novo Inquilino', '11144477735', '2026-11-16', 'active'], array_map('strval', array_values($new)));
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries WHERE payer_resident_id IS NOT NULL')->fetchColumn());
        self::assertSame(['owner' => 1, 'tenant' => 1], array_map('count', array_intersect_key($change->current($this->user, $this->unit), ['tenant' => 1, 'owner' => 1])));

        // Inquilino paga: mês da entrada e seguintes vão para o novo; o anterior fica com o antigo; boleto ativo não muda.
        $this->pdo->exec("UPDATE erp_units SET billing_responsible = 'inquilino' WHERE id = {$this->unit}");
        $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number) VALUES (?, 'Conta', '077', '0001', '1')")->execute([$this->alpha]);
        $bank = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on) VALUES (?, ?, ?, 'fake', 'registered', '1', 1000, '2026-12-10')")
            ->execute([$this->alpha, $bank, $entries['2026-12']]);
        $current = $result['resident_id'];
        $third = $change->apply($this->user, $this->unit, ['role' => 'tenant', 'person' => ['name' => 'Terceiro Inquilino'], 'exit_on' => '2026-11-18', 'entry_on' => '2026-11-18',
            'invoices' => 'current_and_next'], $today);
        self::assertSame(['current_and_next', 1, 1, 1], [$third['invoices'], $third['moved'], $third['kept'], $third['with_boleto']]);
        $payers = $this->pdo->query("SELECT competence, payer_resident_id FROM erp_entries WHERE unit_id = {$this->unit} ORDER BY competence")->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame(['2026-10' => (string)$current, '2026-11' => (string)$third['resident_id'], '2026-12' => null], array_map(static fn ($v) => $v === null ? null : (string)$v, $payers));
        self::assertGreaterThan(0, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_units' AND entity_id = {$this->unit} AND context_json LIKE '%_invoices%'")->fetchColumn());
        self::assertSame('active', $this->pdo->query("SELECT status FROM erp_residents WHERE id = {$owner}")->fetchColumn(), 'Proprietário não muda.');
    }

    public function testOwnerChangeWithoutHistoryExistingPersonAndRefusals(): void
    {
        $old = $this->link('owner', 'Dono Antigo', null);
        $entries = $this->entries(['2026-09', '2026-11']);
        $other = (int)$this->service->save($this->user, 'units', ['condominium_id' => $this->alpha, 'number' => '102', 'type' => 'apartamento'])['id'];
        $source = (int)$this->service->save($this->user, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $other, 'relationship' => 'owner', 'name' => 'Maria Souza',
            'document' => '529.982.247-25', 'email' => 'maria@example.test'])['id'];
        $change = new UnitOccupantChange($this->pdo);

        $result = $change->apply($this->user, $this->unit, ['role' => 'owner', 'person_id' => $source, 'history' => false, 'invoices' => 'all_unbilled'], new \DateTimeImmutable('2026-11-20'));
        self::assertSame(['all_unbilled', 2, 0], [$result['invoices'], $result['moved'], $result['kept']]);
        $row = $this->pdo->query("SELECT ends_on, status FROM erp_residents WHERE id = {$old}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([null, 'inactive'], [$row['ends_on'], (string)$row['status']], 'Sem histórico: só inativa.');
        $new = $this->pdo->query("SELECT name, email, starts_on FROM erp_residents WHERE id = {$result['resident_id']}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['Maria Souza', 'maria@example.test', null], [$new['name'], $new['email'], $new['starts_on']]);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_entries WHERE payer_resident_id = {$result['resident_id']}")->fetchColumn());
        unset($entries);

        $this->assertInvalid('person_id', fn () => $change->apply($this->user, $this->unit, ['role' => 'owner', 'person_id' => $source, 'history' => false, 'invoices' => 'none']));
        $this->assertInvalid('person.document', fn () => $change->apply($this->user, $this->unit, ['role' => 'tenant', 'person' => ['name' => 'Outro Nome', 'document' => '52998224725'], 'history' => false, 'invoices' => 'none']));
        $this->assertInvalid('entry_on', fn () => $change->apply($this->user, $this->unit, ['role' => 'tenant', 'person' => ['name' => 'Fulano'], 'exit_on' => '2026-11-10', 'entry_on' => '2026-11-01', 'invoices' => 'none']));
        $this->assertInvalid('invoices', fn () => $change->apply($this->user, $this->unit, ['role' => 'tenant', 'person' => ['name' => 'Fulano'], 'history' => false]));
        $this->assertInvalid('person.name', fn () => $change->apply($this->user, $this->unit, ['role' => 'tenant', 'person' => ['name' => ''], 'history' => false, 'invoices' => 'none']));
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_residents')->fetchColumn();

        $outsider = $this->user([$this->beta]);
        try {
            $change->apply($outsider, $this->unit, ['role' => 'tenant', 'person' => ['name' => 'Fulano'], 'history' => false, 'invoices' => 'none']);
            self::fail('Trocou em unidade fora do escopo.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
        self::assertSame($count, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_residents')->fetchColumn(), 'Recusa não grava nada.');
    }

    private function link(string $role, string $name, ?string $document): int
    {
        return (int)$this->service->save($this->user, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'relationship' => $role, 'name' => $name,
            'document' => $document, 'starts_on' => '2025-01-01'])['id'];
    }

    /** @return array<string, int> */
    private function entries(array $competences): array
    {
        $ids = [];
        $chart = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = 'T.1'")->fetchColumn();
        if ($chart === 0) {
            $this->pdo->exec("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES ('T.1', 'Taxa', 'receita', 'analitica')");
            $chart = (int)$this->pdo->lastInsertId();
        }
        foreach ($competences as $competence) {
            $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, unit_id, status) VALUES (?, 'receita', ?, 'Taxa', 1000, ?, ?, ?, 'active')")
                ->execute([$this->alpha, $chart, $competence, $competence . '-10', $this->unit]);
            $ids[$competence] = (int)$this->pdo->lastInsertId();
        }
        return $ids;
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
