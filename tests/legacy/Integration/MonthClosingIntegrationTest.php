<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\RecurringEntries;
use Source\Services\Erp\Registry\RegistryLocked;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Fechamento mensal: foto, trava dos lançamentos, reabertura e escopo (ADR 0031). */
final class MonthClosingIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private User $admin;
    private int $fee;
    private int $water;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->water = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Água'])['id'];
    }

    public function testCloseStoresSnapshotAndLocksTheCompetence(): void
    {
        $revenue = $this->entry('receita', $this->fee, '1.000,00', '2026-11');
        $this->entry('despesa', $this->water, '300,00', '2026-11');
        $closings = new MonthClosing($this->pdo);

        $closed = $closings->close($this->admin, $this->alpha, '2026-11');
        self::assertSame('closed', $closed['status']);
        self::assertSame(100000, $closed['revenue_cents']);
        self::assertSame(30000, $closed['expense_cents']);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_month_closings'")->fetchColumn());

        $this->assertLocked(fn () => $this->entry('despesa', $this->water, '10,00', '2026-11'), 'Criar na competência fechada.');
        $this->assertLocked(fn () => $this->service->save($this->admin, 'entries', $this->input('receita', $this->fee, '999,00', '2026-11'), $revenue), 'Alterar.');
        $this->assertLocked(fn () => $this->service->save($this->admin, 'entries', $this->input('receita', $this->fee, '1.000,00', '2026-12'), $revenue), 'Tirar da competência fechada.');
        try {
            $this->service->delete($this->admin, 'entries', $revenue);
            self::fail('Excluiu lançamento de competência fechada.');
        } catch (RegistryLocked) {
            self::addToAssertionCount(1);
        }
        self::assertGreaterThan(0, $this->entry('despesa', $this->water, '10,00', '2026-12'), 'Outra competência continua aberta.');
        self::assertGreaterThan(0, $this->service->save($this->admin, 'entries', $this->input('despesa', $this->water, '10,00', '2026-11', $this->beta))['id'], 'Outro condomínio não é afetado.');

        $this->expectException(DomainException::class);
        $closings->close($this->admin, $this->alpha, '2026-11');
    }

    /**
     * ADR 0100: gravação que já conferiu a competência (leitura travada, na transação) segura o fechamento
     * até terminar; o fechamento feito depois enxerga o lançamento gravado.
     */
    public function testCloseWaitsForAWriteThatAlreadyCheckedTheCompetence(): void
    {
        $other = new \PDO('mysql:host=' . CONF_DB_HOST . ';port=' . CONF_DB_PORT . ';dbname=' . CONF_DB_NAME . ';charset=utf8mb4',
            CONF_DB_USER, CONF_DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $closing = new MonthClosing($other);

        $this->pdo->beginTransaction();
        try {
            self::assertFalse($this->service->isClosed($this->alpha, '2026-10'));
            $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, status)
                VALUES (?, 'receita', ?, 'Gravada junto', 5000, '2026-10', '2026-10-10', 'active')")->execute([$this->alpha, $this->fee]);
            try {
                $closing->close($this->admin, $this->alpha, '2026-10');
                self::fail('O fechamento devia esperar a gravação em andamento.');
            } catch (\PDOException $error) {
                self::assertStringContainsString('1205', $error->getMessage(), 'Espera de trava (lock wait timeout).');
            }
            $this->pdo->commit();
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        $closed = $closing->close($this->admin, $this->alpha, '2026-10');
        self::assertSame('closed', $closed['status']);
        self::assertSame(5000, $closed['revenue_cents'], 'O lançamento gravado antes entra na foto.');

        $this->pdo->beginTransaction();
        try {
            self::assertTrue($this->service->isClosed($this->alpha, '2026-10'), 'Depois de fechado, a leitura travada vê o fechamento.');
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testReopenNeedsReasonAndUnlocks(): void
    {
        $entry = $this->entry('despesa', $this->water, '300,00', '2026-11');
        $closings = new MonthClosing($this->pdo);
        $closings->close($this->admin, $this->alpha, '2026-11');

        try {
            $closings->reopen($this->admin, $this->alpha, '2026-11', 'ok');
            self::fail('Reabriu sem motivo suficiente.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $reopened = $closings->reopen($this->admin, $this->alpha, '2026-11', 'Nota fiscal lançada com valor errado');
        self::assertSame('reopened', $reopened['status']);
        self::assertSame('Nota fiscal lançada com valor errado', $reopened['reopen_reason']);
        self::assertSame(35000, $this->service->save($this->admin, 'entries', $this->input('despesa', $this->water, '350,00', '2026-11'), $entry)['amount_cents']);

        $again = $closings->close($this->admin, $this->alpha, '2026-11');
        self::assertSame(35000, $again['expense_cents'], 'Novo fechamento grava a foto nova.');
        $this->expectException(DomainException::class);
        $closings->reopen($this->admin, $this->alpha, '2026-12', 'Competência que nunca foi fechada');
    }

    public function testStatusScopeAndRecurringGenerationInClosedMonth(): void
    {
        $closings = new MonthClosing($this->pdo);
        self::assertSame('open', $closings->status($this->admin, $this->alpha, '2026-11')['status']);

        $template = $this->service->save($this->admin, 'recurring_entries', [
            'condominium_id' => $this->alpha, 'nature' => 'despesa', 'description' => 'Portaria', 'amount_cents' => '100,00',
            'chart_account_id' => $this->water, 'day_of_month' => '10', 'starts_competence' => '2026-01',
        ])['id'];
        $closings->close($this->admin, $this->alpha, '2026-11');
        $result = (new RecurringEntries($this->pdo))->generate($this->admin, '2026-11');
        self::assertArrayHasKey($template, $result['failed'], 'Geração em competência fechada falha sem criar.');
        self::assertArrayHasKey('competence', $result['failed'][$template]);

        $outsider = $this->user([$this->beta]);
        $this->expectException(CondominiumAccessDenied::class);
        $closings->status($outsider, $this->alpha, '2026-11');
    }

    private function assertLocked(callable $call, string $message): void
    {
        try {
            $call();
            self::fail('Alterou competência fechada: ' . $message);
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('competence', $exception->errors, $message);
        }
    }

    private function input(string $nature, int $account, string $amount, string $competence, ?int $condominium = null): array
    {
        return ['condominium_id' => $condominium ?? $this->alpha, 'nature' => $nature, 'chart_account_id' => $account, 'amount_cents' => $amount,
            'competence' => $competence, 'description' => 'Teste', 'due_on' => $competence . '-10'];
    }

    private function entry(string $nature, int $account, string $amount, string $competence): int
    {
        return $this->service->save($this->admin, 'entries', $this->input($nature, $account, $amount, $competence))['id'];
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
