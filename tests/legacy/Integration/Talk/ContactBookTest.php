<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\ContactBook;
use Source\Services\Talk\Service\DomainError;

final class ContactBookTest extends DbTestCase
{
    private ContactBook $book;
    private StaffMember $sup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = new ContactBook($this->db, $this->svc);
        $this->sup = new StaffMember(1, true);
    }

    private function contactOf(int $convId): int
    {
        return (int) $this->conv($convId)['contact_id'];
    }

    public function test_lista_busca_e_escopo_por_setor(): void
    {
        $fin = $this->routed('preciso da segunda via do boleto', '5531911110001');
        $man = $this->routed('o portão da garagem quebrou', '5531922220002');
        $this->db->run("UPDATE talk_contacts SET name = 'Mariana Souza', erp_snapshot = '{\"condominio\":\"Residencial Jardins\",\"unidade\":\"Bloco A 101\"}' WHERE id = ?", [$this->contactOf($fin)]);

        $this->assertSame(2, $this->book->list($this->sup, [])['total']);
        $this->assertSame([$this->contactOf($fin)], array_map('intval', array_column($this->book->list($this->agent(2, 'Financeiro'), [])['items'], 'id')), 'Financeiro só vê quem falou com o Financeiro');
        $this->assertSame(1, $this->book->list($this->sup, ['q' => 'jardins'])['total'], 'busca no condomínio');
        $this->assertSame(1, $this->book->list($this->sup, ['q' => '2222-0002'])['total'], 'busca pelo telefone com máscara');
        $this->assertSame(1, $this->book->list($this->sup, ['q' => 'mariana'])['total']);
        $this->assertSame(0, $this->book->list($this->sup, ['q' => '%'])['total'], 'curinga do LIKE não vale');

        $this->expectExceptionCode(404);
        $this->book->show($this->agent(2, 'Financeiro'), $this->contactOf($man));
    }

    public function test_bloquear_descarta_mensagens_e_encerra_sem_avisar(): void
    {
        $id = $this->routed('preciso da segunda via do boleto', '5531933330003');
        $contact = $this->contactOf($id);
        $outBefore = (int) $this->db->one("SELECT COUNT(*) n FROM talk_messages WHERE direction = 'out'")['n'];

        try {
            $this->book->block($this->agent(2, 'Financeiro'), $contact, 'spam');
            $this->fail('atendente não bloqueia');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->getCode());
        }
        try {
            $this->book->block(new StaffMember(5, false, [], [$this->sectors['Manutenção']]), $contact, 'x');
            $this->fail('gerente da Manutenção não vê (nem bloqueia) contato do Financeiro');
        } catch (DomainError $e) {
            $this->assertSame(404, $e->getCode());
        }
        $c = $this->book->block(new StaffMember(6, false, [], [$this->sectors['Financeiro']]), $contact, 'mensagens ofensivas');
        $this->assertNotNull($c['blocked_at']);
        $this->assertSame('closed', $this->conv($id)['status'], 'atendimento aberto é encerrado');
        $this->assertSame($outBefore, (int) $this->db->one("SELECT COUNT(*) n FROM talk_messages WHERE direction = 'out'")['n'], 'sem mensagem de encerramento');

        $this->assertNull($this->inbound('oi, de novo', '5531933330003'));
        $this->assertNull($this->inbound('alô?', '31933330003'), 'mesmo número em outro formato');
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM talk_conversations WHERE contact_id = ?", [$contact])['n']);
        $this->assertSame(2, (int) $this->book->show($this->sup, $contact)['blocked_messages']);
        $this->assertSame(1, $this->book->list($this->sup, ['status' => 'blocked'])['total']);

        $this->book->unblock($this->sup, $contact);
        $this->assertNotNull($this->inbound('voltei', '5531933330003'));
    }

    public function test_editar_nome_e_historico_filtrado(): void
    {
        $a = $this->routed('preciso da segunda via do boleto', '5531944440004');
        $this->svc->close($a, $this->sup, 'ok');
        $b = $this->routed('o portão da garagem quebrou', '5531944440004');
        $contact = $this->contactOf($a);

        $this->assertSame('João da Silva', $this->book->rename($this->agent(2, 'Financeiro'), $contact, '  João   da Silva ')['name']);
        $this->assertSame([$b, $a], array_map('intval', array_column($this->book->show($this->sup, $contact)['history'], 'id')));
        $this->assertSame([$a], array_map('intval', array_column($this->book->show($this->agent(2, 'Financeiro'), $contact)['history'], 'id')));
        $this->assertFalse($this->book->show($this->agent(2, 'Financeiro'), $contact)['can_block']);
        $this->expectExceptionMessage('150');
        $this->book->rename($this->sup, $contact, str_repeat('a', 151));
    }
}
