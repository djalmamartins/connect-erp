<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\RecurringClosing;
use Source\Services\Erp\RecurringEntries;
use Source\Services\Erp\RecurringPortfolio;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0272: carteira de lançamentos fixos com os cadastros e a geração; somente leitura, sem descrição e igual ao encerramento em lote. */
final class RecurringPortfolioTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        // Acesso a todos os condomínios, como no RecurringReadjustmentTest: o plano de contas padrão (sem condomínio) exige.
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        $this->admin = (new User())->findById($id);
        $this->registry->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->account = (int)$this->registry->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Portaria'])['id'];
    }

    public function testPortfolioFromTheRegistryAndTheGeneration(): void
    {
        $now = date('Y-m');
        $this->template('Portaria de teste', '1000,00', null);
        $this->template('Limpeza de teste', '500,00', RecurringPortfolio::addMonths($now, 1));
        $this->template('Elevador de teste', '300,00', RecurringPortfolio::addMonths($now, -2));
        (new RecurringEntries($this->pdo))->generate($this->admin, $now, $this->condominium);
        $this->template('Jardim de teste', '200,00', null);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new RecurringPortfolio($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['running' => 2, 'ending_soon' => 1, 'ended_active' => 1, 'future' => 0, 'inactive' => 0],
            array_combine(array_column($report['rows'], 'situation'), array_column($report['rows'], 'templates')));
        self::assertSame(['templates' => 4, 'receita_cents' => 0, 'despesa_cents' => 200000], $report['totals']);
        self::assertSame(['in_force' => 3, 'generated' => 2, 'pending' => 1], $report['generation'], 'O Jardim, criado depois da geração, falta gerar.');
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Portaria de teste', 'Elevador de teste', 'description'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $preview = (new RecurringClosing($this->pdo))->close($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Contratos encerrados', 'dry_run' => true]);
        self::assertSame($report['rows'][2]['templates'], $preview['count'], 'O relatório mostra o que o encerramento em lote pegaria.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new RecurringPortfolio($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function template(string $description, string $amount, ?string $ends): int
    {
        return (int)$this->registry->save($this->admin, 'recurring_entries', ['condominium_id' => $this->condominium, 'nature' => 'despesa', 'description' => $description,
            'amount_cents' => $amount, 'chart_account_id' => $this->account, 'day_of_month' => '10',
            'starts_competence' => RecurringPortfolio::addMonths(date('Y-m'), -12), 'ends_competence' => $ends])['id'];
    }
}
