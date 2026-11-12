<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ResidentLinkExpiry;

/** ADR 0241: vínculos com o fim vencido encerrados pelo cadastro, com o fim mantido, convites revogados e nenhum aviso. */
final class ResidentLinkExpiryTest extends TestCase
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
            $this->units[$number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
    }

    public function testExpiresEndedActiveLinksKeepingTheEndDate(): void
    {
        $tenant = $this->link('101', 'tenant', -40);
        $owner = $this->link('101', 'owner', -30);
        $resident = $this->link('101', 'resident', -5);
        $future = $this->link('101', 'tenant', 10);
        $open = $this->link('101', 'owner', null);
        $orphan = $this->link('102', 'tenant', -20);
        $this->pdo->prepare("UPDATE erp_units SET status = 'inactive' WHERE id = ?")->execute([$this->units['102']]);
        $this->pdo->prepare("INSERT INTO erp_resident_invitations (condominium_id, resident_id, token_hash, status, expires_at) VALUES (?, ?, ?, 'pending', ?)")
            ->execute([$this->condominium, $tenant, str_repeat('a', 64), (new \DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s')]);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Contrato encerrado'];

        $preview = (new ResidentLinkExpiry($this->pdo))->expire($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$tenant, $owner, $resident], [40, 30, 5], 1, 1], [$preview['dry_run'], $preview['changed'], array_column($preview['links'], 'id'),
            array_column($preview['links'], 'days_since_end'), $preview['skipped_inactive_unit'], $preview['invitations_revoked']]);
        self::assertSame('active', $this->state($tenant), 'dry_run não grava.');
        self::assertSame('pending', $this->pdo->query('SELECT status FROM erp_resident_invitations')->fetchColumn(), 'dry_run não revoga.');

        $onlyOwners = (new ResidentLinkExpiry($this->pdo))->expire($this->admin, $input + ['dry_run' => true, 'relationships' => ['owner']]);
        self::assertSame([$owner], array_column($onlyOwners['links'], 'id'), 'Filtro por tipo de vínculo.');

        $done = (new ResidentLinkExpiry($this->pdo))->expire($this->admin, $input);
        self::assertSame(3, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'inactive', 'active', 'active', 'active'], [$this->state($tenant), $this->state($owner), $this->state($resident),
            $this->state($future), $this->state($open), $this->state($orphan)]);
        self::assertSame((new \DateTimeImmutable('-40 days'))->format('Y-m-d'),
            substr((string)$this->pdo->query("SELECT ends_on FROM erp_residents WHERE id = {$tenant}")->fetchColumn(), 0, 10), 'O fim do vínculo é mantido.');
        self::assertSame('revoked', $this->pdo->query('SELECT status FROM erp_resident_invitations')->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_residents' AND action = 'expire'")->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_residents' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_resident_invitations' AND action = 'update'")->fetchColumn());
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        self::assertStringNotContainsString('Pessoa de teste', (string)json_encode($done, JSON_UNESCAPED_UNICODE));
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $ended = $this->link('101', 'tenant', -10);
        $future = $this->link('101', 'tenant', 10);
        try {
            (new ResidentLinkExpiry($this->pdo))->expire($this->admin, ['condominium_id' => $this->condominium, 'resident_ids' => [$ended, $future],
                'reason' => 'Contrato encerrado']);
            self::fail('Vínculo ainda valendo entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$future, $exception->errors['_resident']);
        }
        self::assertSame('active', $this->state($ended), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ResidentLinkExpiry($this->pdo))->expire($limited, ['condominium_id' => $this->condominium, 'reason' => 'Contrato encerrado']);
            self::fail('Encerramento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function link(string $unit, string $relationship, ?int $endDays): int
    {
        return (int)$this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit],
            'relationship' => $relationship, 'name' => 'Pessoa de teste', 'starts_on' => (new \DateTimeImmutable('-400 days'))->format('Y-m-d'),
            'ends_on' => $endDays !== null ? (new \DateTimeImmutable("{$endDays} days"))->format('Y-m-d') : null])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_residents WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
