<?php
declare(strict_types=1);

namespace MovesOSTests\Unit\Talk;

use Source\Services\Talk\Domain\InboundMessage;
use Source\Services\Talk\Domain\StatusUpdate;
use Source\Services\Talk\Gateway\CloudApiGateway;
use Source\Services\Talk\Gateway\EvolutionGateway;
use Source\Services\Talk\Gateway\GatewayException;
use MovesOSTests\Fakes\Talk\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class GatewayParsingTest extends TestCase
{
    private function cloud(?FakeHttpClient $http = null): CloudApiGateway
    {
        return new CloudApiGateway(['phone_number_id' => '123', 'access_token' => 'TOKEN', 'app_secret' => 'segredo', 'verify_token' => 'vt'], $http ?? new FakeHttpClient());
    }

    public function test_cloud_api_le_mensagem_e_status(): void
    {
        $payload = json_encode(['entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => '5531999998888', 'profile' => ['name' => 'Maria']]],
            'messages' => [['from' => '5531999998888', 'id' => 'wamid.A', 'type' => 'text', 'text' => ['body' => 'Olá']]],
            'statuses' => [['id' => 'wamid.B', 'status' => 'read']],
        ]]]]]]);
        $items = $this->cloud()->parseWebhook($payload);
        $this->assertInstanceOf(InboundMessage::class, $items[0]);
        $this->assertSame('Olá', $items[0]->body);
        $this->assertSame('Maria', $items[0]->contactName);
        $this->assertEquals(new StatusUpdate('wamid.B', 'read'), $items[1]);
    }

    public function test_cloud_api_confere_assinatura(): void
    {
        $body = '{"a":1}';
        $ok = 'sha256=' . hash_hmac('sha256', $body, 'segredo');
        $this->assertTrue($this->cloud()->verifyWebhook($body, ['X-Hub-Signature-256' => $ok]));
        $this->assertFalse($this->cloud()->verifyWebhook($body, ['X-Hub-Signature-256' => 'sha256=errado']));
        $this->assertFalse($this->cloud()->verifyWebhook($body, []));
    }

    public function test_cloud_api_verificacao_do_webhook(): void
    {
        $this->assertSame('42', $this->cloud()->verifySubscription(['hub_mode' => 'subscribe', 'hub_verify_token' => 'vt', 'hub_challenge' => '42']));
        $this->assertNull($this->cloud()->verifySubscription(['hub_mode' => 'subscribe', 'hub_verify_token' => 'x', 'hub_challenge' => '42']));
    }

    public function test_cloud_api_envia_modelo_para_boleto(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = ['status' => 200, 'body' => '{"messages":[{"id":"wamid.X"}]}'];
        $r = $this->cloud($http)->sendTemplate('5531999998888', 'boleto_disponivel', 'pt_BR', ['Maria', 'outubro', 'https://x/b'], 'fallback');
        $this->assertSame('wamid.X', $r->providerMessageId);
        $call = $http->calls[0];
        $this->assertSame('https://graph.facebook.com/v21.0/123/messages', $call['url']);
        $this->assertSame('Bearer TOKEN', $call['headers']['Authorization']);
        $this->assertSame('boleto_disponivel', $call['body']['template']['name']);
        $this->assertCount(3, $call['body']['template']['components'][0]['parameters']);
    }

    public function test_cloud_api_erro_vira_excecao(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = ['status' => 400, 'body' => '{"error":{"message":"Template not approved"}}'];
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Template not approved');
        $this->cloud($http)->sendText('55', 'x');
    }

    public function test_evolution_le_mensagens_e_ignora_grupos_e_proprias(): void
    {
        $gw = new EvolutionGateway(['base_url' => 'http://evo', 'instance' => 'i', 'api_key' => 'k', 'webhook_secret' => 's'], new FakeHttpClient());
        $mk = fn (string $jid, bool $fromMe, array $msg) => json_encode(['event' => 'messages.upsert', 'data' => ['key' => ['remoteJid' => $jid, 'fromMe' => $fromMe, 'id' => 'ID' . md5($jid . $fromMe)], 'pushName' => 'Zé', 'message' => $msg]]);

        $items = $gw->parseWebhook($mk('5531999998888@s.whatsapp.net', false, ['extendedTextMessage' => ['text' => 'oi']]));
        $this->assertSame('oi', $items[0]->body);
        $this->assertSame('5531999998888', $items[0]->fromPhone);
        $this->assertSame([], $gw->parseWebhook($mk('123@g.us', false, ['conversation' => 'grupo'])));
        $this->assertSame([], $gw->parseWebhook($mk('5531999998888@s.whatsapp.net', true, ['conversation' => 'eu'])));

        $img = $gw->parseWebhook($mk('5531999998888@s.whatsapp.net', false, ['imageMessage' => ['caption' => 'foto do vazamento', 'mimetype' => 'image/jpeg']]));
        $this->assertSame('image', $img[0]->type);
        $this->assertSame('foto do vazamento', $img[0]->body);

        $ack = $gw->parseWebhook(json_encode(['event' => 'MESSAGES_UPDATE', 'data' => ['keyId' => 'K1', 'status' => 'DELIVERY_ACK']]));
        $this->assertEquals(new StatusUpdate('K1', 'delivered'), $ack[0]);
    }

    public function test_evolution_exige_segredo(): void
    {
        $gw = new EvolutionGateway(['base_url' => 'http://evo', 'instance' => 'i', 'api_key' => 'k', 'webhook_secret' => 's3'], new FakeHttpClient());
        $this->assertTrue($gw->verifyWebhook('{}', ['X-Talk-Secret' => 's3']));
        $this->assertFalse($gw->verifyWebhook('{}', ['X-Talk-Secret' => 'nao']));
    }

    public function test_evolution_espera_o_qr_que_o_whatsapp_manda_depois(): void
    {
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 404, 'body' => '{}'],                                                  // número novo
            ['status' => 201, 'body' => '{"instance":{"status":"close"},"qrcode":{"count":0}}'], // criou, ainda sem QR
            ['status' => 201, 'body' => '{}'],                                                  // webhook/set
            ['status' => 200, 'body' => '{"instance":{"state":"connecting"}}'],                 // connectionState
            ['status' => 200, 'body' => '{"count":0}'],                                         // ainda gerando
            ['status' => 200, 'body' => '{"pairingCode":null,"code":"2@x","base64":"data:image/png;base64,QUJD","count":1}'],
        ];
        $naps = 0;
        $gw = new EvolutionGateway(['base_url' => 'http://evo', 'instance' => 'talk-1', 'api_key' => 'k', 'webhook_secret' => 's'], $http, 10, function () use (&$naps) { $naps++; });
        $r = $gw->connect('http://app/webhook/1', 's');
        $this->assertSame('data:image/png;base64,QUJD', $r['qr']);
        $this->assertSame(1, $naps);
        $this->assertSame('http://app/webhook/1', $http->calls[2]['body']['webhook']['url']);

        // sem resposta do WhatsApp: desiste depois do tempo e diz o que fazer
        $http->responses = array_merge([['status' => 200, 'body' => '{"instance":{"state":"connecting"}}'], ['status' => 200, 'body' => '{"instance":{"state":"connecting"}}']],
            array_fill(0, 4, ['status' => 200, 'body' => '{"count":0}']));
        $gw = new EvolutionGateway(['base_url' => 'http://evo', 'instance' => 'talk-1', 'api_key' => 'k'], $http, 3, fn () => null);
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('não mandou o QR Code a tempo');
        $gw->connect();
    }

    public function test_evolution_troca_o_codigo_lid_pelo_telefone(): void
    {
        $gw = new EvolutionGateway(['base_url' => 'http://evo', 'instance' => 'i', 'api_key' => 'k', 'webhook_secret' => 's'], new FakeHttpClient());
        $body = fn (array $key) => json_encode(['event' => 'messages.upsert', 'data' => ['key' => $key + ['fromMe' => false, 'id' => 'L1'],
            'pushName' => 'Djalma', 'message' => ['conversation' => 'bom dia, estamos sem água']]]);
        $this->assertSame('5531987235451', $gw->parseWebhook($body(['remoteJid' => '187743727853723@lid', 'remoteJidAlt' => '5531987235451@s.whatsapp.net', 'addressingMode' => 'lid']))[0]->fromPhone);
        $this->assertSame('5531987235451', $gw->parseWebhook($body(['remoteJid' => '187743727853723@lid', 'senderPn' => '5531987235451@s.whatsapp.net']))[0]->fromPhone);
        $this->assertSame('5531987235451', $gw->parseWebhook($body(['remoteJid' => '5531987235451@s.whatsapp.net']))[0]->fromPhone);
    }
}
