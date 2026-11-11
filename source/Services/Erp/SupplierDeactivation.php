<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Inativação de fornecedores em lote (ADR 0202): limpa o cadastro comum de fornecedores, por exemplo os "sem uso em 24
 * meses" da conferência do cadastro (ADR 0200, dormant), de uma vez e com motivo. Inativar não apaga nada: o histórico, os
 * lançamentos e a auditoria ficam; o fornecedor só deixa de aparecer para novos lançamentos e pode voltar pelo formulário.
 *
 * - Cadastro comum a todos os condomínios (scope none): só quem acessa todos os condomínios pode inativar em lote, porque
 *   a recusa olha as contas de todos; os outros recebem RegistryForbidden (403).
 * - Recusa (regra pura problem): fornecedor não encontrado ou excluído; já inativo; com conta a pagar em aberto (ativa,
 *   não paga) em qualquer condomínio; com lançamento recorrente ativo (geraria conta para um inativo); com empenho ativo.
 * - Tudo ou nada, numa transação com os fornecedores travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form e _supplier). dry_run passa por tudo e desfaz no fim (prévia).
 * - Auditoria update por fornecedor (status active → inactive, com o motivo e o tamanho do lote), só quando grava.
 *
 * Saem id, nomes, documento mascarado e a data do último lançamento; e-mail, telefone, banco e Pix não saem.
 */
final class SupplierDeactivation
{
    public const MAX_SUPPLIERS = 100;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return list<int> */
    public static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Escolha os fornecedores.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Fornecedores: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_SUPPLIERS) {
            throw new InvalidArgumentException(sprintf('Inative até %d fornecedores por vez.', self::MAX_SUPPLIERS));
        }
        return array_values($ids);
    }

    /**
     * Motivo da recusa (regra pura); null quando pode inativar.
     *
     * @param array{count:int,cents:int}|null $open
     */
    public static function problem(?array $supplier, ?array $open, bool $recurring, bool $commitment): ?string
    {
        return match (true) {
            $supplier === null => 'Fornecedor não encontrado.',
            ($supplier['status'] ?? '') !== 'active' => 'Fornecedor já está inativo.',
            $open !== null && $open['count'] > 0 => sprintf('Fornecedor com %d conta(s) a pagar em aberto (R$ %s): pague ou cancele antes.',
                $open['count'], number_format($open['cents'] / 100, 2, ',', '.')),
            $recurring => 'Fornecedor com lançamento recorrente ativo: encerre a recorrência antes.',
            $commitment => 'Fornecedor com empenho ativo: liquide ou cancele o empenho antes.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function deactivate(User $user, array $input): array
    {
        $ids = self::ids($input['supplier_ids'] ?? null);
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo da inativação (5 a 255 caracteres).');
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$this->scope->hasGlobalAccess($user)) {
            throw new RegistryForbidden('Só quem acessa todos os condomínios inativa fornecedores em lote.');
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $result = ['dry_run' => $dryRun, 'reason' => $reason, 'count' => count($ids), 'suppliers' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id, legal_name, trade_name, document, status FROM erp_suppliers WHERE deleted_at IS NULL AND id IN ({$marks}) FOR UPDATE");
            $stmt->execute($ids);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            $stmt = $this->pdo->prepare("SELECT supplier_id, COUNT(*) AS n, SUM(amount_cents) AS cents FROM erp_entries
                WHERE supplier_id IN ({$marks}) AND nature = 'despesa' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL GROUP BY supplier_id");
            $stmt->execute($ids);
            $open = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $open[(int)$r['supplier_id']] = ['count' => (int)$r['n'], 'cents' => (int)$r['cents']];
            }
            $recurring = $this->flags("SELECT DISTINCT supplier_id FROM erp_recurring_entries WHERE status = 'active' AND deleted_at IS NULL AND supplier_id IN ({$marks})", $ids);
            $commitments = $this->flags("SELECT DISTINCT supplier_id FROM erp_commitments WHERE status = 'active' AND deleted_at IS NULL AND supplier_id IN ({$marks})", $ids);
            $stmt = $this->pdo->prepare("SELECT supplier_id, MAX(due_on) FROM erp_entries WHERE supplier_id IN ({$marks}) AND deleted_at IS NULL GROUP BY supplier_id");
            $stmt->execute($ids);
            $lastUsed = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $update = $this->pdo->prepare("UPDATE erp_suppliers SET status = 'inactive', updated_by = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'active' AND deleted_at IS NULL");
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                $problem = self::problem($row, $open[$id] ?? null, isset($recurring[$id]), isset($commitments[$id]));
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_supplier' => (string)$id]);
                }
                $update->execute([(int)$user->id, $id]);
                $result['suppliers'][] = ['supplier_id' => $id, 'legal_name' => (string)$row['legal_name'], 'trade_name' => $row['trade_name'] === null ? null : (string)$row['trade_name'],
                    'document_masked' => CollectionReports::maskDocument($row['document']),
                    'last_used_on' => isset($lastUsed[$id]) ? substr((string)$lastUsed[$id], 0, 10) : null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['suppliers'] as $supplier) {
                Audit::record('update', 'erp_suppliers', $supplier['supplier_id'], ['status' => 'active'],
                    ['status' => 'inactive', 'reason' => $reason, 'batch' => count($ids)]);
            }
        }
        $result['changed'] = $dryRun ? 0 : count($result['suppliers']);
        return $result;
    }

    /** @return array<int, true> */
    private function flags(string $sql, array $ids): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }
}
