<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Acesso ao condomínio tirado em lote de quem, pela regra, não entra no ERP (ADR 0283). Os acessos dos usuários (ADR
 * 0282) mostram em without_erp_access_ids quem tem a linha em user_condominium_access mas não entra no ERP: sem papel,
 * papel sem erp.access ou bloqueio por exceção. A linha fica esquecida e volta a valer se o papel mudar. Tirar hoje é,
 * na tela de usuários (AP-149), um "desativar" por pessoa.
 *
 * Reusa o que já existe: cada acesso sai pelo ErpAccess::deactivate, o mesmo "desativar" da tela e da API de usuários
 * (só apaga a linha de user_condominium_access, audita a exclusão e limpa o cache de permissões; confere de novo quem
 * pode mexer em quem). A regra de entrada no ERP é ErpAccess::effective sobre erp.access, o papel que vale é o de maior
 * nível (UserAccessReport::topRole), e quem pode ser alterado segue ErpAccess::canManage (nunca o próprio usuário, nunca
 * papel igual ou acima do de quem pede, nunca papel protegido). Papéis, exceções, situação da conta, senha e 2FA não são
 * tocados.
 *
 * - Entram: as linhas do condomínio dos usuários que não entram no ERP e que quem pede pode alterar, ou só os user_ids
 *   informados. Regra pura problem(): sem acesso ao condomínio, o próprio usuário, entra no ERP pela regra ou não pode ser
 *   alterado. Sem ids, os que não podem ser alterados ficam de fora e a contagem sai em skipped_unmanageable.
 * - Tudo ou nada, numa transação com as linhas do condomínio travadas (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form e _user). dry_run passa por tudo e desfaz (inclusive a auditoria da exclusão,
 *   que vai na mesma transação).
 * - Auditoria: a do ErpAccess::deactivate e um registro revoke_no_erp por usuário (motivo da falta de acesso, motivo do
 *   pedido e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, a causa e contagens: nenhum
 * nome, e-mail, telefone ou documento. Até MAX_USERS por vez; motivo obrigatório.
 */
final class UserAccessRevocation
{
    public const MAX_USERS = 500;
    /** Por que o usuário não entra no ERP. */
    public const CAUSES = ['no_role' => 'Sem papel', 'role_without_erp' => 'Papel sem acesso ao ERP', 'denied' => 'Bloqueado por exceção'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private ErpAccess $access;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?ErpAccess $access = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->access = $access ?? new ErpAccess($this->pdo, $this->scope);
    }

    /** Causa (regra pura) de quem não entra no ERP; null quando entra (ErpAccess::effective). */
    public static function cause(?string $role, bool $roleAllowed, ?string $override): ?string
    {
        if (ErpAccess::effective($role !== null, $roleAllowed, $override)) {
            return null;
        }
        return match (true) {
            $role === null => 'no_role',
            $override === 'deny' => 'denied',
            default => 'role_without_erp',
        };
    }

    /** Motivo da recusa (regra pura); null quando o acesso ao condomínio pode sair. */
    public static function problem(?array $row, bool $self): ?string
    {
        return match (true) {
            $row === null => 'O usuário não tem acesso a este condomínio.',
            $self => 'Você não pode tirar o próprio acesso.',
            $row['cause'] === null => 'O usuário entra no ERP pela regra: tire o acesso pela tela de usuários, se for o caso.',
            !$row['manageable'] => 'O usuário tem papel igual ou acima do seu (ou protegido): não pode ser alterado aqui.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function revoke(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('user_ids', $input) && $input['user_ids'] !== null) {
            if (!is_array($input['user_ids']) || $input['user_ids'] === []) {
                throw new InvalidArgumentException('Usuários: informe a lista de ids ou deixe vazio para todos os que não entram no ERP.');
            }
            $ids = [];
            foreach ($input['user_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Usuários: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_USERS) {
                throw new InvalidArgumentException(sprintf('Até %d usuários por vez.', self::MAX_USERS));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        $self = (int)$user->id;

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'skipped_unmanageable' => 0, 'users' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT user_id FROM user_condominium_access WHERE condominium_id = ? ORDER BY user_id FOR UPDATE');
            $stmt->execute([(int)$condominium]);
            $byId = $this->states(array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))), $self);
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $id => $row) {
                    if ($row['cause'] === null) {
                        continue;
                    }
                    if ($id === $self || !$row['manageable']) {
                        $result['skipped_unmanageable']++;
                        continue;
                    }
                    $rows[] = $row;
                }
                if (count($rows) > self::MAX_USERS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d usuários a tirar: tire em partes (escolha os ids).', self::MAX_USERS));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $id === $self);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_user' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            foreach ($rows as $row) {
                $this->access->deactivate($user, $row['id'], (int)$condominium);
                $result['users'][] = ['id' => $row['id'], 'cause' => $row['cause']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['users'] as $u) {
                Audit::record('revoke_no_erp', 'user_condominium_access', $u['id'], ['condominium_id' => (int)$condominium], ['cause' => $u['cause'],
                    'reason' => $reason, 'batch' => count($result['users'])]);
            }
        }
        $byCause = array_fill_keys(array_keys(self::CAUSES), 0);
        foreach ($result['users'] as $u) {
            $byCause[$u['cause']]++;
        }
        $result['by_cause'] = array_map(static fn (string $cause, int $n): array => ['cause' => $cause, 'cause_label' => self::CAUSES[$cause], 'users' => $n],
            array_keys($byCause), array_values($byCause));
        $result['count'] = count($result['users']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }

    /**
     * Situação de cada usuário com acesso ao condomínio: causa da falta de acesso ao ERP (ou null) e se quem pede pode
     * alterá-lo.
     *
     * @param list<int> $userIds
     * @return array<int, array{id:int, cause:?string, manageable:bool}>
     */
    private function states(array $userIds, int $self): array
    {
        $roles = $this->roles(array_merge($userIds, [$self]));
        $erpRoles = $this->erpRoles();
        $actorRole = UserAccessReport::topRole($roles[$self] ?? [], $roles[$self] ?? []);
        $actor = ['slug' => $actorRole, 'level' => $actorRole === null ? 0 : $roles[$self][$actorRole]];
        $overrides = [];
        if ($userIds !== []) {
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $this->pdo->prepare("SELECT o.user_id, o.effect FROM access_user_overrides o JOIN access_permissions p ON p.id = o.permission_id
                WHERE p.slug = ? AND o.user_id IN ({$in})");
            $stmt->execute(array_merge([UserAccessReport::ERP_PERMISSION], $userIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $overrides[(int)$r['user_id']] = (string)$r['effect'];
            }
        }
        $out = [];
        foreach ($userIds as $id) {
            $role = UserAccessReport::topRole($roles[$id] ?? [], $roles[$id] ?? []);
            $target = ['slug' => $role, 'level' => $role === null ? 0 : $roles[$id][$role]];
            $out[$id] = ['id' => $id, 'cause' => self::cause($role, $role !== null && in_array($role, $erpRoles, true), $overrides[$id] ?? null),
                'manageable' => ErpAccess::canManage($actor, $target, $id === $self)];
        }
        ksort($out);
        return $out;
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array<string, int>> usuário => papel => nível
     */
    private function roles(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare("SELECT ur.user_id, r.slug, r.level FROM access_user_roles ur JOIN access_roles r ON r.id = ur.role_id WHERE ur.user_id IN ({$in})");
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['user_id']][(string)$r['slug']] = (int)$r['level'];
        }
        return $out;
    }

    /** @return list<string> papéis que têm erp.access */
    private function erpRoles(): array
    {
        $stmt = $this->pdo->prepare('SELECT DISTINCT r.slug FROM access_role_permissions rp JOIN access_roles r ON r.id = rp.role_id
            JOIN access_permissions p ON p.id = rp.permission_id WHERE p.slug = ?');
        $stmt->execute([UserAccessReport::ERP_PERMISSION]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
