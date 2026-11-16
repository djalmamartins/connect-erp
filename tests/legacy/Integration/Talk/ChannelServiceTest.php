<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\ChannelService;
use Source\Services\Talk\Service\DomainError;

final class ChannelServiceTest extends DbTestCase
{
    private function channels(bool $lab = false): ChannelService
    {
        return new ChannelService($this->db, $this->crypto, $this->gateways(), 'https://talk.connect.lab/', $lab);
    }

    private function sup(): StaffMember
    {
        return new StaffMember(1, true);
    }

    public function test_so_o_supervisor_ve_e_gerencia_as_conexoes(): void
    {
        $manager = new StaffMember(5, false, [$this->sectors['Manutenção']], [$this->sectors['Manutenção']]);
        foreach ([fn () => $this->channels()->list($manager), fn () => $this->channels()->save($manager, ['kind' => 'lab', 'name' => 'x'])] as $call) {
            try {
                $call();
                $this->fail('deveria negar');
            } catch (DomainError $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
    }

    public function test_lista_nao_devolve_segredos_e_mostra_webhook(): void
    {
        $c = $this->channels()->list($this->sup())[0];
        $this->assertSame('https://talk.connect.lab/webhook/' . $this->channelId, $c['webhook_url']);
        $this->assertSame('http://evo:8080', $c['config']['base_url']);
        $this->assertSame('••••', mb_substr($c['config']['api_key'], 0, 4));
        $this->assertSame('••••cret', $c['config']['webhook_secret']);
        $this->assertArrayNotHasKey('config_encrypted', $c);
        $this->assertStringNotContainsString('s3cret', json_encode($this->channels()->list($this->sup())));
    }

    public function test_cria_qrcode_gera_segredo_e_editar_com_senha_vazia_mantem(): void
    {
        $svc = $this->channels();
        $c = $svc->save($this->sup(), ['kind' => 'qrcode', 'name' => 'Financeiro', 'phone' => '(31) 98888-7777', 'sector_id' => $this->sectors['Financeiro'],
            'config' => ['base_url' => 'http://evo:8080', 'instance' => 'fin', 'api_key' => 'chave-1234']]);
        $this->assertSame('5531988887777', $c['phone']);
        $this->assertSame('Financeiro', $c['sector_name']);
        $cfg = $this->crypto->decrypt($this->db->one('SELECT config_encrypted FROM talk_channels WHERE id = ?', [$c['id']])['config_encrypted']);
        $this->assertSame(32, strlen($cfg['webhook_secret']));

        $svc->save($this->sup(), ['name' => 'Financeiro QR', 'config' => ['base_url' => 'http://evo2:8080', 'instance' => 'fin', 'api_key' => '']], $c['id']);
        $cfg2 = $this->crypto->decrypt($this->db->one('SELECT config_encrypted FROM talk_channels WHERE id = ?', [$c['id']])['config_encrypted']);
        $this->assertSame('chave-1234', $cfg2['api_key']);
        $this->assertSame($cfg['webhook_secret'], $cfg2['webhook_secret']);
        $this->assertSame('http://evo2:8080', $cfg2['base_url']);
        $this->assertSame('unknown', $this->db->one('SELECT connection_state FROM talk_channels WHERE id = ?', [$c['id']])['connection_state']);
    }

    public function test_validacoes(): void
    {
        $svc = $this->channels();
        $cases = [
            ['kind' => 'lab', 'name' => 'Lab'], // laboratório só no laboratório
            ['kind' => 'qrcode', 'name' => 'Sem Evolution', 'phone' => '31977776666'], // sem EVOLUTION_URL no servidor
            ['kind' => 'qrcode', 'name' => 'Sem número', 'config' => ['base_url' => 'http://evo', 'api_key' => 'k']],
            ['kind' => 'qrcode', 'name' => 'URL ruim', 'phone' => '31977776666', 'config' => ['base_url' => 'evo', 'api_key' => 'k']],
            ['kind' => 'official', 'name' => 'Sem token', 'config' => ['phone_number_id' => '1']],
            ['kind' => 'qrcode', 'name' => 'Tel', 'phone' => '123', 'config' => ['base_url' => 'http://evo', 'instance' => 'x', 'api_key' => 'k']],
        ];
        foreach ($cases as $in) {
            try {
                $svc->save($this->sup(), $in);
                $this->fail('deveria recusar: ' . $in['name']);
            } catch (DomainError $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
        $this->assertSame('lab', $this->channels(true)->save($this->sup(), ['kind' => 'lab', 'name' => 'Lab'])['kind']);
    }

    public function test_conectar_qrcode_configura_webhook_e_devolve_o_qr(): void
    {
        $this->http->responses = [
            ['status' => 200, 'body' => '{"instance":{"state":"close"}}'],              // a instância já existe
            ['status' => 201, 'body' => '{}'],                                          // webhook/set
            ['status' => 200, 'body' => '{"instance":{"state":"close"}}'],              // connectionState
            ['status' => 200, 'body' => '{"base64":"iVBORw0KGgo=","code":"2@abc"}'],    // instance/connect
        ];
        $r = $this->channels()->connect($this->sup(), $this->channelId);
        $this->assertSame('connecting', $r['state']);
        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $r['qr']);
        $this->assertSame('http://evo:8080/webhook/set/connect', $this->http->calls[1]['url']);
        $this->assertSame('https://talk.connect.lab/webhook/' . $this->channelId, $this->http->calls[1]['body']['webhook']['url']);
        $this->assertSame('s3cret', $this->http->calls[1]['body']['webhook']['headers']['X-Talk-Secret']);
        $this->assertSame('k', $this->http->calls[1]['headers']['apikey']);
        $this->assertSame('http://evo:8080/instance/connect/connect', $this->http->calls[3]['url']);

        $this->http->responses = [['status' => 200, 'body' => '{"instance":{"instanceName":"connect","state":"open"}}']];
        $this->assertSame('connected', $this->channels()->refresh($this->sup(), $this->channelId)['state']);
        $row = $this->db->one('SELECT connection_state, state_checked_at FROM talk_channels WHERE id = ?', [$this->channelId]);
        $this->assertSame('connected', $row['connection_state']);
        $this->assertNotNull($row['state_checked_at']);
    }

    public function test_conectar_com_falha_guarda_o_erro(): void
    {
        $this->http->responses = [
            ['status' => 401, 'body' => '{}'],
            ['status' => 401, 'body' => '{}'],
            ['status' => 401, 'body' => '{"response":{"message":["Unauthorized"]}}'],
            ['status' => 401, 'body' => '{"response":{"message":["Unauthorized"]}}'],
        ];
        try {
            $this->channels()->connect($this->sup(), $this->channelId);
            $this->fail('deveria falhar');
        } catch (DomainError $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
        }
        $row = $this->db->one('SELECT connection_state, last_error FROM talk_channels WHERE id = ?', [$this->channelId]);
        $this->assertSame('disconnected', $row['connection_state']);
        $this->assertStringContainsString('Unauthorized', $row['last_error']);
    }

    public function test_oficial_confere_numero_na_meta(): void
    {
        $c = $this->channels()->save($this->sup(), ['kind' => 'official', 'name' => 'Massa', 'purpose' => 'bulk',
            'config' => ['phone_number_id' => '1098', 'access_token' => 'tok', 'app_secret' => 'sec']]);
        $this->assertNotSame('', $c['config']['verify_token']); // gerado para colar na Meta
        $this->http->responses = [['status' => 200, 'body' => '{"display_phone_number":"+55 31 3333-0000","verified_name":"Connect","quality_rating":"GREEN"}']];
        $r = $this->channels()->connect($this->sup(), $c['id']);
        $this->assertSame('connected', $r['state']);
        $this->assertNull($r['qr']);
        $this->assertSame('553133330000', $r['channel']['phone']);
        $this->assertStringContainsString('graph.facebook.com/v21.0/1098?fields=', $this->http->calls[0]['url']);
        $this->assertSame('Bearer tok', $this->http->calls[0]['headers']['Authorization']);
    }

    public function test_padrao_desativar_e_excluir(): void
    {
        $svc = $this->channels();
        $other = $svc->save($this->sup(), ['kind' => 'qrcode', 'name' => 'Outro', 'phone' => '31933330001', 'config' => ['base_url' => 'http://e', 'instance' => 'o', 'api_key' => 'k']]);
        $this->assertTrue($other['is_default']); // primeiro com padrão (o do teste não tinha)
        $svc->setDefault($this->sup(), $this->channelId);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM talk_channels WHERE is_default = 1')['n']);
        try {
            $svc->toggle($this->sup(), $this->channelId);
            $this->fail('não desativa a padrão');
        } catch (DomainError) {
        }
        $this->assertSame('disabled', $svc->toggle($this->sup(), $other['id'])['status']);
        $this->assertSame('active', $svc->toggle($this->sup(), $other['id'])['status']);

        $this->routed('boleto atrasado'); // o canal do teste passa a ter histórico
        $svc->setDefault($this->sup(), $other['id']);
        try {
            $svc->delete($this->sup(), $this->channelId);
            $this->fail('com histórico não exclui');
        } catch (DomainError $e) {
            $this->assertStringContainsString('desative', $e->getMessage());
        }
        $third = $svc->save($this->sup(), ['kind' => 'qrcode', 'name' => 'Terceiro', 'phone' => '31933330002', 'config' => ['base_url' => 'http://e', 'instance' => 't', 'api_key' => 'k']]);
        $svc->delete($this->sup(), $third['id']);
        $this->assertNull($this->db->one('SELECT id FROM talk_channels WHERE id = ?', [$third['id']]));
    }

    public function test_numero_com_setor_vai_direto_para_o_setor_sem_a_jack_adivinhar(): void
    {
        $this->db->run('UPDATE talk_channels SET sector_id = ? WHERE id = ?', [$this->sectors['Manutenção'], $this->channelId]);
        $id = $this->routed('quero a segunda via do boleto'); // assunto do Financeiro, mas o número é da Manutenção
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->sectors['Manutenção'], (int) $c['sector_id']);
    }

    public function test_numero_sem_setor_segue_a_jack(): void
    {
        $id = $this->routed('quero a segunda via do boleto');
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);
    }

    public function test_webhook_de_conexao_e_canal_desativado(): void
    {
        $h = new \Source\Services\Talk\Service\WebhookHandler($this->gateways(), $this->svc);
        $r = $h->handle($this->channelId, '{"event":"connection.update","data":{"state":"open"}}', ['X-Talk-Secret' => 's3cret']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('connected', $this->db->one('SELECT connection_state FROM talk_channels WHERE id = ?', [$this->channelId])['connection_state']);
        $this->assertSame(401, $h->handle($this->channelId, '{"event":"connection.update","data":{"state":"close"}}', [])['status']);

        $this->db->run("UPDATE talk_channels SET status = 'disabled' WHERE id = ?", [$this->channelId]);
        $msg = '{"event":"messages.upsert","data":{"key":{"remoteJid":"5531999998888@s.whatsapp.net","fromMe":false,"id":"X1"},"message":{"conversation":"oi"},"messageType":"conversation"}}';
        $this->assertSame(0, $h->handle($this->channelId, $msg, ['X-Talk-Secret' => 's3cret'])['processed']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM talk_conversations')['n']);
    }

    public function test_laboratorio_qr_e_simular_leitura(): void
    {
        $svc = $this->channels(true);
        $lab = $svc->save($this->sup(), ['kind' => 'lab', 'name' => 'Lab']);
        $r = $svc->connect($this->sup(), $lab['id']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $r['qr']);
        $this->assertSame('connecting', $svc->refresh($this->sup(), $lab['id'])['state']);
        $this->assertSame('connected', $svc->simulateScan($this->sup(), $lab['id'])['connection_state']);
        $this->expectException(DomainError::class);
        $this->channels(false)->simulateScan($this->sup(), $lab['id']);
    }

    private function withEvolution(bool $lab = false): ChannelService
    {
        $g = new \Source\Services\Talk\Gateway\GatewayFactory($this->db, $this->crypto, $this->http, ['base_url' => 'http://evolution:8080', 'api_key' => 'global-key']);
        return new ChannelService($this->db, $this->crypto, $g, 'https://talk.connect.lab/', $lab);
    }

    public function test_qrcode_so_com_nome_e_numero_cria_a_instancia_e_mostra_o_qr(): void
    {
        $svc = $this->withEvolution();
        $c = $svc->save($this->sup(), ['kind' => 'qrcode', 'name' => 'Atendimento', 'phone' => '(31) 97777-6666']);
        $this->assertSame('5531977776666', $c['phone']);
        $this->assertSame('attendance', $c['purpose']);
        $this->assertFalse($c['simulated']);
        $cfg = $this->crypto->decrypt($this->db->one('SELECT config_encrypted FROM talk_channels WHERE id = ?', [$c['id']])['config_encrypted']);
        $this->assertSame('talk-5531977776666', $cfg['instance']);
        $this->assertArrayNotHasKey('base_url', $cfg); // o servidor vem do .env, não fica no canal

        $this->http->responses = [
            ['status' => 404, 'body' => '{"response":{"message":["instance does not exist"]}}'], // número novo
            ['status' => 201, 'body' => '{"instance":{"instanceName":"talk-5531977776666"},"qrcode":{"base64":"data:image/png;base64,QUJD"}}'],
            ['status' => 201, 'body' => '{}'],                                                    // webhook/set
        ];
        $r = $svc->connect($this->sup(), $c['id']);
        $this->assertSame('data:image/png;base64,QUJD', $r['qr']);
        $this->assertSame('http://evolution:8080/instance/create', $this->http->calls[1]['url']);
        $this->assertSame('talk-5531977776666', $this->http->calls[1]['body']['instanceName']);
        $this->assertSame('global-key', $this->http->calls[1]['headers']['apikey']);
        $this->assertSame('http://evolution:8080/webhook/set/talk-5531977776666', $this->http->calls[2]['url']);

        // número antigo com endereço próprio salvo (ex.: o do laboratório): passa a usar o motor do servidor
        $this->http->calls = [];
        $this->http->responses = [['status' => 200, 'body' => '{"instance":{"state":"open"}}']];
        $this->assertSame('connected', $svc->refresh($this->sup(), $this->channelId)['state']);
        $this->assertSame('http://evolution:8080/instance/connectionState/connect', $this->http->calls[0]['url']);
        $this->assertSame('global-key', $this->http->calls[0]['headers']['apikey']);

        try {
            $svc->save($this->sup(), ['kind' => 'qrcode', 'name' => 'De novo', 'phone' => '5531977776666']);
            $this->fail('número repetido');
        } catch (DomainError $e) {
            $this->assertStringContainsString('já está cadastrado', $e->getMessage());
        }
    }

    public function test_qrcode_no_laboratorio_sem_evolution_simula(): void
    {
        $g = new \Source\Services\Talk\Gateway\GatewayFactory($this->db, $this->crypto, $this->http, [], simulateQr: true);
        $svc = new ChannelService($this->db, $this->crypto, $g, 'https://talk.connect.lab/', true);
        $c = $svc->save($this->sup(), ['kind' => 'qrcode', 'name' => 'Teste', 'phone' => '31966665555']);
        $this->assertTrue($c['simulated']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $svc->connect($this->sup(), $c['id'])['qr']);
        $this->assertSame('connected', $svc->simulateScan($this->sup(), $c['id'])['connection_state']);
        $this->assertSame([], $this->http->calls);
    }
}
