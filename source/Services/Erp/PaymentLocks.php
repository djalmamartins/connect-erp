<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;

/**
 * Travas ligadas ao banco (ADR 0192, 0193 e 0194), usadas pelo estorno em lote (BatchReversal) e pelo formulário
 * (RegistryService: estorno, cancelamento e exclusão): a baixa de uma conta não volta para "em aberto", e a conta não é
 * cancelada nem excluída, quando
 * - a conta está conciliada com uma linha de extrato importado (erp_bank_statement_lines.status = matched, ADR 0058): a
 *   linha do banco ficaria apontando para uma conta em aberto;
 * - o banco confirmou o pagamento do boleto da conta (erp_bank_charges.status = paid, ADR 0035).
 * Só leitura; nada do fluxo do banco muda.
 */
final class PaymentLocks
{
    public const RECONCILED = 'Conta conciliada com o extrato bancário: o estorno não desfaz a conciliação.';
    public const BANK_CONFIRMED = 'O banco confirmou o pagamento do boleto desta conta.';
    /** ADR 0194: excluir ou cancelar a conta pelo formulário. */
    public const RECONCILED_REMOVAL = 'Conta conciliada com o extrato bancário: não pode ser excluída nem cancelada enquanto a conciliação existir.';
    public const BANK_CONFIRMED_REMOVAL = 'O banco confirmou o pagamento do boleto desta conta: ela não pode ser excluída nem cancelada.';

    /**
     * Trava de exclusão ou cancelamento de uma conta (ADR 0194): o mesmo motivo do find, com a mensagem de remoção.
     *
     * @return array{code:string, message:string}|null
     */
    public static function removal(PDO $pdo, int $entryId): ?array
    {
        $lock = self::find($pdo, [$entryId])[$entryId] ?? null;
        if ($lock === null) {
            return null;
        }
        return ['code' => $lock['code'], 'message' => $lock['code'] === 'reconciled' ? self::RECONCILED_REMOVAL : self::BANK_CONFIRMED_REMOVAL];
    }

    /**
     * As contas da lista que não podem ter a baixa estornada, com o motivo (conciliação primeiro).
     *
     * @param list<int> $entryIds
     * @return array<int, array{code:string, message:string}>
     */
    public static function find(PDO $pdo, array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($entryIds), '?'));
        $out = [];
        foreach ([['reconciled', self::RECONCILED, "SELECT entry_id FROM erp_bank_statement_lines WHERE status = 'matched' AND entry_id IN ({$marks})"],
            ['bank_confirmed', self::BANK_CONFIRMED, "SELECT entry_id FROM erp_bank_charges WHERE status = 'paid' AND entry_id IN ({$marks})"]] as [$code, $message, $sql]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($entryIds);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[(int)$id] ??= ['code' => $code, 'message' => $message];
            }
        }
        return $out;
    }
}
