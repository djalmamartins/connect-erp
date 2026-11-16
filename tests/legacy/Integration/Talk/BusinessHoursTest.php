<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\BusinessHours;
use Source\Services\Talk\Service\DomainError;

final class BusinessHoursTest extends DbTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable($this->db->one('SELECT NOW() n')['n']);
    }

    private function hours(): BusinessHours
    {
        return new BusinessHours($this->db);
    }

    private function sup(): StaffMember
    {
        return new StaffMember(1, true);
    }

    /** Semana vazia, com faixas só nos dias pedidos (0 = hoje, 2 = depois de amanhã...). */
    private function week(array $byOffset): array
    {
        $w = array_fill_keys(array_map('strval', range(0, 6)), []);
        foreach ($byOffset as $off => $iv) {
            $w[$this->now->modify("+$off day")->format('w')] = $iv;
        }
        return $w;
    }

    private function outbound(int $conv, string $type): array
    {
        return array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' AND type = ?", [$conv, $type]), 'body');
    }

    public function test_desligado_nao_muda_nada(): void
    {
        $this->assertFalse($this->hours()->enabled());
        // a prévia mostra a volta do horário padrão (seg a sex, 8h às 18h), não "agora"
        $this->assertMatchesRegularExpression('/^(amanhã|hoje|segunda|terça|quarta|quinta|sexta).* às 8h$/', $this->hours()->overview(new StaffMember(1, true))['sample']['{volta}']);
        $c = $this->routed('quero a segunda via do boleto');
        $this->assertCount(0, $this->outbound($c, 'off_hours'));
        $this->assertCount(0, $this->outbound($c, 'jack_routed')); // dentro do horário, encaminha em silêncio
    }

    public function test_fora_do_horario_a_jack_diz_quando_volta_uma_vez_so(): void
    {
        $this->hours()->save($this->sup(), ['enabled' => true, 'week' => $this->week([2 => [['08:00', '12:00'], ['13:30', '18:00']]])]);
        $c = $this->routed('quero a segunda via do boleto');
        $this->assertSame('pending', $this->conv($c)['status']); // continua na fila do setor
        $this->assertCount(0, $this->outbound($c, 'jack_routed'));
        $msg = $this->outbound($c, 'off_hours');
        $this->assertCount(1, $msg);
        $day = BusinessHours::DAYS[(int) $this->now->modify('+2 day')->format('w')];
        $this->assertStringContainsString($this->conv($c)['protocol'], $msg[0]);
        $this->assertStringContainsString('*Financeiro*', $msg[0]);
        $this->assertStringContainsString("A equipe responde $day às 8h.", $msg[0]);
        $this->assertStringContainsString('8h às 12h e 13h30 às 18h', $msg[0]);
        $this->assertStringStartsWith("*Jack:*\nOi, Maria!", $msg[0]);

        $this->inbound('alguém aí?');
        $this->triage();
        $this->assertCount(1, $this->outbound($c, 'off_hours'));
    }

    public function test_dentro_do_horario_segue_normal_e_setor_24h_e_feriado(): void
    {
        $this->hours()->save($this->sup(), ['enabled' => true, 'week' => $this->week([0 => [['00:00', '24:00']], 1 => [['09:00', '17:00']]])]);
        $c = $this->routed('quero a segunda via do boleto');
        $this->assertCount(0, $this->outbound($c, 'off_hours'));
        $this->assertCount(0, $this->outbound($c, 'jack_routed'));

        // hoje vira feriado: fecha, e a volta é amanhã às 9h; a Manutenção (24 h) continua atendendo
        $this->hours()->addHoliday($this->sup(), ['day' => $this->now->format('Y-m-d'), 'name' => 'Feriado municipal']);
        $this->hours()->save($this->sup(), ['enabled' => true, 'week' => $this->week([0 => [['00:00', '24:00']], 1 => [['09:00', '17:00']]]),
            'sectors' => [$this->sectors['Manutenção'] => ['mode' => 'always']]]);
        $h = $this->hours();
        $this->assertFalse($h->isOpen($this->sectors['Financeiro'], $this->now));
        $this->assertTrue($h->isOpen($this->sectors['Manutenção'], $this->now));
        $this->assertSame($this->now->modify('+1 day')->format('Y-m-d') . ' 09:00', $h->nextOpening($this->sectors['Financeiro'], $this->now)->format('Y-m-d H:i'));

        $this->svc = $this->makeService(); // o serviço relê o horário a cada 30 s; aqui, na hora
        $m = $this->routed('vazamento no teto', '5531911110000');
        $this->assertCount(0, $this->outbound($m, 'off_hours'));
        $f = $this->routed('boleto atrasado', '5531922220000');
        $this->assertStringContainsString('amanhã às 9h', $this->outbound($f, 'off_hours')[0]);

        $ov = $h->overview($this->sup());
        $byName = array_column($ov['sectors'], null, 'name');
        $this->assertFalse($byName['Financeiro']['open_now']);
        $this->assertSame('amanhã às 9h', $byName['Financeiro']['next_label']);
        $this->assertTrue($byName['Manutenção']['open_now']);
        $this->assertSame('Feriado municipal', $ov['holidays'][0]['name']);
    }

    public function test_setor_com_horario_proprio(): void
    {
        $this->hours()->save($this->sup(), ['enabled' => true, 'week' => $this->week([0 => [['00:00', '24:00']]]),
            'sectors' => [$this->sectors['Administrativo'] => ['mode' => 'custom', 'week' => $this->week([3 => [['10:00', '16:00']]])]]]);
        $h = $this->hours();
        $this->assertTrue($h->isOpen($this->sectors['Financeiro'], $this->now));
        $this->assertFalse($h->isOpen($this->sectors['Administrativo'], $this->now));
        $this->assertSame($this->now->modify('+3 day')->format('Y-m-d') . ' 10:00', $h->nextOpening($this->sectors['Administrativo'], $this->now)->format('Y-m-d H:i'));
    }

    public function test_textos(): void
    {
        $h = $this->hours();
        $week = ['0' => [], '1' => [['08:00', '12:00'], ['13:00', '18:00']], '2' => [['08:00', '12:00'], ['13:00', '18:00']],
            '3' => [['08:00', '12:00'], ['13:00', '18:00']], '4' => [['08:00', '12:00'], ['13:00', '18:00']], '5' => [['08:00', '17:00']], '6' => [['08:30', '12:00']]];
        $this->assertSame('seg a qui, 8h às 12h e 13h às 18h; sex, 8h às 17h; sáb, 8h30 às 12h', $h->describe($week));
        $this->assertSame('sáb e dom, 9h às 13h', $h->describe(['6' => [['09:00', '13:00']], '0' => [['09:00', '13:00']]]));
        $this->assertSame('sem horário definido', $h->describe([]));
        $now = new \DateTimeImmutable('2026-11-13 19:00'); // sexta
        $this->assertSame('hoje às 20h', $h->whenLabel(new \DateTimeImmutable('2026-11-13 20:00'), $now));
        $this->assertSame('amanhã às 8h30', $h->whenLabel(new \DateTimeImmutable('2026-11-14 08:30'), $now));
        $this->assertSame('segunda-feira às 8h', $h->whenLabel(new \DateTimeImmutable('2026-11-16 08:00'), $now));
        $this->assertSame('dia 23/11 às 8h', $h->whenLabel(new \DateTimeImmutable('2026-11-23 08:00'), $now));

        $n = BusinessHours::nationalHolidays(2026);
        $this->assertSame('Carnaval', $n['2026-02-16']);
        $this->assertSame('Carnaval', $n['2026-02-17']);
        $this->assertSame('Sexta-feira Santa', $n['2026-04-03']);
        $this->assertSame('Corpus Christi', $n['2026-06-04']);
        $this->assertSame('Dia da Consciência Negra', $n['2026-11-20']);
        $this->assertCount(13, $n);
        $this->assertSame('Sexta-feira Santa', BusinessHours::nationalHolidays(2027)['2027-03-26']);
    }

    public function test_feriados_importar_e_apagar(): void
    {
        $year = (int) $this->now->format('Y') + 1;
        $r = $this->hours()->importNational($this->sup(), $year);
        $this->assertSame(13, $r['added']);
        $this->assertSame(0, $this->hours()->importNational($this->sup(), $year)['added']);
        $id = $r['holidays'][0]['id'];
        $this->assertCount(12, $this->hours()->deleteHoliday($this->sup(), $id));
        $this->assertSame(3, (int) $this->db->one("SELECT COUNT(*) n FROM talk_audit_log WHERE action LIKE 'holiday.%'")['n']);
    }

    public function test_validacoes_e_permissoes(): void
    {
        $ok = $this->week([1 => [['08:00', '18:00']]]);
        foreach ([
            ['week' => $this->week([1 => [['08:00', '12:00'], ['11:00', '14:00']]])],
            ['week' => $this->week([1 => [['18:00', '08:00']]])],
            ['week' => $this->week([1 => [['8h', '18:00']]])],
            ['week' => $this->week([1 => [['01:00', '02:00'], ['03:00', '04:00'], ['05:00', '06:00'], ['07:00', '08:00']]])],
            ['week' => $this->week([]), 'enabled' => true],
            ['week' => $ok, 'message' => str_repeat('a', 1001)],
            ['week' => 'x'],
            ['week' => $ok, 'sectors' => [$this->sectors['Financeiro'] => ['mode' => 'quase sempre']]],
        ] as $i => $bad) {
            try {
                $this->hours()->save($this->sup(), $bad);
                $this->fail("caso $i");
            } catch (DomainError $e) {
                $this->assertSame(422, $e->getCode(), "caso $i: " . $e->getMessage());
            }
        }
        foreach ([['day' => '2026-02-30', 'name' => 'x'], ['day' => '2026-12-25', 'name' => '']] as $bad) {
            try {
                $this->hours()->addHoliday($this->sup(), $bad);
                $this->fail('feriado');
            } catch (DomainError $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
        $this->hours()->addHoliday($this->sup(), ['day' => '2026-12-25', 'name' => 'Natal']);
        try {
            $this->hours()->addHoliday($this->sup(), ['day' => '2026-12-25', 'name' => 'Natal de novo']);
            $this->fail('repetido');
        } catch (DomainError) {
        }
        $this->expectExceptionCode(403);
        $this->hours()->save(new StaffMember(5, false, [], [$this->sectors['Financeiro']]), ['week' => $ok]);
    }

    public function test_espera_so_conta_dentro_do_horario_e_fora_de_feriado(): void
    {
        $h = $this->hours();
        $sector = $this->sectors['Financeiro'];
        $at = fn (string $t) => new \DateTimeImmutable($t);
        // desligado: tempo corrido
        $this->assertSame(3600 * 15, $h->openSeconds($sector, $at('2026-11-16 17:00'), $at('2026-11-17 08:00')));

        $week = array_fill_keys(array_map('strval', range(0, 6)), []);
        foreach (['1', '2', '3', '4', '5'] as $d) {
            $week[$d] = [['08:00', '12:00'], ['13:00', '18:00']];
        }
        $h->save($this->sup(), ['enabled' => true, 'week' => $week]);
        // segunda 17:00 → terça 08:30: 1 h na segunda + 30 min na terça; a noite não conta
        $this->assertSame(5400, $h->openSeconds($sector, $at('2026-11-16 17:00'), $at('2026-11-17 08:30')));
        // almoço não conta: 11:30 → 13:30 = 1 h
        $this->assertSame(3600, $h->openSeconds($sector, $at('2026-11-16 11:30'), $at('2026-11-16 13:30')));
        // sexta 17:00 → segunda 08:15 (fim de semana fechado) = 1 h 15
        $this->assertSame(4500, $h->openSeconds($sector, $at('2026-11-20 17:00'), $at('2026-11-23 08:15')));
        // feriado no meio não conta
        $this->db->insert('talk_holidays', ['day' => '2026-11-17', 'name' => 'Teste']);
        $this->assertSame(3600 + 1800, $h->openSeconds($sector, $at('2026-11-16 17:00'), $at('2026-11-18 08:30')));
        // chegou e foi aceita com o setor fechado: espera zero
        $this->assertSame(0, $h->openSeconds($sector, $at('2026-11-21 10:00'), $at('2026-11-21 11:00')));
    }
}
