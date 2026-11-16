<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\ConversationService;
use Source\Services\Talk\Service\DomainError;
use Source\Services\Talk\Service\ReportService;

final class ReportServiceTest extends DbTestCase
{
    private int $ana;
    private int $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->db->insert('talk_users', ['name' => 'Ana Ribeiro', 'email' => 'ana@x.com', 'password_hash' => 'x']);
        $this->beto = $this->db->insert('talk_users', ['name' => 'Beto (Manutenção)', 'email' => 'beto@x.com', 'password_hash' => 'x']);
        $this->db->insert('talk_staff', ['user_id' => $this->ana, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $this->db->insert('talk_staff', ['user_id' => $this->beto, 'sector_id' => $this->sectors['Manutenção'], 'role' => 'agent']);
    }

    private function reports(): ReportService
    {
        return new ReportService($this->db);
    }

    /** Atende e encerra; ajusta os horários para ter esperas e durações conhecidas (em minutos). */
    private function attended(string $text, string $phone, int $userId, string $sector, int $waitMin, int $handleMin): int
    {
        $c = $this->routed($text, $phone);
        $u = $this->agent($userId, $sector);
        $this->svc->accept($c, $u);
        $this->svc->close($c, $u);
        $this->db->run(
            'UPDATE talk_conversations SET opened_at = NOW() - INTERVAL 3 HOUR, queued_at = NOW() - INTERVAL 2 HOUR,
                    accepted_at = NOW() - INTERVAL 2 HOUR + INTERVAL ? MINUTE,
                    closed_at = NOW() - INTERVAL 2 HOUR + INTERVAL ? MINUTE WHERE id = ?',
            [$waitMin, $waitMin + $handleMin, $c]
        );
        return $c;
    }

    public function test_avaliacao_pergunta_ao_encerrar_e_guarda_a_nota(): void
    {
        $c = $this->routed('quero a segunda via do boleto');
        $ana = $this->agent($this->ana, 'Financeiro');
        $this->svc->accept($c, $ana);
        $this->svc->close($c, $ana);
        $this->assertStringContainsString('o atendimento de *Ana*', $this->lastOutbound($c));
        $this->assertStringContainsString('de *1* (ruim) a *5* (ótimo)', $this->lastOutbound($c));

        $this->assertSame($c, $this->inbound('5 - muito rápida, obrigada!'));
        $r = $this->db->one('SELECT * FROM talk_ratings WHERE conversation_id = ?', [$c]);
        $this->assertSame(5, (int) $r['score']);
        $this->assertSame('muito rápida, obrigada!', $r['comment']);
        $this->assertSame($this->ana, (int) $r['user_id']);
        $this->assertStringStartsWith('Obrigado pela avaliação', $this->lastOutbound($c));
        $this->assertSame('closed', $this->conv($c)['status']);

        // depois de avaliar, a próxima mensagem abre outro atendimento
        $novo = $this->inbound('4');
        $this->assertNotSame($c, $novo);
    }

    public function test_mensagem_que_nao_e_nota_abre_atendimento_novo(): void
    {
        $c = $this->routed('quero a segunda via do boleto');
        $ana = $this->agent($this->ana, 'Financeiro');
        $this->svc->accept($c, $ana);
        $this->svc->close($c, $ana);
        $novo = $this->inbound('2 via do condomínio de dezembro, por favor');
        $this->assertNotSame($c, $novo);
        $this->assertNull($this->db->one('SELECT score FROM talk_ratings WHERE conversation_id = ?', [$c])['score']);
        // a pergunta antiga não vale mais
        $this->svc->close($novo, new StaffMember(1, true), null, false);
        $this->assertNotSame($c, $this->inbound('5'));
    }

    public function test_nao_pergunta_sem_atendente_nem_depois_de_24h(): void
    {
        $c = $this->routed('vazamento no teto', '5531911112222');
        $this->svc->close($c, new StaffMember(1, true)); // fechado direto da fila
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM talk_ratings')['n']);

        $c = $this->routed('quero a segunda via do boleto');
        $ana = $this->agent($this->ana, 'Financeiro');
        $this->svc->accept($c, $ana);
        $this->svc->close($c, $ana);
        $this->db->run('UPDATE talk_ratings SET asked_at = NOW() - INTERVAL 25 HOUR');
        $this->assertNotSame($c, $this->inbound('5'));
    }

    public function test_leitura_da_nota(): void
    {
        foreach (['5' => [5, null], ' 3 ' => [3, null], '4/5' => [4, null], '1 de 5' => [1, null], '2, demorou muito' => [2, 'demorou muito'],
            '⭐⭐⭐⭐' => [4, null], '⭐⭐⭐⭐⭐ perfeito' => [5, 'perfeito'], 'Ótimo' => [5, null], 'muito bom!' => [5, null], 'ruim' => [2, null]] as $in => $want) {
            $this->assertSame($want, ConversationService::parseRating((string) $in), "entrada: $in");
        }
        foreach (['2 via do boleto', '2ª via', '6', '10', '0', 'obrigada', '12345', 'bom dia'] as $in) {
            $this->assertNull(ConversationService::parseRating($in), "entrada: $in");
        }
    }

    public function test_painel_tempos_por_setor_e_atendente(): void
    {
        $a = $this->attended('quero a segunda via do boleto', '5531900000001', $this->ana, 'Financeiro', 4, 10);
        $this->attended('boleto atrasado, como pago?', '5531900000002', $this->ana, 'Financeiro', 8, 20);
        $this->attended('vazamento no teto', '5531900000003', $this->beto, 'Manutenção', 30, 60);
        $this->routed('o portão quebrou', '5531900000004'); // ainda na fila
        $this->inbound('5', '5531900000001'); // nota da Ana
        $this->inbound('3 - poderia ser mais rápido', '5531900000003');

        $s = $this->reports()->summary(new StaffMember(1, true));
        $this->assertSame(4, $s['kpis']['total']);
        $this->assertSame(3, $s['kpis']['closed']);
        $this->assertSame(75, $s['kpis']['resolution_rate']);
        $this->assertSame(14 * 60, $s['kpis']['avg_wait_seconds']); // (4+8+30)/3
        $this->assertSame(30 * 60, $s['kpis']['avg_handle_seconds']); // (10+20+60)/3
        $this->assertSame(4.0, $s['kpis']['rating_avg']);
        $this->assertSame(2, $s['kpis']['rating_count']);
        $this->assertSame(3, $s['kpis']['rating_asked']);
        $this->assertCount(7, $s['by_day']);
        $this->assertSame(4, array_sum(array_column($s['by_day'], 'total')));

        $fin = array_values(array_filter($s['by_sector'], fn ($x) => $x['name'] === 'Financeiro'))[0];
        $this->assertSame([2, 6 * 60, 15 * 60, 5.0], [$fin['closed'], $fin['avg_wait_seconds'], $fin['avg_handle_seconds'], $fin['rating_avg']]);
        $this->assertSame(['Ana Ribeiro', 'Beto (Manutenção)'], array_column($s['by_user'], 'name'));
        $this->assertSame([0, 0, 1, 0, 1], array_values($s['ratings']['distribution']));
        $this->assertSame('poderia ser mais rápido', $s['ratings']['latest'][0]['comment'] ?? $s['ratings']['latest'][1]['comment']);

        // filtro por atendente e CSV
        $f = $this->reports()->summary(new StaffMember(1, true), ['user_id' => $this->beto]);
        $this->assertSame(1, $f['kpis']['total']);
        $csv = $this->reports()->exportCsv(new StaffMember(1, true), ['sector_id' => $this->sectors['Financeiro']]);
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Protocolo;"Aberto em"', $lines[0]);
        $this->assertStringContainsString('"Ana Ribeiro";Encerrado', $lines[1]);
        $this->assertStringContainsString(';4,0;10,0;5;', $lines[1]);
        $this->assertSame($a, $a);
    }

    public function test_escopo_e_periodo(): void
    {
        $this->attended('quero a segunda via do boleto', '5531900000001', $this->ana, 'Financeiro', 4, 10);
        $this->attended('vazamento no teto', '5531900000003', $this->beto, 'Manutenção', 30, 60);
        // atendente: só os próprios
        $s = $this->reports()->summary($this->agent($this->ana, 'Financeiro'));
        $this->assertSame(1, $s['kpis']['total']);
        $this->assertSame('mine', $s['scope']);
        $this->assertSame([], $s['filters']['sectors']);
        // gerente: o setor que gerencia, mesmo filtrando outro
        $gerente = new StaffMember(50, false, [], [$this->sectors['Manutenção']]);
        $this->assertSame(1, $this->reports()->summary($gerente)['kpis']['total']);
        $this->assertSame(0, $this->reports()->summary($gerente, ['sector_id' => $this->sectors['Financeiro']])['kpis']['total']);
        // período sem nada e validações
        $this->assertSame(0, $this->reports()->summary(new StaffMember(1, true), ['from' => '2025-01-01', 'to' => '2025-01-31'])['kpis']['total']);
        foreach ([['from' => '2026-02-30'], ['from' => '2026-05-10', 'to' => '2026-05-01'], ['from' => '2024-01-01', 'to' => '2026-01-01'], ['to' => 'ontem']] as $bad) {
            try {
                $this->reports()->summary(new StaffMember(1, true), $bad);
                $this->fail(json_encode($bad));
            } catch (DomainError $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }
}
