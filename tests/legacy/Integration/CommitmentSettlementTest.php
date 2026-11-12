<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommitmentSettlement;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0253: empenhos com o lançamento pago liquidados pelo cadastro na data do pagamento, tudo ou nada, com auditoria e nenhum aviso. */
final class CommitmentSettlementTest extends TestCase
{
    private int $condominium;
    private int $account;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '2.91', 'Manutenção', 'despesa', 'analitica')")
            ->execute([$this->condominium]);
        $this->account = (int)$this->pdo->lastInsertId();
    }

    public function testSettlesCommitmentsWithPaidEntriesOnThePaymentDate(): void
    {
        $paid = $this->entry(15250, '2026-06-10');
        $open = $this->entry(1000, null);
        $settled = $this->commitment($paid, '150,00');
        $waiting = $this->commitment($open, '10,00');
        $loose = $this->commitment(null, '20,00');
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium];

        $preview = (new CommitmentSettlement($this->pdo))->settle($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$settled], ['2026-06-10'], 1, 15000, 15250, 250], [$preview['dry_run'], $preview['changed'], array_column($preview['commitments'], 'id'),
            array_column($preview['commitments'], 'settled_on'), $preview['awaiting_payment'], $preview['amount_cents'], $preview['entry_amount_cents'],
            $preview['difference_cents']]);
        self::assertSame(['active', null], $this->state($settled), 'dry_run não grava.');

        $done = (new CommitmentSettlement($this->pdo))->settle($this->admin, $input);
        self::assertSame(1, $done['changed']);
        self::assertSame([['settled', '2026-06-10'], ['active', null], ['active', null]], [$this->state($settled), $this->state($waiting), $this->state($loose)]);
        self::assertSame(15000, (int)$this->pdo->query("SELECT amount_cents FROM erp_commitments WHERE id = {$settled}")->fetchColumn(), 'Valor preservado.');
        self::assertSame($paid, (int)$this->pdo->query("SELECT entry_id FROM erp_commitments WHERE id = {$settled}")->fetchColumn(), 'Lançamento preservado.');
        self::assertSame('2026-06-10', (string)$this->pdo->query("SELECT paid_on FROM erp_entries WHERE id = {$paid}")->fetchColumn(), 'O lançamento não muda.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_commitments' AND action = 'settle'")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_commitments' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Empenho de teste', 'Pagamento de teste', 'Manutenção'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, (new CommitmentSettlement($this->pdo))->settle($this->admin, $input)['count'], 'Já liquidado não entra de novo.');
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $ok = $this->commitment($this->entry(1000, '2026-06-10'), '10,00');
        $waiting = $this->commitment($this->entry(1000, null), '10,00');
        try {
            (new CommitmentSettlement($this->pdo))->settle($this->admin, ['condominium_id' => $this->condominium, 'commitment_ids' => [$ok, $waiting]]);
            self::fail('Empenho com lançamento em aberto entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$waiting, $exception->errors['_commitment']);
        }
        self::assertSame(['active', null], $this->state($ok), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new CommitmentSettlement($this->pdo))->settle($limited, ['condominium_id' => $this->condominium]);
            self::fail('Liquidação fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function entry(int $cents, ?string $paidOn): int
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, 'despesa', ?, 'Pagamento de teste', ?, '2026-06', '2026-06-10', ?)")->execute([$this->condominium, $this->account, $cents, $paidOn]);
        return (int)$this->pdo->lastInsertId();
    }

    private function commitment(?int $entry, string $amount): int
    {
        return (int)$this->registry->save($this->admin, 'commitments', ['condominium_id' => $this->condominium, 'description' => 'Empenho de teste',
            'amount_cents' => $amount, 'committed_on' => '2026-05-01', 'entry_id' => $entry])['id'];
    }

    /** @return array{0:string, 1:?string} */
    private function state(int $id): array
    {
        $row = $this->pdo->query("SELECT status, settled_on FROM erp_commitments WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        return [(string)$row['status'], $row['settled_on'] === null ? null : substr((string)$row['settled_on'], 0, 10)];
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
