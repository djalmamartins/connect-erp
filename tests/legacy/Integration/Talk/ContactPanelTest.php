<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Service\AccessPolicy;
use Source\Services\Talk\Service\ContactPanel;
use Source\Services\Talk\Service\DomainError;
use Source\Services\Talk\Service\InboxQuery;

final class ContactPanelTest extends DbTestCase
{
    private ContactPanel $p;
    private StaffMember $sup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new ContactPanel($this->db, new AccessPolicy(), new EventBus($this->db));
        $this->sup = new StaffMember(1, true);
    }

    public function test_so_supervisor_e_gerente_cadastram_etiqueta_e_nome_e_unico(): void
    {
        $t = $this->p->saveTag($this->sup, ['name' => '  Síndico ', 'color' => '#c9a227']);
        $this->assertSame(['Síndico', '#C9A227'], [$t['name'], $t['color']]);
        $this->p->saveTag(new StaffMember(5, false, [], [$this->sectors['Manutenção']]), ['name' => 'Obra']);
        foreach ([[$this->agent(2, 'Financeiro'), ['name' => 'X'], 403], [$this->sup, ['name' => 'síndico'], 422], [$this->sup, ['name' => 'Y', 'color' => 'red'], 422]] as [$who, $in, $code]) {
            try {
                $this->p->saveTag($who, $in);
                $this->fail('deveria recusar ' . json_encode($in));
            } catch (DomainError $e) {
                $this->assertSame($code, $e->getCode());
            }
        }
        $this->assertSame(['Obra', 'Síndico'], array_column($this->p->tags(), 'name'));
    }

    public function test_etiqueta_fica_no_contato_aparece_na_lista_e_filtra(): void
    {
        $sind = $this->p->saveTag($this->sup, ['name' => 'Síndico'])['id'];
        $inad = $this->p->saveTag($this->sup, ['name' => 'Inadimplente'])['id'];
        $a = $this->routed('preciso da segunda via do boleto', '5531911110001');
        $b = $this->routed('quero a segunda via do boleto', '5531911110002');
        $ana = $this->agent(2, 'Financeiro');

        $tags = $this->p->setContactTags($ana, $a, [$sind, $inad, $sind]);
        $this->assertSame(['Inadimplente', 'Síndico'], array_column($tags, 'name'));
        $this->assertSame([], array_column($this->p->setContactTags($ana, $b, []), 'name'));

        $q = new InboxQuery($this->db, new AccessPolicy());
        $rows = $q->list($ana, 'pending');
        $byId = array_column($rows, 'tag_ids', 'id');
        $this->assertSame(min($sind, $inad) . ',' . max($sind, $inad), $byId[$a]);
        $this->assertNull($byId[$b]);
        $this->assertSame([$a], array_map('intval', array_column($q->list($ana, 'pending', tagId: $inad), 'id')));

        // nova conversa da mesma pessoa já vem com as etiquetas
        $this->svc->close($a, new StaffMember(1, true), 'ok');
        $a2 = $this->routed('outra coisa: segunda via do boleto de dezembro', '5531911110001');
        $this->assertSame(['Inadimplente', 'Síndico'], array_column($this->p->panel($ana, $a2)['tags'], 'name'));

        $this->expectExceptionCode(403);
        $this->p->setContactTags($this->agent(4, 'Manutenção'), $b, [$sind]);
    }

    public function test_ultimos_chamados_so_mostram_o_que_o_setor_pode_ver(): void
    {
        $sup = $this->sup;
        $fin = $this->routed('preciso da segunda via do boleto');
        $this->svc->close($fin, $sup, 'enviado');
        $man = $this->routed('o portão da garagem quebrou');
        $this->svc->close($man, $sup, 'consertado');
        $now = $this->routed('segunda via do boleto de novo');

        $doSup = $this->p->panel($sup, $now);
        $this->assertSame([$now, $man, $fin], array_column($doSup['history'], 'id'), 'do mais novo para o mais antigo, com o atual');
        $this->assertTrue($doSup['history'][0]['current']);
        $this->assertSame('o portão da garagem quebrou', $doSup['history'][1]['subject']);

        $daAna = $this->p->panel($this->agent(2, 'Financeiro'), $now);
        $this->assertSame([$now, $fin], array_column($daAna['history'], 'id'), 'Financeiro não vê o chamado da Manutenção');
        $this->assertSame(1, $daAna['history_hidden']);
    }

    public function test_protocolos_da_pessoa_de_5_em_5(): void
    {
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = $id = $this->routed("segunda via do boleto $i");
            $this->svc->close($id, $this->sup, 'ok');
        }
        $p1 = $this->p->panel($this->sup, $ids[6]);
        $this->assertSame([$ids[6], $ids[5], $ids[4], $ids[3], $ids[2]], array_column($p1['history'], 'id'));
        $this->assertSame([1, 2, 7], [$p1['history_page'], $p1['history_pages'], $p1['history_total']]);
        $p2 = $this->p->history($this->sup, $ids[6], 2);
        $this->assertSame([$ids[1], $ids[0]], array_column($p2['items'], 'id'));
        $this->assertSame(2, $this->p->history($this->sup, $ids[6], 99)['page'], 'página além do fim volta para a última');
        $this->assertMatchesRegularExpression('/^SAC\d{6}\d{5}$/', $p2['items'][0]['protocol']);
    }

    public function test_notas_do_contato_valem_para_todas_as_conversas_e_so_o_autor_apaga(): void
    {
        $a = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(2, 'Financeiro');
        $n = $this->p->addNote($ana, $a, "  prefere contato depois das 18h \r\n");
        $this->assertSame('prefere contato depois das 18h', $n['body']);
        $this->svc->close($a, $this->sup, 'ok');
        $b = $this->routed('o portão da garagem quebrou');
        $notes = $this->p->panel($this->agent(4, 'Manutenção'), $b)['notes'];
        $this->assertSame(['prefere contato depois das 18h'], array_column($notes, 'body'));

        try {
            $this->p->deleteNote($this->agent(4, 'Manutenção'), $b, $n['id']);
            $this->fail('outro atendente não apaga');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->p->deleteNote(new StaffMember(5, false, [], [$this->sectors['Manutenção']]), $b, $n['id']);
        $this->assertSame([], $this->p->panel($this->sup, $b)['notes']);
        $this->expectExceptionMessage('1000');
        $this->p->addNote($this->sup, $b, str_repeat('x', 1001));
    }
}
