<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Support\Audit;
use Source\Support\Erp\BrDocument;

/**
 * Cadastro de condomínios do ERP (AP-004). Administradora única (ADR-002): criar ou
 * alterar um condomínio é ato de quem acessa todos eles (erp.condominiums.manage +
 * erp.condominiums.all); a checagem fica no controller, a regra de dados fica aqui.
 * Sem exclusão: um condomínio que sai da carteira fica inativo, preservando os cadastros.
 */
final class CondominiumService
{
    public const STATUS = ['active' => 'Ativo', 'inactive' => 'Inativo'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /**
     * Normaliza e valida a entrada do formulário. Não acessa o banco.
     *
     * @param array<string, mixed> $input
     * @return array{0: array{name: string, document: ?string, status: string}, 1: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $name = trim(preg_replace('/\s+/u', ' ', (string)(is_scalar($input['name'] ?? null) ? $input['name'] : '')) ?? '');
        if ($name === '') {
            $errors['name'] = 'Informe o nome do condomínio.';
        } elseif (mb_strlen($name) > 160) {
            $errors['name'] = 'Use no máximo 160 caracteres.';
        }

        $documentRaw = trim((string)(is_scalar($input['document'] ?? null) ? $input['document'] : ''));
        $document = null;
        if ($documentRaw !== '') {
            if (BrDocument::isCnpj($documentRaw)) {
                $document = BrDocument::digits($documentRaw);
            } else {
                $errors['document'] = 'CNPJ inválido.';
            }
        }

        $status = (string)(is_scalar($input['status'] ?? null) ? $input['status'] : 'active');
        if (!array_key_exists($status, self::STATUS)) {
            $errors['status'] = 'Selecione uma situação válida.';
            $status = 'active';
        }

        return [['name' => $name, 'document' => $document, 'status' => $status], $errors];
    }

    /** @return list<array{id:int,name:string,document:?string,status:string}> Todos, inclusive inativos. */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT id, name, document, status FROM condominiums ORDER BY status = \'active\' DESC, name')->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'row'], $rows);
    }

    /** @return array{id:int,name:string,document:?string,status:string}|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, name, document, status FROM condominiums WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /**
     * Cria (id null) ou altera um condomínio.
     *
     * @param array<string, mixed> $input
     * @throws CondominiumValidationException
     * @return int ID do condomínio gravado
     */
    public function save(array $input, ?int $id = null): int
    {
        [$data, $errors] = self::validate($input);
        $before = null;
        if ($id !== null) {
            $before = $this->find($id);
            if ($before === null) {
                throw new CondominiumValidationException(['_form' => 'Condomínio não encontrado.']);
            }
        }
        if ($data['document'] !== null && !isset($errors['document']) && $this->documentTaken($data['document'], $id)) {
            $errors['document'] = 'Já existe um condomínio com este CNPJ.';
        }
        if ($errors !== []) {
            throw new CondominiumValidationException($errors);
        }

        try {
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO condominiums (name, document, status) VALUES (:name, :document, :status)');
                $stmt->execute($data);
                $id = (int)$this->pdo->lastInsertId();
            } else {
                $stmt = $this->pdo->prepare('UPDATE condominiums SET name = :name, document = :document, status = :status WHERE id = :id');
                $stmt->execute($data + ['id' => $id]);
            }
        } catch (PDOException $exception) {
            // Corrida com outro cadastro do mesmo CNPJ (uq_condominiums_document).
            if ($exception->getCode() === '23000') {
                throw new CondominiumValidationException(['document' => 'Já existe um condomínio com este CNPJ.']);
            }
            throw $exception;
        }

        $after = ['name' => $data['name'], 'document' => $data['document'], 'status' => $data['status']];
        Audit::record($before === null ? 'create' : 'update', 'condominiums', $id,
            $before === null ? null : ['name' => $before['name'], 'document' => $before['document'], 'status' => $before['status']], $after);
        return $id;
    }

    private function documentTaken(string $document, ?int $exceptId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM condominiums WHERE document = :document AND id <> :id LIMIT 1');
        $stmt->execute(['document' => $document, 'id' => $exceptId ?? 0]);
        return (bool)$stmt->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'name' => (string)$row['name'],
            'document' => $row['document'] !== null ? (string)$row['document'] : null, 'status' => (string)$row['status'],
        ];
    }
}
