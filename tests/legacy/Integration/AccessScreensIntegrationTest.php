<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\AccessScreens;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PartnerAccess;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas de acesso (ADR 0147) contra o banco: imobiliárias com os usuários livres para vincular (sem papel, sem
 * acesso e nunca quem concede), convites que pedem um condomínio, a matriz do portal desligada por padrão e a
 * demonstração sem permissão.
 */
final class AccessScreensIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMigrationPermissions();
        $this->pdo->exec("INSERT IGNORE INTO access_roles (name, slug, level, description) VALUES ('Imobiliária parceira', 'erp_partner', 1, 'teste')");
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $userId = $this->createUser(['level' => 10]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Developer', 'developer', 100]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, (int)$this->pdo->lastInsertId()]);
        (new CondominiumScope($this->pdo))->grant($userId, $this->alpha);
        $this->admin = (new User())->findById($userId);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        CondominiumContext::use([], null);
        $_GET = [];
        parent::tearDown();
    }

    public function testPartnersScreenListsAgenciesAndFreeUsers(): void
    {
        $free = $this->createUser(['level' => 1]);
        $linked = $this->createUser(['level' => 1]);
        $staff = $this->createUser(['level' => 5]);
        $service = new PartnerAccess($this->pdo);
        $agency = $service->createAgency($this->admin, ['name' => 'Imob Sul', 'document' => '11222333000181', 'email' => 'contato@imob.test']);
        $service->linkUser($this->admin, (int)$agency['id'], $linked);
        $service->grant($this->admin, $linked, $this->alpha);

        $data = (new AccessScreens())->data($this->admin, 'AP-148');
        self::assertTrue($data['accessReady']);
        self::assertFalse($data['accessNeedsCondo'], 'Imobiliárias não dependem do condomínio da barra.');
        self::assertSame([$this->alpha => 'Alpha'], $data['scopeCondominiums']);
        self::assertSame([$linked], array_column($data['agencies'][0]['users'], 'user_id'));
        self::assertSame([$this->alpha], $data['agencies'][0]['users'][0]['condominium_ids']);
        $eligible = array_column($data['eligibleUsers'], 'id');
        self::assertContains($free, $eligible);
        self::assertNotContains($linked, $eligible, 'Já vinculado não aparece.');
        self::assertNotContains($staff, $eligible, 'Nível administrativo não vira parceiro.');
        self::assertNotContains((int)$this->admin->id, $eligible, 'Quem concede nunca aparece.');
        self::assertStringNotContainsString('contato@imob.test', json_encode($data['agencies']), 'Contato mascarado.');
    }

    public function testInvitationsAndPortalNeedACondominium(): void
    {
        self::assertTrue((new AccessScreens())->data($this->admin, 'AP-152')['accessNeedsCondo']);
        self::assertTrue((new AccessScreens())->data($this->admin, 'AP-153')['accessNeedsCondo']);
        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_label, relationship, name, email, status) VALUES (?, '101', 'owner', 'Eva', 'eva@x.test', 'active')")->execute([$this->alpha]);
        $invitations = (new AccessScreens())->data($this->admin, 'AP-152');
        self::assertSame([], $invitations['invitations']);
        self::assertSame(['Eva'], array_column($invitations['invitableResidents'], 'name'));
        self::assertTrue($invitations['invitableResidents'][0]['has_email']);
        self::assertStringNotContainsString('eva@x.test', $invitations['invitableResidents'][0]['email']);

        $portal = (new AccessScreens())->data($this->admin, 'AP-153');
        self::assertSame(array_keys(ResidentPortalPermissions::FEATURES), array_column($portal['portalMatrix'], 'feature'));
        self::assertSame([false], array_values(array_unique(array_column($portal['portalMatrix'], 'enabled'))), 'Tudo começa desligado.');
        (new ResidentPortalPermissions($this->pdo))->set($this->admin, $this->alpha, 'boletos', true);
        self::assertTrue((new AccessScreens())->data($this->admin, 'AP-153')['portalMatrix'][0]['enabled']);
    }

    public function testWithoutPermissionKeepsTheDemo(): void
    {
        $plain = (new User())->findById($this->createUser(['level' => 1]));
        foreach (array_keys(AccessScreens::PAGES) as $page) {
            self::assertSame(['accessReady' => false], (new AccessScreens())->data($plain, $page), $page);
        }
    }
}
