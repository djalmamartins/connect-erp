<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LostFoundDisposal;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0233: achados e perdidos fora do prazo descartados pelo cadastro, tudo ou nada, com destino na auditoria e nenhum aviso. */
final class LostFoundDisposalTest extends TestCase
{
    private int $condominium;
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
    }

    public function testDisposesItemsPastTheKeepingPeriod(): void
    {
        $oldest = $this->item(-300, 'active');
        $old = $this->item(-120, 'active');
        $recent = $this->item(-20, 'active');
        $returned = $this->item(-200, 'returned');
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'destination' => 'doacao', 'reason' => 'Prazo do regimento'];

        $preview = (new LostFoundDisposal($this->pdo))->dispose($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$oldest, $old], [300, 120]], [$preview['dry_run'], $preview['changed'], array_column($preview['items'], 'id'),
            array_column($preview['items'], 'days_kept')]);
        self::assertSame('active', $this->state($oldest), 'dry_run não grava.');

        $done = (new LostFoundDisposal($this->pdo))->dispose($this->admin, $input);
        self::assertSame([2, 'Doação'], [$done['changed'], $done['destination_label']]);
        self::assertSame(['discarded', 'discarded', 'active', 'returned'], [$this->state($oldest), $this->state($old), $this->state($recent), $this->state($returned)]);
        self::assertSame('Item de teste', $this->pdo->query("SELECT item FROM erp_lost_found WHERE id = {$old}")->fetchColumn(), 'Dados preservados.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_lost_found' AND action = 'dispose'
            AND context_json LIKE '%doacao%'")->fetchColumn(), 'O destino fica na auditoria.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_lost_found' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        self::assertStringNotContainsString('Item de teste', (string)json_encode($done, JSON_UNESCAPED_UNICODE));
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $old = $this->item(-120, 'active');
        $recent = $this->item(-20, 'active');
        try {
            (new LostFoundDisposal($this->pdo))->dispose($this->admin, ['condominium_id' => $this->condominium, 'destination' => 'descarte',
                'item_ids' => [$old, $recent], 'reason' => 'Prazo do regimento']);
            self::fail('Item dentro do prazo entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$recent, $exception->errors['_item']);
        }
        self::assertSame('active', $this->state($old), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new LostFoundDisposal($this->pdo))->dispose($limited, ['condominium_id' => $this->condominium, 'destination' => 'descarte', 'reason' => 'Prazo do regimento']);
            self::fail('Destino fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function item(int $days, string $status): int
    {
        $found = (new \DateTimeImmutable("{$days} days"))->format('Y-m-d');
        $returned = $status === 'returned' ? (new \DateTimeImmutable(($days + 5) . ' days'))->format('Y-m-d') : null;
        return (int)$this->registry->save($this->admin, 'lost_found', ['condominium_id' => $this->condominium, 'item' => 'Item de teste', 'location' => 'Portaria',
            'found_on' => $found, 'found_by' => 'Porteiro de teste', 'returned_on' => $returned, 'returned_to' => $returned !== null ? 'Morador de teste' : null,
            'status' => $status])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_lost_found WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
