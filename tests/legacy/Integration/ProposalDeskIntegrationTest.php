<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Inbox;
use Source\Models\User;
use Source\Services\Proposals\ProposalDesk;
use Source\Services\Proposals\ProposalDeskError;
use Source\Support\Access;
use Source\Support\Proposal\ProposalService;

/** Propostas do site no ERP (CN-011, ADR 0644): lista, número de novas, situação, responsável, auditoria e sino. */
final class ProposalDeskIntegrationTest extends TestCase
{
    private function userWithRole(string $slug, array $permissions): int
    {
        $user = $this->createUser(['first_name' => ucfirst($slug)]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$user, $role]);
        foreach ($permissions as $permission) {
            $this->pdo->prepare('INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, ?)')->execute([$permission, $permission, 'Teste']);
            $id = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote($permission))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $id]);
        }
        Access::clear();
        return $user;
    }

    private function submit(string $condominium): void
    {
        $result = (new ProposalService())->submit(['name' => 'Síndica Fictícia', 'email' => 'sindica@exemplo.test', 'whatsapp' => '(31) 98888-0000',
            'condominio' => $condominium, 'units' => '24', 'profile' => 'sindico', 'privacy' => '1']);
        self::assertTrue($result['success'], $result['message']);
    }

    public function testListCountsUpdateAndAudit(): void
    {
        $seller = $this->userWithRole('comercial_teste', ['proposals.manage', 'erp.access']);
        $other = $this->userWithRole('portaria_teste', ['erp.access']);
        $this->submit('Residencial Alfa');
        $this->submit('Edifício Beta');
        $desk = new ProposalDesk();

        self::assertSame(2, $desk->newCount());
        self::assertSame(['total' => 2, 'new' => 2, 'progress' => 0, 'won' => 0], $desk->stats());
        $list = $desk->list('Beta');
        self::assertSame(1, $list['total']);
        self::assertSame('Edifício Beta', $list['data'][0]['condominium']);
        self::assertSame('Nova', $list['data'][0]['status_label']);
        self::assertSame(0, $desk->list('100%')['total'], 'o % da busca é literal');
        self::assertSame([$seller], array_column($desk->team(), 'id'), 'responsável só com proposals.manage');

        $id = (int)$list['data'][0]['id'];
        try {
            $desk->update($id, 'contacted', $other);
            self::fail('responsável sem a permissão');
        } catch (ProposalDeskError $error) {
            self::assertSame(422, $error->status);
        }
        try {
            $desk->update($id, 'sumiu', null);
            self::fail('situação inválida');
        } catch (ProposalDeskError $error) {
            self::assertArrayHasKey('status', $error->fields);
        }
        $updated = $desk->update($id, 'contacted', $seller);
        self::assertSame(['contacted', $seller], [$updated['status'], $updated['assigned_to']]);
        self::assertNotNull($updated['contacted_at']);
        self::assertSame(1, $desk->newCount(), 'o número do menu cai');
        $first = $updated['contacted_at'];
        self::assertSame($first, $desk->update($id, 'contacted', null)['contacted_at'], 'a data do primeiro contato não muda');
        self::assertGreaterThanOrEqual(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'proposals' AND entity_id = '{$id}'")->fetchColumn());

        $this->expectException(ProposalDeskError::class);
        $desk->update(999999, 'won', null);
    }

    public function testBellOpensTheProposalInTheErpOnlyForTheTeam(): void
    {
        $seller = (new User())->findById($this->userWithRole('comercial_teste', ['proposals.manage', 'erp.access']));
        $other = (new User())->findById($this->userWithRole('portaria_teste', ['erp.access']));
        self::assertStringEndsWith('/comunicacao/propostas/5', (string)Inbox::erpTarget('https://studio.connect.lab/studio/proposals/5', $seller));
        self::assertSame('https://studio.connect.lab/studio/proposals/5', Inbox::erpTarget('https://studio.connect.lab/studio/proposals/5', $other));
    }
}
