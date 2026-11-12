<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContactDeduplication;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0257: cópias dos contatos úteis inativadas pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class ContactDeduplicationTest extends TestCase
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

    public function testDeactivatesCopiesOnly(): void
    {
        $this->pdo->exec("INSERT INTO erp_useful_contacts (condominium_id, service, name, phone) VALUES (NULL, 'emergencia', 'Bombeiros', '193')");
        $shared = (int)$this->pdo->lastInsertId();
        $copyOfShared = $this->contact('emergencia', '193');
        $first = $this->contact('encanador', '(16) 99000-0001');
        $second = $this->contact('encanador', '16990000001');
        $other = $this->contact('eletricista', '(16) 99000-0001');
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium];

        $preview = (new ContactDeduplication($this->pdo))->deduplicate($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$copyOfShared, $second], [$shared, $first], 1], [$preview['dry_run'], $preview['changed'], array_column($preview['contacts'], 'id'),
            array_column($preview['contacts'], 'kept_id'), $preview['kept_shared']]);
        self::assertSame('active', $this->state($second), 'dry_run não grava.');

        $done = (new ContactDeduplication($this->pdo))->deduplicate($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['active', 'inactive', 'active', 'inactive', 'active'], [$this->state($shared), $this->state($copyOfShared), $this->state($first),
            $this->state($second), $this->state($other)]);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_useful_contacts' AND action = 'deduplicate'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_useful_contacts' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Contato de teste', 'Bombeiros', '16990000001', 'phone', 'name'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame(0, (new ContactDeduplication($this->pdo))->deduplicate($this->admin, $input)['count'], 'Sem cópias depois.');
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $first = $this->contact('encanador', '16990000001');
        $second = $this->contact('encanador', '16990000001');
        try {
            (new ContactDeduplication($this->pdo))->deduplicate($this->admin, ['condominium_id' => $this->condominium, 'contact_ids' => [$second, $first]]);
            self::fail('O contato que fica entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$first, $exception->errors['_contact']);
        }
        self::assertSame('active', $this->state($second), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ContactDeduplication($this->pdo))->deduplicate($limited, ['condominium_id' => $this->condominium]);
            self::fail('Inativação fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function contact(string $service, string $phone): int
    {
        return (int)$this->registry->save($this->admin, 'contacts', ['condominium_id' => $this->condominium, 'service' => $service, 'name' => 'Contato de teste',
            'phone' => $phone])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_useful_contacts WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
