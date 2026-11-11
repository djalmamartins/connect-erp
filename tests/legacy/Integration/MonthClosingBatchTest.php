<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\MonthClosingBatch;
use Source\Services\Erp\MonthClosingBatchRefused;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0205: fechamento em lote pela conferência (ADR 0181) e pelo MonthClosing::close, tudo ou nada. */
final class MonthClosingBatchTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private int $gamma;
    private RegistryService $service;
    private User $admin;
    private int $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->gamma = $this->condominium('Gama');
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta, $this->gamma], true);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        // Beta tem uma receita da competência em aberto (aviso open_entries); Alpha não tem nada (pronta, sem aviso).
        $this->service->save($this->admin, 'entries', ['condominium_id' => $this->beta, 'nature' => 'receita', 'chart_account_id' => $this->fee,
            'amount_cents' => '100,00', 'competence' => '2026-11', 'description' => 'Taxa', 'due_on' => '2026-11-10']);
    }

    public function testWarningsBlockUnlessAllowedThenCloseAll(): void
    {
        $input = ['competence' => '2026-11', 'condominium_ids' => [$this->alpha, $this->beta]];
        $refused = $this->refused($input);
        self::assertSame([[$this->beta, 'Beta', ['open_entries']]], array_map(static fn (array $r): array => [$r['condominium_id'], $r['name'], array_column($r['reasons'], 'code')], $refused),
            'Só a Beta é recusada; a lista vem inteira.');
        self::assertSame(0, $this->closings(), 'Nada fechado.');

        $preview = (new MonthClosingBatch($this->pdo))->close($this->admin, $input + ['allow_warnings' => true, 'dry_run' => true]);
        self::assertSame([true, 0, 2, 10000], [$preview['dry_run'], $preview['changed'], $preview['count'], $preview['revenue_cents']]);
        self::assertSame([[], ['open_entries']], array_column($preview['condominiums'], 'accepted_warnings'));
        self::assertSame([0, 0], [$this->closings(), $this->audits()], 'dry_run não grava nem audita.');

        $done = (new MonthClosingBatch($this->pdo))->close($this->admin, $input + ['allow_warnings' => true]);
        self::assertSame(2, $done['changed']);
        self::assertSame(['closed', 'closed'], $this->pdo->query("SELECT status FROM erp_month_closings WHERE competence = '2026-11' ORDER BY condominium_id")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_month_closings' AND action = 'close_batch'")->fetchColumn());
        self::assertSame(10000, (new MonthClosing($this->pdo))->status($this->admin, $this->beta, '2026-11')['summary']['revenue_cents'] ?? null, 'A foto é a do fechamento de um por um.');

        $again = $this->refused($input + ['allow_warnings' => true]);
        self::assertSame([['already_closed'], ['already_closed']], array_map(static fn (array $r): array => array_column($r['reasons'], 'code'), $again));
    }

    public function testErrorNeverClosesAndScopeIsChecked(): void
    {
        $bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->gamma, 'name' => 'Conta 1', 'bank_code' => '341',
            'branch' => '1234', 'account_number' => '11111-1', 'kind' => 'corrente'])['id'];
        $paid = $this->service->save($this->admin, 'entries', ['condominium_id' => $this->gamma, 'nature' => 'receita', 'chart_account_id' => $this->fee,
            'amount_cents' => '50,00', 'competence' => '2026-11', 'description' => 'Taxa paga', 'due_on' => '2026-11-10', 'paid_on' => '2026-11-09',
            'bank_account_id' => $bank])['id'];
        $this->pdo->exec("UPDATE erp_entries SET bank_account_id = NULL WHERE id = {$paid}");   // pago sem conta bancária (erro da conferência)

        $refused = $this->refused(['competence' => '2026-11', 'condominium_ids' => [$this->alpha, $this->gamma], 'allow_warnings' => true]);
        self::assertSame([$this->gamma], array_column($refused, 'condominium_id'));
        self::assertContains('paid_without_bank', array_column($refused[0]['reasons'], 'code'));
        self::assertSame('error', $refused[0]['reasons'][array_search('paid_without_bank', array_column($refused[0]['reasons'], 'code'), true)]['level']);
        self::assertSame(0, $this->closings(), 'Erro nunca fecha, nem com allow_warnings; a Alpha também não.');

        $limited = $this->user([$this->alpha]);
        try {
            (new MonthClosingBatch($this->pdo))->close($limited, ['competence' => '2026-11', 'condominium_ids' => [$this->alpha, $this->beta]]);
            self::fail('Fechou condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertSame(0, $this->closings());
        }
    }

    /** @return list<array{condominium_id:int, name:string, reasons:list<array{code:string, level:string, message:string}>}> */
    private function refused(array $input): array
    {
        try {
            (new MonthClosingBatch($this->pdo))->close($this->admin, $input);
        } catch (MonthClosingBatchRefused $exception) {
            return $exception->refused;
        }
        self::fail('Recusa esperada: ' . json_encode($input));
    }

    private function closings(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_month_closings WHERE status = 'closed'")->fetchColumn();
    }

    private function audits(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_month_closings'")->fetchColumn();
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
