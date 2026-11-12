<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CheckCopyMatching;
use Source\Services\Erp\CheckCopyReview;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0249: cheques ligados pelo cadastro ao único lançamento pago que confere, com o valor mantido e nenhum aviso. */
final class CheckCopyMatchingTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $bank;
    private int $chartAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->bank = $this->registry->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->chartAccount = $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica',
            'name' => 'Manutenção'])['id'];
    }

    public function testLinksOnlyTheUnambiguousPairs(): void
    {
        $paid = $this->expense('900,00', '2026-11-10');
        $this->expense('120,00', '2026-11-12');
        $taken = $this->expense('300,00', '2026-11-11');
        $this->expense('50,00', '2026-11-11');
        $match = $this->check(2001, '900,00', null);
        $twinA = $this->check(2002, '120,00', null);
        $twinB = $this->check(2003, '120,00', null);
        $this->check(2004, '40,00', null);
        $this->check(2005, '300,00', $taken);
        $this->check(2006, '300,00', null);
        $orphan = $this->check(2007, '50,00', null);
        $this->pdo->prepare('UPDATE erp_documents SET deleted_at = NOW() WHERE id = (SELECT document_id FROM erp_check_copies WHERE id = ?)')->execute([$orphan]);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Conferência do talão'];

        $preview = (new CheckCopyMatching($this->pdo))->link($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [[$match, $paid, 1]], 2, 2, 1], [$preview['dry_run'], $preview['changed'],
            array_map(static fn (array $c): array => [$c['id'], $c['entry_id'], $c['days_to_payment']], $preview['checks']), $preview['skipped_no_match'],
            $preview['skipped_ambiguous'], $preview['skipped_inactive_link']]);
        self::assertNull($this->state($match)[0], 'dry_run não grava.');

        $done = (new CheckCopyMatching($this->pdo))->link($this->admin, $input);
        self::assertSame(1, $done['changed']);
        self::assertSame([$paid, 90000], $this->state($match), 'Lançamento ligado e valor mantido.');
        self::assertSame([null, null], [$this->state($twinA)[0], $this->state($twinB)[0]], 'Na dúvida, não liga.');
        self::assertSame(['active', '2026-11-10'], array_values($this->pdo->query("SELECT status, paid_on FROM erp_entries WHERE id = {$paid}")->fetch(\PDO::FETCH_ASSOC)),
            'O lançamento não é tocado.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_check_copies' AND action = 'link_entry'")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_check_copies' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        self::assertStringNotContainsString('Favorecido Pessoa', (string)json_encode($done, JSON_UNESCAPED_UNICODE));

        $review = (new CheckCopyReview($this->pdo))->build($this->admin, $this->condominium);
        self::assertNotContains($match, array_column($review['items'], 'check_copy_id'), 'A conferência deixa de apontar o cheque.');
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $paid = $this->expense('900,00', '2026-11-10');
        $match = $this->check(2001, '900,00', null);
        $loose = $this->check(2004, '40,00', null);
        try {
            (new CheckCopyMatching($this->pdo))->link($this->admin, ['condominium_id' => $this->condominium, 'check_copy_ids' => [$match, $loose],
                'reason' => 'Conferência do talão']);
            self::fail('Cheque sem par entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$loose, $exception->errors['_check']);
        }
        self::assertNull($this->state($match)[0], 'Nada gravado.');
        self::assertSame(1, (new CheckCopyMatching($this->pdo))->link($this->admin, ['condominium_id' => $this->condominium, 'check_copy_ids' => [$match],
            'reason' => 'Conferência do talão'])['changed']);
        self::assertSame($paid, $this->state($match)[0]);

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new CheckCopyMatching($this->pdo))->link($limited, ['condominium_id' => $this->condominium, 'reason' => 'Conferência do talão']);
            self::fail('Vínculo fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function expense(string $amount, string $paidOn): int
    {
        return (int)$this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'despesa', 'chart_account_id' => $this->chartAccount,
            'amount_cents' => $amount, 'competence' => '2026-11', 'description' => 'Serviço', 'due_on' => '2026-11-10', 'paid_on' => $paidOn,
            'bank_account_id' => $this->bank])['id'];
    }

    private function check(int $number, string $amount, ?int $entry): int
    {
        $this->pdo->prepare("INSERT INTO erp_documents (condominium_id, folder, title, original_name, path, mime, size_bytes, sha256)
            VALUES (?, 'outros', 'Cheque', 'c.pdf', ?, 'application/pdf', 10, ?)")
            ->execute([$this->condominium, $this->condominium . '/2026/' . bin2hex(random_bytes(16)) . '.pdf', hash('sha256', random_bytes(8))]);
        $image = (int)$this->pdo->lastInsertId();
        return (int)$this->registry->save($this->admin, 'check_copies', ['condominium_id' => $this->condominium, 'bank_account_id' => $this->bank,
            'check_number' => (string)$number, 'issued_on' => '2026-11-09', 'payee' => 'Favorecido Pessoa', 'amount_cents' => $amount, 'document_id' => $image]
            + ($entry !== null ? ['entry_id' => $entry] : []))['id'];
    }

    /** @return array{0:?int, 1:int} */
    private function state(int $id): array
    {
        $row = $this->pdo->query("SELECT entry_id, amount_cents FROM erp_check_copies WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        return [$row['entry_id'] !== null ? (int)$row['entry_id'] : null, (int)$row['amount_cents']];
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
