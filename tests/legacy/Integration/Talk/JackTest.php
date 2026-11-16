<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Erp\NullContactDirectory;
use Source\Services\Talk\Erp\NullHelpdesk;
use Source\Services\Talk\Jack\Jack;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Service\AccessPolicy;
use Source\Services\Talk\Service\ConversationService;
use Source\Services\Talk\Service\Texts;

final class JackTest extends DbTestCase
{
    private Jack $jack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withJack('auto');
    }

    private function withJack(string $mode): void
    {
        $this->jack = new Jack($this->db, mode: $mode);
        $this->svc = new ConversationService($this->db, new EventBus($this->db), new AccessPolicy(), new NullContactDirectory(), new NullHelpdesk(), new Texts(), $this->jack);
    }

    private function outs(int $convId): array
    {
        return array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' ORDER BY id", [$convId]), 'body');
    }

    public function test_acertos_contam_as_correcoes_dos_atendentes(): void
    {
        $this->routed('o portão da garagem quebrou', '5531911111111');                 // Manutenção, ninguém mexeu: acerto
        $b = $this->routed('quero um orçamento para o condomínio', '5531922222222');  // Administrativo…
        $this->svc->transfer($b, new \Source\Services\Talk\Domain\StaffMember(1, true), $this->sectors['Manutenção'], 'era reparo'); // …humano corrigiu: erro
        $this->routed('preciso da segunda via do boleto', '5531933333333');            // Financeiro: acerto

        $s = $this->jack->stats();
        $this->assertSame(3, $s['com_sugestao']);
        $this->assertSame(2, $s['acertos']);
        $this->assertSame(1, $s['erros']);
        $this->assertSame(0.667, $s['taxa']);
    }

    public function test_perguntou_se_e_robo_ela_se_apresenta_e_depois_passa_o_que_nao_sabe(): void
    {
        $id = $this->inbound('oi');
        $this->inbound('vc é um robô?');
        $outs = $this->outs($id);
        $this->assertCount(1, $outs);
        $this->assertStringStartsWith('*Jack:*', $outs[0]);
        $this->assertStringContainsString('Eu sou a inteligência artificial do time da Connect', $outs[0]);

        $this->inbound('e quem é o síndico do meu prédio agora?');
        $outs = $this->outs($id);
        $this->assertStringContainsString('fora do meu escopo de treinamento', end($outs));
        $this->assertStringContainsString('*Administrativo*', end($outs));
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->sectors['Administrativo'], (int) $c['sector_id']);

        $this->inbound('você é robô mesmo?');
        $this->inbound('outra pergunta qualquer');
        $this->triage();
        $this->assertCount(2, $this->outs($id), 'apresentação e fora do escopo, nada mais');
    }

    public function test_depois_da_apresentacao_assunto_claro_segue_o_fluxo_normal(): void
    {
        $id = $this->inbound('é uma pessoa de verdade?');
        $this->inbound('preciso da segunda via do boleto');
        $this->triage();
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);
        $this->assertStringNotContainsString('escopo', implode(' ', $this->outs($id)));
    }

    public function test_com_atendente_humano_ela_nao_responde(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $this->svc->accept($id, $this->agent(5, 'Financeiro'));
        $this->inbound('você é um robô?');
        $this->assertStringNotContainsString('inteligência artificial', implode(' ', $this->outs($id)));
    }

    public function test_desligada_tudo_vai_direto_para_o_setor_padrao_sem_mensagem(): void
    {
        $this->withJack('off');
        $id = $this->inbound('vazamento no teto');
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->sectors['Administrativo'], (int) $c['sector_id']);
        $this->assertSame([], $this->outs($id));
    }

    public function test_reconhece_perguntas_sobre_robo(): void
    {
        foreach (['Você é um robô?', 'isso é bot?', 'estou falando com uma pessoa?', 'vc é IA?', 'é humano?', 'é inteligência artificial?'] as $t) {
            $this->assertTrue(Jack::asksIfBot($t), $t);
        }
        $this->assertFalse(Jack::asksIfBot('eu ia pagar amanhã'));
        $this->assertFalse(Jack::asksIfBot('preciso de uma pessoa para consertar o portão'));
    }
}
