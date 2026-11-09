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
     * @param array{condominium_id?: mixed, include_standard?: bool, status?: mixed, q?: mixed, page?: mixed, filter?: mixed} $filters
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function list(User $user, string $slug, array $filters = []): array
    {
        $definition = self::definition($slug);
        [$where, $params] = $this->scopeWhere($user, $definition);

        $condominium = filter_var($filters['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium !== false && $condominium !== null && $definition['scope'] !== 'none') {
            // 'include_standard' (telas do ERP, ADR 0032): com um condomínio em contexto, os
            // cadastros de escopo optional mostram também o padrão da Connect (sem condomínio),
            // que esse condomínio usa (plano de contas, tipos de faturas, centros de custo).
            $where[] = $definition['scope'] === 'optional' && !empty($filters['include_standard'])
                ? '(t.condominium_id = :f_condominium OR t.condominium_id IS NULL)'
                : 't.condominium_id = :f_condominium';
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
        // 'fixed' (ADR 0024): colunas que a visão grava sempre com o mesmo valor (ex.: natureza
        // despesa em contas a pagar). Não vêm do formulário e não podem ser trocadas pela entrada.
        $values = array_merge($values, $definition['fixed'] ?? []);
        if ($definition['scope'] !== 'none' && !isset($errors['condominium_id'])) {
            $condominium = $values['condominium_id'] ?? null;
            if ($condominium !== null && !$this->scope->canAccess($user, (int)$condominium)) {
                $errors['condominium_id'] = 'Condomínio fora do seu escopo de acesso.';
            } elseif ($condominium === null && $definition['scope'] === 'optional' && !$this->scope->hasGlobalAccess($user)) {
                $errors['condominium_id'] = sprintf('Somente quem acessa todos os condomínios pode cadastrar %s %s para todos. Selecione um condomínio.', $definition['gender'] === 'a' ? 'uma' : 'um', $definition['entity']);
            }
        }
        if ($errors === []) {
            $errors = $this->unitRules($definition, $values);
        }
        if ($errors === []) {
            $errors = $this->refRules($definition, $values);
        }
        if ($errors === []) {
            $errors = $this->businessRules($definition, $values, $id);
        }
        // Fechamento mensal (ADR 0031): não grava em competência fechada, nem tira um registro dela.
        if ($errors === [] && isset($definition['closing_lock'])) {
            $field = $definition['closing_lock'];
            foreach (array_filter([$before, $values]) as $state) {
                if ($this->isClosed($state['condominium_id'] ?? null, $state[$field] ?? null)) {
                    $errors[$field] = (new RegistryLocked((string)$state[$field]))->getMessage();
                    break;
                }
            }
        }
        // Registro gerado por outro módulo (ADR 0033/0039, fatura ou acordo): só a baixa muda aqui.
        if ($errors === [] && $before !== null && isset($definition['origin_lock']) && Definitions::lockedByOrigin($definition, $before)) {
            foreach ($definition['origin_lock']['columns'] as $column) {
                if (array_key_exists($column, $values) && (string)($values[$column] ?? '') !== (string)($before[$column] ?? '')) {
                    $errors[isset($definition['fields'][$column]) ? $column : '_form'] = $definition['origin_lock']['message'];
                }
            }
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
        if (isset($definition['closing_lock']) && $this->isClosed($row['condominium_id'] ?? null, $row[$definition['closing_lock']] ?? null)) {
            throw new RegistryLocked((string)$row[$definition['closing_lock']]);
        }
        // Gerado por outro módulo (fatura ou acordo, ADR 0033/0039): exclui-se pela origem.
        if (isset($definition['origin_lock']) && Definitions::lockedByOrigin($definition, $row)) {
            throw new RegistryInUse();
        }
        // Unidade com cadastros ativos vinculados não sai (eles perderiam a unidade escolhida).
        foreach ($definition['referenced_by'] ?? [] as $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE unit_id = :id AND deleted_at IS NULL");
            $stmt->execute(['id' => $id]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new RegistryInUse();
            }
        }
        // Registro apontado por campo 'ref' de outro cadastro ativo também não sai (ADR 0020).
        foreach (Definitions::all() as $other) {
            foreach ($other['fields'] as $name => $field) {
                if ($field['type'] !== 'ref' || ($field['ref'] ?? null) !== $slug) {
                    continue;
                }
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$other['table']}` WHERE `{$name}` = :id AND deleted_at IS NULL");
                $stmt->execute(['id' => $id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new RegistryInUse();
                }
            }
        }
        // Conta com contas-filhas ativas (mesmo plano) não sai (ADR 0018).
        if (isset($definition['hierarchy'])) {
            $field = $definition['hierarchy']['field'];
            $separator = $definition['hierarchy']['separator'];
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$definition['table']}` WHERE deleted_at IS NULL AND id <> :id AND `{$field}` LIKE :prefix
                AND (condominium_id <=> :condominium OR :condominium_is_null = 1)");
            $stmt->execute([
                'id' => $id,
                'prefix' => addcslashes((string)$row[$field] . $separator, '%_\\') . '%',
                'condominium' => $row['condominium_id'],
                'condominium_is_null' => $row['condominium_id'] === null ? 1 : 0,
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new RegistryInUse();
            }
        }
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
    /**
     * Quantidade de registros do cadastro no escopo do usuário, opcionalmente só de um
     * condomínio (dashboard, ADR 0030). Registros sem condomínio (escopo optional) só entram
     * na visão de todos os condomínios.
     */
    public function count(User $user, string $slug, ?int $condominiumId = null): int
    {
        $definition = self::definition($slug);
        [$where, $params] = $this->scopeWhere($user, $definition);
        if ($condominiumId !== null && $definition['scope'] !== 'none') {
            $where[] = 't.condominium_id = :f_condominium';
            $params['f_condominium'] = $condominiumId;
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$definition['table']}` t WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function condominiums(User $user): array
    {
        return $this->scope->allowed($user);
    }

    /**
     * Unidades ativas dos condomínios do escopo, para os campos do tipo 'unit' (ADR 0017).
     *
     * @return list<array{id:int,condominium_id:int,label:string}>
     */
    public function units(User $user): array
    {
        $ids = $this->scope->allowedIds($user);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = $this->pdo->query("SELECT id, condominium_id, label FROM erp_units WHERE deleted_at IS NULL AND status = 'active' AND condominium_id IN ({$in}) ORDER BY condominium_id, label")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'label' => (string)$r['label']], $rows);
    }

    /**
     * Opções dos campos 'ref' (ADR 0020): registros ativos do cadastro apontado, com os filtros
     * 'ref_where', dentro do escopo do usuário (registros sem condomínio valem para todos).
     *
     * @return array<string, list<array{id:int,condominium_id:?int,label:string}>> por nome do campo
     */
    public function references(User $user, array $definition): array
    {
        $options = [];
        foreach ($definition['fields'] as $name => $field) {
            if ($field['type'] !== 'ref') {
                continue;
            }
            $target = self::definition((string)$field['ref']);
            [$where, $params] = $this->scopeWhere($user, $target);
            $where[] = "t.status = 'active'";
            foreach ($field['ref_where'] ?? [] as $column => $value) {
                $where[] = "t.`{$column}` = :w_{$column}";
                $params["w_{$column}"] = $value;
            }
            $labels = implode(', ', array_map(static fn (string $c): string => "t.`{$c}`", $field['ref_label'] ?? ['id']));
            $condominium = $target['scope'] === 'none' ? 'NULL' : 't.condominium_id';
            $stmt = $this->pdo->prepare("SELECT t.id, {$condominium} AS condominium_id, CONCAT_WS(' — ', {$labels}) AS label
                FROM `{$target['table']}` t WHERE " . implode(' AND ', $where) . " ORDER BY t.`{$target['order']}`, t.id");
            $stmt->execute($params);
            $options[$name] = array_map(static fn (array $r): array => [
                'id' => (int)$r['id'],
                'condominium_id' => $r['condominium_id'] === null ? null : (int)$r['condominium_id'],
                'label' => (string)$r['label'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return $options;
    }

    /** Competência fechada no fechamento mensal (ADR 0031)? Sem condomínio ou competência, não. */
    public function isClosed(mixed $condominiumId, mixed $competence): bool
    {
        if ($condominiumId === null || $competence === null || $competence === '') {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM erp_month_closings WHERE condominium_id = :c AND competence = :m AND status = 'closed' LIMIT 1");
        $stmt->execute(['c' => (int)$condominiumId, 'm' => (string)$competence]);
        return (bool)$stmt->fetchColumn();
    }

    /** O cadastro tem algum campo de unidade? (a tela só carrega a lista quando precisa) */
    public static function hasUnitField(array $definition): bool
    {
        foreach ($definition['fields'] as $field) {
            if ($field['type'] === 'unit') {
                return true;
            }
        }
        return false;
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
        // 'base_where' (ADR 0024): visão filtrada de outra tabela (ex.: contas a pagar = despesas em
        // aberto de erp_entries). null = IS NULL. Vale para listagem, leitura, edição e exclusão.
        foreach ($definition['base_where'] ?? [] as $column => $value) {
            if ($value === null) {
                $where[] = "t.`{$column}` IS NULL";
            } else {
                $where[] = "t.`{$column}` = :bw_{$column}";
                $params["bw_{$column}"] = $value;
            }
        }
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

    /**
     * Campos do tipo 'unit' (ADR 0017): a unidade precisa existir, estar ativa e ser do mesmo
     * condomínio do registro. Com unidade escolhida, unit_label passa a ser o texto dela; sem
     * unidade e com texto, liga automaticamente à unidade de mesmo texto, se existir.
     *
     * @param array<string, mixed> $values alterado por referência
     * @return array<string, string>
     */
    private function unitRules(array $definition, array &$values): array
    {
        $errors = [];
        $condominium = $values['condominium_id'] ?? null;
        foreach ($definition['fields'] as $name => $field) {
            if ($field['type'] !== 'unit') {
                continue;
            }
            $hasText = array_key_exists('unit_label', $values);
            if ($values[$name] !== null) {
                $stmt = $this->pdo->prepare("SELECT condominium_id, label FROM erp_units WHERE id = :id AND deleted_at IS NULL AND status = 'active'");
                $stmt->execute(['id' => $values[$name]]);
                $unit = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($unit) || $condominium === null || (int)$unit['condominium_id'] !== (int)$condominium) {
                    $errors[$name] = 'Escolha uma unidade ativa do condomínio selecionado.';
                    continue;
                }
                if ($hasText) {
                    $values['unit_label'] = mb_substr((string)$unit['label'], 0, 40);
                }
            } elseif ($hasText && $values['unit_label'] !== null && $condominium !== null) {
                $stmt = $this->pdo->prepare("SELECT MIN(id) FROM erp_units WHERE condominium_id = :c AND label = :l AND deleted_at IS NULL AND status = 'active'");
                $stmt->execute(['c' => $condominium, 'l' => $values['unit_label']]);
                $match = $stmt->fetchColumn();
                $values[$name] = $match !== null && $match !== false ? (int)$match : null;
            }
        }
        return $errors;
    }

    /**
     * Campos 'ref' (ADR 0020): o registro apontado precisa existir ativo, atender aos filtros
     * 'ref_where' e valer para o condomínio do registro (sem condomínio no apontado = vale para
     * todos; registro sem condomínio só aponta para registros sem condomínio).
     *
     * @return array<string, string>
     */
    private function refRules(array $definition, array $values): array
    {
        $errors = [];
        foreach ($definition['fields'] as $name => $field) {
            if ($field['type'] !== 'ref' || $values[$name] === null) {
                continue;
            }
            $target = self::definition((string)$field['ref']);
            // 'ref_match' => [coluna do apontado => campo deste registro] (ex.: natureza da conta = natureza do lançamento).
            $columns = array_unique(array_merge(['status'], array_keys($field['ref_where'] ?? []), array_keys($field['ref_match'] ?? []), $target['scope'] === 'none' ? [] : ['condominium_id']));
            $stmt = $this->pdo->prepare('SELECT ' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns))
                . " FROM `{$target['table']}` WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute(['id' => $values[$name]]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $message = $field['ref_message'] ?? 'Escolha uma opção válida da lista.';
            if (!is_array($row) || $row['status'] !== 'active') {
                $errors[$name] = $message;
                continue;
            }
            foreach ($field['ref_where'] ?? [] as $column => $expected) {
                if ((string)$row[$column] !== (string)$expected) {
                    $errors[$name] = $message;
                    continue 2;
                }
            }
            foreach ($field['ref_match'] ?? [] as $column => $ownField) {
                if ((string)$row[$column] !== (string)($values[$ownField] ?? '')) {
                    $errors[$name] = $field['ref_match_message'] ?? $message;
                    continue 2;
                }
            }
            if ($target['scope'] !== 'none' && $row['condominium_id'] !== null) {
                $own = $values['condominium_id'] ?? null;
                if ($own === null || (int)$row['condominium_id'] !== (int)$own) {
                    $errors[$name] = 'Esta opção pertence a outro condomínio.';
                }
            }
        }
        return $errors;
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
                    // 'nullable': vazio conta como valor (ex.: unidade "101" sem bloco).
                    if (in_array($field, $rule['nullable'] ?? [], true)) {
                        $where[] = "(`{$field}` IS NULL OR `{$field}` = '')";
                        continue;
                    }
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
        if (isset($definition['hierarchy']) && !isset($errors[$definition['hierarchy']['field']])) {
            $errors += $this->hierarchyRules($definition, $values);
        }
        return $errors;
    }

    /**
     * Código hierárquico (plano de contas, ADR 0018): a conta-pai (código sem o último segmento)
     * precisa existir ativa no mesmo plano ou no plano padrão, ser do tipo indicado e ter o
     * mesmo valor em 'same' (natureza). Conta de condomínio não repete código do plano padrão.
     *
     * @return array<string, string>
     */
    private function hierarchyRules(array $definition, array $values): array
    {
        $rule = $definition['hierarchy'];
        $field = $rule['field'];
        $code = (string)$values[$field];
        $condominium = $values['condominium_id'] ?? null;
        $table = $definition['table'];

        if ($condominium !== null) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM `{$table}` WHERE deleted_at IS NULL AND condominium_id IS NULL AND `{$field}` = :code LIMIT 1");
            $stmt->execute(['code' => $code]);
            if ($stmt->fetchColumn()) {
                return [$field => 'Este código já existe no plano padrão da Connect. Use outro código para a conta do condomínio.'];
            }
        }

        $segments = explode($rule['separator'], $code);
        if (count($segments) < 2) {
            return [];
        }
        $parentCode = implode($rule['separator'], array_slice($segments, 0, -1));
        [$kindField, $kindValue] = $rule['parent_kind'];
        $same = $rule['same'];
        $stmt = $this->pdo->prepare("SELECT `{$kindField}` AS kind, `{$same}` AS same_value FROM `{$table}`
            WHERE deleted_at IS NULL AND status = 'active' AND `{$field}` = :code AND (condominium_id IS NULL OR condominium_id <=> :condominium)
            ORDER BY condominium_id IS NULL LIMIT 1");
        $stmt->execute(['code' => $parentCode, 'condominium' => $condominium]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($parent)) {
            return [$field => "A conta-pai {$parentCode} não existe neste plano. Cadastre-a antes."];
        }
        if ($parent['kind'] !== $kindValue) {
            return [$field => "A conta-pai {$parentCode} é analítica e não pode ter contas abaixo dela."];
        }
        if ($parent['same_value'] !== ($values[$same] ?? null)) {
            return [$same => 'A natureza precisa ser a mesma da conta-pai ' . $parentCode . '.'];
        }
        return [];
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
                in_array($field['type'], ['condominium', 'unit', 'ref', 'money', 'int'], true) => (int)$value,
                $field['type'] === 'time' => substr((string)$value, 0, 5),
                in_array($field['type'], ['percent', 'decimal'], true) => (float)$value,
                default => (string)$value,
            };
        }
        // Colunas geradas pelo banco, somente leitura (ex.: erp_units.label).
        foreach ($definition['computed'] ?? [] as $column) {
            $out[$column] = isset($row[$column]) ? (string)$row[$column] : null;
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
