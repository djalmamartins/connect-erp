<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Usuários do Sistema (AP-149) e Permissões de Usuários (AP-150), ADR 0168. Reaproveita o controle de acesso
 * que já existe (access_roles, access_permissions, access_role_permissions, access_user_overrides e
 * user_condominium_access) e decide de forma fail-closed:
 *
 * - O ERP não cria conta, não troca papel, não mexe em senha, login nem 2FA: isso continua no Studio
 *   (Usuários), porque os papéis também carregam permissões do Studio. "Convidar" aqui é dar acesso a
 *   condomínios do escopo para uma conta que já existe e cujo papel já tem erp.access. "Desativar" é tirar o
 *   acesso a condomínios do escopo.
 * - Só se mexe em quem tem papel de nível menor que o de quem administra (o desenvolvedor mexe em todos) e
 *   nunca em si mesmo.
 * - Só se editam permissões erp.*, e nunca erp.condominiums.all (só o desenvolvedor). Conceder exige que quem
 *   concede tenha a permissão. Mudar o papel inteiro exige acesso a todos os condomínios.
 * - Tudo é auditado e limpa o cache de permissões.
 */
final class ErpAccess
{
    public const MAX_ROWS = 2000;
    public const PROTECTED_ROLES = ['developer', 'super_admin'];
    public const EFFECTS = ['allow', 'deny', 'inherit'];

    private PDO $pdo;
    private CondominiumScope $scope;
    /** @var \Closure(string, User): bool */
    private \Closure $can;

    /** @param (callable(string, User): bool)|null $can substitui Access::can (testes) */
    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?callable $can = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->can = $can !== null ? \Closure::fromCallable($can) : static fn (string $permission, User $user): bool => Access::can($permission, $user);
    }

    /**
     * Quem administra pode mexer no alvo? (regra pura) Desenvolvedor mexe em todos menos em si mesmo; os demais
     * só em papel de nível menor e nunca em papel protegido.
     *
     * @param array{slug:?string, level:int} $actor
     * @param array{slug:?string, level:int} $target
     */
    public static function canManage(array $actor, array $target, bool $self): bool
    {
        if ($self || $actor['slug'] === null) {
            return false;
        }
        if ($actor['slug'] === 'developer') {
            return true;
        }
        return !in_array($target['slug'], self::PROTECTED_ROLES, true) && $target['level'] < $actor['level'];
    }

    /**
     * Permissão efetiva (regra pura), como em Access::can: sem papel nega; override deny vence; override allow
     * libera; senão vale o papel.
     */
    public static function effective(bool $hasRole, bool $roleAllowed, ?string $override): bool
    {
        if (!$hasRole) {
            return false;
        }
        return $override === 'deny' ? false : ($override === 'allow' ? true : $roleAllowed);
    }

    /** AP-149: usuários com acesso a condomínios do escopo (ou a um deles), com e-mail mascarado. */
    public function users(User $admin, ?int $condominiumId): array
    {
        $ids = $this->ids($admin, $condominiumId);
        $base = ['condominium_id' => $condominiumId];
        if ($ids === []) {
            return $base + ['truncated' => false, 'rows' => []];
        }
        $in = implode(',', $ids);
        $actor = $this->roleOf((int)$admin->id);
        $stmt = $this->pdo->query("SELECT a.user_id, a.condominium_id, c.name AS condominium_name, u.first_name, u.last_name, u.email, u.status,
                r.slug AS role_slug, r.name AS role_name, r.level AS role_level
            FROM user_condominium_access a JOIN users u ON u.id = a.user_id JOIN condominiums c ON c.id = a.condominium_id
            LEFT JOIN access_user_roles ur ON ur.user_id = a.user_id LEFT JOIN access_roles r ON r.id = ur.role_id
            WHERE a.condominium_id IN ({$in}) ORDER BY u.first_name, u.last_name, a.user_id, c.name LIMIT " . (self::MAX_ROWS + 1));
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($raw) > self::MAX_ROWS;
        $users = [];
        foreach (array_slice($raw, 0, self::MAX_ROWS) as $r) {
            $id = (int)$r['user_id'];
            $target = ['slug' => $r['role_slug'], 'level' => (int)($r['role_level'] ?? 0)];
            $users[$id] ??= ['id' => $id, 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'email' => RegistryReports::maskEmail($r['email']),
                'status' => (string)$r['status'], 'role' => $r['role_slug'], 'role_name' => $r['role_name'],
                'manageable' => self::canManage($actor, $target, $id === (int)$admin->id), 'condominiums' => []];
            $users[$id]['condominiums'][] = ['id' => (int)$r['condominium_id'], 'name' => (string)$r['condominium_name']];
        }
        return $base + ['truncated' => $truncated, 'rows' => array_values($users)];
    }

    /**
     * AP-149, "convidar": dá acesso aos condomínios escolhidos (todos do escopo) a uma conta que já existe e cujo
     * papel já tem erp.access. Sem conta, 422 (a conta é criada no Studio). Papel acima, protegido ou si mesmo, 403.
     *
     * @param list<mixed> $condominiumIds
     */
    public function invite(User $admin, mixed $email, array $condominiumIds): array
    {
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }
        $ids = array_values(array_unique(array_filter(array_map(static fn (mixed $v): int => (int)filter_var($v, FILTER_VALIDATE_INT), $condominiumIds), static fn (int $v): bool => $v > 0)));
        if ($ids === [] || count($ids) > 200) {
            throw new InvalidArgumentException('Escolha de 1 a 200 condomínios.');
        }
        foreach ($ids as $id) {
            $this->scope->assertAccess($admin, $id);   // 404 antes de qualquer gravação
        }
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            throw new InvalidArgumentException('Não há conta com este e-mail. A conta é criada no Studio (Usuários), com o papel adequado.');
        }
        $userId = (int)$userId;
        $this->assertManageable($admin, $userId, true);
        if (!$this->roleHas($userId, 'erp.access')) {
            throw new InvalidArgumentException('O papel desta conta não tem acesso ao ERP. Ajuste o papel no Studio (Usuários).');
        }
        $granted = [];
        $exists = $this->pdo->prepare('SELECT 1 FROM user_condominium_access WHERE user_id = :u AND condominium_id = :c');
        $insert = $this->pdo->prepare('INSERT INTO user_condominium_access (user_id, condominium_id, granted_by) VALUES (:u, :c, :by)');
        foreach ($ids as $id) {
            $exists->execute(['u' => $userId, 'c' => $id]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            if (!$this->insertOnce($insert, ['u' => $userId, 'c' => $id, 'by' => (int)$admin->id])) {
                continue;   // outro pedido deu o mesmo acesso ao mesmo tempo
            }
            $granted[] = $id;
            Audit::record('create', 'user_condominium_access', $userId, null, ['condominium_id' => $id, 'origin' => 'erp_users']);
        }
        Access::clear($userId);
        return ['user_id' => $userId, 'granted' => $granted, 'already' => array_values(array_diff($ids, $granted))];
    }

    /** AP-149, "desativar": tira o acesso a um condomínio ou a todos do escopo de quem administra. */
    public function deactivate(User $admin, int $userId, ?int $condominiumId = null): array
    {
        $ids = $this->ids($admin, $condominiumId);
        $this->assertManageable($admin, $userId);
        $revoked = [];
        if ($ids !== []) {
            $in = implode(',', $ids);
            $stmt = $this->pdo->prepare("SELECT condominium_id FROM user_condominium_access WHERE user_id = :u AND condominium_id IN ({$in}) ORDER BY condominium_id");
            $stmt->execute(['u' => $userId]);
            $revoked = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $this->pdo->prepare("DELETE FROM user_condominium_access WHERE user_id = :u AND condominium_id IN ({$in})")->execute(['u' => $userId]);
            foreach ($revoked as $id) {
                Audit::record('delete', 'user_condominium_access', $userId, ['condominium_id' => $id, 'origin' => 'erp_users'], null);
            }
        }
        if ($revoked === []) {
            throw new DomainException('Este usuário não tem acesso a condomínios do seu escopo.');
        }
        Access::clear($userId);
        return ['user_id' => $userId, 'revoked' => $revoked];
    }

    /** AP-150: papéis visíveis e as permissões erp.* com o que cada papel tem. */
    public function matrix(User $admin): array
    {
        $actor = $this->roleOf((int)$admin->id);
        $global = $this->scope->hasGlobalAccess($admin);
        $roles = $this->pdo->query('SELECT id, slug, name, level FROM access_roles ORDER BY level DESC, slug')->fetchAll(PDO::FETCH_ASSOC);
        $roles = array_values(array_filter($roles, static fn (array $r): bool => $actor['slug'] === 'developer' || ($r['slug'] !== 'developer' && (int)$r['level'] <= $actor['level'])));
        $permissions = $this->permissions();
        $grants = [];
        foreach ($this->pdo->query("SELECT r.slug AS role, p.slug AS permission FROM access_role_permissions rp JOIN access_roles r ON r.id = rp.role_id
            JOIN access_permissions p ON p.id = rp.permission_id WHERE p.slug LIKE 'erp.%'")->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $grants[$g['role']][$g['permission']] = true;
        }
        return [
            'editable_roles' => $global,
            'roles' => array_map(fn (array $r): array => ['slug' => (string)$r['slug'], 'name' => (string)$r['name'], 'level' => (int)$r['level'],
                'editable' => $global && self::canManage($actor, ['slug' => $r['slug'], 'level' => (int)$r['level']], false),
                'permissions' => array_keys($grants[$r['slug']] ?? [])], $roles),
            'permissions' => $permissions,
        ];
    }

    /** AP-150: liga ou desliga uma permissão erp.* de um papel (exige acesso a todos os condomínios). */
    public function setRolePermission(User $admin, mixed $roleSlug, mixed $permission, bool $allowed): array
    {
        if (!$this->scope->hasGlobalAccess($admin)) {
            throw new RegistryForbidden();   // o papel vale para todos os condomínios
        }
        $permissionId = $this->permissionId($admin, $permission, $allowed);
        $stmt = $this->pdo->prepare('SELECT id, slug, level FROM access_roles WHERE slug = :s');
        $stmt->execute(['s' => is_string($roleSlug) ? $roleSlug : '']);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($role === false) {
            throw new InvalidArgumentException('Papel inválido.');
        }
        if (!self::canManage($this->roleOf((int)$admin->id), ['slug' => $role['slug'], 'level' => (int)$role['level']], false)) {
            throw new RegistryForbidden();
        }
        $had = $this->pdo->prepare('SELECT 1 FROM access_role_permissions WHERE role_id = :r AND permission_id = :p');
        $had->execute(['r' => (int)$role['id'], 'p' => $permissionId]);
        $before = $had->fetchColumn() !== false;
        if ($before !== $allowed) {
            $params = ['r' => (int)$role['id'], 'p' => $permissionId];
            if ($allowed) {
                $this->insertOnce($this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (:r, :p)'), $params);
            } else {
                $this->pdo->prepare('DELETE FROM access_role_permissions WHERE role_id = :r AND permission_id = :p')->execute($params);
            }
            Audit::record('update', 'access_role_permissions', (int)$role['id'], ['role' => $role['slug'], 'permission' => $permission, 'allowed' => $before ? '1' : '0'],
                ['role' => $role['slug'], 'permission' => $permission, 'allowed' => $allowed ? '1' : '0']);
            Access::clear();
        }
        return ['role' => (string)$role['slug'], 'permission' => (string)$permission, 'allowed' => $allowed, 'changed' => $before !== $allowed];
    }

    /** AP-150: permissões erp.* de um usuário do escopo — do papel, a exceção e o efetivo. */
    public function userPermissions(User $admin, int $userId): array
    {
        $this->assertInScope($admin, $userId);
        $role = $this->roleOf($userId);
        $roleGrants = [];
        $stmt = $this->pdo->prepare("SELECT p.slug FROM access_user_roles ur JOIN access_role_permissions rp ON rp.role_id = ur.role_id
            JOIN access_permissions p ON p.id = rp.permission_id WHERE ur.user_id = :u AND p.slug LIKE 'erp.%'");
        $stmt->execute(['u' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $roleGrants[(string)$slug] = true;
        }
        $overrides = [];
        $stmt = $this->pdo->prepare("SELECT p.slug, o.effect FROM access_user_overrides o JOIN access_permissions p ON p.id = o.permission_id
            WHERE o.user_id = :u AND p.slug LIKE 'erp.%'");
        $stmt->execute(['u' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $overrides[(string)$o['slug']] = (string)$o['effect'];
        }
        $rows = array_map(static fn (array $p): array => $p + ['role' => isset($roleGrants[$p['slug']]), 'override' => $overrides[$p['slug']] ?? null,
            'effective' => self::effective($role['slug'] !== null, isset($roleGrants[$p['slug']]), $overrides[$p['slug']] ?? null)], $this->permissions());
        return ['user_id' => $userId, 'role' => $role['slug'], 'manageable' => self::canManage($this->roleOf((int)$admin->id), $role, $userId === (int)$admin->id),
            'rows' => $rows];
    }

    /** AP-150: exceção de um usuário do escopo numa permissão erp.* (allow, deny ou inherit = tira a exceção). */
    public function setUserPermission(User $admin, int $userId, mixed $permission, mixed $effect): array
    {
        if (!in_array($effect, self::EFFECTS, true)) {
            throw new InvalidArgumentException('Efeito inválido: use allow, deny ou inherit.');
        }
        $this->assertManageable($admin, $userId);
        $permissionId = $this->permissionId($admin, $permission, $effect === 'allow');
        $old = $this->pdo->prepare('SELECT effect FROM access_user_overrides WHERE user_id = :u AND permission_id = :p');
        $old->execute(['u' => $userId, 'p' => $permissionId]);
        $before = $old->fetchColumn();
        $before = $before === false ? 'inherit' : (string)$before;
        if ($before !== $effect) {
            // Apagar e gravar juntos (ADR 0172): uma falha no meio não deixa o usuário sem a exceção anterior.
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare('DELETE FROM access_user_overrides WHERE user_id = :u AND permission_id = :p')->execute(['u' => $userId, 'p' => $permissionId]);
                if ($effect !== 'inherit') {
                    $this->insertOnce($this->pdo->prepare('INSERT INTO access_user_overrides (user_id, permission_id, effect, assigned_by) VALUES (:u, :p, :e, :by)'),
                        ['u' => $userId, 'p' => $permissionId, 'e' => $effect, 'by' => (int)$admin->id]);
                }
                $this->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
            Audit::record('update', 'access_user_overrides', $userId, ['permission' => $permission, 'effect' => $before], ['permission' => $permission, 'effect' => $effect]);
            Access::clear($userId);
        }
        return ['user_id' => $userId, 'permission' => (string)$permission, 'effect' => $effect, 'changed' => $before !== $effect];
    }

    /**
     * INSERT que aceita a chave repetida de um pedido simultâneo (MySQL 1062, SQLite 19) como "já existe", em vez de
     * responder 500 (ADR 0172). Devolve false quando a linha já estava lá.
     */
    private function insertOnce(\PDOStatement $insert, array $params): bool
    {
        try {
            $insert->execute($params);
            return true;
        } catch (\PDOException $error) {
            if (!in_array($error->errorInfo[1] ?? null, [1062, 19], true)) {
                throw $error;
            }
            return false;
        }
    }

    /** @return array{slug:?string, level:int} */
    private function roleOf(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT r.slug, r.level FROM access_user_roles ur JOIN access_roles r ON r.id = ur.role_id WHERE ur.user_id = :u');
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? ['slug' => null, 'level' => 0] : ['slug' => (string)$row['slug'], 'level' => (int)$row['level']];
    }

    private function roleHas(int $userId, string $permission): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM access_user_roles ur JOIN access_role_permissions rp ON rp.role_id = ur.role_id
            JOIN access_permissions p ON p.id = rp.permission_id WHERE ur.user_id = :u AND p.slug = :p');
        $stmt->execute(['u' => $userId, 'p' => $permission]);
        return $stmt->fetchColumn() !== false;
    }

    /** O alvo precisa ter acesso a um condomínio do escopo (ou quem administra acessa todos). Fora = 404. */
    private function assertInScope(User $admin, int $userId): void
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :u');
        $exists->execute(['u' => $userId]);
        if ($exists->fetchColumn() === false) {
            throw new CondominiumAccessDenied(0);
        }
        if ($this->scope->hasGlobalAccess($admin)) {
            return;
        }
        $ids = $this->scope->allowedIds($admin);
        if ($ids !== []) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM user_condominium_access WHERE user_id = :u AND condominium_id IN (' . implode(',', array_map('intval', $ids)) . ')');
            $stmt->execute(['u' => $userId]);
            if ($stmt->fetchColumn() !== false) {
                return;
            }
        }
        throw new CondominiumAccessDenied(0);
    }

    private function assertManageable(User $admin, int $userId, bool $invite = false): void
    {
        $invite ? $this->assertInScopeOrNew($admin, $userId) : $this->assertInScope($admin, $userId);
        if (!self::canManage($this->roleOf((int)$admin->id), $this->roleOf($userId), $userId === (int)$admin->id)) {
            throw new RegistryForbidden();
        }
    }

    /** No convite o alvo ainda pode não ter condomínio nenhum: basta existir. Nos demais casos, precisa estar no escopo. */
    private function assertInScopeOrNew(User $admin, int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_condominium_access WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $exists = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :u');
            $exists->execute(['u' => $userId]);
            if ($exists->fetchColumn() === false) {
                throw new CondominiumAccessDenied(0);
            }
            return;
        }
        $this->assertInScope($admin, $userId);
    }

    /** Permissão erp.* cadastrada, delegável e, para conceder, que quem concede tenha. */
    private function permissionId(User $admin, mixed $permission, bool $granting): int
    {
        if (!is_string($permission) || preg_match('/^erp\.[a-z0-9_.]{1,90}$/', $permission) !== 1) {
            throw new InvalidArgumentException('Escolha uma permissão do ERP (erp.*).');
        }
        $stmt = $this->pdo->prepare('SELECT id FROM access_permissions WHERE slug = :s');
        $stmt->execute(['s' => $permission]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException('Permissão não cadastrada.');
        }
        $actor = $this->roleOf((int)$admin->id);
        if ($permission === CondominiumScope::ALL_PERMISSION && $actor['slug'] !== 'developer') {
            throw new RegistryForbidden();   // muda o escopo de condomínios: só o desenvolvedor
        }
        if ($granting && $actor['slug'] !== 'developer' && !($this->can)($permission, $admin)) {
            throw new RegistryForbidden();   // não se concede o que não se tem
        }
        return (int)$id;
    }

    /** @return list<array{slug:string, name:string, group:string, description:?string}> */
    private function permissions(): array
    {
        return array_map(static fn (array $p): array => ['slug' => (string)$p['slug'], 'name' => (string)$p['name'], 'group' => (string)$p['group_name'],
            'description' => $p['description']], $this->pdo->query("SELECT slug, name, group_name, description FROM access_permissions WHERE slug LIKE 'erp.%'
            ORDER BY group_name, name LIMIT " . self::MAX_ROWS)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        if ($condominiumId !== null) {
            $this->scope->assertAccess($user, $condominiumId);
            return [$condominiumId];
        }
        return array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $id): bool => $id > 0));
    }
}
