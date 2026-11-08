<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Plano de contas (AP-160): padrão da Connect, contas por condomínio e hierarquia (ADR 0019). */
final class ChartOfAccountsTest extends TestCase
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

    public function testStandardChartNeedsGlobalAccessAndIsVisibleToEveryone(): void
    {
        $global = $this->user([], true);
        $local = $this->user([$this->alpha]);

        $this->assertInvalid('condominium_id', fn () => $this->account($local, null, '2', 'despesa', 'sintetica', 'Despesas'));
        $root = $this->account($global, null, '2', 'despesa', 'sintetica', 'Despesas');
        self::assertNull($root['condominium_id']);

        self::assertSame(1, $this->service->list($local, 'chart')['total'], 'Plano padrão aparece para quem só acessa um condomínio.');
        try {
            $this->service->save($local, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Outro nome'], $root['id']);
            self::fail('Usuário sem acesso global alterou conta do plano padrão.');
        } catch (RegistryForbidden) {
            self::addToAssertionCount(1);
        }
    }

    public function testHierarchyRequiresSyntheticParentWithSameNature(): void
    {
        $global = $this->user([$this->alpha], true);
        $this->account($global, null, '2', 'despesa', 'sintetica', 'Despesas');
        $this->account($global, null, '2.01', 'despesa', 'sintetica', 'Pessoal');
        $salary = $this->account($global, null, '2.01.001', 'despesa', 'analitica', 'Salários');
        self::assertSame('2.01.001', $salary['code']);

        $this->assertInvalid('code', fn () => $this->account($global, null, '3.01', 'receita', 'analitica', 'Sem pai'), 'Pai inexistente.');
        $this->assertInvalid('code', fn () => $this->account($global, null, '2.01.001.1', 'despesa', 'analitica', 'Filha de analítica'), 'Pai analítico.');
        $this->assertInvalid('nature', fn () => $this->account($global, null, '2.02', 'receita', 'analitica', 'Natureza trocada'), 'Natureza diferente do pai.');
        $this->assertInvalid('code', fn () => $this->account($global, null, '2.01', 'despesa', 'sintetica', 'Duplicada'), 'Código repetido no padrão.');
    }

    public function testCondominiumAccountsExtendTheStandardWithoutRepeatingCodes(): void
    {
        $global = $this->user([$this->alpha, $this->beta], true);
        $local = $this->user([$this->alpha]);
        $this->account($global, null, '1', 'receita', 'sintetica', 'Receitas');

        $own = $this->account($local, $this->alpha, '1.90', 'receita', 'analitica', 'Aluguel do salão');
        self::assertSame($this->alpha, $own['condominium_id']);
        $this->assertInvalid('code', fn () => $this->account($local, $this->alpha, '1', 'receita', 'sintetica', 'Repete o padrão'));
        $this->assertInvalid('code', fn () => $this->account($local, $this->alpha, '1.90', 'receita', 'analitica', 'Repetida'));
        $this->assertInvalid('condominium_id', fn () => $this->account($local, $this->beta, '1.91', 'receita', 'analitica', 'Fora do escopo'));

        $sameCodeElsewhere = $this->account($global, $this->beta, '1.90', 'receita', 'analitica', 'Taxa extra');
        self::assertSame($this->beta, $sameCodeElsewhere['condominium_id'], 'Outro condomínio pode usar o mesmo código próprio.');

        $other = $this->user([$this->beta]);
        $codes = array_column($this->service->list($other, 'chart')['data'], 'code');
        self::assertSame(['1', '1.90'], $codes, 'Beta vê o padrão e a própria conta, não a de Alpha.');
    }

    public function testAccountWithChildrenCannotBeDeleted(): void
    {
        $global = $this->user([$this->alpha], true);
        $parent = $this->account($global, null, '2', 'despesa', 'sintetica', 'Despesas');
        $child = $this->account($global, $this->alpha, '2.50', 'despesa', 'analitica', 'Manutenção local');

        try {
            $this->service->delete($global, 'chart', $parent['id']);
            self::fail('Excluiu conta do padrão com filha em condomínio.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
        $this->service->delete($global, 'chart', $child['id']);
        $this->service->delete($global, 'chart', $parent['id']);
        self::assertSame(0, $this->service->list($global, 'chart')['total']);
    }

    private function account(User $user, ?int $condominium, string $code, string $nature, string $kind, string $name): array
    {
        return $this->service->save($user, 'chart', ['condominium_id' => $condominium, 'code' => $code, 'nature' => $nature, 'kind' => $kind, 'name' => $name]);
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
