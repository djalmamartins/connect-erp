<?php

declare(strict_types=1);

namespace Source\Services\Directory;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Sugestões de pessoa e e-mail enquanto se digita (ADR 0687; pedido do Djalma em 27/11/2026: "quando começar a
 * digitar, buscar direto no banco; se a pessoa não tiver, digita tudo").
 *
 * Uma única busca para todos os campos de pessoa ou e-mail do ERP, do Studio e do Help Desk, usada pelo componente
 * container/shared/assets/connect-fields/connect-suggest.js. O campo continua livre: a sugestão só ajuda a preencher.
 *
 * Fontes e quem vê cada uma (LGPD: só o necessário):
 *  - "residents": proprietários, inquilinos e moradores (erp_residents) dos condomínios do escopo de quem busca
 *    (CondominiumScope), com erp.residents.view. Nunca sai pessoa de outro condomínio, documento ou telefone.
 *  - "team": a equipe da Connect, ou seja, contas com um papel que entra no ERP (erp.access; desde a ADR 0682 o
 *    Gerente, o Financeiro, o Operador e o Atendente têm users.level 2) ou o nível legado de administrador
 *    (level >= 5). Só para quem é da equipe. Conta de morador ou de imobiliária nunca entra por aqui.
 * Fonte sem permissão é ignorada em silêncio; sem nenhuma fonte permitida = 403.
 *
 * O e-mail só sai quando o campo pede (kind=email ou with=email), porque o campo vai preenchê-lo; nesses casos a
 * busca também casa pelo e-mail. Cada busca fica na auditoria sem o texto digitado, e há limite por hora.
 */
final class PeopleSuggest
{
    public const MIN_QUERY = 3;
    public const DEFAULT_LIMIT = 8;
    public const MAX_LIMIT = 15;
    public const MAX_SEARCHES_PER_HOUR = 900;
    public const SOURCES = ['residents', 'team'];
    public const TEAM_LEVEL = 5;
    public const ROLE_LABELS = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];
    public const AUDIT_ENTITY = 'people_suggest';

    /** Conta da equipe (alias "a"): papel com erp.access ou nível legado de administrador. */
    private const TEAM_SQL = "(a.level >= " . self::TEAM_LEVEL . " OR EXISTS (SELECT 1 FROM access_user_roles ur
        JOIN access_role_permissions rp ON rp.role_id = ur.role_id JOIN access_permissions p ON p.id = rp.permission_id
        WHERE ur.user_id = a.id AND p.slug = 'erp.access'))";

    private PDO $pdo;
    private CondominiumScope $scope;
    /** @var callable(string, User): bool */
    private $can;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?callable $can = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->can = $can ?? static fn (string $permission, User $user): bool => Access::can($permission, $user);
    }

    /**
     * Valida os parâmetros (regra pura).
     *
     * @return array{q:string, kind:string, email:bool, sources:list<string>, limit:int, condominium_id:?int, unit_id:?int}
     */
    public static function criteria(array $input): array
    {
        $q = is_string($input['q'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', (string)$input['q'])) : '';
        if (mb_strlen($q) < self::MIN_QUERY) {
            throw new InvalidArgumentException('Digite pelo menos ' . self::MIN_QUERY . ' letras para buscar.');
        }
        $q = mb_substr($q, 0, 80);
        $kind = ($input['kind'] ?? 'person') === 'email' ? 'email' : 'person';
        $email = $kind === 'email' || in_array((string)($input['with'] ?? ''), ['email', '1', 'true'], true);
        $raw = $input['sources'] ?? '';
        $raw = is_array($raw) ? $raw : explode(',', (string)$raw);
        $sources = array_values(array_intersect(self::SOURCES, array_map(static fn ($s): string => trim((string)$s), $raw)));
        if ($sources === []) {
            $sources = self::SOURCES;
        }
        $limit = filter_var($input['limit'] ?? null, FILTER_VALIDATE_INT);
        $limit = $limit === false || $limit === null ? self::DEFAULT_LIMIT : max(1, min(self::MAX_LIMIT, (int)$limit));
        $id = static function (mixed $v): ?int {
            $v = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $v === false ? null : (int)$v;
        };
        return ['q' => $q, 'kind' => $kind, 'email' => $email, 'sources' => $sources, 'limit' => $limit,
            'condominium_id' => $id($input['condominium_id'] ?? null), 'unit_id' => $id($input['unit_id'] ?? null)];
    }

    /** Fontes que este usuário pode ver, na ordem pedida. @param list<string> $wanted @return list<string> */
    public function allowedSources(User $user, array $wanted): array
    {
        $can = $this->can;
        $out = [];
        foreach ($wanted as $source) {
            $ok = match ($source) {
                'residents' => $can('erp.access', $user) && $can('erp.residents.view', $user),
                'team' => $can('erp.access', $user) || (int)($user->level ?? 0) >= self::TEAM_LEVEL,
                default => false,
            };
            if ($ok) {
                $out[] = $source;
            }
        }
        return $out;
    }

    /**
     * @return list<array{source:string, id:int, account_id:?int, name:string, email:?string, context:string, role:string}>
     */
    public function search(User $user, array $input): array
    {
        $criteria = self::criteria($input);
        $sources = $this->allowedSources($user, $criteria['sources']);
        if ($sources === []) {
            throw new AccessDeniedSuggest('Sem permissão para buscar pessoas.');
        }
        $this->assertNotThrottled($user);
        $items = [];
        if (in_array('residents', $sources, true)) {
            $items = array_merge($items, $this->residents($user, $criteria));
        }
        if (in_array('team', $sources, true)) {
            $items = array_merge($items, $this->team($criteria));
        }
        $items = self::rank($items, $criteria['q'], $criteria['limit']);
        Audit::record('search', self::AUDIT_ENTITY, null, null, ['kind' => $criteria['kind'], 'sources' => $sources, 'results' => count($items)]);
        return $items;
    }

    /**
     * Junta a mesma pessoa em várias unidades (mesmo documento ou e-mail) e monta o texto de contexto (regra pura).
     *
     * @param list<array<string, mixed>> $rows id, name, email, document, relationship, unit_label, condominium_name, account_id
     * @return list<array{source:string, id:int, account_id:?int, name:string, email:?string, context:string, role:string}>
     */
    public static function groupResidents(array $rows, bool $withEmail): array
    {
        $people = [];
        foreach ($rows as $row) {
            $document = (string)preg_replace('/\D/', '', (string)($row['document'] ?? ''));
            $email = mb_strtolower(trim((string)($row['email'] ?? '')));
            $key = $document !== '' ? 'd' . $document : ($email !== '' ? 'e' . $email : 'i' . (int)$row['id']);
            $unit = trim((string)($row['unit_label'] ?? ''));
            $place = trim(($unit !== '' ? $unit . ' · ' : '') . (string)($row['condominium_name'] ?? ''), ' ·');
            if (!isset($people[$key])) {
                $people[$key] = ['source' => 'resident', 'id' => (int)$row['id'],
                    'account_id' => isset($row['account_id']) && $row['account_id'] !== null ? (int)$row['account_id'] : null,
                    'name' => (string)$row['name'], 'email' => $withEmail && $email !== '' ? $email : null,
                    'role' => (string)$row['relationship'], 'places' => []];
            } elseif ($withEmail && $people[$key]['email'] === null && $email !== '') {
                $people[$key]['email'] = $email;
            }
            if ($place !== '' && !in_array($place, $people[$key]['places'], true)) {
                $people[$key]['places'][] = $place;
            }
        }
        $out = [];
        foreach ($people as $person) {
            $label = self::ROLE_LABELS[$person['role']] ?? 'Morador';
            $places = $person['places'];
            $more = count($places) > 2 ? ' +' . (count($places) - 2) : '';
            $person['context'] = $label . ($places !== [] ? ' · ' . implode(', ', array_slice($places, 0, 2)) . $more : '');
            unset($person['places']);
            $out[] = $person;
        }
        return $out;
    }

    /**
     * Ordena: nome que começa com o texto, depois palavra do nome que começa com ele, depois o resto; empate pelo
     * nome. Corta no limite (regra pura).
     *
     * @param list<array{name:string, email:?string}> $items
     * @return list<array>
     */
    public static function rank(array $items, string $q, int $limit): array
    {
        $needle = self::fold($q);
        $score = static function (array $item) use ($needle): int {
            $name = self::fold((string)$item['name']);
            $email = self::fold((string)($item['email'] ?? ''));
            if (str_starts_with($name, $needle) || ($email !== '' && str_starts_with($email, $needle))) {
                return 0;
            }
            return str_contains(' ' . $name, ' ' . $needle) ? 1 : 2;
        };
        usort($items, static function (array $a, array $b) use ($score): int {
            return [$score($a), self::fold((string)$a['name'])] <=> [$score($b), self::fold((string)$b['name'])];
        });
        return array_slice($items, 0, $limit);
    }

    /** Minúsculas sem acento, para comparar como o banco compara (regra pura). */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $plain = class_exists(\Normalizer::class) ? \Normalizer::normalize($text, \Normalizer::FORM_D) : $text;
        return (string)preg_replace('/\p{Mn}+/u', '', is_string($plain) ? $plain : $text);
    }

    /** @return list<array> */
    private function residents(User $user, array $criteria): array
    {
        $ids = array_map('intval', $this->scope->allowedIds($user));
        if ($criteria['condominium_id'] !== null) {
            $ids = in_array($criteria['condominium_id'], $ids, true) ? [$criteria['condominium_id']] : [];
        }
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $like = '%' . addcslashes($criteria['q'], '%_\\') . '%';
        $params = array_merge($ids, [$like]);
        $match = 'r.name LIKE ?';
        if ($criteria['email']) {
            $match = '(r.name LIKE ? OR r.email LIKE ?)';
            $params[] = $like;
        }
        $unit = '';
        if ($criteria['unit_id'] !== null) {
            $unit = ' AND r.unit_id = ?';
            $params[] = $criteria['unit_id'];
        }
        $stmt = $this->pdo->prepare("SELECT r.id, r.name, r.email, r.document, r.relationship, COALESCE(u.label, r.unit_label) AS unit_label,
                c.name AS condominium_name
            FROM erp_residents r
            JOIN condominiums c ON c.id = r.condominium_id
            LEFT JOIN erp_units u ON u.id = r.unit_id AND u.deleted_at IS NULL
            WHERE r.deleted_at IS NULL AND r.status = 'active' AND r.condominium_id IN ({$in}) AND {$match}{$unit}
            ORDER BY r.name, r.id LIMIT 120");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Conta do app (mesmo e-mail) numa consulta à parte: users e erp_residents têm collations diferentes.
        $emails = array_values(array_unique(array_filter(array_map(static fn (array $r): string => mb_strtolower(trim((string)$r['email'])), $rows))));
        $accounts = [];
        if ($emails !== []) {
            $marks = implode(',', array_fill(0, count($emails), '?'));
            $find = $this->pdo->prepare("SELECT id, email FROM users WHERE status <> 'trash' AND email IN ({$marks}) ORDER BY id DESC");
            $find->execute($emails);
            foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $account) {
                $accounts[mb_strtolower(trim((string)$account['email']))] = (int)$account['id'];
            }
        }
        foreach ($rows as &$row) {
            $row['account_id'] = $accounts[mb_strtolower(trim((string)$row['email']))] ?? null;
        }
        unset($row);
        return self::groupResidents($rows, $criteria['email']);
    }

    /** @return list<array> */
    private function team(array $criteria): array
    {
        $like = '%' . addcslashes($criteria['q'], '%_\\') . '%';
        $match = "CONCAT(a.first_name, ' ', a.last_name) LIKE :n";
        $params = ['n' => $like];
        if ($criteria['email']) {
            $match = "(CONCAT(a.first_name, ' ', a.last_name) LIKE :n OR a.email LIKE :e)";
            $params['e'] = $like;
        }
        $stmt = $this->pdo->prepare("SELECT a.id, TRIM(CONCAT(a.first_name, ' ', a.last_name)) AS name, a.email FROM users a
            WHERE a.status <> 'trash' AND " . self::TEAM_SQL . " AND {$match} ORDER BY a.first_name, a.last_name, a.id LIMIT 40");
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = mb_strtolower(trim((string)$row['email']));
            $out[] = ['source' => 'team', 'id' => (int)$row['id'], 'account_id' => (int)$row['id'], 'name' => (string)$row['name'],
                'email' => $criteria['email'] && $email !== '' ? $email : null, 'context' => 'Equipe Connect', 'role' => 'team'];
        }
        return $out;
    }

    /**
     * Confere a conta escolhida na sugestão antes de gravá-la (ex.: solicitante do chamado). Vale só se o e-mail enviado
     * é o da conta e se a conta é da equipe (para quem vê a equipe) ou de um morador ativo de um condomínio do escopo
     * (para quem vê moradores). Assim ninguém troca o id no navegador para pôr outra pessoa no lugar.
     */
    public function accountAllowed(User $actor, int $accountId, ?string $email): bool
    {
        $email = mb_strtolower(trim((string)$email));
        if ($accountId < 1 || $email === '') {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT id, email FROM users WHERE id = ? AND status <> 'trash'");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || mb_strtolower(trim((string)$account['email'])) !== $email) {
            return false;
        }
        $sources = $this->allowedSources($actor, self::SOURCES);
        if (in_array('team', $sources, true) && $this->isTeam($accountId)) {
            return true;
        }
        if (!in_array('residents', $sources, true)) {
            return false;
        }
        $ids = array_map('intval', $this->scope->allowedIds($actor));
        if ($ids === []) {
            return false;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT 1 FROM erp_residents WHERE deleted_at IS NULL AND status = 'active' AND email = ? AND condominium_id IN ({$in}) LIMIT 1");
        $stmt->execute(array_merge([$email], $ids));
        return (bool)$stmt->fetchColumn();
    }

    /** A conta é da equipe (mesma régua do responsável de chamado). */
    public function isTeam(int $accountId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users a WHERE a.id = ? AND a.status <> 'trash' AND " . self::TEAM_SQL);
        $stmt->execute([$accountId]);
        return (bool)$stmt->fetchColumn();
    }

    private function assertNotThrottled(User $user): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM system_audit_logs WHERE users_id = :u AND action = 'search' AND entity = :e AND created_at > :since");
        $stmt->execute(['u' => (int)$user->id, 'e' => self::AUDIT_ENTITY, 'since' => date('Y-m-d H:i:s', time() - 3600)]);
        if ((int)$stmt->fetchColumn() >= self::MAX_SEARCHES_PER_HOUR) {
            throw new DomainException(sprintf('Limite de %d buscas por hora. Continue digitando o nome completo.', self::MAX_SEARCHES_PER_HOUR));
        }
    }
}
