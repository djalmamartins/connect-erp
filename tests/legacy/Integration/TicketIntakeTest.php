<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Environments;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;

/**
 * Chamados de qualquer origem caem na fila do Help Desk com condomínio, unidade e solicitante,
 * e a troca de ambiente entre hosts usa código de uso único (ADR 0034).
 */
final class TicketIntakeTest extends TestCase
{
    public function testErpTicketLandsInHelpDeskQueueWithCondominiumUnitAndRequester(): void
    {
        $condo = $this->condominium('Residencial Alfa');
        $unit = $this->unit($condo, 'B', '101');
        $agent = $this->user(5);
        $user = $this->user();
        (new CondominiumScope($this->pdo))->grant((int)$user->id, $condo);

        $opened = (new TicketService($this->pdo))->open([
            'subject' => 'Interfone sem funcionar', 'message' => 'O interfone da unidade não toca desde ontem.',
            'priority' => 'high', 'condominium_id' => $condo, 'unit_id' => $unit,
            'requester_name' => 'Maria Moradora', 'requester_email' => 'maria@example.com', 'requester_phone' => '11 98888-7777',
        ], 'erp', $user);

        self::assertMatchesRegularExpression('/^CH\d{6}[A-F0-9]{4}$/', $opened['protocol']);
        $row = $this->pdo->query('SELECT * FROM studio_support_tickets WHERE id = ' . $opened['id'])->fetch();
        self::assertSame('erp', $row->origin);
        self::assertSame($condo, (int)$row->condominium_id);
        self::assertSame($unit, (int)$row->unit_id);
        self::assertSame('Maria Moradora', $row->requester_name);
        self::assertSame('11 98888-7777', $row->requester_phone);
        self::assertSame((int)$user->id, (int)$row->created_by);
        self::assertSame('high', $row->priority);
        self::assertNotNull($row->access_token, 'Solicitante sem conta recebe código de acompanhamento.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_ticket_events WHERE ticket_id = {$opened['id']} AND event_type = 'origin' AND new_value = 'erp'")->fetchColumn());

        // A equipe do Help Desk é avisada, com link para a fila no host do Help Desk.
        $notice = $this->pdo->query('SELECT * FROM notifications WHERE users_id = ' . (int)$agent->id)->fetch();
        self::assertNotFalse($notice);
        self::assertStringContainsString('Residencial Alfa', (string)$notice->body);
        self::assertStringContainsString('/helpdesk/tickets?ticket=' . $opened['id'], (string)$notice->link);

        // O ERP lista o chamado com o contexto.
        $list = (new TicketService($this->pdo))->listFor($user);
        self::assertCount(1, $list);
        self::assertSame('Residencial Alfa', $list[0]['condominium']['name']);
        self::assertSame('B - 101', $list[0]['unit']['label']);
        self::assertSame('Maria Moradora', $list[0]['requester']['name']);
    }

    public function testRequesterDefaultsToWhoOpensIt(): void
    {
        $user = $this->user();
        $opened = (new TicketService($this->pdo))->open(['subject' => 'Dúvida geral', 'message' => 'Como emito a segunda via do boleto?'], 'erp', $user);
        $row = $this->pdo->query('SELECT requester_id, access_token, condominium_id FROM studio_support_tickets WHERE id = ' . $opened['id'])->fetch();
        self::assertSame((int)$user->id, (int)$row->requester_id);
        self::assertNull($row->access_token);
        self::assertNull($row->condominium_id);
    }

    public function testCondominiumOutsideScopeAndForeignUnitAreRefused(): void
    {
        $mine = $this->condominium('Meu');
        $other = $this->condominium('Outro');
        $foreignUnit = $this->unit($other, '', '7');
        $user = $this->user();
        (new CondominiumScope($this->pdo))->grant((int)$user->id, $mine);
        $service = new TicketService($this->pdo);
        $base = ['subject' => 'Chamado qualquer', 'message' => 'Descrição suficiente do chamado.'];

        try {
            $service->open($base + ['condominium_id' => $other], 'erp', $user);
            self::fail('Condomínio fora do escopo foi aceito.');
        } catch (TicketError $error) {
            self::assertSame(403, $error->status());
        }
        try {
            $service->open($base + ['condominium_id' => $mine, 'unit_id' => $foreignUnit], 'erp', $user);
            self::fail('Unidade de outro condomínio foi aceita.');
        } catch (TicketError $error) {
            self::assertSame('unit_id', $error->field);
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM studio_support_tickets')->fetchColumn());
    }

    public function testListOnlyShowsTicketsFromScopeOrOwn(): void
    {
        $mine = $this->condominium('Meu');
        $other = $this->condominium('Outro');
        $user = $this->user();
        $stranger = $this->user();
        $scope = new CondominiumScope($this->pdo);
        $scope->grant((int)$user->id, $mine);
        $scope->grant((int)$stranger->id, $other);
        $service = new TicketService($this->pdo);
        $base = ['subject' => 'Chamado qualquer', 'message' => 'Descrição suficiente do chamado.'];
        $visible = $service->open($base + ['condominium_id' => $mine], 'erp', $this->withScope($stranger, $mine));
        $hidden = $service->open($base + ['condominium_id' => $other], 'erp', $stranger);

        $ids = array_column($service->listFor($user), 'id');
        self::assertContains($visible['id'], $ids);
        self::assertNotContains($hidden['id'], $ids);
        self::assertNull($service->findFor($user, $hidden['id']));
        self::assertNotNull($service->findFor($user, $visible['id']));
    }

    public function testHandoffCodeIsSingleUseAndBoundToEnvironment(): void
    {
        $user = $this->user();
        $environments = new Environments($this->pdo);
        $token = $environments->issue($user, 'helpdesk');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM auth_environment_handoffs WHERE token_hash = '{$token}'")->fetchColumn(), 'O banco guarda só o hash.');

        self::assertNull($environments->consume($token, 'studio'), 'Código do Help Desk não abre o Studio.');
        self::assertSame((int)$user->id, $environments->consume($token, 'helpdesk'));
        self::assertNull($environments->consume($token, 'helpdesk'), 'Segundo uso é recusado.');

        $expired = $environments->issue($user, 'erp');
        $this->pdo->exec("UPDATE auth_environment_handoffs SET expires_at = NOW() - INTERVAL 1 SECOND WHERE token_hash = '" . hash('sha256', $expired) . "'");
        self::assertNull($environments->consume($expired, 'erp'), 'Código vencido é recusado.');
        self::assertNull($environments->consume('nao-hex', 'erp'));
    }

    private function withScope(User $user, int $condominiumId): User
    {
        (new CondominiumScope($this->pdo))->grant((int)$user->id, $condominiumId);
        return $user;
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare("INSERT INTO condominiums (name, status) VALUES (?, 'active')")->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }

    private function unit(int $condominiumId, string $block, string $number): int
    {
        $this->pdo->prepare('INSERT INTO erp_units (condominium_id, block, number) VALUES (?, ?, ?)')->execute([$condominiumId, $block, $number]);
        return (int)$this->pdo->lastInsertId();
    }

    private function user(int $level = 2): User
    {
        return (new User())->findById($this->createUser(['level' => $level]));
    }
}
