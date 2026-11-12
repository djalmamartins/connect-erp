<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MoveSettlement;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0247: agendamentos passados baixados pelo cadastro, com a data mantida, as saídas apontadas e nenhum aviso. */
final class MoveSettlementTest extends TestCase
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

    public function testSettlesPastMovesKeepingTheDate(): void
    {
        $this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['101'], 'relationship' => 'tenant',
            'name' => 'Pessoa de teste']);
        $out = $this->move('101', 'saida', -10, 'manha');
        $in = $this->move('101', 'entrada', -5, 'tarde');
        $future = $this->move('101', 'entrada', 3, 'manha');
        $today = $this->move('101', 'entrada', 0, 'tarde');
        $orphan = $this->move('102', 'saida', -7, 'manha');
        $this->pdo->prepare("UPDATE erp_units SET status = 'inactive' WHERE id = ?")->execute([$this->units['102']]);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Baixa da portaria'];

        $preview = (new MoveSettlement($this->pdo))->settle($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$out, $in], [10, 5], 1, 1], [$preview['dry_run'], $preview['changed'], array_column($preview['moves'], 'id'),
            array_column($preview['moves'], 'days_since'), $preview['skipped_inactive_unit'], $preview['move_outs_to_review']]);
        self::assertSame('active', $this->state($out)[0], 'dry_run não grava.');

        $entries = (new MoveSettlement($this->pdo))->settle($this->admin, $input + ['dry_run' => true, 'kinds' => ['entrada']]);
        self::assertSame([[$in], 0], [array_column($entries['moves'], 'id'), $entries['move_outs_to_review']], 'Filtro por tipo.');
        $noShow = (new MoveSettlement($this->pdo))->settle($this->admin, $input + ['dry_run' => true, 'outcome' => 'inactive']);
        self::assertSame([[$out, $in], 0], [array_column($noShow['moves'], 'id'), $noShow['move_outs_to_review']], 'Cancelada não aponta saída.');

        $done = (new MoveSettlement($this->pdo))->settle($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['done', 'done', 'active', 'active', 'active'], [$this->state($out)[0], $this->state($in)[0], $this->state($future)[0],
            $this->state($today)[0], $this->state($orphan)[0]]);
        self::assertSame((new \DateTimeImmutable('-10 days'))->format('Y-m-d'), $this->state($out)[1], 'A data é mantida.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_residents WHERE status = 'active'")->fetchColumn(), 'O inquilino não é tocado.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_moves' AND action = 'settle'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_moves' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        self::assertStringNotContainsString('Morador de teste', (string)json_encode($done, JSON_UNESCAPED_UNICODE));
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $past = $this->move('101', 'entrada', -3, 'manha');
        $future = $this->move('101', 'entrada', 3, 'manha');
        try {
            (new MoveSettlement($this->pdo))->settle($this->admin, ['condominium_id' => $this->condominium, 'move_ids' => [$past, $future],
                'reason' => 'Baixa da portaria']);
            self::fail('Agendamento futuro entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$future, $exception->errors['_move']);
        }
        self::assertSame('active', $this->state($past)[0], 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new MoveSettlement($this->pdo))->settle($limited, ['condominium_id' => $this->condominium, 'reason' => 'Baixa da portaria']);
            self::fail('Baixa fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function move(string $unit, string $kind, int $days, string $period): int
    {
        return (int)$this->registry->save($this->admin, 'moves', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit],
            'resident_name' => 'Morador de teste', 'kind' => $kind, 'scheduled_on' => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d'),
            'period' => $period])['id'];
    }

    /** @return array{0:string, 1:string} */
    private function state(int $id): array
    {
        $row = $this->pdo->query("SELECT status, scheduled_on FROM erp_moves WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        return [(string)$row['status'], substr((string)$row['scheduled_on'], 0, 10)];
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
