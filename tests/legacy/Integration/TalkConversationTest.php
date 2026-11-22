<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Api\V1\Talk;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketDesk;
use Source\Services\Support\TicketError;
use Source\Services\Talk\TalkConversation;
use Source\Services\Talk\TalkHelpdesk;
use Source\Services\Talk\TalkTokens;
use Source\Support\Access;

/**
 * Conversa dos chamados do Talk (ADR 0634): a resposta da equipe sai pelo feed com cursor, nota interna nunca sai,
 * a mensagem do WhatsApp entra no chamado e reabre o resolvido, sem duplicar; chamado de outro lugar é 404.
 */
final class TalkConversationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $agent;
    private User $staff;
    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Help Desk', 'support.manage', 'Conteúdo')");
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        $scope = new CondominiumScope($this->pdo);
        $agent = $this->createUser(['level' => 5, 'first_name' => 'Connect Talk', 'last_name' => 'Integração']);
        $scope->grant($agent, $this->alpha);
        $this->agent = (new User())->findById($agent);
        $staff = $this->createUser(['level' => 5, 'first_name' => 'Carla', 'last_name' => 'Atendente']);
        $scope->grant($staff, $this->alpha);
        $scope->grant($staff, $this->beta);
        $this->staff = (new User())->findById($staff);
        Access::clear();

        $opened = (new TalkHelpdesk($this->pdo))->open($this->agent, ['talk_protocol' => 'WA-100', 'requester_phone' => '31999998888',
            'requester_name' => 'Maria', 'condominium_id' => $this->alpha, 'message' => 'O portão da garagem não abre desde ontem.']);
        $this->ticket = (int)$opened['ticket']['id'];
        Auth::actingAs($this->agent);
        TalkTokens::setCurrent(['id' => 5, 'user' => $this->agent, 'scopes' => ['tickets.write']]);
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        TalkTokens::setCurrent(null);
        unset($_GET['after']);
        parent::tearDown();
    }

    private function call(callable $action): array
    {
        ob_start();
        $action();
        return (array)json_decode((string)ob_get_clean(), true);
    }

    public function testTeamRepliesComeThroughTheFeedWithoutInternalNotes(): void
    {
        $desk = new TicketDesk($this->pdo);
        $desk->reply($this->staff, $this->ticket, 'Nota só da equipe: chamar o técnico.', true);
        $desk->reply($this->staff, $this->ticket, 'Olá Maria, o técnico vai amanhã às 9h.', false);
        Auth::actingAs($this->agent);

        $feed = $this->call(static fn () => (new Talk())->feed());
        self::assertCount(1, $feed['data'], 'A abertura (do próprio Talk) e a nota interna não saem.');
        $item = $feed['data'][0];
        self::assertSame('Olá Maria, o técnico vai amanhã às 9h.', $item['body']);
        self::assertSame('Carla', $item['author'], 'Só o primeiro nome de quem respondeu.');
        self::assertSame('WA-100', $item['ticket']['talk_protocol']);
        self::assertSame($item['id'], $feed['meta']['next_after']);
        self::assertFalse($feed['meta']['has_more']);

        $_GET['after'] = (string)$feed['meta']['next_after'];
        $again = $this->call(static fn () => (new Talk())->feed());
        self::assertSame([], $again['data'], 'Com o cursor, nada repetido.');
        self::assertSame($feed['meta']['next_after'], $again['meta']['next_after']);

        $show = $this->call(fn () => (new Talk())->ticket(['id' => (string)$this->ticket]));
        $bodies = array_column($show['data']['messages'], 'body');
        self::assertNotContains('Nota só da equipe: chamar o técnico.', $bodies);
        self::assertContains('Olá Maria, o técnico vai amanhã às 9h.', $bodies);
    }

    public function testWhatsAppMessageGoesIntoTheTicketReopensAndIsNotDuplicated(): void
    {
        (new TicketDesk($this->pdo))->reply($this->staff, $this->ticket, 'Portão consertado.', false, 0, true);
        $service = new TalkConversation($this->pdo);
        $first = $service->post($this->agent, $this->ticket, ['message' => '<b>Voltou</b> a travar hoje cedo.', 'talk_message_id' => 'wamid.ABC']);
        self::assertFalse($first['duplicate']);
        self::assertSame('open', $first['status'], 'Resolvido reabre, como no Help Desk.');
        $again = $service->post($this->agent, $this->ticket, ['message' => 'Voltou a travar hoje cedo.', 'talk_message_id' => 'wamid.ABC']);
        self::assertTrue($again['duplicate']);
        self::assertSame($first['message_id'], $again['message_id']);
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_ticket_messages WHERE ticket_id = {$this->ticket} AND message LIKE '%travar%'")->fetchColumn();
        self::assertSame(1, $count);
        self::assertSame('Voltou a travar hoje cedo.', (string)$this->pdo->query("SELECT message FROM studio_support_ticket_messages WHERE id = {$first['message_id']}")->fetchColumn(), 'Sem HTML.');

        $show = $service->show($this->agent, $this->ticket);
        $mine = array_values(array_filter($show['messages'], static fn (array $m): bool => $m['id'] === $first['message_id']))[0];
        self::assertTrue($mine['from_talk']);
        self::assertSame('WhatsApp', $mine['author']);

        $feed = $service->feed($this->agent);
        self::assertNotContains($first['message_id'], array_column($feed['data'], 'id'), 'O que veio do WhatsApp não volta no feed.');
    }

    public function testTicketsOutsideTheTalkOrTheScopeAreNotFound(): void
    {
        $service = new TalkConversation($this->pdo);
        $beta = (new TalkHelpdesk($this->pdo))->open(null, ['talk_protocol' => 'WA-200', 'requester_phone' => '31988887777', 'condominium_id' => $this->beta]);
        $erp = $this->pdo->prepare("INSERT INTO studio_support_tickets (protocol, subject, message, area, origin, condominium_id, priority, status, due_at) VALUES ('CHTESTE00001', 'x', 'y', 'general', 'erp', ?, 'medium', 'open', NOW())");
        $erp->execute([$this->alpha]);
        $erpTicket = (int)$this->pdo->lastInsertId();
        foreach ([(int)$beta['ticket']['id'], $erpTicket, 999999] as $hidden) {
            try {
                $service->post($this->agent, $hidden, ['message' => 'Mensagem de teste']);
                self::fail('Chamado fora do Talk ou do escopo tem de ser 404.');
            } catch (TicketError $error) {
                self::assertSame(404, $error->status());
            }
            self::assertSame('not_found', $this->call(static fn () => (new Talk())->ticket(['id' => (string)$hidden]))['error']['code']);
        }
        (new TicketDesk($this->pdo))->reply($this->staff, (int)$beta['ticket']['id'], 'Resposta no Beta.', false);
        Auth::actingAs($this->agent);
        self::assertNotContains('Resposta no Beta.', array_column($service->feed($this->agent)['data'], 'body'));

        TalkTokens::setCurrent(['id' => 5, 'user' => $this->agent, 'scopes' => ['people.read']]);
        self::assertSame('insufficient_scope', $this->call(static fn () => (new Talk())->feed())['error']['code']);
    }

    /** ADR 0636: o link público mostra a situação e as respostas, sem a descrição nem as notas internas. */
    public function testTrackingLinkShowsStatusAndPublicRepliesOnly(): void
    {
        $summary = (new TalkHelpdesk($this->pdo))->summary($this->ticket);
        $row = $this->pdo->query('SELECT protocol, access_token FROM studio_support_tickets WHERE id = ' . $this->ticket)->fetch(\PDO::FETCH_ASSOC);
        self::assertMatchesRegularExpression('#/suporte/chamado/' . $row['protocol'] . '\?c=[a-f0-9]{64}$#', (string)$summary['tracking_url']);
        self::assertStringEndsWith('?c=' . $row['access_token'], (string)$summary['tracking_url']);

        $desk = new TicketDesk($this->pdo);
        $desk->reply($this->staff, $this->ticket, 'Nota só da equipe: chamar o técnico.', true);
        $desk->reply($this->staff, $this->ticket, 'Olá Maria, o técnico vai amanhã às 9h.', false);
        (new TalkConversation($this->pdo))->post($this->agent, $this->ticket, ['message' => 'Obrigada!', 'talk_message_id' => 'M1']);

        $tracking = new \Source\Services\Support\TicketTracking($this->pdo);
        $page = $tracking->find(strtolower($row['protocol']), $row['access_token']);
        self::assertNotNull($page);
        self::assertSame('Em atendimento', $page['status_label'], 'A resposta de quem pediu volta o chamado para atendimento.');
        self::assertSame([['Carla', false, 'Olá Maria, o técnico vai amanhã às 9h.'], ['Você', true, 'Obrigada!']],
            array_map(static fn (array $m): array => [$m['author'], $m['mine'], $m['body']], $page['messages']));
        self::assertStringNotContainsString('portão da garagem', json_encode($page, JSON_UNESCAPED_UNICODE), 'A descrição da abertura não sai.');

        (new TalkHelpdesk($this->pdo))->close($this->agent, $this->ticket, ['resolution' => 'Técnico trocou o motor.']);
        $closed = $tracking->find($row['protocol'], $row['access_token']);
        self::assertTrue($closed['closed']);
        self::assertSame('Equipe Connect', end($closed['messages'])['author'], 'O encerramento é da equipe, não de quem pediu.');

        self::assertNull($tracking->find($row['protocol'], str_repeat('0', 64)), 'Código errado = não encontrado.');
        self::assertNull($tracking->find($row['protocol'], ''));
        self::assertNull($tracking->find('CH000000AAAA', $row['access_token']));
    }

    /** ADR 0637: o chamado aberto pelo Talk usa o protocolo da conversa, e a busca do SAC acha pelo número do Talk. */
    public function testTicketUsesTheTalkProtocolAndIsFoundBySearch(): void
    {
        $helpdesk = new TalkHelpdesk($this->pdo);
        $new = $helpdesk->open($this->agent, ['talk_protocol' => '26112200403', 'requester_phone' => '31999998888',
            'condominium_id' => $this->alpha, 'subject' => 'Portão da garagem', 'message' => 'O portão não fecha desde ontem.', 'area' => 'technical']);
        self::assertSame('26112200403', $new['ticket']['protocol'], 'Mesmo número no Talk e no SAC.');
        self::assertSame('Portão da garagem', (string)$this->pdo->query('SELECT subject FROM studio_support_tickets WHERE id = ' . (int)$new['ticket']['id'])->fetchColumn());

        $old = $helpdesk->open($this->agent, ['talk_protocol' => 'SAC26112200404', 'requester_phone' => '31999998888', 'condominium_id' => $this->alpha]);
        self::assertSame('26112200404', $old['ticket']['protocol'], 'O protocolo antigo, com SAC na frente, vira só o número.');
        self::assertSame('SAC26112200404', $old['ticket']['talk_protocol']);

        // número já usado por outro chamado: fica o protocolo do ERP, sem duplicar
        $this->pdo->prepare("INSERT INTO studio_support_tickets (protocol, subject, message, area, origin, condominium_id, priority, status, due_at) VALUES ('26112200405', 'x', 'y', 'general', 'erp', ?, 'medium', 'open', NOW())")->execute([$this->alpha]);
        $clash = $helpdesk->open($this->agent, ['talk_protocol' => '26112200405', 'requester_phone' => '31999998888', 'condominium_id' => $this->alpha]);
        self::assertMatchesRegularExpression('/^CH\d{6}[A-F0-9]{4}$/', $clash['ticket']['protocol']);

        $service = new \Source\Services\Support\TicketService($this->pdo, new CondominiumScope($this->pdo));
        foreach (['26112200403', 'SAC26112200404', '26112200405'] as $q) {
            $found = array_column($service->listFor($this->staff, ['q' => $q]), 'protocol');
            self::assertNotSame([], $found, "A busca do SAC acha $q.");
        }

        $token = (string)$this->pdo->query('SELECT access_token FROM studio_support_tickets WHERE id = ' . (int)$new['ticket']['id'])->fetchColumn();
        self::assertNotNull((new \Source\Services\Support\TicketTracking($this->pdo))->find('26112200403', $token), 'O link de acompanhamento aceita o protocolo do Talk.');
    }
}
