<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;
use Source\Support\Erp\CondominiumContext;

/**
 * Pessoas (proprietários e inquilinos) para o cadastro de unidades (ADR 0162).
 *
 * O cadastro é erp_residents: uma linha por vínculo (pessoa + unidade + papel). Uma pessoa com várias
 * unidades, inclusive em condomínios diferentes, é o mesmo documento (CPF/CNPJ) em várias linhas; sem
 * documento, cada linha é uma pessoa. Não há unicidade que impeça isso (a regra única do cadastro é
 * condomínio + unidade + vínculo + documento).
 *
 * - search(): busca por nome (contém) ou por CPF/CNPJ (só dígitos, igualdade). A busca por nome só traz
 *   pessoas com vínculo no escopo. Pessoa de fora do escopo só aparece na busca pelo documento completo
 *   (quem busca já sabe o CPF/CNPJ), com nome, documento mascarado e links vazio (revisão do Preparar, ADR
 *   0162). E-mail e telefone nunca saem. Limite por usuário e auditoria leve (sem o texto buscado).
 * - link(): vincula uma pessoa existente a N unidades do escopo de uma vez, em transação, sem duplicar
 *   vínculo que já existe (idempotente). Unidade fora do escopo = 404. Pessoa de fora do escopo exige
 *   "document" no corpo igual ao dela (o mesmo digitado na busca); sem isso, 404, para que ninguém copie
 *   nome e CPF de outro condomínio só trocando o id.
 */
final class People
{
    public const MIN_QUERY = 3;
    public const MAX_LIMIT = 20;
    public const MAX_UNITS = 50;
    public const MAX_SEARCHES_PER_HOUR = 300;
    public const ROLES = ['owner', 'tenant'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Valida os parâmetros da busca (regra pura).
     *
     * @return array{q:string, role:string, limit:int, digits:?string}
     */
    public static function criteria(mixed $q, mixed $role, mixed $limit): array
    {
        $q = is_string($q) ? trim((string)preg_replace('/\s+/u', ' ', $q)) : '';
        if (mb_strlen($q) < self::MIN_QUERY) {
            throw new InvalidArgumentException('Digite pelo menos ' . self::MIN_QUERY . ' caracteres para buscar.');
        }
        $q = mb_substr($q, 0, 80);
        $role = is_string($role) && $role !== '' ? $role : 'any';
        if (!in_array($role, ['owner', 'tenant', 'any'], true)) {
            throw new InvalidArgumentException('Vínculo inválido: use owner, tenant ou any.');
        }
        $limit = filter_var($limit, FILTER_VALIDATE_INT);
        $limit = $limit === false || $limit === null ? self::MAX_LIMIT : max(1, min(self::MAX_LIMIT, (int)$limit));
        // Só dígitos e pontuação de documento = busca por CPF/CNPJ (igualdade).
        $digits = preg_match('/^[\d.\-\/\s]+$/', $q) === 1 ? (string)preg_replace('/\D/', '', $q) : null;
        return ['q' => $q, 'role' => $role, 'limit' => $limit, 'digits' => $digits];
    }

    /** CPF "***.456.789-**", CNPJ "**.345.678/****-**"; outro tamanho = só asteriscos (regra pura). */
    public static function maskDocument(?string $document): ?string
    {
        $d = (string)preg_replace('/\D/', '', (string)$document);
        if ($d === '') {
            return null;
        }
        if (strlen($d) === 11) {
            return '***.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-**';
        }
        if (strlen($d) === 14) {
            return '**.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/****-**';
        }
        return str_repeat('*', strlen($d));
    }

    /** PJ com 14 dígitos; o resto é PF (regra pura). */
    public static function personType(?string $document): string
    {
        return strlen((string)preg_replace('/\D/', '', (string)$document)) === 14 ? 'PJ' : 'PF';
    }

    /**
     * Agrupa as linhas do cadastro por pessoa e monta a resposta (regra pura).
     *
     * @param list<array<string, mixed>> $rows id, name, document, relationship, unit_id, unit_label, condominium_id
     * @param array<int, array{slug:string, name:string}> $condominiums condomínios do escopo
     * @return list<array{id:int, name:string, document_masked:?string, person_type:string, links:list<array>}>
     */
    public static function group(array $rows, array $condominiums, int $limit): array
    {
        $people = [];
        foreach ($rows as $row) {
            $document = (string)preg_replace('/\D/', '', (string)($row['document'] ?? ''));
            $key = $document !== '' ? 'd' . $document : 'i' . (int)$row['id'];
            if (!isset($people[$key])) {
                if (count($people) >= $limit) {
                    continue;
                }
                $people[$key] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'document_masked' => self::maskDocument($document),
                    'person_type' => self::personType($document), 'links' => []];
            }
            $people[$key]['id'] = min($people[$key]['id'], (int)$row['id']);
            $condominium = $condominiums[(int)$row['condominium_id']] ?? null;
            $role = (string)$row['relationship'];
            if ($condominium === null || $row['unit_id'] === null || !in_array($role, self::ROLES, true)) {
                continue;   // fora do escopo: só nome e documento mascarado
            }
            $link = ['unit_id' => (int)$row['unit_id'], 'unit_label' => (string)$row['unit_label'], 'condominium_slug' => $condominium['slug'],
                'condominium_name' => $condominium['name'], 'role' => $role];
            if (!in_array($link, $people[$key]['links'], true)) {
                $people[$key]['links'][] = $link;
            }
        }
        return array_values($people);
    }

    /** @return list<array{id:int, name:string, document_masked:?string, person_type:string, links:list<array>}> */
    public function search(User $user, mixed $q, mixed $role = 'any', mixed $limit = self::MAX_LIMIT): array
    {
        $criteria = self::criteria($q, $role, $limit);
        $this->assertNotThrottled($user);
        $roles = $criteria['role'] === 'any' ? self::ROLES : [$criteria['role']];
        $params = [];
        $where = "r.deleted_at IS NULL AND r.relationship IN ('" . implode("','", $roles) . "')";
        if ($criteria['digits'] !== null) {
            $where .= ' AND r.document = :d';
            $params['d'] = $criteria['digits'];
        } else {
            $where .= ' AND r.name LIKE :n';
            $params['n'] = '%' . addcslashes($criteria['q'], '%_\\') . '%';
        }
        $stmt = $this->pdo->prepare("SELECT r.id, r.name, r.document, r.relationship, r.unit_id, COALESCE(u.label, r.unit_label) AS unit_label, r.condominium_id
            FROM erp_residents r LEFT JOIN erp_units u ON u.id = r.unit_id AND u.deleted_at IS NULL
            WHERE {$where} ORDER BY r.name, r.id LIMIT 400");
        $stmt->execute($params);
        $condominiums = $this->condominiums($user);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($criteria['digits'] === null) {
            $rows = self::inScopeOnly($rows, $condominiums);
        }
        $people = self::group($rows, $condominiums, $criteria['limit']);
        Audit::record('search', 'erp_people', null, null, ['by' => $criteria['digits'] !== null ? 'document' : 'name', 'role' => $criteria['role'], 'results' => count($people)]);
        return $people;
    }

    /**
     * Busca por nome (regra pura): só as pessoas com ao menos um vínculo num condomínio do escopo. A pessoa
     * é o documento (ou a linha, sem documento), então os vínculos dela fora do escopo continuam fora da resposta.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, mixed> $condominiums condomínios do escopo
     * @return list<array<string, mixed>>
     */
    public static function inScopeOnly(array $rows, array $condominiums): array
    {
        $keep = [];
        foreach ($rows as $row) {
            if (isset($condominiums[(int)$row['condominium_id']])) {
                $document = (string)preg_replace('/\D/', '', (string)($row['document'] ?? ''));
                $keep[$document !== '' ? 'd' . $document : 'i' . (int)$row['id']] = true;
            }
        }
        return array_values(array_filter($rows, static function (array $row) use ($keep): bool {
            $document = (string)preg_replace('/\D/', '', (string)($row['document'] ?? ''));
            return isset($keep[$document !== '' ? 'd' . $document : 'i' . (int)$row['id']]);
        }));
    }

    /**
     * Valida o pedido de vínculo (regra pura).
     *
     * @return array{unit_ids:list<int>, role:string, starts_at:?string}
     */
    public static function linkInput(array $input): array
    {
        $ids = is_array($input['unit_ids'] ?? null) ? $input['unit_ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v): int => (int)filter_var($v, FILTER_VALIDATE_INT), $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || count($ids) > self::MAX_UNITS) {
            throw new InvalidArgumentException('Escolha de 1 a ' . self::MAX_UNITS . ' unidades.');
        }
        $role = $input['role'] ?? null;
        if (!is_string($role) || !in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Vínculo inválido: use owner ou tenant.');
        }
        $starts = $input['starts_at'] ?? null;
        if ($starts !== null && $starts !== '') {
            $date = is_string($starts) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $starts) : false;
            if ($date === false || $date->format('Y-m-d') !== $starts) {
                throw new InvalidArgumentException('Data de início inválida (use AAAA-MM-DD).');
            }
        } else {
            $starts = null;
        }
        sort($ids);
        return ['unit_ids' => $ids, 'role' => $role, 'starts_at' => $starts];
    }

    /** @return array{person_id:int, created:list<array{id:int, unit_id:int}>, existing:list<int>} */
    public function link(User $user, int $personId, array $input): array
    {
        $request = self::linkInput($input);
        $stmt = $this->pdo->prepare('SELECT id, condominium_id, name, document, email, phone FROM erp_residents WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $personId]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($person)) {
            throw new RegistryNotFound();
        }
        $allowed = array_map('intval', $this->scope->allowedIds($user));
        // Pessoa de fora do escopo: reaproveita só nome e documento, nunca e-mail e telefone, e só com o documento
        // completo no corpo (prova de que quem vincula já conhece a pessoa; impede varrer ids de outro condomínio).
        $sourceInScope = in_array((int)$person['condominium_id'], $allowed, true);
        $personDocument = (string)preg_replace('/\D/', '', (string)$person['document']);
        $sentDocument = is_scalar($input['document'] ?? null) ? (string)preg_replace('/\D/', '', (string)$input['document']) : '';
        if (!$sourceInScope && ($personDocument === '' || !hash_equals($personDocument, $sentDocument))) {
            throw new RegistryNotFound();
        }
        $placeholders = implode(',', array_fill(0, count($request['unit_ids']), '?'));
        $units = $this->pdo->prepare("SELECT id, condominium_id, label FROM erp_units WHERE id IN ({$placeholders}) AND deleted_at IS NULL AND status = 'active'");
        $units->execute($request['unit_ids']);
        $found = [];
        foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $unit) {
            $found[(int)$unit['id']] = $unit;
        }
        foreach ($request['unit_ids'] as $unitId) {
            if (!isset($found[$unitId]) || !in_array((int)$found[$unitId]['condominium_id'], $allowed, true)) {
                throw new RegistryNotFound();   // 404: não diz se a unidade existe fora do escopo
            }
        }
        $document = (string)preg_replace('/\D/', '', (string)$person['document']);
        $result = ['person_id' => (int)$person['id'], 'created' => [], 'existing' => []];
        $this->pdo->beginTransaction();
        try {
            $exists = $this->pdo->prepare('SELECT id FROM erp_residents WHERE unit_id = :u AND relationship = :r AND deleted_at IS NULL
                AND ((:d1 <> \'\' AND document = :d2) OR (:d3 = \'\' AND document IS NULL AND name = :n)) LIMIT 1');
            $insert = $this->pdo->prepare('INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document, email, phone, starts_on, created_by)
                VALUES (:c, :u, :l, :r, :n, :d, :e, :p, :s, :by)');
            foreach ($request['unit_ids'] as $unitId) {
                $unit = $found[$unitId];
                $exists->execute(['u' => $unitId, 'r' => $request['role'], 'd1' => $document, 'd2' => $document, 'd3' => $document, 'n' => $person['name']]);
                if ($exists->fetchColumn() !== false) {
                    $result['existing'][] = $unitId;
                    continue;
                }
                $insert->execute(['c' => (int)$unit['condominium_id'], 'u' => $unitId, 'l' => mb_substr((string)$unit['label'], 0, 40), 'r' => $request['role'],
                    'n' => $person['name'], 'd' => $document !== '' ? $document : null, 'e' => $sourceInScope ? $person['email'] : null,
                    'p' => $sourceInScope ? $person['phone'] : null, 's' => $request['starts_at'], 'by' => (int)$user->id]);
                $result['created'][] = ['id' => (int)$this->pdo->lastInsertId(), 'unit_id' => $unitId];
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        foreach ($result['created'] as $created) {
            Audit::record('create', 'erp_residents', $created['id'], null, ['unit_id' => $created['unit_id'], 'relationship' => $request['role'],
                'starts_on' => $request['starts_at'], 'linked_from' => $result['person_id']]);
        }
        return $result;
    }

    /** @return array<int, array{slug:string, name:string}> */
    private function condominiums(User $user): array
    {
        $options = CondominiumContext::booted() ? CondominiumContext::options() : [];
        if ($options === []) {
            $rows = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $this->scope->allowed($user));
            $options = CondominiumContext::withSlugs($rows);
        }
        $allowed = array_flip(array_map('intval', $this->scope->allowedIds($user)));
        $map = [];
        foreach ($options as $option) {
            if (isset($allowed[(int)$option['id']])) {
                $map[(int)$option['id']] = ['slug' => (string)$option['slug'], 'name' => (string)$option['name']];
            }
        }
        return $map;
    }

    private function assertNotThrottled(User $user): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM system_audit_logs WHERE users_id = :u AND action = 'search' AND entity = 'erp_people'
            AND created_at > :since");
        $stmt->execute(['u' => (int)$user->id, 'since' => date('Y-m-d H:i:s', time() - 3600)]);
        if ((int)$stmt->fetchColumn() >= self::MAX_SEARCHES_PER_HOUR) {
            throw new DomainException(sprintf('Limite de %d buscas por hora. Tente mais tarde.', self::MAX_SEARCHES_PER_HOUR));
        }
    }
}
