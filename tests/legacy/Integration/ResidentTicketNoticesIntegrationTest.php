<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Resident\ResidentFeed;
use Source\Services\Resident\ResidentPush;
use Source\Services\Support\TicketDesk;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;

/**
 * ADR 0641: cada movimentação da equipe no chamado vira aviso no app do morador (sininho e push), nota interna não,
 * e a equipe do chamado é uma lista.
 */
final class ResidentTicketNoticesIntegrationTest extends TestCase
{
    private int $condominium;
    private int $other;
    private User $agent;
    private int $resident;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->other = (int)$this->pdo->lastInsertId();
        $this->condominium = $this->other - 1;
        $agent = $this->createUser(['level' => 5, 'first_name' => 'Ana']);
        (new CondominiumScope($this->pdo))->grant($agent, $this->condominium);
        $this->agent = (new User())->findById($agent);
        $this->resident = $this->createUser(['first_name' => 'Morador']);
    }

    private function notices(): array
    {
        return $this->pdo->query("SELECT event, title, detail, push_status FROM resident_ticket_notices WHERE user_id = {$this->resident} ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testEveryTeamMoveNotifiesTheResidentButInternalNotesDoNot(): void
    {
        $mailBefore = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue WHERE users_id = ' . $this->resident)->fetchColumn();
        $ticket = (new TicketService($this->pdo))->open(['subject' => 'Vazamento na garagem', 'message' => 'Tem água no box 12.',
            'condominium_id' => $this->condominium, 'requester_id' => $this->resident], 'erp', $this->agent);
        $id = (int)$ticket['id'];
        $desk = new TicketDesk($this->pdo);

        $desk->reply($this->agent, $id, 'Conferir com o zelador antes.', true); // nota interna: só a situação muda
        $desk->update($this->agent, $id, ['team' => 'manutencao', 'assigned_to' => (int)$this->agent->id, 'priority' => 'high']);
        $desk->update($this->agent, $id, ['tags' => 'garagem']); // triagem interna: nada
        $desk->reply($this->agent, $id, 'O encanador vai amanhã às 9h.', false);
        $desk->update($this->agent, $id, ['status' => 'resolved']);

        $notices = $this->notices();
        self::assertSame(['opened', 'status', 'team', 'reply', 'status'], array_column($notices, 'event'));
        self::assertSame('A equipe está cuidando do seu chamado.', $notices[1]['detail'], 'Da nota interna só a situação chega.');
        self::assertStringNotContainsString('zelador', implode(' ', array_column($notices, 'detail')));
        self::assertSame('Chamado ' . $ticket['protocol'] . ' com a equipe Manutenção', $notices[2]['title']);
        self::assertStringContainsString('Ana, da equipe Connect', $notices[2]['detail']);
        self::assertSame('O encanador vai amanhã às 9h.', $notices[3]['detail']);
        self::assertSame(['none'], array_values(array_unique(array_column($notices, 'push_status'))), 'Sem celular cadastrado, nada para o push.');
        self::assertSame('Manutenção', (string)$this->pdo->query("SELECT team FROM studio_support_tickets WHERE id = {$id}")->fetchColumn());
        self::assertSame($mailBefore, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue WHERE users_id = ' . $this->resident)->fetchColumn(),
            'Morador não recebe e-mail do chamado (ADR 0601).');

        // Sininho: só no vínculo do condomínio do chamado, do mais novo para o mais antigo.
        $feed = (new ResidentFeed($this->pdo))->notifications(['condominium_id' => $this->condominium, 'unit_id' => 0, 'features' => []], null, $this->resident);
        self::assertCount(5, $feed);
        self::assertSame(['ticket', '/ocorrencias/' . $id], [$feed[0]['kind'], $feed[0]['path']]);
        self::assertSame([], (new ResidentFeed($this->pdo))->notifications(['condominium_id' => $this->other, 'unit_id' => 0, 'features' => []], null, $this->resident));
        self::assertSame([], (new ResidentFeed($this->pdo))->notifications(['condominium_id' => $this->condominium, 'unit_id' => 0, 'features' => []], null, (int)$this->agent->id),
            'Quem não pediu o chamado não vê.');
    }

    public function testTeamMustComeFromTheListButOldTextStays(): void
    {
        $id = (int)(new TicketService($this->pdo))->open(['subject' => 'Boleto', 'message' => 'Segunda via do boleto.',
            'condominium_id' => $this->condominium, 'requester_id' => $this->resident], 'erp', $this->agent)['id'];
        $desk = new TicketDesk($this->pdo);
        try {
            $desk->update($this->agent, $id, ['team' => 'Suporte técnico']);
            self::fail('Equipe fora da lista deveria ser recusada.');
        } catch (TicketError $error) {
            self::assertSame('team', $error->field);
        }
        $this->pdo->exec("UPDATE studio_support_tickets SET team = 'Portaria' WHERE id = {$id}");
        self::assertSame([], $desk->update($this->agent, $id, ['team' => 'Portaria'])['changed'], 'Texto antigo continua ao salvar sem mudar.');
        self::assertSame(['team'], $desk->update($this->agent, $id, ['team' => 'Financeiro'])['changed']);
        self::assertSame(['team'], $desk->update($this->agent, $id, ['team' => ''])['changed'], 'Sem equipe também vale.');
        self::assertNull($this->pdo->query("SELECT team FROM studio_support_tickets WHERE id = {$id}")->fetchColumn());
    }

    public function testPushGoesToTheResidentPhoneAndForgetsUnregisteredDevices(): void
    {
        $push = new ResidentPush($this->pdo, function (array $messages): array {
            return array_map(static fn (array $m): array => str_contains($m['to'], 'antigo')
                ? ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']] : ['status' => 'ok', 'id' => 'x'], $messages);
        });
        $push->register($this->resident, 'ExponentPushToken[celularnovo123456]', 'android');
        $push->register($this->resident, 'ExponentPushToken[celularantigo1234]', 'ios');
        try {
            $push->register($this->resident, 'token-invalido');
            self::fail('Token inválido deveria ser recusado.');
        } catch (\InvalidArgumentException) {
        }
        (new TicketService($this->pdo))->open(['subject' => 'Portão', 'message' => 'Portão não fecha.',
            'condominium_id' => $this->condominium, 'requester_id' => $this->resident], 'erp', $this->agent);
        self::assertSame('pending', $this->notices()[0]['push_status']);

        self::assertSame(['mode' => 'log', 'sent' => 0, 'skipped' => 1, 'failed' => 0, 'tokens_removed' => 0], $push->dispatch(10, 'log'),
            'Sem CONNECT_RESIDENT_PUSH=expo nada sai.');
        $this->pdo->exec("UPDATE resident_ticket_notices SET push_status = 'pending'");
        self::assertSame(['mode' => 'expo', 'sent' => 1, 'skipped' => 0, 'failed' => 0, 'tokens_removed' => 1], $push->dispatch(10, 'expo'));
        self::assertSame(['ExponentPushToken[celularnovo123456]'], $this->pdo->query('SELECT token FROM resident_push_tokens')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame('sent', $this->notices()[0]['push_status']);

        $push->forget($this->resident, 'ExponentPushToken[celularnovo123456]');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM resident_push_tokens')->fetchColumn());
    }
}
