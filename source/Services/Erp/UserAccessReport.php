<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Acessos dos usuários ao ERP por condomínio (ADR 0282), somente leitura. Usuários do Sistema (AP-149, ADR 0168) lista
 * quem tem acesso ao condomínio, um a um, com o papel; Permissões de Usuários (AP-150) mostra as exceções de uma pessoa
 * por vez. Ninguém vê, de uma vez, quantos acessos há por papel, quantos têm exceção de permissão, quantos estão sem papel
 * e quantos, pela regra, nem entram no ERP.
 *
 * Reusa o que já existe: o controle de acesso (access_roles, access_user_roles, access_role_permissions,
 * access_user_overrides e user_condominium_access) e a decisão fail-closed de ErpAccess::effective (sem papel não acessa;
 * deny vence; allow concede; sem exceção vale o papel), aplicada a erp.access. Os efeitos das exceções vêm de
 * ErpAccess::EFFECTS.
 *
 * O cadastro de usuários não guarda o último login (users não tem a coluna e o login não grava data), então não há "sem
 * login há mais de 90 dias" aqui. Não há migration para isso.
 *
 * Regra pura summarize():
 * - rows: uma linha por papel cadastrado (do maior nível para o menor), com users; cada usuário conta no papel de maior
 *   nível; without_role conta os sem papel; totals soma: users = soma das linhas + without_role;
 * - erp_access: com e sem acesso ao ERP pela regra (ErpAccess::effective sobre erp.access), somando users;
 * - overrides: usuários com alguma exceção e, por efeito de EFFECTS, exceções e usuários;
 * - by_status: usuários por situação da conta, somando users;
 * - without_role_ids, without_erp_access_ids e with_overrides_ids: até MAX_IDS cada, com truncated.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * papéis, efeitos, situações, contagens e ids: nenhum nome, e-mail, telefone ou documento.
 */
final class UserAccessReport
{
    public const MAX_ROWS = 20000;
    public const MAX_IDS = 200;
    public const ERP_PERMISSION = 'erp.access';

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, status:string, roles:array<string, int>, overrides:array<string, string>}> $users papéis: slug => nível; exceções: permissão => efeito
     * @param list<array{slug:string, name:string, level:int}> $roles todos os papéis cadastrados
     * @param list<string> $erpRoles papéis que têm erp.access
     */
    public static function summarize(array $users, array $roles, array $erpRoles): array
    {
        usort($roles, static fn (array $a, array $b): int => [$b['level'], $a['slug']] <=> [$a['level'], $b['slug']]);
        $counts = array_fill_keys(array_column($roles, 'slug'), 0);
        $effects = array_fill_keys(ErpAccess::EFFECTS, ['overrides' => 0, 'users' => 0]);
        $statuses = [];
        $withoutRole = [];
        $withoutErp = [];
        $withOverrides = [];
        $erp = ['with' => 0, 'without' => 0];
        foreach ($users as $u) {
            $role = self::topRole($u['roles'], $counts);
            if ($role === null) {
                $withoutRole[] = $u['id'];
            } else {
                $counts[$role]++;
            }
            $roleAllowed = $role !== null && in_array($role, $erpRoles, true);
            $allowed = ErpAccess::effective($role !== null, $roleAllowed, $u['overrides'][self::ERP_PERMISSION] ?? null);
            $erp[$allowed ? 'with' : 'without']++;
            if (!$allowed) {
                $withoutErp[] = $u['id'];
            }
            $seen = [];
            foreach ($u['overrides'] as $effect) {
                if (!isset($effects[$effect])) {
                    continue;
                }
                $effects[$effect]['overrides']++;
                $seen[$effect] = true;
            }
            foreach (array_keys($seen) as $effect) {
                $effects[$effect]['users']++;
            }
            if ($seen !== []) {
                $withOverrides[] = $u['id'];
            }
            $statuses[$u['status']] = ($statuses[$u['status']] ?? 0) + 1;
        }
        $rows = [];
        foreach ($roles as $r) {
            $rows[] = ['role' => $r['slug'], 'role_name' => $r['name'], 'level' => $r['level'], 'users' => $counts[$r['slug']]];
        }
        ksort($statuses);
        $cut = static function (array $ids): array {
            sort($ids);
            return ['ids' => array_slice($ids, 0, self::MAX_IDS), 'truncated' => count($ids) > self::MAX_IDS];
        };
        $noRole = $cut($withoutRole);
        $noErp = $cut($withoutErp);
        $overridden = $cut($withOverrides);
        return ['rows' => $rows, 'totals' => ['users' => count($users), 'with_role' => array_sum($counts), 'without_role' => count($withoutRole)],
            'erp_access' => $erp,
            'overrides' => ['users' => count($withOverrides), 'by_effect' => array_map(static fn (string $effect, array $n): array => ['effect' => $effect] + $n,
                array_keys($effects), array_values($effects))],
            'by_status' => array_map(static fn (string $status, int $n): array => ['status' => $status, 'users' => $n], array_keys($statuses), array_values($statuses)),
            'without_role_ids' => $noRole['ids'], 'without_role_truncated' => $noRole['truncated'],
            'without_erp_access_ids' => $noErp['ids'], 'without_erp_access_truncated' => $noErp['truncated'],
            'with_overrides_ids' => $overridden['ids'], 'with_overrides_truncated' => $overridden['truncated']];
    }

    /**
     * Papel que vale (regra pura): o de maior nível entre os cadastrados; empate, o menor slug.
     *
     * @param array<string, int> $userRoles slug => nível
     * @param array<string, int> $known papéis cadastrados
     */
    public static function topRole(array $userRoles, array $known): ?string
    {
        $best = null;
        foreach ($userRoles as $slug => $level) {
            if (!array_key_exists($slug, $known)) {
                continue;
            }
            if ($best === null || $level > $userRoles[$best] || ($level === $userRoles[$best] && strcmp($slug, $best) < 0)) {
                $best = $slug;
            }
        }
        return $best;
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT DISTINCT a.user_id, u.status FROM user_condominium_access a JOIN users u ON u.id = a.user_id WHERE a.condominium_id = ?
            ORDER BY a.user_id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($raw) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d usuários no condomínio: fale com o suporte.', self::MAX_ROWS));
        }
        $users = [];
        foreach ($raw as $r) {
            $users[(int)$r['user_id']] = ['id' => (int)$r['user_id'], 'status' => (string)$r['status'], 'roles' => [], 'overrides' => []];
        }
        $stmt = $this->pdo->prepare('SELECT DISTINCT ur.user_id, r.slug, r.level FROM access_user_roles ur JOIN access_roles r ON r.id = ur.role_id
            JOIN user_condominium_access a ON a.user_id = ur.user_id WHERE a.condominium_id = ?');
        $stmt->execute([(int)$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($users[(int)$r['user_id']])) {
                $users[(int)$r['user_id']]['roles'][(string)$r['slug']] = (int)$r['level'];
            }
        }
        $stmt = $this->pdo->prepare('SELECT DISTINCT o.user_id, p.slug, o.effect FROM access_user_overrides o JOIN access_permissions p ON p.id = o.permission_id
            JOIN user_condominium_access a ON a.user_id = o.user_id WHERE a.condominium_id = ?');
        $stmt->execute([(int)$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($users[(int)$r['user_id']])) {
                $users[(int)$r['user_id']]['overrides'][(string)$r['slug']] = (string)$r['effect'];
            }
        }
        $roles = array_map(static fn (array $r): array => ['slug' => (string)$r['slug'], 'name' => (string)$r['name'], 'level' => (int)$r['level']],
            $this->pdo->query('SELECT slug, name, level FROM access_roles')->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $this->pdo->prepare('SELECT DISTINCT r.slug FROM access_role_permissions rp JOIN access_roles r ON r.id = rp.role_id
            JOIN access_permissions p ON p.id = rp.permission_id WHERE p.slug = ?');
        $stmt->execute([self::ERP_PERMISSION]);
        $erpRoles = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return ['condominium_id' => (int)$id] + self::summarize(array_values($users), $roles, $erpRoles);
    }
}
