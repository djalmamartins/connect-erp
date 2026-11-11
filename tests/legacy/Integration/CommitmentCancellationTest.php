<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommitmentCancellation;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0239: empenhos em aberto há muito tempo cancelados pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class CommitmentCancellationTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $suppliers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['ativo' => '11222333000181', 'inativo' => '11444777000161'] as $key => $document) {
            $this->pdo->prepare("INSERT INTO erp_suppliers (legal_name, document, category) VALUES ('Fornecedor de teste', ?, 'servicos')")->execute([$document]);
            $this->suppliers[$key] = (int)$this->pdo->lastInsertId();
        }
    }

    public function testCancelsStaleOpenCommitmentsOnly(): void
    {
        $oldest = $this->commitment(-300, 'ativo');
        $old = $this->commitment(-120, null);
        $recent = $this->commitment(-20, null);
        $orphan = $this->commitment(-150, 'inativo');
        $this->pdo->prepare("UPDATE erp_suppliers SET status = 'inactive' WHERE id = ?")->execute([$this->suppliers['inativo']]);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Orçamento recusado'];

        $preview = (new CommitmentCancellation($this->pdo))->cancel($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$oldest, $old], [300, 120], 1, 25000], [$preview['dry_run'], $preview['changed'], array_column($preview['commitments'], 'id'),
            array_column($preview['commitments'], 'days_open'), $preview['skipped_inactive_link'], $preview['amount_cents']]);
        self::assertSame('active', $this->state($oldest), 'dry_run não grava.');

        $done = (new CommitmentCancellation($this->pdo))->cancel($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'active', 'active'], [$this->state($oldest), $this->state($old), $this->state($recent), $this->state($orphan)]);
        self::assertSame(15000, (int)$this->pdo->query("SELECT amount_cents FROM erp_commitments WHERE id = {$oldest}")->fetchColumn(), 'Valor preservado.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_commitments' AND action = 'cancel'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_commitments' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Empenho de teste', 'Fornecedor de teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $old = $this->commitment(-120, null);
        $recent = $this->commitment(-20, null);
        try {
            (new CommitmentCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->condominium, 'commitment_ids' => [$old, $recent],
                'reason' => 'Orçamento recusado']);
            self::fail('Empenho dentro do prazo entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$recent, $exception->errors['_commitment']);
        }
        self::assertSame('active', $this->state($old), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new CommitmentCancellation($this->pdo))->cancel($limited, ['condominium_id' => $this->condominium, 'reason' => 'Orçamento recusado']);
            self::fail('Cancelamento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function commitment(int $days, ?string $supplier): int
    {
        return (int)$this->registry->save($this->admin, 'commitments', ['condominium_id' => $this->condominium, 'description' => 'Empenho de teste',
            'amount_cents' => $supplier === 'ativo' ? '150,00' : '100,00', 'committed_on' => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d'),
            'supplier_id' => $supplier !== null ? $this->suppliers[$supplier] : null])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_commitments WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
