<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ErpAccess;
use Source\Services\Erp\UserAccessReport;

/** ADR 0282: acessos dos usuários ao ERP com papéis, permissões e exceções de verdade; somente leitura e sem nome, e-mail nem documento. */
final class UserAccessReportTest extends TestCase
{
    private int $condominium;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2, 'first_name' => 'Fulana', 'last_name' => 'Gestora']);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testAccessFromRealRolesAndOverrides(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $gestor = $this->role("gestor-{$suffix}", 60);
        $leitor = $this->role("leitor-{$suffix}", 15);
        $erp = $this->permission(UserAccessReport::ERP_PERMISSION);
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$gestor, $erp]);
        $scope = new CondominiumScope($this->pdo);
        $noRole = $this->createUser(['level' => 1, 'status' => 'registered']);
        $reader = $this->createUser(['level' => 1]);
        $blocked = $this->createUser(['level' => 1]);
        foreach ([$noRole, $reader, $blocked] as $user) {
            $scope->grant($user, $this->condominium);
        }
        foreach ([[(int)$this->admin->id, $gestor], [$reader, $leitor], [$blocked, $gestor]] as [$user, $role]) {
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$user, $role]);
        }
        foreach ([[$reader, 'allow'], [$blocked, 'deny']] as [$user, $effect]) {
            $this->pdo->prepare('INSERT INTO access_user_overrides (user_id, permission_id, effect) VALUES (?, ?, ?)')->execute([$user, $erp, $effect]);
        }
        $outside = $this->createUser(['level' => 1]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$outside, $gestor]);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new UserAccessReport($this->pdo))->build($this->admin, $this->condominium);
        $rows = array_combine(array_column($report['rows'], 'role'), $report['rows']);
        self::assertSame([2, 1], [$rows["gestor-{$suffix}"]['users'], $rows["leitor-{$suffix}"]['users']], 'O usuário de fora do condomínio não conta.');
        self::assertSame(['users' => 4, 'with_role' => 3, 'without_role' => 1], $report['totals']);
        self::assertSame(['with' => 2, 'without' => 2], $report['erp_access'], 'O leitor entra pelo allow; o bloqueado sai pelo deny; sem papel não entra.');
        $without = $report['without_erp_access_ids'];
        sort($without);
        $expected = [$noRole, $blocked];
        sort($expected);
        self::assertSame($expected, $without);
        self::assertSame([$noRole], $report['without_role_ids']);
        self::assertSame(ErpAccess::EFFECTS, array_column($report['overrides']['by_effect'], 'effect'));
        self::assertSame(2, $report['overrides']['users']);
        self::assertSame(4, array_sum(array_column($report['by_status'], 'users')));
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulana', 'Gestora', '@test.local', (string)$this->admin->document] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new UserAccessReport($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
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
