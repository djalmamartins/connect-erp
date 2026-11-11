<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BoardTermExpiry;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0243: mandatos vencidos encerrados pelo cadastro, com o fim mantido, o síndico novo liberado e nenhum aviso. */
final class BoardTermExpiryTest extends TestCase
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

    public function testExpiresEndedTermsKeepingTheEndDate(): void
    {
        $sindico = $this->member('sindico', -30);
        $fiscal = $this->member('fiscal', -10);
        $current = $this->member('consultivo', 100);
        $orphan = $this->member('suplente', -5, '102');
        $incomplete = $this->member('fiscal', -20);
        $this->pdo->prepare("UPDATE erp_units SET status = 'inactive' WHERE id = ?")->execute([$this->units['102']]);
        $this->pdo->prepare("UPDATE erp_board_members SET email = NULL WHERE id = ?")->execute([$incomplete]);   // cadastro anterior à ADR 0151
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Nova eleição em assembleia'];

        $preview = (new BoardTermExpiry($this->pdo))->expire($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$sindico, $fiscal], [30, 10], 1, 1, 0], [$preview['dry_run'], $preview['changed'], array_column($preview['members'], 'id'),
            array_column($preview['members'], 'days_since_end'), $preview['skipped_inactive_unit'], $preview['skipped_incomplete'], $preview['sindico_active_after']]);
        self::assertSame('active', $this->state($sindico), 'dry_run não grava.');

        $onlyFiscal = (new BoardTermExpiry($this->pdo))->expire($this->admin, $input + ['dry_run' => true, 'roles' => ['fiscal']]);
        self::assertSame([[$fiscal], 1], [array_column($onlyFiscal['members'], 'id'), $onlyFiscal['sindico_active_after']], 'Filtro por cargo.');

        $done = (new BoardTermExpiry($this->pdo))->expire($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'active', 'active', 'active'], [$this->state($sindico), $this->state($fiscal), $this->state($current),
            $this->state($orphan), $this->state($incomplete)]);
        self::assertSame((new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
            substr((string)$this->pdo->query("SELECT term_ends_on FROM erp_board_members WHERE id = {$sindico}")->fetchColumn(), 0, 10), 'O fim do mandato é mantido.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_board_members' AND action = 'expire'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_board_members' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Pessoa de teste', 'conselho@example.invalid', '11999990000'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }

        $this->member('sindico', 700);   // a regra de um síndico ativo agora deixa cadastrar o novo
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_board_members WHERE condominium_id = {$this->condominium} AND role = 'sindico'
            AND status = 'active'")->fetchColumn());
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $ended = $this->member('fiscal', -10);
        $current = $this->member('fiscal', 10);
        try {
            (new BoardTermExpiry($this->pdo))->expire($this->admin, ['condominium_id' => $this->condominium, 'member_ids' => [$ended, $current],
                'reason' => 'Nova eleição em assembleia']);
            self::fail('Mandato valendo entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$current, $exception->errors['_member']);
        }
        self::assertSame('active', $this->state($ended), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new BoardTermExpiry($this->pdo))->expire($limited, ['condominium_id' => $this->condominium, 'reason' => 'Nova eleição em assembleia']);
            self::fail('Encerramento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function member(string $role, int $endDays, ?string $unit = null): int
    {
        return (int)$this->registry->save($this->admin, 'board', ['condominium_id' => $this->condominium, 'role' => $role, 'name' => 'Pessoa de teste',
            'unit_id' => $unit !== null ? $this->units[$unit] : null, 'term_starts_on' => (new \DateTimeImmutable('-730 days'))->format('Y-m-d'),
            'term_ends_on' => (new \DateTimeImmutable("{$endDays} days"))->format('Y-m-d'), 'email' => 'conselho@example.invalid', 'phone' => '11999990000'])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_board_members WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
