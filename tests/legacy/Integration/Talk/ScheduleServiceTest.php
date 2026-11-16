<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\AccessPolicy;
use Source\Services\Talk\Service\DomainError;
use Source\Services\Talk\Service\ScheduleService;

final class ScheduleServiceTest extends DbTestCase
{
    private int $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->db->insert('talk_users', ['name' => 'Ana (Financeiro)', 'email' => 'ana@x.com', 'password_hash' => 'x']);
        $this->db->insert('talk_staff', ['user_id' => $this->ana, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
    }

    private function sched(): ScheduleService
    {
        return new ScheduleService($this->db, new AccessPolicy(), $this->svc);
    }

    private function future(string $mod = '+1 day'): string
    {
        $now = $this->db->one('SELECT NOW() n')['n'];
        return date('Y-m-d H:i', strtotime("$now $mod"));
    }

    /** "Volta no tempo" para o agendamento vencer. */
    private function due(int $id): void
    {
        $this->db->run('UPDATE talk_schedules SET send_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$id]);
    }

    public function test_agenda_para_numero_novo_e_abre_conversa_na_hora(): void
    {
        $ana = $this->agent($this->ana, 'Financeiro');
        $s = $this->sched()->save($ana, ['phone' => '(31) 97777-1111', 'name' => 'Paulo', 'sector_id' => $this->sectors['Financeiro'],
            'send_at' => $this->future(), 'body' => 'Seu boleto de dezembro já está disponível.']);
        $this->assertSame('scheduled', $s['status']);
        $this->assertSame('5531977771111', $s['phone']);
        $this->assertSame(0, $this->sched()->runDue()); // ainda não venceu

        $this->due($s['id']);
        $this->assertSame(1, $this->sched()->runDue());
        $row = $this->db->one('SELECT * FROM talk_schedules WHERE id = ?', [$s['id']]);
        $this->assertSame('sent', $row['status']);
        $conv = $this->conv((int) $row['sent_conversation_id']);
        $this->assertSame('open', $conv['status']);
        $this->assertSame($this->ana, (int) $conv['assigned_user_id']);
        $this->assertSame($this->sectors['Financeiro'], (int) $conv['sector_id']);
        $this->assertSame($this->channelId, (int) $conv['channel_id']);
        $this->assertSame("*Ana:*\nSeu boleto de dezembro já está disponível.", $this->lastOutbound((int) $conv['id']));
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM talk_outbox')['n']);
        $this->assertSame(0, $this->sched()->runDue()); // não manda duas vezes
    }

    public function test_agendar_pela_conversa_usa_a_mesma_conversa_e_deixa_nota(): void
    {
        $conv = $this->routed('quero a segunda via do boleto');
        $ana = $this->agent($this->ana, 'Financeiro');
        $this->svc->accept($conv, $ana);
        $s = $this->sched()->save($ana, ['conversation_id' => $conv, 'send_at' => $this->future('+2 hours'), 'body' => 'Conseguiu pagar?', 'sign' => false]);
        $this->assertSame($conv, $s['conversation_id']);
        $this->assertStringStartsWith('Mensagem agendada para',
            $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'internal' ORDER BY id DESC LIMIT 1", [$conv])['body']);
        $this->due($s['id']);
        $this->sched()->runDue();
        $this->assertSame('Conseguiu pagar?', $this->lastOutbound($conv));
        $this->assertSame($conv, (int) $this->db->one('SELECT sent_conversation_id c FROM talk_schedules WHERE id = ?', [$s['id']])['c']);
    }

    public function test_conversa_encerrada_abre_nova_com_protocolo(): void
    {
        $conv = $this->routed('quero a segunda via do boleto');
        $sup = new StaffMember(999, true); // supervisor que não é usuário ativo: a conversa nova vai para a fila
        $s = $this->sched()->save($sup, ['conversation_id' => $conv, 'send_at' => $this->future(), 'body' => 'Lembrete: vence amanhã.']);
        $this->svc->close($conv, $sup, null, false);
        $this->due($s['id']);
        $this->sched()->runDue();
        $new = (int) $this->db->one('SELECT sent_conversation_id c FROM talk_schedules WHERE id = ?', [$s['id']])['c'];
        $this->assertNotSame($conv, $new);
        $this->assertNotSame($this->conv($conv)['protocol'], $this->conv($new)['protocol']);
        $this->assertSame('pending', $this->conv($new)['status']);
    }

    public function test_permissoes_e_escopo(): void
    {
        $ana = $this->agent($this->ana, 'Financeiro');
        $carlos = $this->agent(99, 'Manutenção');
        try {
            $this->sched()->save($ana, ['phone' => '31977771111', 'sector_id' => $this->sectors['Manutenção'], 'send_at' => $this->future(), 'body' => 'oi']);
            $this->fail('setor alheio');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->getCode());
        }
        $s = $this->sched()->save($ana, ['phone' => '31977771111', 'sector_id' => $this->sectors['Financeiro'], 'send_at' => $this->future(), 'body' => 'oi']);
        $this->assertCount(0, $this->sched()->list($carlos));
        $this->assertCount(1, $this->sched()->list(new StaffMember(1, true)));
        try {
            $this->sched()->cancel($carlos, $s['id']);
            $this->fail('não vê');
        } catch (DomainError $e) {
            $this->assertSame(404, $e->getCode());
        }
        // colega do mesmo setor vê, mas não altera; gerente do setor altera
        $colega = $this->agent(98, 'Financeiro');
        $this->assertFalse($this->sched()->list($colega)[0]['can_edit']);
        $gerente = new StaffMember(97, false, [], [$this->sectors['Financeiro']]);
        $this->assertSame('canceled', $this->sched()->cancel($gerente, $s['id'])['status']);

        $conv = $this->routed('vazamento no teto'); // Manutenção
        $this->expectException(DomainError::class);
        $this->sched()->save($ana, ['conversation_id' => $conv, 'send_at' => $this->future(), 'body' => 'oi']);
    }

    public function test_validacoes(): void
    {
        $sup = new StaffMember(1, true);
        $base = ['phone' => '31977771111', 'sector_id' => $this->sectors['Financeiro'], 'send_at' => $this->future(), 'body' => 'oi'];
        foreach ([['body' => ''], ['send_at' => '2020-01-01 10:00'], ['send_at' => 'amanhã'], ['send_at' => $this->future('+2 years')],
            ['phone' => '123'], ['sector_id' => '']] as $i => $bad) {
            try {
                $this->sched()->save($sup, $bad + $base);
                $this->fail("caso $i");
            } catch (DomainError $e) {
                $this->assertSame(422, $e->getCode(), "caso $i: " . $e->getMessage());
            }
        }
        // só número oficial ativo: não agenda (a Meta exige modelo)
        $this->db->run("UPDATE talk_channels SET kind = 'official'");
        $this->expectExceptionMessage('modelos aprovados');
        $this->sched()->save($sup, $base);
    }

    public function test_editar_cancelar_e_contato_bloqueado(): void
    {
        $sup = new StaffMember(1, true);
        $s = $this->sched()->save($sup, ['phone' => '31977771111', 'sector_id' => $this->sectors['Financeiro'], 'send_at' => $this->future(), 'body' => 'oi']);
        $e = $this->sched()->save($sup, ['body' => 'oi de novo', 'send_at' => $this->future('+3 days'), 'sign' => false], $s['id']);
        $this->assertSame('oi de novo', $e['body']);
        $this->assertFalse($e['sign']);

        $this->db->run('UPDATE talk_contacts SET blocked_at = NOW()');
        $this->due($s['id']);
        $this->assertSame(0, $this->sched()->runDue());
        $row = $this->db->one('SELECT status, error FROM talk_schedules WHERE id = ?', [$s['id']]);
        $this->assertSame('canceled', $row['status']);
        $this->assertStringContainsString('bloqueado', $row['error']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM talk_outbox')['n']);
        try {
            $this->sched()->cancel($sup, $s['id']);
            $this->fail('já cancelado');
        } catch (DomainError) {
        }
        $counts = $this->sched()->counts($sup);
        $this->assertSame(1, $counts['canceled']);
    }

    public function test_numero_desativado_marca_falha(): void
    {
        $sup = new StaffMember(1, true);
        $s = $this->sched()->save($sup, ['phone' => '31977771111', 'sector_id' => $this->sectors['Financeiro'], 'send_at' => $this->future(), 'body' => 'oi']);
        $this->db->run("UPDATE talk_channels SET status = 'disabled'");
        $this->due($s['id']);
        $this->sched()->runDue();
        $row = $this->db->one('SELECT status, error FROM talk_schedules WHERE id = ?', [$s['id']]);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('desativado', $row['error']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM talk_conversations')['n']);
    }
}
