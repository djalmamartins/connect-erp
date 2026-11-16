<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\InboundMessage;
use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Service\AccessPolicy;
use Source\Services\Talk\Service\DomainError;
use Source\Services\Talk\Service\InboxQuery;
use Source\Services\Talk\Service\OutboxWorker;

final class MediaTest extends DbTestCase
{
    // PNG 1x1 de verdade (o tipo é conferido pelo conteúdo)
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private function worker(): OutboxWorker
    {
        return new OutboxWorker($this->db, $this->gateways(), new EventBus($this->db), $this->media);
    }

    public function test_atendente_envia_pdf_com_legenda_assinada_e_worker_manda_pela_evolution(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(2, 'Financeiro');
        $this->db->run('DELETE FROM talk_outbox'); // a mensagem da Jack não interessa aqui
        $msgId = $this->svc->sendFile($id, $ana, self::PDF, 'application/pdf', 'boleto nov.pdf', 'Segue o boleto', 'Ana');

        $m = $this->db->one('SELECT * FROM talk_messages WHERE id = ?', [$msgId]);
        $this->assertSame('document', $m['type']);
        $this->assertSame("*Ana:*\nSegue o boleto", $m['body']);
        $this->assertSame('boleto nov.pdf', $m['media_name']);
        $this->assertFileExists($this->mediaRoot . '/' . $m['media_path']);
        $this->assertSame('open', $this->conv($id)['status'], 'enviar arquivo assume a conversa, como responder');

        $this->assertSame(1, $this->worker()->runOnce());
        $call = end($this->http->calls);
        $this->assertSame('http://evo:8080/message/sendMedia/connect', $call['url']);
        $this->assertSame('document', $call['body']['mediatype']);
        $this->assertSame('boleto nov.pdf', $call['body']['fileName']);
        $this->assertSame(self::PDF, base64_decode($call['body']['media']));
        $this->assertSame('sent', $this->db->one('SELECT ack FROM talk_messages WHERE id = ?', [$msgId])['ack']);
    }

    public function test_recusa_tipo_nao_aceito_tamanho_e_quem_nao_tem_acesso(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        foreach (["<html><script>alert(1)</script></html>", "MZ\x90\x00executavel"] as $bad) {
            try {
                $this->svc->sendFile($id, $this->agent(2, 'Financeiro'), $bad, 'image/png', 'foto.png');
                $this->fail('deveria recusar');
            } catch (DomainError $e) {
                $this->assertStringContainsString('Tipo de arquivo', $e->getMessage());
            }
        }
        try {
            $this->svc->sendFile($id, $this->agent(4, 'Manutenção'), base64_decode(self::PNG), 'image/png', 'x.png');
            $this->fail('outro setor não envia');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertDirectoryDoesNotExist($this->mediaRoot . '/media', 'nada gravado quando recusa');
        $this->expectExceptionMessage('16 MB');
        $this->svc->sendFile($id, $this->agent(2, 'Financeiro'), str_repeat('a', 16 * 1024 * 1024 + 1), 'text/plain', 'grande.txt');
    }

    public function test_audio_vai_sem_legenda_pela_rota_de_audio(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $this->db->run('DELETE FROM talk_outbox');
        $ogg = "OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x1eOpusHead" . str_repeat("\x00", 40);
        $msgId = $this->svc->sendFile($id, $this->agent(2, 'Financeiro'), $ogg, 'audio/ogg', 'audio.ogg', 'ignorada', 'Ana');
        $m = $this->db->one('SELECT type, body, media_mime FROM talk_messages WHERE id = ?', [$msgId]);
        $this->assertSame(['audio', null, 'audio/ogg'], [$m['type'], $m['body'], $m['media_mime']]);
        $this->worker()->runOnce();
        $this->assertSame('http://evo:8080/message/sendWhatsAppAudio/connect', end($this->http->calls)['url']);
    }

    public function test_imagem_recebida_e_baixada_pelo_worker_e_tenta_de_novo_quando_falha(): void
    {
        $this->svc->handleInbound($this->channelId, new InboundMessage('IMG1', '5531999998888', 'image', 'olha o vazamento', 'Maria', 'IMG1', 'image/jpeg'));
        $this->assertSame('pending:IMG1', $this->db->one("SELECT media_path FROM talk_messages WHERE provider_message_id = 'IMG1'")['media_path']);

        $this->http->responses[] = ['status' => 500, 'body' => '{}'];
        $w = $this->worker();
        $this->assertSame(0, $w->fetchMedia());
        $this->assertSame('pending:1:IMG1', $this->db->one("SELECT media_path FROM talk_messages WHERE provider_message_id = 'IMG1'")['media_path']);

        $this->http->responses[] = ['status' => 200, 'body' => json_encode(['base64' => self::PNG, 'mimetype' => 'image/png'])];
        $this->assertSame(1, $w->fetchMedia());
        $call = end($this->http->calls);
        $this->assertSame('http://evo:8080/chat/getBase64FromMediaMessage/connect', $call['url']);
        $this->assertSame('IMG1', $call['body']['message']['key']['id']);
        $m = $this->db->one("SELECT * FROM talk_messages WHERE provider_message_id = 'IMG1'");
        $this->assertMatchesRegularExpression('#^media/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $m['media_path']);
        $this->assertSame('image/png', $m['media_mime'], 'tipo pelo conteúdo, não pelo que veio declarado');
    }

    public function test_midia_que_nao_baixa_tres_vezes_fica_indisponivel(): void
    {
        $this->svc->handleInbound($this->channelId, new InboundMessage('DOC1', '5531999998888', 'document', 'contrato.pdf', 'Maria', 'DOC1', 'application/pdf'));
        $w = $this->worker();
        for ($i = 0; $i < 3; $i++) {
            $this->http->responses[] = ['status' => 404, 'body' => '{}'];
            $w->fetchMedia();
        }
        $this->assertSame('failed:DOC1', $this->db->one("SELECT media_path FROM talk_messages WHERE provider_message_id = 'DOC1'")['media_path']);
        $this->assertSame(0, $w->fetchMedia(), 'não tenta mais');
    }

    public function test_download_confere_setor_e_historico_oculto(): void
    {
        $this->svc->handleInbound($this->channelId, new InboundMessage('IMG2', '5531999998888', 'image', 'segunda via do boleto', 'Maria', 'IMG2', 'image/png'));
        $this->http->responses[] = ['status' => 200, 'body' => json_encode(['base64' => self::PNG, 'mimetype' => 'image/png'])];
        $this->worker()->fetchMedia();
        $id = (int) $this->db->one("SELECT conversation_id FROM talk_messages WHERE provider_message_id = 'IMG2'")['conversation_id'];
        $this->triage();
        $msgId = (int) $this->db->one("SELECT id FROM talk_messages WHERE provider_message_id = 'IMG2'")['id'];
        $q = new InboxQuery($this->db, new AccessPolicy());

        $this->assertStringStartsWith('media/', $q->mediaFor($this->agent(2, 'Financeiro'), $msgId)['media_path']);
        try {
            $q->mediaFor($this->agent(4, 'Manutenção'), $msgId);
            $this->fail('outro setor não baixa');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->getCode());
        }

        // transferida sem manter histórico: quem recebe não vê a imagem antiga, nem paginando para trás
        $beto = 77;
        $this->db->run("INSERT INTO talk_users (id, name, email, password_hash) VALUES (77, 'Beto', 'beto@x', 'x')");
        $this->db->insert('talk_staff', ['user_id' => $beto, 'sector_id' => $this->sectors['Manutenção'], 'role' => 'agent']);
        $this->svc->transfer($id, new StaffMember(1, true), $this->sectors['Manutenção'], 'só o reparo', $beto, keepHistory: false);
        $agentBeto = $this->agent($beto, 'Manutenção');
        $this->assertSame([], $q->messages($agentBeto, $id, $msgId + 1), 'paginação respeita o histórico oculto');
        $this->expectExceptionCode(403);
        $q->mediaFor($agentBeto, $msgId);
    }
}
