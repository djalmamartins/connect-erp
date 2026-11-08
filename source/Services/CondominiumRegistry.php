<?php

declare(strict_types=1);

namespace Source\Services;

use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Cadastro e edição de condomínios (AP-004). Única implementação de escrita: as páginas
 * do ERP e uma futura API chamam este serviço. Exige erp.condominiums.manage e, na
 * edição, que o condomínio esteja no escopo do usuário (CondominiumScope).
 */
final class CondominiumRegistry
{
    public const MANAGE_PERMISSION = 'erp.condominiums.manage';

    private PDO $pdo;
    private CondominiumScope $scope;
    private CondominiumValidator $validator;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?CondominiumValidator $validator = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->validator = $validator ?? new CondominiumValidator();
    }

    /** Registro completo dentro do escopo do usuário (ativo ou inativo), ou null. */
    public function find(?User $user, int $id): ?array
    {
        if ($user === null || $id <= 0 || !$this->inScope($user, $id)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, ' . implode(', ', CondominiumValidator::FIELDS) . ' FROM condominiums WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{ok: bool, id: ?int, errors: array<string, string>, data: array}
     */
    public function create(User $user, array $input): array
    {
        $this->assertCanManage($user);
        ['data' => $data, 'errors' => $errors] = $this->validator->validate($input);
        $errors += $this->uniqueDocument($data['document'], null);
        if ($errors !== []) {
            return ['ok' => false, 'id' => null, 'errors' => $errors, 'data' => $data];
        }

        $columns = CondominiumValidator::FIELDS;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO condominiums (' . implode(', ', $columns) . ', created_by, updated_by) VALUES (:'
                . implode(', :', $columns) . ', :created_by, :updated_by)');
            $stmt->execute($this->params($data) + ['created_by' => (int)$user->id, 'updated_by' => (int)$user->id]);
            $id = (int)$this->pdo->lastInsertId();
            // Sem acesso global, quem cadastra recebe o vínculo para enxergar o condomínio criado.
            if (!$this->scope->hasGlobalAccess($user)) {
                $this->scope->grant((int)$user->id, $id, (int)$user->id);
            }
            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            if ($this->isDuplicate($exception)) {
                return ['ok' => false, 'id' => null, 'errors' => ['document' => 'Já existe um condomínio com este CNPJ.'], 'data' => $data];
            }
            throw $exception;
        }
        Audit::record('create', 'condominiums', $id, null, $data);
        return ['ok' => true, 'id' => $id, 'errors' => [], 'data' => $data];
    }

    /**
     * @return array{ok: bool, id: ?int, errors: array<string, string>, data: array}
     */
    public function update(User $user, int $id, array $input): array
    {
        $this->assertCanManage($user);
        $before = $this->find($user, $id);
        if ($before === null) {
            throw new CondominiumAccessDenied($id);
        }
        ['data' => $data, 'errors' => $errors] = $this->validator->validate($input);
        $errors += $this->uniqueDocument($data['document'], $id);
        if ($errors !== []) {
            return ['ok' => false, 'id' => $id, 'errors' => $errors, 'data' => $data];
        }

        $assignments = implode(', ', array_map(static fn(string $column): string => "{$column} = :{$column}", CondominiumValidator::FIELDS));
        try {
            $stmt = $this->pdo->prepare("UPDATE condominiums SET {$assignments}, updated_by = :updated_by WHERE id = :id");
            $stmt->execute($this->params($data) + ['updated_by' => (int)$user->id, 'id' => $id]);
        } catch (PDOException $exception) {
            if ($this->isDuplicate($exception)) {
                return ['ok' => false, 'id' => $id, 'errors' => ['document' => 'Já existe um condomínio com este CNPJ.'], 'data' => $data];
            }
            throw $exception;
        }
        unset($before['id']);
        Audit::record('update', 'condominiums', $id, $before, $data);
        return ['ok' => true, 'id' => $id, 'errors' => [], 'data' => $data];
    }

    public function canManage(?User $user): bool
    {
        return $user !== null && Access::can('erp.access', $user) && Access::can(self::MANAGE_PERMISSION, $user);
    }

    private function assertCanManage(User $user): void
    {
        if (!$this->canManage($user)) {
            throw new \DomainException('Sem permissão para gerenciar condomínios.');
        }
    }

    /** Edição alcança também condomínios inativos do escopo (CondominiumScope só lista ativos). */
    private function inScope(User $user, int $id): bool
    {
        if ($this->scope->hasGlobalAccess($user)) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM condominiums WHERE id = :id');
            $stmt->execute(['id' => $id]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM user_condominium_access WHERE user_id = :user AND condominium_id = :id');
            $stmt->execute(['user' => (int)$user->id, 'id' => $id]);
        }
        return (bool)$stmt->fetchColumn();
    }

    /** @return array<string, string> */
    private function uniqueDocument(?string $document, ?int $ignoreId): array
    {
        if ($document === null || $document === '') {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id FROM condominiums WHERE document = :document AND (:ignore IS NULL OR id <> :ignore_check) LIMIT 1');
        $stmt->execute(['document' => $document, 'ignore' => $ignoreId, 'ignore_check' => $ignoreId]);
        return $stmt->fetchColumn() === false ? [] : ['document' => 'Já existe um condomínio com este CNPJ.'];
    }

    private function params(array $data): array
    {
        $params = [];
        foreach (CondominiumValidator::FIELDS as $column) {
            $params[$column] = $data[$column] ?? null;
        }
        return $params;
    }

    private function isDuplicate(PDOException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062;
    }
}
