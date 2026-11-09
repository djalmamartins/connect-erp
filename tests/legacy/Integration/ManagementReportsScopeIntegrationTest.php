<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ManagementReports;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Relatórios gerenciais (ADR 0082 e 0068) com dois condomínios: cada relatório de um usuário do Beta
 * traz só o Beta, e a conta bancária de outro condomínio responde como a conta inexistente.
 */
final class ManagementReportsScopeIntegrationTest extends TestCase
{
    public function testEveryReportStaysInScope(): void
    {
        $ids = [];
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
            $ids[$name] = (int)$this->pdo->lastInsertId();
        }
        $admin = $this->user(array_values($ids), true);
        $service = new RegistryService($this->pdo);
        $service->save($admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $expense = $service->save($admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $banks = [];
        $month = date('Y-m');
        $today = date('Y-m-d');
        foreach ($ids as $name => $condominium) {
            $service->save($admin, 'units', ['condominium_id' => $condominium, 'number' => $name === 'Alpha' ? '101' : '201', 'type' => 'apartamento', 'ideal_fraction' => '1,000000']);
            $banks[$name] = $service->save($admin, 'bank_accounts', ['condominium_id' => $condominium, 'name' => "Movimento {$name}", 'bank_code' => '001', 'branch' => '1234',
                'account_number' => $name === 'Alpha' ? '5678-9' : '1111-1', 'kind' => 'corrente'])['id'];
            $service->save($admin, 'entries', ['condominium_id' => $condominium, 'nature' => 'despesa', 'chart_account_id' => $expense, 'description' => "Limpeza {$name}",
                'amount_cents' => $name === 'Alpha' ? '100,00' : '40,00', 'competence' => $month, 'due_on' => $today, 'paid_on' => $today, 'bank_account_id' => $banks[$name]]);
        }
        $reports = new ManagementReports($this->pdo);
        $beta = $this->user([$ids['Beta']]);
        $only = static fn (array $rows): array => array_values(array_unique(array_map('intval', array_column($rows, 'condominium_id'))));

        $names = static fn (array $rows): array => array_values(array_unique(array_column($rows, 'condominium_name')));
        self::assertSame(['Alpha', 'Beta'], $names($reports->entries($admin, null, $month, $month)['rows']), 'O administrador vê os dois.');
        self::assertSame(['Beta'], $names($reports->entries($beta, null, $month, $month, [], true)['rows']));
        self::assertSame([$ids['Beta']], $only($reports->costCenters($beta, null, $month, $month)['rows']));
        foreach (['accounts', 'analytic', 'situation'] as $view) {
            $statement = $reports->statement($beta, null, $month, $month, $view);
            self::assertSame(4000, $statement['totals']['despesa_cents'], "Demonstrativo {$view} só com o Beta.");
        }
        self::assertSame([4000], array_column($reports->comparison($beta, null, $month, $month, $month, $month)['rows'], 'a_cents'), 'Comparativo só com o Beta.');
        self::assertSame(4000, $reports->apportionment($beta, $ids['Beta'], $month, 'unit')['totals']['amount_cents']);
        self::assertSame(-4000, $reports->accountStatement($beta, $banks['Beta'], $today, $today)['totals']['closing_cents']);
        self::assertSame([], $reports->consumption($beta, null, $month, $month, true)['rows']);
        self::assertSame([], $reports->bankCharges($beta, null, $today, $today)['rows']);

        foreach ([
            'conta do Alpha' => fn () => $reports->accountStatement($beta, $banks['Alpha'], $today, $today),
            'conta inexistente' => fn () => $reports->accountStatement($beta, 999999, $today, $today),
            'rateio do Alpha' => fn () => $reports->apportionment($beta, $ids['Alpha'], $month, 'entries'),
            'lançamentos do Alpha' => fn () => $reports->entries($beta, $ids['Alpha'], $month, $month),
            'demonstrativo do Alpha' => fn () => $reports->statement($beta, $ids['Alpha'], $month, $month, 'analytic'),
        ] as $label => $call) {
            try {
                $call();
                self::fail("{$label}: alcançou dado de fora.");
            } catch (CondominiumAccessDenied) {
                self::addToAssertionCount(1);
            }
        }
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
