<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Estorno em lote da baixa (ADR 0192), o caminho de volta da baixa em lote (ADR 0191): devolve para "em aberto" várias
 * contas pagas de um condomínio de uma vez (pagamento lançado na data ou na conta errada, lote baixado por engano).
 *
 * Cada conta precisa estar paga, válida e no condomínio, e não pode:
 * - estar em competência fechada (ADR 0031);
 * - ter recibo válido (RECEIPT_LOCK, ADR 0057): cancela-se o recibo antes, como no formulário;
 * - estar conciliada com uma linha de extrato importado (ADR 0058): a linha do banco ficaria apontando para uma conta em
 *   aberto, e o lote não desfaz conciliação;
 * - ter boleto que o banco confirmou como pago (erp_bank_charges.status = paid): o banco diz que entrou dinheiro.
 * O motivo é obrigatório (5 a 255 caracteres) e vai para a auditoria de cada conta.
 *
 * Tudo ou nada, como a baixa: numa transação, com as contas travadas (FOR UPDATE) e o UPDATE condicionado à baixa que
 * foi conferida (mesmo paid_on); qualquer problema recusa o lote (BatchSettlementRejected, com o motivo de cada conta).
 * dry_run confere e devolve os totais sem gravar. A conta bancária fica no lançamento como sugestão, como no formulário.
 *
 * Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404). Até BatchSettlement::MAX_ENTRIES.
 */
final class BatchReversal
{
    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @param array{condominium_id?:mixed, entry_ids?:mixed, reason?:mixed, dry_run?:mixed} $input */
    public function reverse(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do estorno (5 a 255 caracteres).');
        }
        $ids = BatchSettlement::ids($input['entry_ids'] ?? null);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id, nature, description, amount_cents, competence, due_on, paid_on, bank_account_id, status FROM erp_entries
                WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ({$marks}) FOR UPDATE");
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            $receipts = $this->ids("SELECT de.entry_id FROM erp_unit_document_entries de JOIN erp_unit_documents d ON d.id = de.document_id
                WHERE d.status = 'issued' AND de.entry_id IN ({$marks})", $ids);
            $reconciled = $this->ids("SELECT entry_id FROM erp_bank_statement_lines WHERE status = 'matched' AND entry_id IN ({$marks})", $ids);
            $bankPaid = $this->ids("SELECT entry_id FROM erp_bank_charges WHERE status = 'paid' AND entry_id IN ({$marks})", $ids);
            $registry = new Registry\RegistryService($this->pdo, $this->scope);
            $problems = [];
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                $problem = match (true) {
                    $row === null => ['not_found', 'Conta não encontrada neste condomínio.'],
                    $row['status'] !== 'active' => ['cancelled', 'Conta cancelada.'],
                    $row['paid_on'] === null => ['not_paid', 'Conta em aberto: não há baixa para estornar.'],
                    $registry->isClosed((int)$condominium, $row['competence']) => ['closed_competence', 'A competência ' . $row['competence'] . ' está fechada.'],
                    isset($receipts[$id]) => ['receipt', Registry\Definitions::RECEIPT_LOCK['message']],
                    isset($reconciled[$id]) => ['reconciled', 'Conta conciliada com o extrato bancário: o estorno em lote não desfaz conciliação.'],
                    isset($bankPaid[$id]) => ['bank_confirmed', 'O banco confirmou o pagamento do boleto desta conta.'],
                    default => null,
                };
                if ($problem !== null) {
                    $problems[] = ['entry_id' => $id, 'code' => $problem[0], 'message' => $problem[1]];
                }
            }
            if ($problems !== []) {
                throw new BatchSettlementRejected($problems);
            }
            $totals = ['receita_cents' => 0, 'despesa_cents' => 0];
            foreach ($ids as $id) {
                $totals[$rows[$id]['nature'] . '_cents'] += (int)$rows[$id]['amount_cents'];
            }
            if (!$dryRun) {
                $update = $this->pdo->prepare('UPDATE erp_entries SET paid_on = NULL, updated_by = :u
                    WHERE id = :id AND condominium_id = :c AND paid_on = :p AND deleted_at IS NULL');
                foreach ($ids as $id) {
                    $update->execute(['u' => (int)$user->id, 'id' => $id, 'c' => $condominium, 'p' => $rows[$id]['paid_on']]);
                    if ($update->rowCount() !== 1) {
                        throw new BatchSettlementRejected([['entry_id' => $id, 'code' => 'changed', 'message' => 'A baixa desta conta foi alterada por outra pessoa agora há pouco.']]);
                    }
                }
                $this->pdo->commit();
            } else {
                $this->pdo->rollBack();
            }
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($ids as $id) {
                Audit::record('update', 'erp_entries', $id, ['paid_on' => $rows[$id]['paid_on']],
                    ['paid_on' => null, 'batch_reversal' => count($ids), 'reason' => $reason]);
            }
        }
        return ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reversed' => $dryRun ? 0 : count($ids), 'count' => count($ids), 'reason' => $reason,
            'totals' => $totals + ['net_cents' => $totals['receita_cents'] - $totals['despesa_cents']],
            'entries' => array_map(static fn (int $id): array => ['id' => $id, 'nature' => (string)$rows[$id]['nature'], 'description' => (string)$rows[$id]['description'],
                'amount_cents' => (int)$rows[$id]['amount_cents'], 'competence' => (string)$rows[$id]['competence'], 'paid_on' => (string)$rows[$id]['paid_on'],
                'bank_account_id' => $rows[$id]['bank_account_id'] === null ? null : (int)$rows[$id]['bank_account_id']], $ids)];
    }

    /** @return array<int, true> os ids do lote que aparecem na consulta (marcadores ? na ordem de $ids) */
    private function ids(string $sql, array $ids): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }
}
