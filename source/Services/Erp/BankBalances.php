<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Saldo de cada conta bancária (ADR 0024): saldo inicial + receitas pagas − despesas pagas.
 *
 * Entram só lançamentos válidos (status active, não excluídos) com "Pago em" preenchido e,
 * quando a conta tem data do saldo inicial, pagos a partir dessa data. Limitado ao escopo de
 * condomínios do usuário (ADR-002). Somente leitura: não há saldo gravado, ele é sempre calculado.
 */
final class BankBalances
{
    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Regra pura do saldo, em centavos (pode ficar negativo). */
    public static function balance(int $openingCents, int $receivedCents, int $paidCents): int
    {
        return $openingCents + $receivedCents - $paidCents;
    }

    /**
     * @return list<array{id:int, condominium_id:int, condominium_name:?string, name:string, is_main:bool,
     *   opening_cents:int, opened_on:?string, received_cents:int, paid_cents:int, balance_cents:int}>
     */
    public function forUser(User $user, ?int $condominiumId = null): array
    {
        $ids = $this->scope->allowedIds($user);
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $stmt = $this->pdo->query("SELECT b.id, b.condominium_id, c.name AS condominium_name, b.name, b.is_main,
                COALESCE(b.opening_balance_cents, 0) AS opening_cents, b.opened_on,
                COALESCE(SUM(CASE WHEN e.nature = 'receita' THEN e.amount_cents END), 0) AS received_cents,
                COALESCE(SUM(CASE WHEN e.nature = 'despesa' THEN e.amount_cents END), 0) AS paid_cents
            FROM erp_bank_accounts b
            LEFT JOIN condominiums c ON c.id = b.condominium_id
            LEFT JOIN erp_entries e ON e.bank_account_id = b.id AND e.deleted_at IS NULL AND e.status = 'active'
                AND e.paid_on IS NOT NULL AND (b.opened_on IS NULL OR e.paid_on >= b.opened_on)
            WHERE b.deleted_at IS NULL AND b.status = 'active' AND b.condominium_id IN ({$in})
            GROUP BY b.id, b.condominium_id, c.name, b.name, b.is_main, b.opening_balance_cents, b.opened_on
            ORDER BY c.name, b.is_main DESC, b.name");
        return array_map(static function (array $row): array {
            $opening = (int)$row['opening_cents'];
            $received = (int)$row['received_cents'];
            $paid = (int)$row['paid_cents'];
            return [
                'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'],
                'condominium_name' => $row['condominium_name'] !== null ? (string)$row['condominium_name'] : null,
                'name' => (string)$row['name'], 'is_main' => (bool)$row['is_main'],
                'opening_cents' => $opening, 'opened_on' => $row['opened_on'] !== null ? (string)$row['opened_on'] : null,
                'received_cents' => $received, 'paid_cents' => $paid,
                'balance_cents' => self::balance($opening, $received, $paid),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
