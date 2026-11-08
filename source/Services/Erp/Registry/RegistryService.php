<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Serviço único dos cadastros do ERP: listagem, leitura, gravação e exclusão lógica,
 * sempre limitadas no servidor ao escopo de condomínios do usuário (ADR-002) e com
 * auditoria em system_audit_logs. Usado pelas telas (/cadastros/*) e pela API
 * (/api/v1/<recurso>), para que as duas apliquem as mesmas regras.
 *
 * Permissões (view/manage) são verificadas nos controllers; aqui fica o escopo.
 */
final class RegistryService
{
    public const PER_PAGE = 25;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return array<string, mixed> */
    public static function definition(string $slug): array
    {
        $definition = Definitions::get($slug);
        if ($definition === null) {
            throw new InvalidArgumentException("Cadastro desconhecido: {$slug}");
        }
        return $definition;
    }

    /**
     * @param array{condominium_id?: mixed, status?: mixed, q?: mixed, page?: mixed, filter?: mixed} $filters
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function list(User $user, string $slug, array $filters = []): array
    {
        $definition = self::definition($slug);
        [$where, $params] = $this->scopeWhere($user, $definition);

        $condominium = filter_var($filters['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium !== false && $condominium !== null && $definition['scope'] !== 'none') {
            $where[] = 't.condominium_id = :f_condominium';
            $params['f_condominium'] = $condominium;
        }
        $status = (string)($filters['status'] ?? '');
        if (array_key_exists($status, $definition['status'])) {
            $where[] = 't.status = :f_status';
            $params['f_status'] = $status;
        }
        $filterField = $this->filterField($definition);
        $filterValue = (string)($filters['filter'] ?? '');
        if ($filterField !== null && array_key_exists($filterValue, $definition['fields'][$filterField]['options'])) {
            $where[] = "t.`{$filterField}` = :f_option";
            $params['f_option'] = $filterValue;
        }
        $q = trim(mb_substr((string)($filters['q'] ?? ''), 0, 100));
        if ($q !== '') {
            $like = [];
            $i = 0;
            $digits = preg_replace('/\D+/', '', $q);
            foreach ($definition['fields'] as $name => $field) {
                if (!$field['search']) {
                    continue;
                }
                $isNumeric = in_array($field['type'], ['cpf', 'cnpj', 'document', 'phone'], true);
                if ($isNumeric && $digits === '') {
                    continue;
                }
                $like[] = "t.`{$name}` LIKE :q{$i}";
                $params["q{$i}"] = '%' . addcslashes($isNumeric ? $digits : ($field['type'] === 'plate' ? strtoupper((string)preg_replace('/[^0-9A-Za-z]/', '', $q)) : $q), '%_\\') . '%';
                $i++;
            }
            $where[] = $like === [] ? '1 = 0' : '(' . implode(' OR ', $like) . ')';
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM `{$definition['table']}` t WHERE {$whereSql}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int)(filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1)));
        $offset = ($page - 1) * self::PER_PAGE;
        $order = $definition['order'];
        $stmt = $this->pdo->prepare($this->select($definition) . " WHERE {$whereSql} ORDER BY t.`{$order}`, t.id LIMIT " . self::PER_PAGE . " OFFSET {$offset}");
        $stmt->execute($params);

        return [
            'data' => array_map(fn (array $row): array => $this->present($definition, $row), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE, 'pages' => $pages,
        ];
    }

    /** @return array<string, mixed> */
    public function find(User $user, string $slug, int $id): array
    {
        $definition = self::definition($slug);
        $row = $this->row($user, $definition, $id);
        if ($row === null) {
            throw new RegistryNotFound();
        }
        return $this->present($definition, $row);
    }

    /**
     * Cria (sem $id) ou altera um registro.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> registro gravado
     */
    public function save(User $user, string $slug, array $input, ?int $id = null): array
    {
        $definition = self::definition($slug);
        $before = null;
        if ($id !== null) {
            $before = $this->row($user, $definition, $id);
            if ($before === null) {
                throw new RegistryNotFound();
            }
            $this->assertWritable($user, $definition, $before);
        }

        [$values, $errors] = RegistryValidator::validate($definition, $input);
        if ($definition['scope'] !== 'none' && !isset($errors['condominium_id'])) {
            $condominium = $values['condominium_id'] ?? null;
            if ($condominium !== null && !$this->scope->canAccess($user, (int)$condominium)) {
                $errors['condominium_id'] = 'Condomínio fora do seu escopo de acesso.';
            } elseif ($condominium === null && $definition['scope'] === 'optional' && !$this->scope->hasGlobalAccess($user)) {
                $errors['condominium_id'] = 'Somente quem acessa todos os condomínios pode cadastrar um contato para todos. Selecione um condomínio.';
            }
        }
        if ($errors === []) {
            $errors = $this->businessRules($definition, $values, $id);
        }
        if ($errors !== []) {
            throw new RegistryValidationException($errors);
        }

        $columns = array_keys($values);
        if ($id === null) {
            $values['created_by'] = (int)$user->id;
            $values['updated_by'] = (int)$user->id;
            $columns = array_keys($values);
            $sql = sprintf(
                'INSERT INTO `%s` (%s) VALUES (%s)',
                $definition['table'],
                implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
                implode(', ', array_map(static fn (string $c): string => ":{$c}", $columns))
            );
            $this->pdo->prepare($sql)->execute($values);
            $id = (int)$this->pdo->lastInsertId();
        } else {
            $values['updated_by'] = (int)$user->id;
            $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = :{$c}", array_keys($values)));
            $this->pdo->prepare("UPDATE `{$definition['table']}` SET {$sets} WHERE id = :id AND deleted_at IS NULL")
                ->execute($values + ['id' => $id]);
        }

        $after = $this->rawRow($definition, $id);
        Audit::record($before === null ? 'create' : 'update', $definition['table'], $id, $before ? $this->auditable($before, $columns) : null, $this->auditable($after ?? [], $columns));
        return $this->present($definition, $after ?? []);
    }

    /** Exclusão lógica (deleted_at), preservando o histórico de auditoria. */
    public function delete(User $user, string $slug, int $id): void
    {
        $definition = self::definition($slug);
        $row = $this->row($user, $definition, $id);
        if ($row === null) {
            throw new RegistryNotFound();
        }
        $this->assertWritable($user, $definition, $row);
        $this->pdo->prepare("UPDATE `{$definition['table']}` SET deleted_at = CURRENT_TIMESTAMP, updated_by = :user WHERE id = :id AND deleted_at IS NULL")
            ->execute(['id' => $id, 'user' => (int)$user->id]);
        Audit::record('delete', $definition['table'], $id, ['status' => $row['status']], ['status' => 'deleted']);
    }

    /**
     * Histórico do registro (auditoria). Valores sensíveis já chegam mascarados pelo Audit.
     *
     * @return list<array{action: string, user: ?string, created_at: string, fields: list<string>}>
     */
    public function history(User $user, string $slug, int $id): array
    {
        $definition = self::definition($slug);
        if ($this->row($user, $definition, $id) === null) {
            throw new RegistryNotFound();
        }
        $stmt = $this->pdo->prepare("SELECT l.action, l.context_json, l.created_at, CONCAT_WS(' ', u.first_name, u.last_name) AS user_name
            FROM system_audit_logs l LEFT JOIN users u ON u.id = l.users_id
            WHERE l.entity = :entity AND l.entity_id = :id ORDER BY l.id DESC LIMIT 50");
        $stmt->execute(['entity' => $definition['table'], 'id' => $id]);
        $labels = [];
        foreach ($definition['fields'] as $name => $field) {
            $labels[$name] = $field['label'];
        }
        $labels['status'] = 'Situação';
        return array_map(static function (array $row) use ($labels): array {
            $context = json_decode((string)$row['context_json'], true);
            $fields = array_map(static fn (string $f): string => $labels[$f] ?? $f, (array)($context['changed_fields'] ?? []));
            return ['action' => (string)$row['action'], 'user' => $row['user_name'] ?: null, 'created_at' => (string)$row['created_at'], 'fields' => array_values(array_filter($fields, static fn (string $f): bool => !in_array($f, ['created_by', 'updated_by'], true)))];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Pode alterar este registro? (contato compartilhado exige acesso a todos os condomínios) */
    public function canWrite(User $user, string $slug, array $record): bool
    {
        $definition = self::definition($slug);
        return $definition['scope'] !== 'optional' || ($record['condominium_id'] ?? null) !== null || $this->scope->hasGlobalAccess($user);
    }

    /** @return list<array{id:int,name:string,document:?string,status:string}> condomínios para filtros e formulários */
    public function condominiums(User $user): array
    {
        return $this->scope->allowed($user);
    }

    /** Campo de seleção usado como filtro rápido na listagem (o primeiro com 'filter'). */
    public function filterField(array $definition): ?string
    {
        foreach ($definition['fields'] as $name => $field) {
            if ($field['filter'] && $field['type'] === 'select') {
                return $name;
            }
        }
        return null;
    }

    /** @return array{0: list<string>, 1: array<string, mixed>} */
    private function scopeWhere(User $user, array $definition): array
    {
        $where = ['t.deleted_at IS NULL'];
        $params = [];
        if ($definition['scope'] === 'none') {
            return [$where, $params];
        }
        $ids = $this->scope->allowedIds($user);
        $in = $ids === [] ? 'NULL' : implode(',', array_map('intval', $ids));
        $where[] = $definition['scope'] === 'optional'
            ? "(t.condominium_id IS NULL OR t.condominium_id IN ({$in}))"
            : "t.condominium_id IN ({$in})";
        return [$where, $params];
    }

    private function select(array $definition): string
    {
        $join = $definition['scope'] === 'none' ? '' : ' LEFT JOIN condominiums c ON c.id = t.condominium_id';
        $name = $definition['scope'] === 'none' ? 'NULL' : 'c.name';
        return "SELECT t.*, {$name} AS condominium_name FROM `{$definition['table']}` t{$join}";
    }

    /** Registro visível ao usuário (escopo aplicado) ou null. */
    private function row(User $user, array $definition, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        [$where, $params] = $this->scopeWhere($user, $definition);
        $stmt = $this->pdo->prepare($this->select($definition) . ' WHERE ' . implode(' AND ', $where) . ' AND t.id = :id');
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function rawRow(array $definition, int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->select($definition) . ' WHERE t.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertWritable(User $user, array $definition, array $row): void
    {
        if ($definition['scope'] === 'optional' && $row['condominium_id'] === null && !$this->scope->hasGlobalAccess($user)) {
            throw new RegistryForbidden();
        }
    }

    /** @return array<string, string> */
    private function businessRules(array $definition, array $values, ?int $id): array
    {
        $errors = [];
        foreach ($definition['unique'] as $rule) {
            $where = ['deleted_at IS NULL'];
            $params = [];
            $complete = true;
            foreach ($rule['fields'] as $field) {
                if ($values[$field] === null) {
                    $complete = false;
                    break;
                }
                $where[] = "`{$field}` = :{$field}";
                $params[$field] = $values[$field];
            }
            if (!$complete) {
                continue;
            }
            if ($id !== null) {
                $where[] = 'id <> :self';
                $params['self'] = $id;
            }
            $stmt = $this->pdo->prepare("SELECT 1 FROM `{$definition['table']}` WHERE " . implode(' AND ', $where) . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetchColumn()) {
                $errors[end($rule['fields'])] = $rule['message'];
            }
        }
        foreach ($definition['single_active'] as $rule) {
            if ($values[$rule['field']] !== $rule['value'] || $values['status'] !== 'active') {
                continue;
            }
            $sql = "SELECT 1 FROM `{$definition['table']}` WHERE deleted_at IS NULL AND status = 'active' AND `{$rule['field']}` = :value AND `{$rule['per']}` = :per";
            $params = ['value' => $rule['value'], 'per' => $values[$rule['per']]];
            if ($id !== null) {
                $sql .= ' AND id <> :self';
                $params['self'] = $id;
            }
            $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetchColumn()) {
                $errors[$rule['field']] = $rule['message'];
            }
        }
        return $errors;
    }

    /** @return array<string, mixed> */
    private function auditable(array $row, array $columns): array
    {
        return array_intersect_key($row, array_flip($columns));
    }

    /**
     * Registro com tipos do contrato da API (inteiros, booleanos, datas ISO, valores em centavos).
     *
     * @return array<string, mixed>
     */
    private function present(array $definition, array $row): array
    {
        $out = ['id' => (int)($row['id'] ?? 0)];
        foreach ($definition['fields'] as $name => $field) {
            $value = $row[$name] ?? null;
            $out[$name] = match (true) {
                $value === null => $field['type'] === 'bool' ? false : null,
                $field['type'] === 'bool' => (bool)$value,
                in_array($field['type'], ['condominium', 'money', 'int'], true) => (int)$value,
                $field['type'] === 'time' => substr((string)$value, 0, 5),
                $field['type'] === 'percent' => (float)$value,
                default => (string)$value,
            };
        }
        if ($definition['scope'] !== 'none') {
            $out['condominium_name'] = $row['condominium_name'] ?? null;
        }
        $out['status'] = (string)($row['status'] ?? 'active');
        $out['created_at'] = $row['created_at'] ?? null;
        $out['updated_at'] = $row['updated_at'] ?? null;
        return $out;
    }
}
