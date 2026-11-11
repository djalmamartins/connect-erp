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
 * Baixa em lote (ADR 0191): marca como pagas, numa data e numa conta bancária, várias contas em aberto de um
 * condomínio de uma vez (o fechamento do dia da administradora: as contas a pagar pagas no banco, ou as receitas
 * conferidas no extrato). Antes, era preciso editar lançamento por lançamento.
 *
 * Mesmas regras da baixa pelo formulário (RegistryService, Definitions 'entries'):
 * - cada conta é do condomínio, válida (não cancelada nem excluída) e está em aberto;
 * - a competência da conta não está fechada (ADR 0031);
 * - a conta bancária está ativa e é do condomínio; o pagamento não é antes da abertura dela nem depois de hoje;
 * - conta gerada por fatura ou acordo pode ser baixada (a trava de origem só protege os outros campos).
 *
 * Tudo ou nada: numa transação, com as contas travadas (FOR UPDATE) e o UPDATE condicionado a paid_on IS NULL; qualquer
 * problema recusa o lote inteiro (BatchSettlementRejected, com o motivo de cada conta). dry_run confere e devolve os
 * totais sem gravar. Cada conta baixada é auditada como a edição do formulário. A conta é baixada pelo valor dela:
 * juros, multa ou desconto continuam pelos atalhos do lançamento (ADR 0057).
 *
 * Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404). Até MAX_ENTRIES contas por lote.
 */
final class BatchSettlement
{
    public const MAX_ENTRIES = 200;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Ids do lote (regra pura): inteiros positivos, sem repetição, de 1 a MAX_ENTRIES.
     *
     * @return list<int>
     */
    public static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Escolha as contas da baixa.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Contas: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException(sprintf('Baixe até %d contas por vez.', self::MAX_ENTRIES));
        }
        return array_values($ids);
    }

    /** @param array{condominium_id?:mixed, bank_account_id?:mixed, paid_on?:mixed, entry_ids?:mixed, dry_run?:mixed} $input */
    public function settle(User $user, array $input, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $bank = filter_var($input['bank_account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($bank === false || $bank === null) {
            throw new InvalidArgumentException('Escolha a conta bancária do pagamento.');
        }
        $paidOn = $input['paid_on'] ?? null;
        $date = is_string($paidOn) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn) : false;
        if ($date === false || $date->format('Y-m-d') !== $paidOn) {
            throw new InvalidArgumentException('Informe a data do pagamento (AAAA-MM-DD).');
        }
        if ($paidOn > $today) {
            throw new InvalidArgumentException('A data do pagamento não pode ser futura.');
        }
        $ids = self::ids($input['entry_ids'] ?? null);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $account = $this->pdo->prepare("SELECT id, name, opened_on FROM erp_bank_accounts WHERE id = :b AND condominium_id = :c AND status = 'active' AND deleted_at IS NULL");
        $account->execute(['b' => $bank, 'c' => $condominium]);
        $account = $account->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new InvalidArgumentException('Escolha uma conta bancária ativa deste condomínio.');
        }
        if ($account['opened_on'] !== null && $paidOn < (string)$account['opened_on']) {
            throw new InvalidArgumentException(sprintf('A conta bancária foi aberta em %s: o pagamento não pode ser antes disso.', $account['opened_on']));
        }

        $this->pdo->beginTransaction();
        try {
            $rows = $this->lock((int)$condominium, $ids);
            $registry = new Registry\RegistryService($this->pdo, $this->scope);
            $problems = [];
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                $problem = match (true) {
                    $row === null => ['not_found', 'Conta não encontrada neste condomínio.'],
                    $row['status'] !== 'active' => ['cancelled', 'Conta cancelada.'],
                    $row['paid_on'] !== null => ['already_paid', 'Conta já paga em ' . $row['paid_on'] . '.'],
                    $registry->isClosed((int)$condominium, $row['competence']) => ['closed_competence', 'A competência ' . $row['competence'] . ' está fechada.'],
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
                $update = $this->pdo->prepare('UPDATE erp_entries SET paid_on = :p, bank_account_id = :b, updated_by = :u
                    WHERE id = :id AND condominium_id = :c AND paid_on IS NULL AND deleted_at IS NULL');
                foreach ($ids as $id) {
                    $update->execute(['p' => $paidOn, 'b' => $bank, 'u' => (int)$user->id, 'id' => $id, 'c' => $condominium]);
                    if ($update->rowCount() !== 1) {
                        throw new BatchSettlementRejected([['entry_id' => $id, 'code' => 'already_paid', 'message' => 'A conta foi baixada por outra pessoa agora há pouco.']]);
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
                Audit::record('update', 'erp_entries', $id, ['paid_on' => null, 'bank_account_id' => $rows[$id]['bank_account_id']],
                    ['paid_on' => $paidOn, 'bank_account_id' => $bank, 'batch_settlement' => count($ids)]);
            }
        }
        return ['condominium_id' => (int)$condominium, 'bank_account_id' => $bank, 'bank_account' => (string)$account['name'], 'paid_on' => $paidOn,
            'dry_run' => $dryRun, 'settled' => $dryRun ? 0 : count($ids), 'count' => count($ids),
            'totals' => $totals + ['net_cents' => $totals['receita_cents'] - $totals['despesa_cents']],
            'entries' => array_map(static fn (int $id): array => ['id' => $id, 'nature' => (string)$rows[$id]['nature'], 'description' => (string)$rows[$id]['description'],
                'amount_cents' => (int)$rows[$id]['amount_cents'], 'competence' => (string)$rows[$id]['competence'], 'due_on' => (string)$rows[$id]['due_on']], $ids)];
    }

    /** @return array<int, array<string, mixed>> as contas do lote no condomínio, travadas até o fim da transação */
    private function lock(int $condominiumId, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, nature, description, amount_cents, competence, due_on, paid_on, bank_account_id, status FROM erp_entries
            WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ({$marks}) FOR UPDATE");
        $stmt->execute(array_merge([$condominiumId], $ids));
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int)$r['id']] = $r;
        }
        return $rows;
    }
}
