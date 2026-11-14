<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\ExtraChargeCancellation;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0289: cobranças extras e multas canceladas em lote no MariaDB, com as travas de linha, a auditoria só quando grava e as regras do cancelamento de uma. */
final class ExtraChargeCancellationTest extends TestCase
{
    private int $condominium;
    private int $account;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.91', 'Cobrança extra', 'receita', 'analitica')")
            ->execute([$this->condominium]);
        $this->account = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testCancelsOnlyWhenEveryRuleAllows(): void
    {
        $kinds = array_keys(EntryShortcuts::CHARGE_KINDS);
        $open = $this->charge($kinds[0]);
        $first = $this->entry($open, 1000, false);
        $this->entry($open, 2000, false);
        $paid = $this->charge($kinds[count($kinds) - 1]);
        $this->entry($paid, 500, true);
        $before = $this->audits();

        $preview = (new ExtraChargeCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Lançada por engano',
            'charge_ids' => [$open], 'dry_run' => true]);
        self::assertSame([[$open], 3000, 0], [array_column($preview['charges'], 'id'), $preview['amount_cents'], $preview['changed']]);
        self::assertSame([$before, 'active'], [$this->audits(), $this->chargeStatus($open)], 'A prévia não grava nem audita.');

        try {
            (new ExtraChargeCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Lançada por engano',
                'charge_ids' => [$open, $paid]]);
            self::fail('Cobrança com conta paga passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$paid, $exception->errors['_charge']);
        }
        self::assertSame(['active', 'active'], [$this->chargeStatus($open), $this->entryStatus($first)], 'Tudo ou nada.');

        $done = (new ExtraChargeCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Lançada por engano',
            'charge_ids' => [$open]]);
        self::assertSame(1, $done['changed']);
        self::assertSame(['cancelled', 'inactive', 'active'], [$this->chargeStatus($open), $this->entryStatus($first), $this->chargeStatus($paid)]);
        self::assertSame('Lançada por engano', $this->pdo->query("SELECT cancel_reason FROM erp_extra_charges WHERE id = {$open}")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_extra_charges' AND action = 'update' AND entity_id = '{$open}'")
            ->fetchColumn());

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ExtraChargeCancellation($this->pdo))->cancel($limited, ['condominium_id' => $this->condominium, 'reason' => 'Lançada por engano', 'charge_ids' => [$paid]]);
            self::fail('Fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function audits(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
    }

    private function chargeStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_extra_charges WHERE id = {$id}")->fetchColumn();
    }

    private function entryStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_entries WHERE id = {$id}")->fetchColumn();
    }

    private function charge(string $kind): int
    {
        $this->pdo->prepare("INSERT INTO erp_extra_charges (condominium_id, kind, description, chart_account_id, method, total_cents, units_count, competence, due_on,
                status, created_by) VALUES (?, ?, 'Pintura do Fulano', ?, 'fixed', 1000, 1, DATE_FORMAT(CURDATE(), '%Y-%m'), CURDATE(), 'active', ?)")
            ->execute([$this->condominium, $kind, $this->account, $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entry(int $charge, int $cents, bool $paid): int
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on, status,
                extra_charge_id) VALUES (?, 'receita', ?, 'Cobrança do Fulano', ?, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 10 DAY), ?, 'active', ?)")
            ->execute([$this->condominium, $this->account, $cents, $paid ? date('Y-m-d') : null, $charge]);
        return (int)$this->pdo->lastInsertId();
    }
}
