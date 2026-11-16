<?php
declare(strict_types=1);

namespace MovesOSTests\Integration\Talk;

use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Environments;
use Source\Services\Talk\Erp\LocalContactDirectory;
use Source\Services\Talk\Erp\LocalHelpdesk;
use Source\Services\Talk\ErpLogin;
use Source\Services\Talk\Support\Crypto;
use Source\Services\Talk\TalkApp;
use Source\Services\Talk\TalkHelpdesk;
use Source\Support\Access;

/**
 * Talk dentro do ERP (ADR 0322): entrada com o usuário do ERP (talk.access), pessoa pelo telefone direto no cadastro
 * de moradores e chamado direto no Help Desk, sem API nem token.
 */
final class ErpBridgeTest extends DbTestCase
{
    private int $condo;
    private int $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES
            ('Acessar o Connect Talk', 'talk.access', 'Atendimento'), ('Help Desk', 'support.manage', 'Conteúdo')");
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Residencial Jardins', 'active']);
        $this->condo = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, block, number, type) VALUES (?, 'A', '101', 'apartamento')")->execute([$this->condo]);
        $unit = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, phone, status) VALUES (?, ?, 'A - 101', 'owner', 'João Morador', '31999998888', 'active')")
            ->execute([$this->condo, $unit]);
        $this->resident = (int) $this->pdo->lastInsertId();
    }

    private function erpUser(int $level = 5, string $email = ''): User
    {
        $id = $this->createUser(['level' => $level, 'first_name' => 'Ana', 'last_name' => 'Atendente'] + ($email !== '' ? ['email' => $email] : []));
        (new CondominiumScope($this->pdo))->grant($id, $this->condo);
        Access::clear();
        return (new User())->findById($id);
    }

    public function test_usuario_do_erp_com_permissao_ganha_perfil_e_o_primeiro_e_supervisor(): void
    {
        $login = new ErpLogin($this->db);
        $first = $login->profileFor($this->erpUser());
        $this->assertNotNull($first);
        $this->assertSame('Ana Atendente', $first['name']);
        $this->assertTrue($this->staff($first['id'])->supervisor);

        $second = $login->profileFor($this->erpUser());
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertFalse($this->staff($second['id'])->supervisor, 'o segundo entra sem setor, quem escolhe é o supervisor');

        $again = $login->profileFor((new User())->findById((int) $this->db->one('SELECT erp_user_id FROM talk_users WHERE id = ?', [$first['id']])['erp_user_id']));
        $this->assertSame($first['id'], $again['id'], 'mesmo usuário do ERP, mesmo perfil');
    }

    public function test_sem_permissao_ou_desativado_no_talk_nao_entra(): void
    {
        $login = new ErpLogin($this->db);
        $this->assertNull($login->profileFor(null));
        $this->assertNull($login->profileFor($this->erpUser(1)));
        $this->assertStringContainsString('Acessar o Connect Talk', $login->deniedReason($this->erpUser(1)));

        $ana = $this->erpUser();
        $profile = $login->profileFor($ana);
        $this->db->run('UPDATE talk_users SET active = 0 WHERE id = ?', [$profile['id']]);
        $this->assertNull($login->profileFor($ana));
        $this->assertStringContainsString('desativado', $login->deniedReason($ana));
    }

    public function test_equipe_do_talk_separado_e_reaproveitada_pelo_email(): void
    {
        $old = $this->db->insert('talk_users', ['name' => 'Ana (Talk antigo)', 'email' => 'ana@connect.test', 'password_hash' => 'x']);
        $this->db->insert('talk_staff', ['user_id' => $old, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $profile = (new ErpLogin($this->db))->profileFor($this->erpUser(5, 'ana@connect.test'));
        $this->assertSame($old, $profile['id']);
        $this->assertSame([$this->sectors['Financeiro']], $this->staff($old)->agentSectors);
        $this->assertNotNull($this->db->one('SELECT erp_user_id FROM talk_users WHERE id = ?', [$old])['erp_user_id']);
    }

    public function test_identifica_o_morador_e_abre_e_encerra_o_chamado_no_help_desk(): void
    {
        $erp = new TalkHelpdesk();
        $this->svc = $this->makeService(new LocalContactDirectory($erp), new LocalHelpdesk($erp, $this->db, 'https://talk.connect.test'));
        $id = $this->routed('preciso da segunda via do boleto', '5531999998888');

        $contact = $this->db->one('SELECT c.name, c.erp_person_id FROM talk_contacts c JOIN talk_conversations v ON v.contact_id = c.id WHERE v.id = ?', [$id]);
        $this->assertSame('João Morador', $contact['name']);
        $this->assertSame($this->resident, (int) $contact['erp_person_id']);

        $conv = $this->conv($id);
        $this->assertNotNull($conv['helpdesk_ticket_id'], 'a conversa já cai no Help Desk');
        $ticket = $this->pdo->query('SELECT * FROM studio_support_tickets WHERE id = ' . (int) $conv['helpdesk_ticket_id'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('talk', $ticket['origin']);
        $this->assertSame('financial', $ticket['area']);
        $this->assertSame($this->condo, (int) $ticket['condominium_id']);
        $this->assertSame($conv['protocol'], $ticket['external_ref']);
        $this->assertSame($ticket['protocol'], $conv['helpdesk_protocol']);

        $ana = (new ErpLogin($this->db))->profileFor($this->erpUser());
        $this->db->insert('talk_staff', ['user_id' => $ana['id'], 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $this->svc->close($id, $this->staff($ana['id']), 'Segunda via enviada.');
        $status = $this->pdo->query('SELECT status FROM studio_support_tickets WHERE id = ' . (int) $conv['helpdesk_ticket_id'])->fetchColumn();
        $this->assertSame('resolved', $status);
    }

    public function test_telefone_desconhecido_segue_sem_identificar(): void
    {
        $this->assertNull((new LocalContactDirectory(new TalkHelpdesk()))->findByPhone(['5511912345678', '11912345678']));
    }

    public function test_configuracao_e_ambiente(): void
    {
        putenv('TALK_JACK_MODE=off');
        try {
            $this->assertSame('off', TalkApp::env('JACK_MODE'));
            $this->assertSame('x', TalkApp::env('URL_QUE_NAO_EXISTE', 'x'));
        } finally {
            putenv('TALK_JACK_MODE');
        }
        $crypto = Crypto::unconfigured();
        $this->assertFalse($crypto->configured());
        try {
            $crypto->encrypt(['a' => 1]);
            $this->fail('sem TALK_KEY não cifra');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('TALK_KEY', $e->getMessage());
        }
        $this->assertTrue(talk_host('talk.connect.com.br'));
        $this->assertFalse(talk_host('erp.connect.com.br'));
        $this->assertSame('talk.connect.com.br', Environments::target('talk', 'erp.connect.com.br')['host']);
    }

    private function staff(int $talkUserId): \Source\Services\Talk\Domain\StaffMember
    {
        return (new \Source\Services\Talk\Service\StaffRepository($this->db))->find($talkUserId);
    }
}
