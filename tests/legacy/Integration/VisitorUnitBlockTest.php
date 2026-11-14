<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VisitorUnitBlock;

/** ADR 0265: visitantes de unidades sem morador ativo bloqueados pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class VisitorUnitBlockTest extends TestCase
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
        // A 102 tem morador ativo; a 101 ficou vazia (o vínculo foi encerrado).
        $resident = (int)$this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['101'], 'relationship' => 'tenant',
            'name' => 'Morador de teste', 'document' => '529.982.247-25'])['id'];
        $this->pdo->prepare("UPDATE erp_residents SET status = 'inactive' WHERE id = ?")->execute([$resident]);
        $this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['102'], 'relationship' => 'owner',
            'name' => 'Morador de teste', 'document' => '111.444.777-35']);
    }

    public function testBlocksVisitorsOfEmptyUnitsOnly(): void
    {
        $permanent = $this->visitor('101', true, null);
        $dated = $this->visitor('101', false, (new \DateTimeImmutable('+30 days'))->format('Y-m-d'));
        $kept = $this->visitor('102', true, null);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Família se mudou'];

        $preview = (new VisitorUnitBlock($this->pdo))->block($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$permanent, $dated], 1], [$preview['dry_run'], $preview['changed'], array_column($preview['visitors'], 'id'), $preview['permanent']]);
        self::assertSame('active', $this->state($permanent), 'dry_run não grava.');

        $done = (new VisitorUnitBlock($this->pdo))->block($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'active'], [$this->state($permanent), $this->state($dated), $this->state($kept)]);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_visitors' AND action = 'block_unattached'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_visitors' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Visitante de teste', 'Morador de teste', '12345678909'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, (new VisitorUnitBlock($this->pdo))->block($this->admin, $input)['count'], 'Já bloqueados não entram de novo.');
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $empty = $this->visitor('101', true, null);
        $inhabited = $this->visitor('102', true, null);
        try {
            (new VisitorUnitBlock($this->pdo))->block($this->admin, ['condominium_id' => $this->condominium, 'visitor_ids' => [$empty, $inhabited], 'reason' => 'Família se mudou']);
            self::fail('Visitante de unidade com morador entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$inhabited, $exception->errors['_visitor']);
        }
        self::assertSame('active', $this->state($empty), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VisitorUnitBlock($this->pdo))->block($limited, ['condominium_id' => $this->condominium, 'reason' => 'Família se mudou']);
            self::fail('Bloqueio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function visitor(string $unit, bool $permanent, ?string $until): int
    {
        return (int)$this->registry->save($this->admin, 'visitors', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit], 'name' => 'Visitante de teste',
            'document' => '123.456.789-09', 'permanent_access' => $permanent ? '1' : '0', 'valid_until' => $until])['id'];
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
