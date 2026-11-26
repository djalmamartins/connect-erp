<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Support\Access;
use Source\Support\Proposal\ProposalService;

/**
 * Proposta do site grava e avisa no sininho do Studio quem pode abrir Gestão > Propostas (ADR 0639).
 */
final class ProposalNotificationIntegrationTest extends TestCase
{
    private function userWithRole(string $slug, bool $canProposals): int
    {
        $user = $this->createUser(['first_name' => ucfirst($slug)]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$user, $role]);
        if ($canProposals) {
            $this->pdo->exec("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Gerenciar propostas', 'proposals.manage', 'Comercial')");
            $permission = (int)$this->pdo->query("SELECT id FROM access_permissions WHERE slug = 'proposals.manage'")->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        Access::clear();
        return $user;
    }

    public function testSiteProposalIsSavedAndNotifiesOnlyTheProposalTeam(): void
    {
        $seller = $this->userWithRole('comercial_teste', true);
        $other = $this->userWithRole('portaria_teste', false);
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM notifications_categories WHERE uri = 'studio'")->fetchColumn());

        $result = (new ProposalService())->submit([
            'name' => 'Síndica Fictícia', 'email' => 'sindica@exemplo.test', 'whatsapp' => '(31) 98888-0000',
            'condominio' => 'Residencial Exemplo', 'units' => '24', 'profile' => 'sindico', 'message' => 'Quero conhecer.',
            'privacy' => '1',
        ]);
        self::assertTrue($result['success'], $result['message']);

        $proposal = $this->pdo->query('SELECT id, status, consent_at FROM proposals')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('new', $proposal['status']);
        self::assertNotNull($proposal['consent_at']);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM notifications_categories WHERE uri = 'studio'")->fetchColumn(), 'categoria criada quando falta');

        $rows = $this->pdo->query('SELECT users_id, title, link, view FROM notifications')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'só quem pode abrir Propostas é avisado');
        self::assertSame($seller, (int)$rows[0]['users_id']);
        self::assertNotSame($other, (int)$rows[0]['users_id']);
        self::assertSame('Nova proposta: Residencial Exemplo', $rows[0]['title']);
        self::assertStringEndsWith('/comunicacao/propostas/' . $proposal['id'], (string)$rows[0]['link'], 'ADR 0650: o aviso abre a proposta no ERP.');
        self::assertSame('0', (string)$rows[0]['view'], 'chega como não lida e conta no sininho');

        (new ProposalService())->submit([
            'name' => 'Outro Síndico', 'email' => 'outro@exemplo.test', 'whatsapp' => '(31) 97777-0000',
            'condominio' => 'Edifício Teste', 'units' => '8', 'profile' => 'sindico', 'privacy' => '1',
        ]);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM notifications_categories WHERE uri = 'studio'")->fetchColumn(), 'não duplica a categoria');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE users_id = {$seller} AND view = 0")->fetchColumn());
    }

    public function testRefusedProposalNotifiesNobody(): void
    {
        $this->userWithRole('comercial_teste', true);
        $result = (new ProposalService())->submit(['name' => 'Sem Aceite', 'email' => 'x@exemplo.test', 'whatsapp' => '(31) 90000-0000', 'condominio' => 'Teste', 'units' => '3', 'profile' => 'sindico']);
        self::assertFalse($result['success']);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }
}
