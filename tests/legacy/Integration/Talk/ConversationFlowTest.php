<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Contracts\ContactDirectory;
use Source\Services\Talk\Contracts\Helpdesk;
use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\DomainError;

final class ConversationFlowTest extends DbTestCase
{
    public function test_primeira_mensagem_cria_conversa_com_protocolo_sem_menu(): void
    {
        $id = $this->inbound('oi');
        $c = $this->conv($id);
        $this->assertMatchesRegularExpression('/^SAC\d{6}00001$/', $c['protocol']);
        $this->assertSame('bot', $c['status'], 'fica na triagem da Jack');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM talk_outbox')['n'], 'nada de menu 1, 2, 3');
    }

    public function test_protocolos_sao_sequenciais_por_dia(): void
    {
        $a = $this->conv($this->inbound('oi', '5531911111111'));
        $b = $this->conv($this->inbound('oi', '5531922222222'));
        $this->assertSame(substr($a['protocol'], 0, 9) . '00002', $b['protocol']);
    }

    public function test_webhook_repetido_e_ignorado(): void
    {
        $this->assertNotNull($this->inbound('oi', id: 'DUP'));
        $this->assertNull($this->inbound('oi', id: 'DUP'));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM talk_messages WHERE direction='in'")['n']);
    }

    public function test_espera_a_pessoa_terminar_de_escrever_antes_de_encaminhar(): void
    {
        $id = $this->inbound('Bom dia');
        $this->inbound('passei no corredor');
        $this->assertSame(0, $this->svc->triage(2), 'ainda escrevendo e sem assunto claro: não mexe');
        $this->assertSame('bot', $this->conv($id)['status']);

        $this->inbound('e tem uma água aqui no chão');
        $this->assertSame(1, $this->svc->triage(0), 'o assunto ficou claro: vai na hora');
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->sectors['Manutenção'], (int) $c['sector_id']);
        // ADR 0004: encaminha em silêncio; quem fala com a pessoa é a equipe do setor
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) n FROM talk_messages WHERE conversation_id = ? AND direction = 'out'", [$id])['n']);
    }

    public function test_assunto_claro_vai_para_a_fila_na_hora(): void
    {
        $id = $this->inbound('Bom dia, estamos sem água no bloco B');
        $this->assertSame(1, $this->svc->triage(0), 'nem espera a pessoa parar de escrever');
        $this->assertSame('pending', $this->conv($id)['status']);
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($id)['sector_id']);
    }

    public function test_assunto_que_ela_nao_reconhece_vai_para_o_setor_padrao_em_segundos(): void
    {
        $id = $this->inbound('preciso falar com alguém sobre uma coisa');
        $this->assertSame(0, $this->svc->triage(2));
        $this->assertSame(1, $this->svc->triage(8));
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($id)['sector_id']);
    }

    public function test_falta_de_agua_vai_para_a_manutencao(): void
    {
        $id = $this->routed('Bom dia, estamos com problema de falta de água');
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($id)['sector_id']);
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($this->routed('o prédio está sem luz desde cedo', '5531911112222'))['sector_id']);
    }

    public function test_so_um_bom_dia_ela_espera_calada_o_assunto(): void
    {
        $id = $this->inbound('bom dia');
        $this->triage();
        $this->assertSame('bot', $this->conv($id)['status']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) n FROM talk_messages WHERE direction = 'out'")['n'], 'não puxa conversa');

        $this->inbound('o portão da garagem quebrou');
        $this->triage();
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($id)['sector_id']);
    }

    public function test_assunto_que_ela_nao_reconhece_vai_para_o_administrativo(): void
    {
        $id = $this->routed('queria falar com alguém sobre uma situação aqui do prédio');
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($id)['sector_id']);
    }

    public function test_dois_assuntos_empatados_vao_para_o_administrativo(): void
    {
        $id = $this->routed('o boleto veio errado e tem um vazamento no teto');
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($id)['sector_id']);
    }

    public function test_identifica_morador_pelo_telefone_sem_o_nono_digito(): void
    {
        $dir = new class implements ContactDirectory {
            public array $asked = [];
            public function findByPhone(array $c): ?array
            {
                $this->asked = $c;
                return in_array('3199998888', $c, true)
                    ? ['person_id' => 77, 'name' => 'João Morador', 'details' => ['condominio' => 'Res. Jardins', 'unidade' => 'A-101']]
                    : null;
            }
        };
        $this->svc = $this->makeService($dir);
        $id = $this->inbound('oi', '5531999998888');
        $contact = $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$this->conv($id)['contact_id']]);
        $this->assertSame(77, (int) $contact['erp_person_id']);
        $this->assertSame('João Morador', $contact['name']);
        $this->assertStringContainsString('Jardins', $contact['erp_snapshot']);
    }

    public function test_atendente_aceita_responde_e_encerra(): void
    {
        $id = $this->routed('preciso pagar o boleto');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->accept($id, $ana);
        $this->assertSame('open', $this->conv($id)['status']);

        $this->svc->reply($id, $ana, 'Claro! Segue o link.', 'Ana');
        $this->assertSame("*Ana:*\nClaro! Segue o link.", $this->lastOutbound($id));

        $this->svc->close($id, $ana, 'Enviada 2ª via');
        $c = $this->conv($id);
        $this->assertSame('closed', $c['status']);
        $outs = array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' ORDER BY id DESC LIMIT 2", [$id]), 'body');
        $this->assertStringContainsString($c['protocol'], $outs[1]);
        $this->assertStringStartsWith('Como você avalia', $outs[0]); // logo depois, a pergunta da avaliação

        // nova mensagem depois de encerrar = novo protocolo
        $novo = $this->inbound('obrigada!');
        $this->assertNotSame($id, $novo);
    }

    public function test_dois_atendentes_nao_aceitam_a_mesma_conversa(): void
    {
        $id = $this->routed('preciso do boleto');
        $this->svc->accept($id, $this->agent(10, 'Financeiro'));
        $this->expectException(DomainError::class);
        $this->svc->accept($id, $this->agent(11, 'Financeiro'));
    }

    public function test_setor_nao_mexe_em_conversa_de_outro_setor(): void
    {
        $id = $this->routed('vazamento no banheiro');
        $this->expectException(DomainError::class);
        $this->svc->accept($id, $this->agent(10, 'Financeiro'));
    }

    public function test_transferencia_com_nota_interna(): void
    {
        $hd = new class implements Helpdesk {
            public array $log = [];
            public function open(array $c, array $ct, ?string $s): ?array { $this->log[] = "open:$s"; return ['id' => 500, 'protocol' => 'CH261115A1B2']; }
            public function transfer(int $t, string $s, int $u, ?string $n): void { $this->log[] = "transfer:$t:$s:$n"; }
            public function close(int $t, int $u, ?string $r): void { $this->log[] = "close:$t"; }
        };
        $this->svc = $this->makeService(null, $hd);

        $id = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->accept($id, $ana);
        $this->svc->transfer($id, $ana, $this->sectors['Manutenção'], 'Morador relata vazamento no 101');

        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertNull($c['assigned_user_id']);
        $this->assertSame($this->sectors['Manutenção'], (int) $c['sector_id']);
        $note = $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND type = 'transfer'", [$id]);
        $this->assertStringContainsString('Financeiro para Manutenção', $note['body']);
        $this->assertStringContainsString('vazamento no 101', $note['body']);
        $this->assertSame(['open:Financeiro', 'transfer:500:Manutenção:Morador relata vazamento no 101'], $hd->log);
        $this->assertSame('CH261115A1B2', $c['helpdesk_protocol']);

        // Ana (financeiro) não age mais; manutenção aceita
        try {
            $this->svc->reply($id, $ana, 'oi');
            $this->fail('Financeiro não deveria responder depois de transferir');
        } catch (DomainError) {
        }
        $this->svc->accept($id, $this->agent(20, 'Manutenção'));
        $this->assertSame(20, (int) $this->conv($id)['assigned_user_id']);

        // ao encerrar, o cliente recebe o número do chamado do Help Desk, não o do Talk
        $this->svc->close($id, $this->agent(20, 'Manutenção'));
        $last = $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' AND body LIKE '%encerrado%'", [$id])['body'];
        $this->assertStringContainsString('CH261115A1B2', $last);
        $this->assertStringNotContainsString((string) $c['protocol'], $last);
    }

    public function test_supervisor_e_gerente_podem_agir(): void
    {
        $id = $this->routed('elevador parado');
        $this->svc->accept($id, $this->agent(20, 'Manutenção'));
        $gerente = new StaffMember(30, false, [], [$this->sectors['Manutenção']]);
        $this->svc->addNote($id, $gerente, 'Acompanhar com a empresa do elevador');
        $master = new StaffMember(1, true);
        $this->svc->close($id, $master, 'Técnico enviado');
        $this->assertSame('closed', $this->conv($id)['status']);
    }

    public function test_ack_nao_regride(): void
    {
        $id = $this->routed('preciso do boleto');
        $msg = ['id' => $this->db->insert('talk_messages', ['conversation_id' => $id, 'direction' => 'out', 'type' => 'text', 'body' => 'Bom dia!', 'ack' => 'sent'])];
        $this->db->run("UPDATE talk_messages SET provider_message_id = 'P1' WHERE id = ?", [$msg['id']]);
        $this->svc->handleStatus(new \Source\Services\Talk\Domain\StatusUpdate('P1', 'read'));
        $this->svc->handleStatus(new \Source\Services\Talk\Domain\StatusUpdate('P1', 'delivered'));
        $this->assertSame('read', $this->db->one('SELECT ack FROM talk_messages WHERE id = ?', [$msg['id']])['ack']);
    }

    public function test_erp_fora_do_ar_nao_trava_o_atendimento(): void
    {
        $hd = new class implements Helpdesk {
            public function open(array $c, array $ct, ?string $s): ?array { throw new \RuntimeException('ERP 503'); }
            public function transfer(int $t, string $s, int $u, ?string $n): void { throw new \RuntimeException('ERP 503'); }
            public function close(int $t, int $u, ?string $r): void { throw new \RuntimeException('ERP 503'); }
        };
        $this->svc = $this->makeService(null, $hd);
        $id = $this->routed('preciso do boleto');
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertNull($c['helpdesk_ticket_id']);
        $this->svc->close($id, $this->agent(10, 'Financeiro'));
        $this->assertSame('closed', $this->conv($id)['status']);
    }

    private function staffUser(string $name, string $sector, string $role = 'agent'): int
    {
        $id = $this->db->insert('talk_users', ['name' => $name, 'email' => strtolower(strtok($name, ' ')) . '@t.lab', 'password_hash' => 'x']);
        $this->db->insert('talk_staff', ['user_id' => $id, 'sector_id' => $this->sectors[$sector], 'role' => $role]);
        return $id;
    }

    public function test_trocar_atendente_direto_para_uma_pessoa_do_setor(): void
    {
        $beto = $this->staffUser('Beto Reparos', 'Manutenção');
        $id = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->accept($id, $ana);
        $this->svc->transfer($id, $ana, $this->sectors['Manutenção'], 'vazamento no 101', $beto);

        $c = $this->conv($id);
        $this->assertSame('open', $c['status']);
        $this->assertSame($beto, (int) $c['assigned_user_id']);
        $note = $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND type = 'transfer'", [$id])['body'];
        $this->assertStringContainsString('para Manutenção (Beto Reparos)', $note);
        $this->assertStringContainsString('Motivo: vazamento no 101', $note);
    }

    public function test_nao_transfere_para_atendente_de_outro_setor_nem_motivo_longo(): void
    {
        $carla = $this->staffUser('Carla Contas', 'Financeiro');
        $id = $this->routed('preciso da segunda via do boleto');
        $sup = new StaffMember(1, true);
        try {
            $this->svc->transfer($id, $sup, $this->sectors['Manutenção'], null, $carla);
            $this->fail('Carla não é da Manutenção');
        } catch (DomainError $e) {
            $this->assertStringContainsString('não faz parte do setor', $e->getMessage());
        }
        $this->expectExceptionMessage('até 500 caracteres');
        $this->svc->transfer($id, $sup, $this->sectors['Manutenção'], str_repeat('a', 501));
    }

    public function test_sem_manter_historico_o_novo_atendente_ve_so_a_partir_da_transferencia(): void
    {
        $beto = $this->staffUser('Beto Reparos', 'Manutenção');
        $id = $this->routed('preciso da segunda via do boleto, meu CPF é 123');
        $sup = new StaffMember(1, true);
        $this->svc->transfer($id, $sup, $this->sectors['Manutenção'], 'só o reparo', $beto, keepHistory: false);
        $q = new \Source\Services\Talk\Service\InboxQuery($this->db, new \Source\Services\Talk\Service\AccessPolicy());

        $doBeto = implode(' ', array_column($q->messages($this->agent($beto, 'Manutenção'), $id), 'body'));
        $this->assertStringNotContainsString('CPF', $doBeto);
        $this->assertStringContainsString('Transferido', $doBeto);
        $doGerente = implode(' ', array_column($q->messages(new StaffMember(30, false, [], [$this->sectors['Manutenção']]), $id), 'body'));
        $this->assertStringContainsString('CPF', $doGerente, 'gerente do setor vê tudo');
    }
}
