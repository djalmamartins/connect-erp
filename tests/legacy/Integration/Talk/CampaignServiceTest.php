<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Domain\StatusUpdate;
use Source\Services\Talk\Service\CampaignService;
use Source\Services\Talk\Service\DomainError;

final class CampaignServiceTest extends DbTestCase
{
    private int $official;
    private int $tpl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->official = $this->db->insert('talk_channels', [
            'name' => 'Avisos', 'kind' => 'official', 'purpose' => 'bulk',
            'config_encrypted' => $this->crypto->encrypt(['phone_number_id' => '1098', 'business_account_id' => '777', 'access_token' => 'tok', 'app_secret' => 's', 'verify_token' => 'v']),
        ]);
        $this->tpl = $this->camps()->saveTemplate($this->sup(), ['channel_id' => $this->official, 'name' => 'boleto_disponivel', 'language' => 'pt_BR',
            'body' => 'Olá {{1}}! O boleto de {{2}} do {{3}} já está disponível.'])['id'];
    }

    private function camps(): CampaignService
    {
        return new CampaignService($this->db, $this->gateways());
    }

    private function sup(): StaffMember
    {
        return new StaffMember(1, true);
    }

    private function input(array $over = []): array
    {
        return $over + [
            'name' => 'Boletos de dezembro', 'channel_id' => $this->official, 'template_id' => $this->tpl, 'sector_id' => $this->sectors['Financeiro'],
            'var_map' => ['1' => 'first_name', '2' => 'fixed:dezembro', '3' => 'col:3'],
            'audience' => ['type' => 'list', 'rows' => "telefone;nome;condominio\n(31) 98888-1111;Mariana Souza;Residencial Jardins\n31977772222;Paulo Lima;Ed. Aurora\n31977772222;Paulo Lima;Ed. Aurora\n123;Inválido;X\n31966663333;Sem Condomínio;"],
            'per_minute' => 60,
        ];
    }

    public function test_modelo_conta_variaveis_e_sincroniza_com_a_meta(): void
    {
        $t = $this->camps()->templates($this->sup())[0];
        $this->assertSame(3, $t['variables']);
        $this->http->responses = [['status' => 200, 'body' => json_encode(['data' => [
            ['name' => 'boleto_disponivel', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Oi {{1}}, boleto {{2}}.']]],
            ['name' => 'assembleia', 'language' => 'pt_BR', 'status' => 'PENDING', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Assembleia dia {{1}}.']]],
        ]])]];
        $this->assertSame(2, $this->camps()->syncTemplates($this->sup(), $this->official)['synced']);
        $this->assertStringContainsString('/777/message_templates', $this->http->calls[0]['url']);
        $list = $this->camps()->templates($this->sup());
        $this->assertCount(2, $list);
        $this->assertSame(2, array_values(array_filter($list, fn ($x) => $x['name'] === 'boleto_disponivel'))[0]['variables']);
    }

    public function test_previa_mostra_quem_recebe_e_quem_fica_de_fora(): void
    {
        $this->db->insert('talk_contacts', ['phone' => '5531966663333', 'name' => 'X', 'opt_out_at' => date('Y-m-d H:i:s')]);
        $p = $this->camps()->preview($this->sup(), $this->input());
        $this->assertSame(2, $p['total']);
        $this->assertSame(['telefone inválido' => 1, 'pediu para sair' => 1], $p['skipped']);
        $this->assertSame('Olá Mariana! O boleto de dezembro do Residencial Jardins já está disponível.', $p['samples'][0]['text']);
    }

    public function test_envia_no_ritmo_e_conclui(): void
    {
        $c = $this->camps()->save($this->sup(), $this->input(['per_minute' => 1]));
        $this->assertSame('draft', $c['status']);
        $c = $this->camps()->start($this->sup(), $c['id']);
        $this->assertSame('running', $c['status']);
        $this->assertSame(4, $c['total']); // 5 linhas válidas no formato, 1 repetida não entra
        $this->assertSame(2, $c['skipped']); // inválido e variável vazia (sem condomínio)

        $this->http->responses = [
            ['status' => 200, 'body' => '{"messages":[{"id":"wamid.A"}]}'],
            ['status' => 200, 'body' => '{"messages":[{"id":"wamid.B"}]}'],
        ];
        $this->assertSame(1, $this->camps()->runDue()); // 1 por minuto: só um agora
        $call = $this->http->calls[0];
        $this->assertSame('template', $call['body']['type']);
        $this->assertSame('boleto_disponivel', $call['body']['template']['name']);
        $this->assertSame(['Mariana', 'dezembro', 'Residencial Jardins'], array_column($call['body']['template']['components'][0]['parameters'], 'text'));
        $this->assertSame(0, $this->camps()->runDue()); // ainda no mesmo minuto
        $this->db->run('UPDATE talk_campaigns SET last_batch_at = NOW() - INTERVAL 61 SECOND');
        $this->assertSame(1, $this->camps()->runDue());
        $this->assertSame('done', $this->camps()->one($this->sup(), $c['id'])['status']);

        // confirmações da Meta
        $this->svc->handleStatus(new StatusUpdate('wamid.A', 'delivered'));
        $this->svc->handleStatus(new StatusUpdate('wamid.A', 'read'));
        $this->svc->handleStatus(new StatusUpdate('wamid.A', 'delivered')); // não regride
        $this->assertSame('read', $this->db->one("SELECT status FROM talk_campaign_recipients WHERE provider_message_id = 'wamid.A'")['status']);
    }

    public function test_resposta_vai_para_o_setor_da_campanha_e_sair_e_respeitado(): void
    {
        $c = $this->camps()->start($this->sup(), $this->camps()->save($this->sup(), $this->input(['sector_id' => $this->sectors['Administrativo']]))['id']);
        $this->http->responses = [['status' => 200, 'body' => '{"messages":[{"id":"wamid.M"}]}'], ['status' => 200, 'body' => '{"messages":[{"id":"wamid.P"}]}']];
        $this->assertSame(2, $this->camps()->runDue());
        // a Mariana responde falando de vazamento (Manutenção), mas é resposta ao envio do Administrativo
        $conv = $this->routed('vazamento no teto, e o boleto?', '5531988881111');
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($conv)['sector_id']);
        $notes = implode("\n", array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'internal' ORDER BY id", [$conv]), 'body'));
        $note = $notes;
        $this->assertStringContainsString('Boletos de dezembro', $note);
        $this->assertStringContainsString('Olá Mariana!', $note);
        $this->assertSame(1, $this->camps()->one($this->sup(), $c['id'])['replied']);

        // Paulo pede para sair: próximo envio não inclui
        $this->routed('SAIR', '5531977772222');
        $this->assertNotNull($this->db->one("SELECT opt_out_at FROM talk_contacts WHERE phone = '5531977772222'")['opt_out_at']);
        $p = $this->camps()->preview($this->sup(), $this->input());
        $this->assertSame(1, $p['skipped']['pediu para sair']);
    }

    public function test_pausar_retomar_cancelar_e_agendar(): void
    {
        $later = date('Y-m-d H:i', strtotime($this->db->one('SELECT NOW() n')['n'] . ' +1 day'));
        $c = $this->camps()->start($this->sup(), $this->camps()->save($this->sup(), $this->input(['send_at' => $later]))['id']);
        $this->assertSame('scheduled', $c['status']);
        $this->assertSame(0, $this->camps()->runDue());
        $this->assertSame('paused', $this->camps()->pause($this->sup(), $c['id'])['status']);
        $this->assertSame('running', $this->camps()->resume($this->sup(), $c['id'])['status']);
        $x = $this->camps()->cancel($this->sup(), $c['id']);
        $this->assertSame('canceled', $x['status']);
        $this->assertSame(0, $x['queued']);
        $this->assertSame(0, $this->camps()->runDue());
        $this->expectException(DomainError::class);
        $this->camps()->resume($this->sup(), $c['id']);
    }

    public function test_falha_da_meta_fica_no_destinatario(): void
    {
        $c = $this->camps()->start($this->sup(), $this->camps()->save($this->sup(), $this->input(['per_minute' => 12]))['id']); // primeira leva: 1
        $this->http->responses = [['status' => 400, 'body' => '{"error":{"message":"Template name does not exist in the translation"}}']];
        $this->camps()->runDue();
        $c = $this->camps()->one($this->sup(), $c['id']);
        $this->assertSame(1, $c['failed']);
        $this->assertStringContainsString('Template name', $this->camps()->recipients($this->sup(), $c['id'], 'failed')[0]['error']);
    }

    public function test_publico_por_etiqueta_e_permissoes(): void
    {
        $tag = $this->db->insert('talk_tags', ['name' => 'Inadimplente', 'color' => '#c00']);
        foreach ([['5531911110000', 'Ana Paula'], ['5531922220000', 'Bloqueado da Silva']] as [$ph, $n]) {
            $id = $this->db->insert('talk_contacts', ['phone' => $ph, 'name' => $n]);
            $this->db->insert('talk_contact_tags', ['contact_id' => $id, 'tag_id' => $tag]);
        }
        $this->db->run("UPDATE talk_contacts SET blocked_at = NOW() WHERE phone = '5531922220000'");
        $in = $this->input(['audience' => ['type' => 'tag', 'tag_id' => $tag], 'var_map' => ['1' => 'first_name', '2' => 'fixed:dezembro', '3' => 'fixed:seu condomínio']]);
        $p = $this->camps()->preview($this->sup(), $in);
        $this->assertSame(1, $p['total']);
        $this->assertSame(['bloqueado' => 1], $p['skipped']);

        $gerenteFin = new StaffMember(5, false, [], [$this->sectors['Financeiro']]);
        $this->assertSame(1, $this->camps()->preview($gerenteFin, $in)['total']);
        foreach ([fn () => $this->camps()->preview($gerenteFin, ['sector_id' => $this->sectors['Manutenção']] + $in),
            fn () => $this->camps()->list(new StaffMember(6, false, [$this->sectors['Financeiro']])),
            fn () => $this->camps()->saveTemplate($gerenteFin, ['channel_id' => $this->official, 'name' => 'x', 'body' => 'x'])] as $call) {
            try {
                $call();
                $this->fail('deveria negar');
            } catch (DomainError $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
        // número de QR Code não serve para envio em massa
        $this->expectExceptionMessage('API oficial');
        $this->camps()->preview($this->sup(), ['channel_id' => $this->channelId] + $in);
    }
}
