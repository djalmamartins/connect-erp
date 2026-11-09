<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;
use Source\Services\Erp\Registry\RegistryService;

/** GET /api/v1/bank-returns: arquivos importados com contagens, paginação, filtros e escopo (ADR 0035). */
final class BankReturnFilesIntegrationTest extends TestCase
{
    public function testListsFilesNewestFirstWithCountsAndScope(): void
    {
        $alpha = $this->condominium('Alpha');
        $beta = $this->condominium('Beta');
        $admin = $this->user([$alpha, $beta], true);
        $service = new RegistryService($this->pdo);
        $service->save($admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $service->save($admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $bank = fn (int $condominium, string $account) => $service->save($admin, 'bank_accounts', ['condominium_id' => $condominium, 'name' => 'Movimento',
            'bank_code' => '001', 'branch' => '1234', 'account_number' => $account, 'kind' => 'corrente'])['id'];
        $alphaBank = $bank($alpha, '1111-1');
        $betaBank = $bank($beta, '2222-2');
        $entry = $service->save($admin, 'entries', ['condominium_id' => $alpha, 'nature' => 'receita', 'chart_account_id' => $fee, 'amount_cents' => '500,00',
            'competence' => '2026-11', 'description' => 'Taxa', 'due_on' => '2026-11-10'])['id'];
        $charge = (new BankCharges($this->pdo))->issue($admin, $entry, $alphaBank);

        $returns = new BankReturns($this->pdo);
        $returns->process($admin, $alphaBank, 'r1.txt', SandboxBankAdapter::buildReturn([
            ['event_key' => 'E1', 'kind' => 'paid', 'our_number' => $charge['our_number'], 'amount_cents' => 50000, 'occurred_on' => '2026-11-09'],
            ['event_key' => 'E2', 'kind' => 'paid', 'our_number' => '0000009999', 'amount_cents' => 100, 'occurred_on' => '2026-11-09'],
        ]));
        $returns->process($admin, $betaBank, 'r2.txt', SandboxBankAdapter::buildReturn([]));

        $all = $returns->files($admin);
        self::assertSame(2, $all['total']);
        $byName = array_column($all['data'], null, 'filename');
        self::assertSame([2, 1, 1, 0], [$byName['r1.txt']['events_count'], $byName['r1.txt']['applied_count'], $byName['r1.txt']['divergence_count'], $byName['r1.txt']['other_count']]);
        self::assertSame(64, strlen($byName['r1.txt']['sha256']));
        self::assertSame(['Alpha', 'Movimento'], [$byName['r1.txt']['condominium_name'], $byName['r1.txt']['bank_account_name']]);
        self::assertSame(1, $returns->files($admin, $alpha)['total'], 'Filtro por condomínio.');
        self::assertSame(['r2.txt'], array_column($returns->files($admin, null, ['bank_account_id' => $betaBank])['data'], 'filename'), 'Filtro por conta bancária.');
        self::assertSame([], $returns->files($admin, null, ['page' => 2])['data'], 'Página além do total.');

        $outsider = $this->user([$beta]);
        self::assertSame(['r2.txt'], array_column($returns->files($outsider)['data'], 'filename'), 'Só o escopo do usuário.');
        self::assertSame(0, $returns->files($outsider, $alpha)['total'], 'Condomínio fora do escopo = vazio.');
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
