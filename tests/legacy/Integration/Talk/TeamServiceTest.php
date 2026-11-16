<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Service\DomainError;
use Source\Services\Talk\Service\LoginGuard;
use Source\Services\Talk\Service\StaffRepository;
use Source\Services\Talk\Service\TeamService;

final class TeamServiceTest extends DbTestCase
{
    private int $supId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supId = $this->db->insert('talk_users', ['name' => 'Djalma', 'email' => 'sup@x.com', 'password_hash' => password_hash('senha123', PASSWORD_DEFAULT)]);
        $this->db->insert('talk_staff', ['user_id' => $this->supId, 'sector_id' => null, 'role' => 'supervisor']);
    }

    private function team(): TeamService
    {
        return new TeamService($this->db, new EventBus($this->db), '10.0.0.1');
    }

    private function sup(): StaffMember
    {
        return (new StaffRepository($this->db))->find($this->supId);
    }

    private function newAgent(string $email = 'ana@x.com', string $role = 'agent', ?array $sectors = null): array
    {
        return $this->team()->saveUser($this->sup(), ['name' => 'Ana  Lima', 'email' => strtoupper($email), 'password' => 'abc12345',
            'role' => $role, 'sector_ids' => $sectors ?? [$this->sectors['Financeiro']]]);
    }

    public function test_cadastra_atendente_com_papel_e_setores(): void
    {
        $a = $this->newAgent('ana@x.com', 'manager', [$this->sectors['Financeiro'], $this->sectors['Manutenção']]);
        $this->assertSame('Ana Lima', $a['name']);
        $this->assertSame('ana@x.com', $a['email']);
        $this->assertSame('manager', $a['role']);
        $staff = (new StaffRepository($this->db))->find($a['id']);
        $this->assertEqualsCanonicalizing([$this->sectors['Financeiro'], $this->sectors['Manutenção']], $staff->managerSectors);
        $this->assertTrue(password_verify('abc12345', $this->db->one('SELECT password_hash FROM talk_users WHERE id = ?', [$a['id']])['password_hash']));
        $log = $this->db->one("SELECT * FROM talk_audit_log WHERE action = 'user.create'");
        $this->assertSame($this->supId, (int) $log['user_id']);
        $this->assertStringNotContainsString('abc12345', (string) $log['details']);
        $this->assertSame('10.0.0.1', $log['ip']);
    }

    public function test_supervisor_fica_sem_setor_e_editar_troca_setores(): void
    {
        $a = $this->newAgent();
        $b = $this->team()->saveUser($this->sup(), ['name' => 'Ana Lima', 'email' => 'ana@x.com', 'role' => 'supervisor', 'sector_ids' => [$this->sectors['Financeiro']]], $a['id']);
        $this->assertSame('supervisor', $b['role']);
        $this->assertSame([], $b['sector_ids']);
        $this->assertTrue((new StaffRepository($this->db))->find($a['id'])->supervisor);
        $c = $this->team()->saveUser($this->sup(), ['name' => 'Ana Lima', 'email' => 'ana@x.com', 'role' => 'agent', 'sector_ids' => [$this->sectors['Administrativo']]], $a['id']);
        $this->assertSame([$this->sectors['Administrativo']], $c['sector_ids']);
        $this->assertFalse((new StaffRepository($this->db))->find($a['id'])->supervisor);
    }

    public function test_validacoes(): void
    {
        $this->newAgent();
        $cases = [
            ['name' => '', 'email' => 'a@x.com', 'password' => 'abc12345', 'sector_ids' => [$this->sectors['Financeiro']]],
            ['name' => 'B', 'email' => 'invalido', 'password' => 'abc12345', 'sector_ids' => [$this->sectors['Financeiro']]],
            ['name' => 'B', 'email' => 'ana@x.com', 'password' => 'abc12345', 'sector_ids' => [$this->sectors['Financeiro']]], // e-mail repetido
            ['name' => 'B', 'email' => 'b@x.com', 'password' => 'curta1', 'sector_ids' => [$this->sectors['Financeiro']]],
            ['name' => 'B', 'email' => 'b@x.com', 'password' => '12345678', /* só números */ 'sector_ids' => [$this->sectors['Financeiro']]],
            ['name' => 'B', 'email' => 'b@x.com', 'password' => 'abc12345', 'sector_ids' => []], // atendente sem setor
            ['name' => 'B', 'email' => 'b@x.com', 'password' => 'abc12345', 'role' => 'dono', 'sector_ids' => [$this->sectors['Financeiro']]],
            ['name' => 'B', 'email' => 'b@x.com', 'password' => 'abc12345', 'sector_ids' => [999]],
        ];
        foreach ($cases as $i => $in) {
            try {
                $this->team()->saveUser($this->sup(), $in);
                $this->fail("caso $i deveria ser recusado");
            } catch (DomainError $e) {
                $this->assertSame(422, $e->getCode(), "caso $i");
            }
        }
    }

    public function test_so_supervisor_cadastra(): void
    {
        $a = $this->newAgent('ger@x.com', 'manager');
        $manager = (new StaffRepository($this->db))->find($a['id']);
        foreach ([fn () => $this->team()->users($manager), fn () => $this->team()->sectors($manager),
            fn () => $this->team()->saveSector($manager, ['name' => 'Jurídico']), fn () => $this->team()->setActive($manager, $this->supId, false)] as $call) {
            try {
                $call();
                $this->fail('deveria negar');
            } catch (DomainError $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
    }

    public function test_sempre_fica_um_supervisor_e_ninguem_se_desativa(): void
    {
        foreach ([fn () => $this->team()->setActive($this->sup(), $this->supId, false),
            fn () => $this->team()->saveUser($this->sup(), ['name' => 'Djalma', 'email' => 'sup@x.com', 'role' => 'agent', 'sector_ids' => [$this->sectors['Financeiro']]], $this->supId)] as $call) {
            try {
                $call();
                $this->fail('deveria recusar');
            } catch (DomainError $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
        // com outro supervisor ativo, dá para rebaixar o outro, mas não o último
        $b = $this->newAgent('b@x.com', 'supervisor');
        $this->team()->setActive($this->sup(), $b['id'], false);
        $this->expectException(DomainError::class);
        $this->team()->saveUser($this->sup(), ['name' => 'Djalma', 'email' => 'sup@x.com', 'role' => 'agent', 'sector_ids' => [$this->sectors['Financeiro']]], $this->supId);
    }

    public function test_desativar_devolve_atendimentos_para_a_fila_e_derruba_sessao(): void
    {
        $a = $this->newAgent();
        $conv = $this->routed('quero a segunda via do boleto');
        $this->svc->accept($conv, (new StaffRepository($this->db))->find($a['id']));
        $this->assertSame('open', $this->conv($conv)['status']);
        $guard = new LoginGuard($this->db);
        $this->assertTrue($guard->sessionValid($a['id'], time()));

        $r = $this->team()->setActive($this->sup(), $a['id'], false);
        $this->assertSame(1, $r['returned_to_queue']);
        $c = $this->conv($conv);
        $this->assertSame('pending', $c['status']);
        $this->assertNull($c['assigned_user_id']);
        $this->assertSame('Atendimento voltou para a fila: o atendente foi desativado.',
            $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'internal' ORDER BY id DESC LIMIT 1", [$conv])['body']);
        $this->assertFalse($guard->sessionValid($a['id'], time()));

        $this->assertTrue($this->team()->setActive($this->sup(), $a['id'], true)['active']);
    }

    public function test_troca_de_senha_derruba_sessoes_antigas(): void
    {
        $a = $this->newAgent();
        $guard = new LoginGuard($this->db);
        $loginAt = time() - 10;
        $this->assertTrue($guard->sessionValid($a['id'], $loginAt));
        try {
            $this->team()->changeOwnPassword($a['id'], 'errada99', 'nova12345');
            $this->fail('senha atual errada');
        } catch (DomainError) {
        }
        $this->team()->changeOwnPassword($a['id'], 'abc12345', 'nova12345');
        $this->assertFalse($guard->sessionValid($a['id'], $loginAt));
        $this->assertTrue($guard->sessionValid($a['id'], time()));
        $this->assertTrue(password_verify('nova12345', $this->db->one('SELECT password_hash FROM talk_users WHERE id = ?', [$a['id']])['password_hash']));

        // supervisor redefine: também derruba; editar sem senha não mexe
        $this->db->run('UPDATE talk_users SET password_changed_at = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$a['id']]);
        $this->team()->saveUser($this->sup(), ['name' => 'Ana', 'email' => 'ana@x.com', 'password' => '', 'sector_ids' => [$this->sectors['Financeiro']]], $a['id']);
        $this->assertTrue($guard->sessionValid($a['id'], time() - 60));
    }

    public function test_limite_de_tentativas_de_login(): void
    {
        $g = new LoginGuard($this->db);
        for ($i = 0; $i < LoginGuard::MAX_PER_EMAIL; $i++) {
            $this->assertFalse($g->blocked('Ana@x.com', '1.1.1.1'));
            $g->failed('ana@x.com', '1.1.1.' . $i);
        }
        $this->assertTrue($g->blocked('ana@x.com', '9.9.9.9'));
        $this->assertFalse($g->blocked('outra@x.com', '9.9.9.9'));
        $g->succeeded('ana@x.com');
        $this->assertFalse($g->blocked('ana@x.com', '9.9.9.9'));
        for ($i = 0; $i < LoginGuard::MAX_PER_IP; $i++) {
            $g->failed("u$i@x.com", '2.2.2.2');
        }
        $this->assertTrue($g->blocked('nova@x.com', '2.2.2.2'));
    }

    public function test_setores_criar_renomear_e_desativar(): void
    {
        $t = $this->team();
        $j = $t->saveSector($this->sup(), ['name' => 'Jurídico', 'color' => '#7b3fa0', 'description' => 'Cobrança judicial']);
        $this->assertFalse($j['jack']);
        $this->assertSame('#7B3FA0', $j['color']);
        $this->assertSame('4', $this->db->one('SELECT menu_key FROM talk_sectors WHERE id = ?', [$j['id']])['menu_key']);
        try {
            $t->saveSector($this->sup(), ['name' => 'Jurídico']);
            $this->fail('nome repetido');
        } catch (DomainError) {
        }
        $this->assertFalse($t->setSectorActive($this->sup(), $j['id'], false)['active']);

        try {
            $t->setSectorActive($this->sup(), $this->sectors['Financeiro'], false);
            $this->fail('setor da Jack não desativa');
        } catch (DomainError $e) {
            $this->assertStringContainsString('Jack', $e->getMessage());
        }
    }

    public function test_renomear_setor_da_jack_nao_quebra_a_triagem(): void
    {
        $this->assertTrue($this->team()->sector($this->sup(), $this->sectors['Financeiro'])['jack']);
        $this->team()->saveSector($this->sup(), ['name' => 'Cobrança e Financeiro'], $this->sectors['Financeiro']);
        $id = $this->routed('quero a segunda via do boleto');
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);
    }

    public function test_setor_com_atendimento_nao_desativa(): void
    {
        $j = $this->team()->saveSector($this->sup(), ['name' => 'Jurídico']);
        $conv = $this->routed('quero a segunda via do boleto');
        $this->db->run('UPDATE talk_conversations SET sector_id = ? WHERE id = ?', [$j['id'], $conv]);
        $this->db->run('UPDATE talk_channels SET sector_id = ? WHERE id = ?', [$j['id'], $this->channelId]);
        try {
            $this->team()->setSectorActive($this->sup(), $j['id'], false);
            $this->fail('tem atendimento');
        } catch (DomainError $e) {
            $this->assertStringContainsString('transfira', $e->getMessage());
        }
        $this->db->run("UPDATE talk_conversations SET status = 'closed' WHERE id = ?", [$conv]);
        $this->team()->setSectorActive($this->sup(), $j['id'], false);
        $this->assertNull($this->db->one('SELECT sector_id FROM talk_channels WHERE id = ?', [$this->channelId])['sector_id']);
    }
}
