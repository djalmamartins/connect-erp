<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VisitorAuthorizationExpiry;

/** ADR 0235: autorizações vencidas e ainda liberadas bloqueadas pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class VisitorAuthorizationExpiryTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->unit = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testBlocksExpiredActiveAuthorizationsOnly(): void
    {
        $older = $this->visitor(false, -40);
        $old = $this->visitor(false, -3);
        $current = $this->visitor(false, 10);
        $permanent = $this->visitor(true, null);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Autorização vencida'];

        $preview = (new VisitorAuthorizationExpiry($this->pdo))->expire($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$older, $old], [40, 3]], [$preview['dry_run'], $preview['changed'], array_column($preview['visitors'], 'id'),
            array_column($preview['visitors'], 'days_expired')]);
        self::assertSame('active', $this->state($older), 'dry_run não grava.');

        $done = (new VisitorAuthorizationExpiry($this->pdo))->expire($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'active', 'active'], [$this->state($older), $this->state($old), $this->state($current), $this->state($permanent)]);
        self::assertSame('12345678909', $this->pdo->query("SELECT document FROM erp_visitors WHERE id = {$old}")->fetchColumn(), 'Dados preservados.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_visitors' AND action = 'expire'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_visitors' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Visitante de teste', '12345678909', '11988887777'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $old = $this->visitor(false, -3);
        $permanent = $this->visitor(true, null);
        try {
            (new VisitorAuthorizationExpiry($this->pdo))->expire($this->admin, ['condominium_id' => $this->condominium, 'visitor_ids' => [$old, $permanent],
                'reason' => 'Autorização vencida']);
            self::fail('Acesso permanente entrou no bloqueio.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$permanent, $exception->errors['_visitor']);
        }
        self::assertSame('active', $this->state($old), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VisitorAuthorizationExpiry($this->pdo))->expire($limited, ['condominium_id' => $this->condominium, 'reason' => 'Autorização vencida']);
            self::fail('Bloqueio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function visitor(bool $permanent, ?int $days): int
    {
        $until = $days !== null ? (new \DateTimeImmutable("{$days} days"))->format('Y-m-d') : null;
        return (int)$this->registry->save($this->admin, 'visitors', ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'name' => 'Visitante de teste',
            'document' => '123.456.789-09', 'phone' => '(11) 98888-7777', 'permanent_access' => $permanent ? '1' : '0', 'valid_until' => $until])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_visitors WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
