<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Centros de custo (CN-002) e contas bancárias (CN-003): escopo, regras e referência (ADR 0021). */
final class CostCenterBankAccountTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
    }

    public function testCostCentersStandardAndPerCondominium(): void
    {
        $local = $this->user([$this->alpha]);
        $this->assertInvalid('condominium_id', fn () => $this->center($local, null, 'ADM', 'Administração'), 'Padrão exige acesso global.');
        $this->center($this->admin, null, 'ADM', 'Administração');
        $this->center($local, $this->alpha, 'OBRA-B', 'Obra do bloco B');
        $this->assertInvalid('code', fn () => $this->center($this->admin, null, 'ADM', 'Repetido'));
        $this->center($this->admin, $this->beta, 'OBRA-B', 'Obra Beta');

        $codes = array_column($this->service->list($local, 'cost_centers')['data'], 'code');
        self::assertSame(['ADM', 'OBRA-B'], $codes, 'Alpha vê o padrão e o próprio, não o de Beta.');
    }

    public function testOneMainAccountPerCondominiumAndNoDuplicates(): void
    {
        $first = $this->account($this->alpha, '12345-6', true);
        self::assertTrue($first['is_main']);
        $this->assertInvalid('is_main', fn () => $this->account($this->alpha, '22222-2', true), 'Segunda principal ativa.');
        self::assertFalse($this->account($this->alpha, '22222-2', false)['is_main']);
        self::assertTrue($this->account($this->beta, '33333-3', true)['is_main'], 'Outro condomínio tem a própria principal.');
        $this->assertInvalid('account_number', fn () => $this->account($this->alpha, '12345-6', false), 'Mesma conta no mesmo condomínio.');
        self::assertSame($this->beta, $this->account($this->beta, '12345-6', false)['condominium_id'], 'Mesma conta pode existir em outro condomínio.');

        $outsider = $this->user([$this->beta]);
        self::assertSame(2, $this->service->list($outsider, 'bank_accounts')['total'], 'Beta só vê as próprias contas (33333-3 e 12345-6).');
    }

    public function testDefaultCostCenterMustBeVisibleToTheAccountCondominium(): void
    {
        $standard = $this->center($this->admin, null, 'ADM', 'Administração');
        $betaOnly = $this->center($this->admin, $this->beta, 'B1', 'Só Beta');

        $account = $this->account($this->alpha, '12345-6', false, $standard['id']);
        self::assertSame($standard['id'], $account['cost_center_id']);
        $this->assertInvalid('cost_center_id', fn () => $this->account($this->alpha, '99999-9', false, $betaOnly['id']), 'Centro de outro condomínio.');

        try {
            $this->service->delete($this->admin, 'cost_centers', $standard['id']);
            self::fail('Excluiu centro de custo usado por conta bancária ativa.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
        $this->service->delete($this->admin, 'bank_accounts', $account['id']);
        $this->service->delete($this->admin, 'cost_centers', $standard['id']);
        self::assertSame(1, $this->service->list($this->admin, 'cost_centers')['total']);
    }

    private function center(User $user, ?int $condominium, string $code, string $name): array
    {
        return $this->service->save($user, 'cost_centers', ['condominium_id' => $condominium, 'code' => $code, 'name' => $name]);
    }

    private function account(int $condominium, string $number, bool $main, ?int $costCenter = null): array
    {
        return $this->service->save($this->admin, 'bank_accounts', [
            'condominium_id' => $condominium, 'name' => 'Conta ' . $number, 'bank_code' => '341', 'branch' => '1234',
            'account_number' => $number, 'kind' => 'corrente', 'is_main' => $main ? '1' : '0', 'cost_center_id' => $costCenter,
        ]);
    }

    private function assertInvalid(string $field, callable $call, string $message = ''): void
    {
        try {
            $call();
            self::fail('Aceitou dado inválido. ' . $message);
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors, $message . ' ' . json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
        }
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
