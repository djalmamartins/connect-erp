<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitBulkSettings;

/** Revisão de 15/11, etapa 2 (ADR 0311): configuração em massa, CIB, isenção de rateio, CPF único e vínculo encerrado. */
final class UnitBulkSettingsTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
    }

    public function testAppliesOnlyChosenSettingsWithAuditAndDefaults(): void
    {
        $user = $this->user([$this->alpha]);
        $a = $this->unit($user, $this->alpha, '101');
        $b = $this->unit($user, $this->alpha, '102');
        $row = $this->strings($this->pdo->query("SELECT postage_cents, postage_payer, billing_responsible, bill_address, bill_email_to, due_day FROM erp_units WHERE id = {$a}")->fetch(\PDO::FETCH_ASSOC));
        self::assertSame(['0', 'ambos', 'nao_definido', 'predio', 'responsavel', null], array_values($row), 'Cadastro novo leva os padrões.');
        $this->pdo->exec("UPDATE erp_units SET due_day = 10 WHERE id = {$b}");

        $agency = $this->agency('Imobiliária Teste', 'active');
        $bulk = new UnitBulkSettings($this->pdo);
        $result = $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [$a, $b, (string)$a],
            'settings' => ['due_day' => '10', 'reads_water' => '1', 'postage_cents' => '2,50', 'bill_address' => 'correios', 'partner_agency_id' => $agency]]);
        self::assertSame(['updated' => 2, 'unchanged' => 0], $result);
        $rows = $this->pdo->query("SELECT id, due_day, reads_water, reads_gas, postage_cents, bill_address, partner_agency_id, billing_responsible FROM erp_units WHERE condominium_id = {$this->alpha} ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        foreach (array_map([$this, 'strings'], $rows) as $r) {
            self::assertSame(['10', '1', '0', '250', 'correios', (string)$agency, 'nao_definido'], [$r['due_day'], $r['reads_water'], $r['reads_gas'], $r['postage_cents'], $r['bill_address'], $r['partner_agency_id'], $r['billing_responsible']]);
        }
        $audit = (string)$this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'erp_units' AND action = 'update' AND entity_id = {$b} ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringContainsString('reads_water', $audit);
        self::assertStringNotContainsString('due_day', $audit, 'Só os campos que mudaram entram na auditoria.');

        $again = $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [$a, $b], 'settings' => ['reads_water' => '1', 'due_day' => null, 'partner_agency_id' => null]]);
        self::assertSame(['updated' => 2, 'unchanged' => 0], $again);
        self::assertSame([null, null], [$this->pdo->query("SELECT due_day FROM erp_units WHERE id = {$a}")->fetchColumn() ?: null, $this->pdo->query("SELECT partner_agency_id FROM erp_units WHERE id = {$a}")->fetchColumn() ?: null], 'Tirar o dia e a imobiliária.');
        self::assertSame(['updated' => 0, 'unchanged' => 2], $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [$a, $b], 'settings' => ['reads_water' => '1']]));
    }

    public function testRefusesOutsideScopeOtherCondominiumAndInvalidValuesWithoutWriting(): void
    {
        $user = $this->user([$this->alpha]);
        $admin = $this->user([$this->alpha, $this->beta]);
        $a = $this->unit($user, $this->alpha, '101');
        $other = $this->unit($admin, $this->beta, '201');
        $bulk = new UnitBulkSettings($this->pdo);

        try {
            $bulk->apply($user, ['condominium_id' => $this->beta, 'unit_ids' => [$other], 'settings' => ['reads_gas' => '1']]);
            self::fail('Gravou em condomínio fora do escopo.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
        $this->assertInvalid('unit_ids', fn () => $bulk->apply($admin, ['condominium_id' => $this->alpha, 'unit_ids' => [$a, $other], 'settings' => ['reads_gas' => '1']]));
        self::assertSame('0', (string)$this->pdo->query("SELECT reads_gas FROM erp_units WHERE id = {$a}")->fetchColumn(), 'Tudo ou nada.');
        $this->assertInvalid('unit_ids', fn () => $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [], 'settings' => ['reads_gas' => '1']]));
        $this->assertInvalid('settings', fn () => $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [$a], 'settings' => []]));
        $this->assertInvalid('due_day', fn () => $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [$a], 'settings' => ['due_day' => '32']]));
        $this->assertInvalid('postage_payer', fn () => $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [$a], 'settings' => ['postage_payer' => 'sindico']]));
        $this->assertInvalid('label', fn () => $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [$a], 'settings' => ['label' => 'X']]));
        $inactive = $this->agency('Inativa', 'inactive');
        $this->assertInvalid('partner_agency_id', fn () => $bulk->apply($user, ['condominium_id' => $this->alpha, 'unit_ids' => [$a], 'settings' => ['partner_agency_id' => $inactive]]));
        self::assertNotContains('Inativa', array_column($bulk->agencies(), 'name'), 'Só imobiliárias ativas.');
    }

    public function testExemptUnitStaysOutOfInvoicesAndFractionsAreNormalized(): void
    {
        $admin = $this->user([$this->alpha], true);
        $this->service->save($admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $type = $this->service->save($admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $fee, 'recurrence' => 'mensal', 'default_amount_cents' => '300,00'])['id'];
        $ids = [];
        foreach (['101', '102', '103'] as $number) {
            $ids[$number] = $this->unit($admin, $this->alpha, $number);
        }
        $this->pdo->exec("UPDATE erp_units SET ideal_fraction = 0.333333 WHERE condominium_id = {$this->alpha}");
        (new UnitBulkSettings($this->pdo))->apply($admin, ['condominium_id' => $this->alpha, 'unit_ids' => [$ids['103']], 'settings' => ['apportionment_exempt' => '1']]);

        $invoice = (new Invoices($this->pdo))->prepare($admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type, 'competence' => '2026-11', 'due_on' => '2026-11-10', 'method' => 'fraction', 'amount_cents' => '300,00']);
        $items = $this->pdo->query('SELECT unit_id, amount_cents FROM erp_invoice_items WHERE invoice_id = ' . (int)$invoice['id'] . ' ORDER BY unit_id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertArrayNotHasKey($ids['103'], $items, 'A isenta fica fora.');
        self::assertSame(30000, array_sum(array_map('intval', $items)), 'O total é dividido entre as outras.');
    }

    public function testCibIsFormattedAndUniqueAndPersonDocumentStaysWithOneName(): void
    {
        $user = $this->user([$this->alpha]);
        $a = $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento', 'cib' => 'abc1234-5'])['id'];
        self::assertSame('ABC12345', $this->pdo->query("SELECT cib FROM erp_units WHERE id = {$a}")->fetchColumn());
        $this->assertInvalid('cib', fn () => $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'number' => '102', 'type' => 'apartamento', 'cib' => 'ABC12345']));
        $this->assertInvalid('cib', fn () => $this->service->save($user, 'units', ['condominium_id' => $this->alpha, 'number' => '103', 'type' => 'apartamento', 'cib' => '123']));

        $person = ['condominium_id' => $this->alpha, 'unit_id' => $a, 'relationship' => 'owner', 'name' => 'Maria da Silva', 'document' => '529.982.247-25'];
        $this->service->save($user, 'residents', $person);
        $this->service->save($user, 'residents', ['relationship' => 'financial', 'name' => '  maria  DA silva '] + $person);
        $this->assertInvalid('document', fn () => $this->service->save($user, 'residents', ['name' => 'João Souza'] + $person));
        $this->service->save($user, 'residents', ['document' => null, 'name' => 'Sem documento'] + $person);

        $ended = $this->service->save($user, 'residents', ['relationship' => 'tenant', 'name' => 'Inquilino Antigo', 'document' => null, 'starts_on' => '2025-01-01', 'ends_on' => '2026-01-31', 'status' => 'active'] + $person)['id'];
        self::assertSame('inactive', $this->pdo->query("SELECT status FROM erp_residents WHERE id = {$ended}")->fetchColumn(), 'Vínculo encerrado vira inativo.');
    }

    private function unit(User $user, int $condominium, string $number): int
    {
        return (int)$this->service->save($user, 'units', ['condominium_id' => $condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
    }

    private function agency(string $name, string $status): int
    {
        $this->pdo->prepare('INSERT INTO erp_partner_agencies (name, document, status) VALUES (?, ?, ?)')->execute([$name, substr(str_pad((string)crc32($name), 14, '0', STR_PAD_LEFT), 0, 14), $status]);
        return (int)$this->pdo->lastInsertId();
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

    /** @return array<string, ?string> */
    private function strings(array $row): array
    {
        return array_map(static fn ($v): ?string => $v === null ? null : (string)$v, $row);
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
