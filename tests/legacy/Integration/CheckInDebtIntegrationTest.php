<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Assemblies;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0095: o check-in da assembleia só devolve o valor vencido com erp.agreements.view; o voto e o motivo, sempre. */
final class CheckInDebtIntegrationTest extends TestCase
{
    public function testCheckInHidesTheDebtUnlessAllowed(): void
    {
        foreach (['erp_assembly_votes', 'erp_assembly_attendance', 'erp_assembly_items', 'erp_assemblies'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $alpha = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $alpha);
        $admin = (new User())->findById($id);
        $registry = new RegistryService($this->pdo);
        $late = $registry->save($admin, 'units', ['condominium_id' => $alpha, 'number' => '401', 'type' => 'apartamento'])['id'];
        $other = $registry->save($admin, 'units', ['condominium_id' => $alpha, 'number' => '402', 'type' => 'apartamento'])['id'];
        $registry->save($admin, 'chart', ['condominium_id' => $alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $account = $registry->save($admin, 'chart', ['condominium_id' => $alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $due = date('Y-m-d', strtotime('-15 days'));
        $registry->save($admin, 'entries', ['condominium_id' => $alpha, 'nature' => 'receita', 'chart_account_id' => $account, 'unit_id' => $late,
            'description' => 'Taxa', 'amount_cents' => '250,00', 'competence' => substr($due, 0, 7), 'due_on' => $due]);

        $service = new Assemblies($this->pdo);
        $first = new \DateTimeImmutable('+10 days 19:00');
        $assembly = $service->create($admin, ['condominium_id' => $alpha, 'kind' => 'ordinaria', 'mode' => 'presencial', 'title' => 'AGO débito',
            'location' => 'Salão', 'first_call_at' => $first->format('Y-m-d H:i'), 'items' => [['title' => 'Contas', 'vote_basis' => 'unit', 'quorum' => 'simple']]])['id'];
        $service->convene($admin, $assembly);
        $at = $first->modify('+1 minute');
        $service->open($admin, $assembly, $at);

        $hidden = $service->checkIn($admin, $assembly, ['unit_id' => $late, 'attendee_name' => 'Hugo'], $at);
        self::assertSame([false, 'inadimplente', null], [$hidden['eligible'], $hidden['reason'], $hidden['overdue_cents']], 'Sem a permissão: voto e motivo, sem o valor.');

        $shown = $service->checkIn($admin, $assembly, ['unit_id' => $other, 'attendee_name' => 'Iara'], $at, true);
        self::assertSame([true, null, 0], [$shown['eligible'], $shown['reason'], $shown['overdue_cents']], 'Com a permissão, o valor volta (zero para a unidade quite).');
        self::assertSame(25000, $service->attendanceReport($admin, $assembly, true)['rows'][0]['overdue_cents'], 'O valor gravado aparece só com a permissão.');
    }
}
