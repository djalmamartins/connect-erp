<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Cancelamento de contas em lote (ADR 0203): cancela várias contas em aberto de um condomínio de uma vez (importação
 * em dobro, contrato encerrado com parcelas futuras lançadas, despesa lançada no condomínio errado), com motivo. Cancelar
 * não apaga: a conta fica com a situação "Cancelado", sai dos relatórios e da cobrança, e a auditoria guarda o antes.
 *
 * Recusa (regra pura problem): conta não encontrada no condomínio; já cancelada; já paga (estorne a baixa antes, ADR
 * 0192); com boleto pendente ou registrado no banco (cancele pela cobrança: o título continuaria no banco). Boleto pago,
 * conciliação, recibo válido, origem (fatura, acordo, cobrança extra, multa) e competência fechada são recusados pelas
 * regras do formulário, porque cada conta é regravada pelo RegistryService::save do cadastro "entries" só com a situação
 * trocada (ADR 0031/0033/0057/0100/0194); a auditoria update vem dele, e um registro cancel guarda o motivo.
 *
 * Tudo ou nada, numa transação com as contas travadas (FOR UPDATE): uma recusada desfaz todas
 * (RegistryValidationException com os erros e _entry). dry_run passa por tudo e desfaz no fim. Um condomínio só, do
 * escopo (fora = CondominiumAccessDenied, 404); até BatchSettlement::MAX_ENTRIES contas; motivo obrigatório.
 */
final class EntryCancellation
{
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
            throw new InvalidArgumentException('Escolha as contas a cancelar.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Contas: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > BatchSettlement::MAX_ENTRIES) {
            throw new InvalidArgumentException(sprintf('Cancele até %d contas por vez.', BatchSettlement::MAX_ENTRIES));
        }
        return array_values($ids);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando segue para o formulário. */
    public static function problem(?array $row, bool $bankCharge): ?string
    {
        return match (true) {
            $row === null => 'Lançamento não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'Conta já cancelada.',
            ($row['paid_on'] ?? null) !== null => 'Conta já paga: estorne a baixa antes de cancelar.',
            $bankCharge => 'Conta com boleto pendente ou registrado no banco: cancele pela cobrança.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function cancel(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do cancelamento (5 a 255 caracteres).');
        }
        $ids = self::ids($input['entry_ids'] ?? null);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('entries')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'count' => count($ids), 'total_cents' => 0, 'entries' => []];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . " FROM erp_entries WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ({$marks}) FOR UPDATE");
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            $charges = $this->pdo->prepare("SELECT DISTINCT entry_id FROM erp_bank_charges WHERE status IN ('pending', 'registered') AND entry_id IN ({$marks})");
            $charges->execute($ids);
            $charged = array_fill_keys(array_map('intval', $charges->fetchAll(PDO::FETCH_COLUMN)), true);
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                $problem = self::problem($row, isset($charged[$id]));
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_entry' => (string)$id]);
                }
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = $field === 'amount_cents' ? number_format(((int)$row[$field]) / 100, 2, ',', '') : $row[$field];
                }
                try {
                    // Também no dry_run: grava e desfaz no fim (rollback), para a prévia já trazer as recusas do formulário.
                    $registry->save($user, 'entries', array_merge($data, ['status' => 'inactive']), $id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_entry' => (string)$id]);
                }
                $result['total_cents'] += (int)$row['amount_cents'];
                $result['entries'][] = ['id' => $id, 'description' => (string)$row['description'], 'nature' => (string)$row['nature'],
                    'competence' => (string)$row['competence'], 'due_on' => (string)$row['due_on'], 'amount_cents' => (int)$row['amount_cents']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['entries'] as $entry) {
                Audit::record('cancel', 'erp_entries', $entry['id'], ['status' => 'active'], ['status' => 'inactive', 'reason' => $reason, 'batch' => count($ids)]);
            }
        }
        $result['changed'] = $dryRun ? 0 : count($result['entries']);
        return $result;
    }
}
