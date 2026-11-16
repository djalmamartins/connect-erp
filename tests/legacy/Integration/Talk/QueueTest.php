<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\AccessPolicy;
use Source\Services\Talk\Service\DomainError;
use Source\Services\Talk\Service\QueueQuery;

final class QueueTest extends DbTestCase
{
    private function q(): QueueQuery
    {
        return new QueueQuery($this->db, new AccessPolicy(), 15, 30);
    }

    private function user(int $id, string $name, ?string $sector, string $role = 'agent'): void
    {
        $this->db->run('INSERT INTO talk_users (id, name, email, password_hash) VALUES (?, ?, ?, ?)', [$id, $name, "u$id@x", 'x']);
        $this->db->insert('talk_staff', ['user_id' => $id, 'sector_id' => $sector ? $this->sectors[$sector] : null, 'role' => $role]);
    }

    public function test_fila_por_ordem_de_chegada_com_tempo_e_cor_de_espera(): void
    {
        $a = $this->routed('preciso da segunda via do boleto', '5531900000001');
        $b = $this->routed('quero o boleto de dezembro, segunda via', '5531900000002');
        $c = $this->routed('o portão da garagem quebrou', '5531900000003');
        $this->db->run('UPDATE talk_conversations SET queued_at = NOW() - INTERVAL 40 MINUTE WHERE id = ?', [$a]);
        $this->db->run('UPDATE talk_conversations SET queued_at = NOW() - INTERVAL 20 MINUTE WHERE id = ?', [$b]);

        $o = $this->q()->overview(new StaffMember(1, true));
        $this->assertSame([$a, $b, $c], array_map('intval', array_column($o['waiting'], 'id')));
        $this->assertSame(['late', 'warn', 'ok'], array_column($o['waiting'], 'level'));
        $this->assertSame([1, 2, 3], array_column($o['waiting'], 'position'));
        $this->assertSame('preciso da segunda via do boleto', $o['waiting'][0]['subject']);
        $this->assertSame(3, $o['stats']['waiting']);
        $this->assertGreaterThanOrEqual(2400, $o['stats']['max_wait_seconds']);
        $this->assertLessThan(60, $o['stats']['min_wait_seconds']);
        $this->assertSame([2, 0, 1], array_column($o['distribution'], 'waiting'), 'Financeiro, Administrativo, Manutenção');

        $fin = $this->q()->overview($this->agent(2, 'Financeiro'));
        $this->assertSame([$a, $b], array_map('intval', array_column($fin['waiting'], 'id')), 'Financeiro não vê a fila da Manutenção');
        $this->assertSame(['Financeiro'], array_column($fin['distribution'], 'name'));
    }

    public function test_numeros_do_dia_e_aceitar_registra_hora(): void
    {
        $sup = new StaffMember(1, true);
        $a = $this->routed('preciso da segunda via do boleto', '5531900000001');
        $b = $this->routed('o portão da garagem quebrou', '5531900000002');
        $this->inbound('oi', '5531900000003'); // ainda na triagem: não conta
        $this->svc->accept($a, $this->agent(2, 'Financeiro'));
        $this->assertNotNull($this->conv($a)['accepted_at']);
        $this->svc->close($a, $sup, 'ok');

        $s = $this->q()->overview($sup)['stats'];
        $this->assertSame([2, 1, 50, 1], [$s['today_total'], $s['today_closed'], $s['resolution_rate'], $s['waiting']]);
        $m = $this->q()->overview($this->agent(4, 'Manutenção'))['stats'];
        $this->assertSame([1, 0, 0, 1], [$m['today_total'], $m['today_closed'], $m['resolution_rate'], $m['waiting']], 'Manutenção só conta o próprio setor');
    }

    public function test_transferir_para_setor_volta_para_o_fim_da_fila(): void
    {
        $a = $this->routed('preciso da segunda via do boleto');
        $this->db->run('UPDATE talk_conversations SET queued_at = NOW() - INTERVAL 50 MINUTE WHERE id = ?', [$a]);
        $this->svc->transfer($a, new StaffMember(1, true), $this->sectors['Manutenção'], 'é reparo');
        $w = $this->q()->overview(new StaffMember(1, true))['waiting'][0];
        $this->assertLessThan(60, $w['wait_seconds'], 'espera conta a partir da entrada no novo setor');
    }

    public function test_prioridade_so_quem_pode_agir(): void
    {
        $a = $this->routed('preciso da segunda via do boleto');
        $this->assertSame('high', $this->svc->setPriority($a, $this->agent(2, 'Financeiro'), 'high')['priority']);
        $this->assertSame('high', $this->q()->overview(new StaffMember(1, true))['waiting'][0]['priority']);
        try {
            $this->svc->setPriority($a, $this->agent(4, 'Manutenção'), 'low');
            $this->fail('outro setor não muda prioridade');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->expectExceptionMessage('Prioridade inválida');
        $this->svc->setPriority($a, $this->agent(2, 'Financeiro'), 'urgente');
    }

    public function test_atendentes_online_atendendo_ou_disponiveis(): void
    {
        $this->user(2, 'Ana (Financeiro)', 'Financeiro');
        $this->user(3, 'Bia (Financeiro)', 'Financeiro');
        $this->user(4, 'Caio (Manutenção)', 'Manutenção');
        $q = $this->q();
        $q->touch(2);
        $q->touch(4);
        $this->db->run('UPDATE talk_users SET last_seen_at = NOW() - INTERVAL 5 MINUTE WHERE id = 4');
        $a = $this->routed('preciso da segunda via do boleto');
        $this->svc->accept($a, $this->agent(2, 'Financeiro'));

        $people = array_column($q->attendants(new StaffMember(1, true)), null, 'id');
        $this->assertSame(['busy', 'offline', 'offline'], [$people[2]['status'], $people[3]['status'], $people[4]['status']]);
        $this->assertSame(1, $people[2]['open_count']);
        $this->svc->close($a, new StaffMember(1, true), 'ok');
        $this->assertSame('available', array_column($q->attendants(new StaffMember(1, true)), null, 'id')[2]['status']);
        $this->assertSame([2, 3], array_column($q->attendants($this->agent(2, 'Financeiro')), 'id'), 'Financeiro só vê a própria equipe');
    }
}
