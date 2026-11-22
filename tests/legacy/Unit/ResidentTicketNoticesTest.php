<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Resident\ResidentPush;
use Source\Services\Resident\ResidentTicketNotices;
use Source\Services\Support\TicketService;

/** ADR 0642: regras puras dos avisos do chamado no app do morador, do push e da lista de equipes. */
final class ResidentTicketNoticesTest extends TestCase
{
    private const TICKET = ['protocol' => 'SAC202611220003', 'subject' => 'Vazamento na garagem'];

    public function testOpeningConfirmsTheProtocol(): void
    {
        $notice = ResidentTicketNotices::describe('opened', self::TICKET);
        self::assertSame('Chamado SAC202611220003 aberto', $notice['title']);
        self::assertStringContainsString('Vazamento na garagem', $notice['detail']);
        self::assertSame('Ocorrência OCO202611220001 aberta', ResidentTicketNotices::describe('opened', ['protocol' => 'OCO202611220001', 'subject' => 'x'])['title']);
    }

    public function testStatusTeamAndResponsibleBecomeOneNotice(): void
    {
        $notice = ResidentTicketNotices::describe('status', self::TICKET, ['status' => 'in_progress', 'team' => 'Manutenção', 'assigned_to' => 7, 'assigned_name' => 'Ana']);
        self::assertSame('status', $notice['event']);
        self::assertSame('Chamado SAC202611220003: Em atendimento', $notice['title']);
        self::assertSame('A equipe está cuidando do seu chamado. Encaminhado para a equipe Manutenção. Ana, da equipe Connect, vai acompanhar o seu chamado.', $notice['detail']);

        $team = ResidentTicketNotices::describe('team', self::TICKET, ['team' => 'Financeiro']);
        self::assertSame(['team', 'Chamado SAC202611220003 com a equipe Financeiro'], [$team['event'], $team['title']]);

        self::assertSame('warning', ResidentTicketNotices::describe('status', self::TICKET, ['status' => 'waiting_customer'])['tone']);
        self::assertSame('success', ResidentTicketNotices::describe('status', self::TICKET, ['status' => 'resolved'])['tone']);
        self::assertNull(ResidentTicketNotices::describe('team', self::TICKET, ['team' => null]), 'Tirar a equipe não avisa.');
        self::assertNull(ResidentTicketNotices::describe('assigned', self::TICKET, ['assigned_to' => null]));
    }

    public function testReplyShowsTheAnswerAndTheResolution(): void
    {
        $notice = ResidentTicketNotices::describe('reply', self::TICKET, ['text' => "O encanador   vai amanhã\n às 9h.", 'status' => 'resolved']);
        self::assertSame('Resposta no chamado SAC202611220003 (resolvido)', $notice['title']);
        self::assertSame('O encanador vai amanhã às 9h.', $notice['detail']);
        self::assertSame('Resposta na ocorrência OCO202611220001', ResidentTicketNotices::describe('reply', ['protocol' => 'OCO202611220001', 'subject' => 'x'], ['text' => 'ok'])['title']);
        self::assertSame('/ocorrencias/12', ResidentTicketNotices::path(12));
    }

    public function testTeamListAcceptsCaseAndAccentsAndRefusesFreeText(): void
    {
        self::assertSame(['Administrativo', 'Financeiro', 'Manutenção', 'Geral'], TicketService::TEAMS);
        self::assertSame('Manutenção', TicketService::team('manutencao'));
        self::assertSame('Financeiro', TicketService::team(' FINANCEIRO '));
        self::assertNull(TicketService::team(''));
        self::assertFalse(TicketService::team('Suporte técnico'));
    }

    public function testPushTokenAndMessage(): void
    {
        self::assertTrue(ResidentPush::validToken('ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]'));
        self::assertTrue(ResidentPush::validToken('ExpoPushToken[abc_DEF-1234567]'));
        self::assertFalse(ResidentPush::validToken('qualquer coisa'));
        self::assertFalse(ResidentPush::validToken('ExponentPushToken[<script>]'));
        $message = ResidentPush::message('ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]', ['ticket_id' => 5, 'title' => 'Resposta', 'detail' => str_repeat('a', 300)]);
        self::assertSame('/chamados/5', $message['data']['path']);
        self::assertLessThanOrEqual(178, mb_strlen($message['body']));
    }
}
