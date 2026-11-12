<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OccurrenceClosing;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0255: ocorrências antigas sem chamado resolvidas pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class OccurrenceClosingTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['101', '102'] as $number) {
            $this->units[$number] = (int)$this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
    }

    public function testClosesStaleOccurrencesOnly(): void
    {
        $oldest = $this->occurrence(-300, '101', 'alta');
        $old = $this->occurrence(-120, null, 'baixa');
        $this->pdo->prepare("UPDATE erp_occurrences SET status = 'running' WHERE id = ?")->execute([$old]);
        $recent = $this->occurrence(-20, null, 'baixa');
        $orphan = $this->occurrence(-150, '102', 'media');
        $this->pdo->prepare("UPDATE erp_units SET status = 'inactive' WHERE id = ?")->execute([$this->units['102']]);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Problema já resolvido'];

        $preview = (new OccurrenceClosing($this->pdo))->close($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$oldest, $old], [300, 120], ['active', 'running'], 1], [$preview['dry_run'], $preview['changed'],
            array_column($preview['occurrences'], 'id'), array_column($preview['occurrences'], 'days_open'), array_column($preview['occurrences'], 'from_status'),
            $preview['skipped_inactive_link']]);
        self::assertSame('active', $this->state($oldest), 'dry_run não grava.');

        $done = (new OccurrenceClosing($this->pdo))->close($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['done', 'done', 'active', 'active'], [$this->state($oldest), $this->state($old), $this->state($recent), $this->state($orphan)]);
        self::assertSame('Vazamento de teste', (string)$this->pdo->query("SELECT subject FROM erp_occurrences WHERE id = {$oldest}")->fetchColumn(), 'Assunto preservado.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_occurrences' AND action = 'close_stale'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_occurrences' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Vazamento de teste', 'Relato de teste', 'unit'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $old = $this->occurrence(-120, null, 'baixa');
        $recent = $this->occurrence(-20, null, 'baixa');
        try {
            (new OccurrenceClosing($this->pdo))->close($this->admin, ['condominium_id' => $this->condominium, 'occurrence_ids' => [$old, $recent],
                'reason' => 'Problema já resolvido']);
            self::fail('Ocorrência dentro do prazo entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$recent, $exception->errors['_occurrence']);
        }
        self::assertSame('active', $this->state($old), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new OccurrenceClosing($this->pdo))->close($limited, ['condominium_id' => $this->condominium, 'reason' => 'Problema já resolvido']);
            self::fail('Encerramento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function occurrence(int $days, ?string $unit, string $priority): int
    {
        return (int)$this->registry->save($this->admin, 'occurrences', ['condominium_id' => $this->condominium, 'unit_id' => $unit !== null ? $this->units[$unit] : null,
            'subject' => 'Vazamento de teste', 'priority' => $priority, 'occurred_on' => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d'),
            'description' => 'Relato de teste'])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_occurrences WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
