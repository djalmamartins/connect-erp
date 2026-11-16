<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Contracts\Helpdesk;
use Source\Services\Talk\Service\DomainError;

/** Botão "Abrir chamado": abre no Help Desk do ERP com assunto e descrição do atendente. */
final class OpenTicketTest extends DbTestCase
{
    private function helpdesk(bool $autoOpen): Helpdesk
    {
        return new class ($autoOpen) implements Helpdesk {
            public array $opened = [];
            public bool $on = false; // a abertura automática falha (ERP fora do ar); o botão abre
            public function __construct(private bool $autoOpen) {}
            public function open(array $c, array $ct, ?string $s): ?array
            {
                if (!$this->autoOpen && !$this->on) { throw new \RuntimeException('ERP 503'); }
                $this->opened[] = ['subject' => $c['subject'] ?? null, 'message' => $c['ticket_message'] ?? null, 'sector' => $s, 'details' => $ct['details'] ?? null];
                return ['id' => 900 + count($this->opened), 'protocol' => 'CH2611150A0' . count($this->opened)];
            }
            public function transfer(int $t, string $s, int $u, ?string $n): void {}
            public function close(int $t, int $u, ?string $r): void {}
        };
    }

    public function test_abre_o_chamado_quando_a_abertura_automatica_falhou(): void
    {
        $hd = $this->helpdesk(false);
        $this->svc = $this->makeService(null, $hd);
        $id = $this->routed('preciso da segunda via do boleto');
        $this->assertNull($this->conv($id)['helpdesk_ticket_id']);

        $hd->on = true;
        $ana = $this->agent(10, 'Financeiro');
        $c = $this->svc->openTicketNow($id, $ana, 'Segunda via do boleto', 'Cliente pediu a segunda via de novembro.');
        $this->assertSame(901, (int) $c['helpdesk_ticket_id']);
        $this->assertSame('CH2611150A01', $c['helpdesk_protocol']);
        $this->assertSame('Segunda via do boleto', $c['subject']);
        $this->assertSame('Cliente pediu a segunda via de novembro.', $hd->opened[0]['message']);
        $this->assertSame('Financeiro', $hd->opened[0]['sector']);
        $note = $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'internal' ORDER BY id DESC LIMIT 1", [$id])['body'];
        $this->assertStringContainsString('CH2611150A01', $note);
    }

    public function test_nao_abre_dois_chamados_para_a_mesma_conversa(): void
    {
        $this->svc = $this->makeService(null, $this->helpdesk(true));
        $id = $this->routed('preciso da segunda via do boleto');
        $this->expectExceptionMessage('já tem o chamado CH2611150A01');
        $this->svc->openTicketNow($id, $this->agent(10, 'Financeiro'), 'Outro', '');
    }

    public function test_sem_help_desk_ligado_explica_o_que_falta(): void
    {
        $id = $this->routed('preciso da segunda via do boleto'); // NullHelpdesk
        try {
            $this->svc->openTicketNow($id, $this->agent(10, 'Financeiro'), 'Boleto', '');
            $this->fail('deveria avisar');
        } catch (DomainError $e) {
            $this->assertStringContainsString('ERP_API_URL', $e->getMessage());
            $this->assertSame(409, $e->getCode());
        }
    }

    public function test_outro_setor_nao_abre_e_assunto_e_obrigatorio(): void
    {
        $this->svc = $this->makeService(null, $this->helpdesk(false));
        $id = $this->routed('preciso da segunda via do boleto');
        try {
            $this->svc->openTicketNow($id, $this->agent(20, 'Manutenção'), 'Boleto', '');
            $this->fail('outro setor não deveria abrir');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->expectException(DomainError::class);
        $this->svc->openTicketNow($id, $this->agent(10, 'Financeiro'), '   ', '');
    }
}
