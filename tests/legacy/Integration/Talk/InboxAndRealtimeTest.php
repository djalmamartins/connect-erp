<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Realtime\SseStream;
use Source\Services\Talk\Service\AccessPolicy;
use Source\Services\Talk\Service\InboxQuery;
use Source\Services\Talk\Service\OutboxWorker;
use Source\Services\Talk\Realtime\EventBus;

final class InboxAndRealtimeTest extends DbTestCase
{
    public function test_cada_setor_so_ve_a_propria_fila(): void
    {
        $this->routed('boleto atrasado', '5531911111111');
        $this->routed('vazamento na garagem', '5531922222222');
        $q = new InboxQuery($this->db, new AccessPolicy());

        $fin = $q->list($this->agent(10, 'Financeiro'), 'pending');
        $man = $q->list($this->agent(20, 'Manutenção'), 'pending');
        $all = $q->list(new StaffMember(1, true), 'pending');

        $this->assertCount(1, $fin);
        $this->assertSame('Financeiro', $fin[0]['sector_name']);
        $this->assertCount(1, $man);
        $this->assertCount(2, $all);
    }

    public function test_atendente_nao_ve_conversa_aberta_de_colega_mas_gerente_ve(): void
    {
        $id = $this->routed('preciso do boleto');
        $this->svc->accept($id, $this->agent(10, 'Financeiro'));
        $q = new InboxQuery($this->db, new AccessPolicy());
        $this->assertCount(0, $q->list($this->agent(11, 'Financeiro'), 'open'));
        $this->assertCount(1, $q->list(new StaffMember(30, false, [], [$this->sectors['Financeiro']]), 'open'));
        $this->assertCount(1, $q->list($this->agent(10, 'Financeiro'), 'mine'));
    }

    public function test_busca_o_que_chegou_e_o_que_saiu(): void
    {
        $id = $this->routed('boleto de outubro atrasado');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->reply($id, $ana, 'Vou gerar o boleto atualizado');
        $q = new InboxQuery($this->db, new AccessPolicy());

        $chegou = $q->search($ana, ['q' => 'boleto', 'direction' => 'in']);
        $saiu = $q->search($ana, ['q' => 'boleto', 'direction' => 'out']);
        $this->assertCount(1, $chegou);
        $this->assertStringContainsString('outubro', $chegou[0]['body']);
        $this->assertCount(1, $saiu);
        $this->assertSame([], $q->search($this->agent(20, 'Manutenção'), ['q' => 'boleto']));
    }

    public function test_busca_pelo_numero_do_protocolo_novo_ou_antigo(): void
    {
        $id = $this->routed('boleto de outubro atrasado');
        $this->svc->reply($id, $this->agent(10, 'Financeiro'), 'Vou gerar o boleto');
        $this->db->run("UPDATE talk_conversations SET legacy_protocol = '20261115-00004' WHERE id = ?", [$id]);
        $proto = $this->conv($id)['protocol'];
        $q = new InboxQuery($this->db, new AccessPolicy());
        foreach ([$proto, strtolower($proto), '20261115-00004'] as $term) {
            $r = $q->search($this->agent(10, 'Financeiro'), ['q' => $term, 'contact' => $term]);
            $this->assertSame([$id], array_column($r, 'conversation_id'), "busca por $term: uma linha, a conversa");
        }
        $this->assertSame([], $q->search($this->agent(20, 'Manutenção'), ['q' => $proto]), 'outro setor não acha');
    }

    public function test_sse_entrega_so_eventos_permitidos_e_transferencia_avisa_origem(): void
    {
        $sse = new SseStream($this->db, new AccessPolicy());
        $ana = $this->agent(10, 'Financeiro');
        $beto = $this->agent(20, 'Manutenção');

        $id = $this->routed('segunda via do boleto');
        $this->assertNotEmpty($sse->fetch($ana, 0));
        $this->assertSame([], $sse->fetch($beto, 0));

        $last = (int) $this->db->one('SELECT MAX(id) m FROM talk_events')['m'];
        $this->svc->accept($id, $ana);
        $this->svc->transfer($id, $ana, $this->sectors['Manutenção'], 'é com vocês');

        $types = array_column($sse->fetch($ana, $last), 'type');
        $this->assertContains('conversation.transferred', $types);
        $this->assertContains('conversation.updated', array_column($sse->fetch($beto, $last), 'type'));
    }

    public function test_jaqueline_mostra_digitando_antes_de_enviar(): void
    {
        $this->routed('quero a segunda via do boleto');
        $this->inbound('você é um robô?');   // Jaqueline se apresenta (texto aprovado)
        $w = new OutboxWorker($this->db, $this->gateways(), new EventBus($this->db));

        $this->http->responses[] = ['status' => 201, 'body' => '{}'];
        $this->assertSame(0, $w->runOnce(), 'primeiro só o digitando…');
        $this->assertSame('http://evo:8080/chat/sendPresence/connect', $this->http->calls[0]['url']);
        $this->assertSame('composing', $this->http->calls[0]['body']['presence']);
        $job = $this->db->one('SELECT *, next_attempt_at > NOW() AS later FROM talk_outbox');
        $this->assertSame(0, (int) $job['typing_seconds']);
        $this->assertSame(1, (int) $job['later'], 'mensagem só sai depois da digitação');
        $this->assertSame(0, $w->runOnce(), 'ainda digitando');
        $this->assertSame('pending', $this->db->one("SELECT ack FROM talk_messages WHERE direction='out'")['ack']);
    }

    public function test_worker_envia_e_tenta_de_novo_quando_falha(): void
    {
        $id = $this->routed('quero a segunda via do boleto');
        $this->inbound('você é um robô?');
        $this->db->run('UPDATE talk_outbox SET typing_seconds = 0');  // sem digitando neste teste
        $this->http->responses[] = new \RuntimeException('timeout');
        $w = new OutboxWorker($this->db, $this->gateways(), new EventBus($this->db));

        $this->assertSame(0, $w->runOnce());
        $job = $this->db->one('SELECT * FROM talk_outbox');
        $this->assertSame('queued', $job['status']);
        $this->assertSame(1, (int) $job['attempts']);

        $this->db->run('UPDATE talk_outbox SET next_attempt_at = NOW()');
        $this->assertSame(1, $w->runOnce());
        $msg = $this->db->one("SELECT ack, provider_message_id FROM talk_messages WHERE direction='out'");
        $this->assertSame('sent', $msg['ack']);
        $this->assertSame('OUT1', $msg['provider_message_id']);
        $this->assertSame('http://evo:8080/message/sendText/connect', $this->http->calls[1]['url']);
        $this->assertSame('5531999998888', $this->http->calls[1]['body']['number']);
    }

    public function test_canal_de_massa_prefere_o_oficial(): void
    {
        $official = $this->db->insert('talk_channels', [
            'name' => 'Oficial', 'kind' => 'official', 'purpose' => 'bulk',
            'config_encrypted' => $this->crypto->encrypt(['phone_number_id' => '1', 'access_token' => 't', 'app_secret' => 's', 'verify_token' => 'v']),
        ]);
        $this->assertSame($official, $this->gateways()->bulkChannelId());
        $this->assertInstanceOf(\Source\Services\Talk\Gateway\CloudApiGateway::class, $this->gateways()->forChannel($official));
    }
}
