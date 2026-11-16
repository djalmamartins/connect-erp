<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Service\DomainError;

/** Seletor de status da conversa: fila, em atendimento, aguardando, reabrir. */
final class ConversationStatusTest extends DbTestCase
{
    private function lastNote(int $id): string
    {
        return $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND type = 'status' ORDER BY id DESC LIMIT 1", [$id])['body'] ?? '';
    }

    public function test_aguardando_cliente_e_a_resposta_do_cliente_tira_o_aguardando(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(10, 'Financeiro');
        $c = $this->svc->setStatus($id, $ana, 'waiting_customer');
        $this->assertSame('open', $c['status']);
        $this->assertSame('waiting_customer', $c['stage']);
        $this->assertSame(10, (int) $c['assigned_user_id']);
        $this->assertSame('Status alterado para Aguardando cliente.', $this->lastNote($id));

        $this->inbound('segue o comprovante');
        $this->assertNull($this->conv($id)['stage']);
    }

    public function test_aguardando_retorno_interno_continua_ate_o_atendente_mudar(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->setStatus($id, $ana, 'waiting_internal');
        $this->inbound('alguma novidade?');
        $this->assertSame('waiting_internal', $this->conv($id)['stage']);
        $c = $this->svc->setStatus($id, $ana, 'open');
        $this->assertNull($c['stage']);
        $this->assertSame('Status alterado para Em atendimento.', $this->lastNote($id));
    }

    public function test_devolve_para_a_fila_sem_atendente(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->accept($id, $ana);
        $c = $this->svc->setStatus($id, $ana, 'pending');
        $this->assertSame('pending', $c['status']);
        $this->assertNull($c['assigned_user_id']);
        $this->assertNotNull($c['queued_at']);
    }

    public function test_reabre_encerrado_e_bloqueia_se_ja_ha_outro_aberto(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->setStatus($id, $ana, 'waiting_customer');
        $this->svc->close($id, $ana, 'resolvido', false);
        $this->assertNull($this->conv($id)['stage']);

        try {
            $this->svc->setStatus($id, $ana, 'waiting_customer');
            $this->fail('encerrado só reabre');
        } catch (DomainError $e) {
            $this->assertStringContainsString('Reabra', $e->getMessage());
        }
        $c = $this->svc->setStatus($id, $ana, 'open');
        $this->assertSame('open', $c['status']);
        $this->assertNull($c['closed_at']);
        $this->assertSame('Atendimento reaberto.', $this->lastNote($id));

        // encerra de novo, o cliente abre outro atendimento: não dá para reabrir o antigo
        $this->svc->close($id, $ana, null, false);
        $this->inbound('outro assunto: vazamento no banheiro', '5531999998888', 'novo-1');
        $this->expectExceptionMessage('já tem outro atendimento aberto');
        $this->svc->setStatus($id, $ana, 'open');
    }

    public function test_outro_setor_e_status_invalido_nao_mudam(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        try {
            $this->svc->setStatus($id, $this->agent(20, 'Manutenção'), 'waiting_customer');
            $this->fail('outro setor não deveria mudar');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->expectExceptionMessage('Status inválido');
        $this->svc->setStatus($id, $this->agent(10, 'Financeiro'), 'closed');
    }
}
