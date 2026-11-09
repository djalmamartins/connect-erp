<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\PartnerAccess;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\ResidentInvitations;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Support\Access;

/** Acesso de imobiliárias e moradores (ADR 0090): papel fixo, escopo, fail-closed, convite de uso único e matriz desligada. */
final class AccessAdminIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['erp_resident_portal_permissions', 'erp_resident_invitations', 'erp_partner_agency_users', 'erp_partner_agencies'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");   // a lista do TestCase ganha estas tabelas no alinhamento
        }
        // O TestCase limpa access_roles: o papel da migration volta aqui, com as duas permissões.
        $this->pdo->exec("INSERT IGNORE INTO access_roles (name, slug, level, description) VALUES ('Imobiliária parceira', 'erp_partner', 1, 'teste')");
        $this->pdo->exec("INSERT IGNORE INTO access_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM access_roles r JOIN access_permissions p
            WHERE r.slug = 'erp_partner' AND p.slug IN ('erp.access', 'erp.partner.view')");
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        $this->admin = $this->user(2, [$this->alpha]);
    }

    public function testPartnerLifecycleIsConservative(): void
    {
        $service = new PartnerAccess($this->pdo);
        $agency = $service->createAgency($this->admin, ['name' => 'Imobiliária Centro', 'document' => '11.222.333/0001-81', 'email' => 'contato@imob.test']);
        self::assertSame('************81', $agency['document']);

        $partner = $this->user(1, []);
        $service->linkUser($this->admin, $agency['id'], (int)$partner->id);
        $service->grant($this->admin, (int)$partner->id, $this->alpha);
        $this->expectFailure(fn () => $service->grant($this->admin, (int)$partner->id, $this->beta), CondominiumAccessDenied::class, 'condomínio fora do escopo de quem concede');

        $staff = $this->user(2, []);
        $this->expectFailure(fn () => $service->linkUser($this->admin, $agency['id'], (int)$staff->id), DomainException::class, 'usuário com nível administrativo');
        $withRole = $this->user(1, []);
        $this->pdo->prepare("INSERT INTO access_roles (name, slug, level) VALUES ('Gerente', 'manager', 50)")->execute();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([(int)$withRole->id, (int)$this->pdo->lastInsertId()]);
        $this->expectFailure(fn () => $service->linkUser($this->admin, $agency['id'], (int)$withRole->id), DomainException::class, 'usuário que já tem papel');
        $this->expectFailure(fn () => $service->grant($this->admin, (int)$withRole->id, $this->alpha), DomainException::class, 'conceder condomínio a quem não é parceiro');

        // O parceiro vê unidades e moradores com contato mascarado, e só isso.
        $registry = new RegistryService($this->pdo);
        $unit = $registry->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $registry->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'relationship' => 'owner', 'name' => 'Bia', 'email' => 'bia@morador.test', 'phone' => '11987654321']);
        $view = $service->view($partner, $this->alpha);
        self::assertSame(['b**@morador.test', '*******4321'], [$view['residents'][0]['email'], $view['residents'][0]['phone']]);
        $this->resetAccessCache();
        self::assertTrue(Access::can('erp.partner.view', $partner));
        foreach (['erp.entries.view', 'erp.agreements.view', 'erp.documents.view', 'erp.residents.view', 'erp.access_admin.manage'] as $denied) {
            self::assertFalse(Access::can($denied, $partner), "O parceiro não tem {$denied}.");
        }
        $this->expectFailure(fn () => $service->view($partner, $this->beta), CondominiumAccessDenied::class, 'consulta de condomínio não concedido');

        $service->unlinkUser($this->admin, (int)$partner->id);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_roles WHERE user_id = ' . (int)$partner->id)->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM user_condominium_access WHERE user_id = ' . (int)$partner->id)->fetchColumn());
        $this->resetAccessCache();
        self::assertFalse(Access::can('erp.partner.view', $partner), 'Sem papel, sem acesso (fail-closed).');
        $this->expectFailure(fn () => $service->view($partner, $this->alpha), RegistryNotFound::class, 'consulta depois de desvincular');
        self::assertGreaterThanOrEqual(4, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity IN ('erp_partner_agencies', 'erp_partner_agency_users', 'user_condominium_access')")->fetchColumn());
    }

    public function testInvitationTokenIsSingleUse(): void
    {
        $registry = new RegistryService($this->pdo);
        $unit = $registry->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '102', 'type' => 'apartamento'])['id'];
        $resident = (int)$registry->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'relationship' => 'owner', 'name' => 'Caio', 'document' => self::validCpf(), 'email' => 'caio@morador.test'])['id'];
        $service = new ResidentInvitations($this->pdo);
        if (!in_array(ResidentInvitations::EVENT, NotificationOutbox::EVENTS, true)) {
            $this->expectFailure(fn () => $service->invite($this->admin, $this->alpha, [$resident]), DomainException::class, 'convite antes do evento existir na outbox');
            $this->pdo->prepare('INSERT INTO erp_resident_invitations (condominium_id, resident_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))')
                ->execute([$this->alpha, $resident, hash('sha256', ResidentInvitations::newToken())]);
        } else {
            self::assertSame(1, $service->invite($this->admin, $this->alpha, [$resident])['invited']);
            $payload = (string)$this->pdo->query("SELECT payload_json FROM erp_notification_outbox WHERE event_type = 'resident.invitation' ORDER BY id DESC LIMIT 1")->fetchColumn();
            self::assertStringNotContainsString('token', $payload, 'O token não passa pela outbox.');
        }
        $id = (int)$this->pdo->query('SELECT id FROM erp_resident_invitations ORDER BY id DESC LIMIT 1')->fetchColumn();
        $token = $service->issueToken($id);
        self::assertNotNull($token);
        self::assertStringNotContainsString((string)$token, (string)$this->pdo->query('SELECT token_hash FROM erp_resident_invitations WHERE id = ' . $id)->fetchColumn(), 'Só o hash fica no banco.');
        self::assertNull($service->lookup($token, new \DateTimeImmutable('+8 days')), 'Vencido não vale.');
        self::assertSame($resident, $service->lookup($token)['resident_id']);
        $user = $this->createUser(['level' => 1]);
        self::assertTrue($service->accept($token, $user));
        self::assertFalse($service->accept($token, $user), 'Uso único.');
        self::assertNull($service->issueToken($id), 'Aceito não gera token novo.');
        self::assertFalse($service->accept(ResidentInvitations::newToken(), $user), 'Token inventado não vale.');
    }

    public function testPortalMatrixStartsOffAndIsAudited(): void
    {
        $service = new ResidentPortalPermissions($this->pdo);
        self::assertSame([false, false, false, false, false], array_column($service->matrix($this->admin, $this->alpha), 'enabled'));
        $service->set($this->admin, $this->alpha, 'reservations', true);
        self::assertTrue(ResidentPortalPermissions::enabled($this->pdo, $this->alpha, 'reservations'));
        self::assertFalse(ResidentPortalPermissions::enabled($this->pdo, $this->beta, 'reservations'), 'Cada condomínio tem a sua matriz.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_resident_portal_permissions'")->fetchColumn());
        $this->expectFailure(fn () => $service->set($this->admin, $this->alpha, 'financeiro', true), InvalidArgumentException::class, 'função desconhecida');
        $this->expectFailure(fn () => $service->set($this->admin, $this->beta, 'boletos', true), CondominiumAccessDenied::class, 'condomínio fora do escopo');
    }

    /** Access guarda a decisão por usuário e permissão na requisição; o teste muda o papel no meio. */
    private function resetAccessCache(): void
    {
        (new \ReflectionProperty(Access::class, 'cache'))->setValue(null, []);
    }

    /** @param class-string<\Throwable> $class */
    private function expectFailure(callable $action, string $class, string $what): void
    {
        try {
            $action();
            self::fail("Aceitou {$what}.");
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error, $what);
        }
    }

    /** @param list<int> $condominiums */
    private function user(int $level, array $condominiums): User
    {
        $id = $this->createUser(['level' => $level]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        return (new User())->findById($id);
    }
}
