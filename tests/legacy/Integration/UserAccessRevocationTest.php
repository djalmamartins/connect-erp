<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UserAccessReport;
use Source\Services\Erp\UserAccessRevocation;

/** ADR 0283: acesso ao condomínio tirado de quem não entra no ERP, com papéis e exceções de verdade; auditado e sem mexer em papel, exceção ou conta. */
final class UserAccessRevocationTest extends TestCase
{
    private int $condominium;
    private int $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $this->other = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2, 'first_name' => 'Fulana', 'last_name' => 'Gestora']);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testRevokesUsersWithoutErpAccess(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $gestor = $this->role("gestor-{$suffix}", 60);
        $leitor = $this->role("leitor-{$suffix}", 15);
        $chefe = $this->role("chefe-{$suffix}", 90);
        $erp = $this->permission(UserAccessReport::ERP_PERMISSION);
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$gestor, $erp]);
        $scope = new CondominiumScope($this->pdo);
        $noRole = $this->createUser(['level' => 1]);
        $reader = $this->createUser(['level' => 1]);
        $allowed = $this->createUser(['level' => 1]);
        $boss = $this->createUser(['level' => 1]);
        foreach ([$noRole, $reader, $allowed, $boss] as $user) {
            $scope->grant($user, $this->condominium);
        }
        $scope->grant($reader, $this->other);
        foreach ([[(int)$this->admin->id, $gestor], [$reader, $leitor], [$allowed, $leitor], [$boss, $chefe]] as [$user, $role]) {
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$user, $role]);
        }
        $this->pdo->prepare("INSERT INTO access_user_overrides (user_id, permission_id, effect) VALUES (?, ?, 'allow')")->execute([$allowed, $erp]);
        $roles = (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_roles')->fetchColumn();
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
        $service = new UserAccessRevocation($this->pdo);
        $reason = ['condominium_id' => $this->condominium, 'reason' => 'Revisão dos acessos'];

        $preview = $service->revoke($this->admin, $reason + ['dry_run' => true]);
        $ids = array_column($preview['users'], 'id');
        sort($ids);
        $expected = [$noRole, $reader];
        sort($expected);
        self::assertSame([$expected, 1, 0], [$ids, $preview['skipped_unmanageable'], $preview['changed']], 'O chefe (papel acima) fica de fora.');
        self::assertTrue($this->hasAccess($noRole, $this->condominium), 'A prévia não tira.');
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'A prévia desfaz a auditoria.');

        foreach ([[$allowed, 'entra no ERP'], [$boss, 'não pode ser alterado'], [(int)$this->admin->id, 'próprio']] as [$user, $message]) {
            try {
                $service->revoke($this->admin, $reason + ['user_ids' => [$noRole, $user]]);
                self::fail("Usuário {$user} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$user, $exception->errors['_user']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertTrue($this->hasAccess($noRole, $this->condominium), 'Tudo ou nada.');
        }

        $result = $service->revoke($this->admin, $reason);
        self::assertSame([2, 2], [$result['count'], $result['changed']]);
        self::assertFalse($this->hasAccess($noRole, $this->condominium));
        self::assertFalse($this->hasAccess($reader, $this->condominium));
        self::assertTrue($this->hasAccess($reader, $this->other), 'O acesso a outro condomínio fica.');
        self::assertTrue($this->hasAccess($allowed, $this->condominium));
        self::assertTrue($this->hasAccess($boss, $this->condominium));
        self::assertSame($roles, (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_roles')->fetchColumn(), 'Papéis intactos.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'user_condominium_access' AND action = 'revoke_no_erp'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'user_condominium_access' AND action = 'delete'")->fetchColumn(),
            'A exclusão auditada pelo desativar da tela de usuários.');
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Fulana', 'Gestora', '@test.local'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, $service->revoke($this->admin, $reason)['count'], 'Rodar de novo não muda nada.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            $service->revoke($limited, $reason);
            self::fail('Pedido fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function hasAccess(int $user, int $condominium): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM user_condominium_access WHERE user_id = ? AND condominium_id = ?');
        $stmt->execute([$user, $condominium]);
        return $stmt->fetchColumn() !== false;
    }

    private function role(string $slug, int $level): int
    {
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute([ucfirst($slug), $slug, $level]);
        return (int)$this->pdo->lastInsertId();
    }

    private function permission(string $slug): int
    {
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'Teste')")->execute([$slug, $slug]);
        $stmt = $this->pdo->prepare('SELECT id FROM access_permissions WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn();
    }
}
