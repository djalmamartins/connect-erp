<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\RecurringEntries;
use Source\Services\Erp\Registry\RegistryService;

/** Geração dos lançamentos do mês a partir dos lançamentos fixos, sem duplicar (ADR 0027). */
final class RecurringEntriesTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private User $admin;
    private int $water;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->water = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Portaria'])['id'];
    }

    public function testGeneratesOpenEntriesOncePerCompetence(): void
    {
        $template = $this->template($this->alpha, '2026-11', null, 15);
        $generator = new RecurringEntries($this->pdo);

        $first = $generator->generate($this->admin, '2026-11');
        self::assertCount(1, $first['created']);
        $entry = $this->service->find($this->admin, 'entries', $first['created'][0]);
        self::assertSame('2026-11-15', $entry['due_on']);
        self::assertSame('2026-11', $entry['competence']);
        self::assertSame(850000, $entry['amount_cents']);
        self::assertNull($entry['paid_on'], 'Nasce em aberto.');
        self::assertSame($template, (int)$this->pdo->query('SELECT recurring_entry_id FROM erp_entries WHERE id = ' . (int)$entry['id'])->fetchColumn());

        $again = $generator->generate($this->admin, '2026-11');
        self::assertSame([], $again['created']);
        self::assertSame([$template], $again['skipped']);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn(), 'Repetir não duplica.');

        $this->service->delete($this->admin, 'entries', $entry['id']);
        self::assertSame([$template], $generator->generate($this->admin, '2026-11')['skipped'], 'Excluído não é recriado.');
        self::assertCount(1, $generator->generate($this->admin, '2026-12')['created'], 'Próxima competência gera normalmente.');
    }

    public function testRespectsValidityScopeAndStatus(): void
    {
        $this->template($this->alpha, '2026-12', null, 5);
        $this->template($this->alpha, '2026-01', '2026-10', 5);
        $inactive = $this->template($this->alpha, '2026-01', null, 5);
        $this->pdo->exec("UPDATE erp_recurring_entries SET status = 'inactive' WHERE id = {$inactive}");
        $this->template($this->beta, '2026-01', null, 5);

        $generator = new RecurringEntries($this->pdo);
        $local = $this->user([$this->alpha]);
        self::assertSame([], $generator->generate($local, '2026-11')['created'], 'Alpha: um modelo começa depois, outro já terminou, outro está inativo.');
        self::assertCount(1, $generator->generate($this->admin, '2026-11', $this->beta)['created']);
        self::assertSame([], $generator->generate($local, '2026-11', $this->beta)['created'], 'Condomínio fora do escopo é ignorado.');
    }

    public function testTemplateWithInvalidAccountFailsWithoutStoppingOthers(): void
    {
        $broken = $this->template($this->alpha, '2026-11', null, 10);
        $ok = $this->template($this->beta, '2026-11', null, 10);
        $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Portaria', 'status' => 'inactive'], $this->water);
        $this->pdo->exec("UPDATE erp_recurring_entries SET chart_account_id = {$this->water}");
        // Reativa só para Beta: cria outra conta e aponta o modelo de Beta para ela.
        $other = $this->service->save($this->admin, 'chart', ['code' => '2.02', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $this->pdo->exec("UPDATE erp_recurring_entries SET chart_account_id = {$other} WHERE id = {$ok}");

        $result = (new RecurringEntries($this->pdo))->generate($this->admin, '2026-11');
        self::assertArrayHasKey($broken, $result['failed']);
        self::assertArrayHasKey('chart_account_id', $result['failed'][$broken]);
        self::assertCount(1, $result['created']);
    }

    public function testTemplateWithInvalidDayFailsWithoutLeavingTransactionOpen(): void
    {
        // ADR 0106: dia 31 gravado direto no banco (o cadastro só aceita 1 a 28).
        $broken = $this->template($this->alpha, '2026-11', null, 10);
        $ok = $this->template($this->beta, '2026-11', null, 10);
        $this->pdo->prepare('UPDATE erp_recurring_entries SET day_of_month = 31 WHERE id = ?')->execute([$broken]);

        $result = (new RecurringEntries($this->pdo))->generate($this->admin, '2026-11');
        self::assertSame(['_form' => 'Competência ou dia inválido.'], $result['failed'][$broken] ?? null);
        self::assertCount(1, $result['created'], 'O modelo de Beta continua sendo gerado.');
        self::assertNotContains($ok, array_keys($result['failed']));
        self::assertFalse($this->pdo->inTransaction(), 'Nenhuma transação fica aberta.');
    }

    private function template(int $condominium, string $starts, ?string $ends, int $day): int
    {
        return $this->service->save($this->admin, 'recurring_entries', [
            'condominium_id' => $condominium, 'nature' => 'despesa', 'description' => 'Portaria terceirizada', 'amount_cents' => '8.500,00',
            'chart_account_id' => $this->water, 'day_of_month' => (string)$day, 'starts_competence' => $starts, 'ends_competence' => $ends,
        ])['id'];
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
