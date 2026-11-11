<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Conferência das cópias de cheques (ADR 0214), somente leitura. O cadastro de cópias de cheques (AP-127, ADR 0050)
 * guarda os cheques emitidos pelo condomínio com a imagem no Disco Virtual e, opcionalmente, o lançamento pago com cada
 * um; nada confere esse cadastro com o financeiro nem a sequência dos talões.
 *
 * - Sequência por conta bancária, regra pura gaps(): números que faltam entre o primeiro e o último cheque registrado
 *   (emitidos e cancelados preenchem a sequência), até MAX_GAP_NUMBERS listados, com a contagem total.
 * - Cada cheque é conferido com o lançamento ligado pela regra pura issues():
 *   - cheque emitido: no_entry (sem lançamento), entry_missing (lançamento de outro condomínio ou apagado),
 *     entry_cancelled, not_expense (lançamento de receita), amount_mismatch (valor diferente), other_account (lançamento
 *     pago por outra conta), entry_unpaid (lançamento ainda em aberto);
 *   - cheque cancelado ligado a lançamento válido e pago: cancelled_but_paid.
 * - Nada muda: o relatório não grava e não envia nada; a correção continua sendo pelo formulário de cada cadastro.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem número, data, valor, situação,
 * ids e o nome da conta bancária do condomínio: nenhum favorecido (pode ser pessoa física), nenhum documento.
 */
final class CheckCopyReview
{
    public const MAX_CHECKS = 5000;
    public const MAX_GAP_NUMBERS = 100;
    public const MAX_ITEMS = 500;
    public const ISSUES = ['no_entry', 'entry_missing', 'entry_cancelled', 'not_expense', 'amount_mismatch', 'other_account', 'entry_unpaid', 'cancelled_but_paid'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Números que faltam na sequência (regra pura).
     *
     * @param list<int> $numbers
     * @return array{first:?int, last:?int, missing_count:int, missing:list<int>}
     */
    public static function gaps(array $numbers, int $limit = self::MAX_GAP_NUMBERS): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);
        if ($numbers === []) {
            return ['first' => null, 'last' => null, 'missing_count' => 0, 'missing' => []];
        }
        $missing = [];
        $count = 0;
        for ($i = 1, $n = count($numbers); $i < $n; $i++) {
            $hole = $numbers[$i] - $numbers[$i - 1] - 1;
            if ($hole <= 0) {
                continue;
            }
            $count += $hole;
            for ($k = $numbers[$i - 1] + 1; $k < $numbers[$i] && count($missing) < $limit; $k++) {
                $missing[] = $k;
            }
        }
        return ['first' => $numbers[0], 'last' => $numbers[count($numbers) - 1], 'missing_count' => $count, 'missing' => $missing];
    }

    /**
     * Problemas de um cheque com o lançamento ligado (regra pura). $entry null com entry_id preenchido = lançamento
     * fora do condomínio ou apagado.
     *
     * @param array{status:string, amount_cents:int, bank_account_id:int, entry_id:?int} $check
     * @param array{status:string, nature:string, amount_cents:int, paid_on:?string, bank_account_id:?int}|null $entry
     * @return list<string>
     */
    public static function issues(array $check, ?array $entry): array
    {
        if ($check['status'] !== 'active') {
            return $entry !== null && $entry['status'] === 'active' && $entry['paid_on'] !== null ? ['cancelled_but_paid'] : [];
        }
        if ($check['entry_id'] === null) {
            return ['no_entry'];
        }
        if ($entry === null) {
            return ['entry_missing'];
        }
        if ($entry['status'] !== 'active') {
            return ['entry_cancelled'];
        }
        $issues = [];
        if ($entry['nature'] !== 'despesa') {
            $issues[] = 'not_expense';
        }
        if ($entry['amount_cents'] !== $check['amount_cents']) {
            $issues[] = 'amount_mismatch';
        }
        if ($entry['paid_on'] === null) {
            $issues[] = 'entry_unpaid';
        } elseif ($entry['bank_account_id'] !== null && $entry['bank_account_id'] !== $check['bank_account_id']) {
            $issues[] = 'other_account';
        }
        return $issues;
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT k.id, k.bank_account_id, k.check_number, k.issued_on, k.amount_cents, k.status, k.entry_id,
                e.id AS e_id, e.status AS e_status, e.nature AS e_nature, e.amount_cents AS e_amount, e.paid_on AS e_paid_on, e.bank_account_id AS e_bank
            FROM erp_check_copies k
            LEFT JOIN erp_entries e ON e.id = k.entry_id AND e.condominium_id = k.condominium_id AND e.deleted_at IS NULL
            WHERE k.condominium_id = ? AND k.deleted_at IS NULL
            ORDER BY k.bank_account_id, k.check_number LIMIT ' . (self::MAX_CHECKS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_CHECKS) {
            throw new InvalidArgumentException(sprintf('Mais de %d cheques registrados: confira pela lista do cadastro.', self::MAX_CHECKS));
        }
        $names = [];
        $stmt = $this->pdo->prepare('SELECT id, name FROM erp_bank_accounts WHERE condominium_id = ?');
        $stmt->execute([(int)$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bank) {
            $names[(int)$bank['id']] = (string)$bank['name'];
        }

        $numbersBy = [];
        $accounts = [];
        $items = [];
        $summary = array_fill_keys(self::ISSUES, 0);
        foreach ($rows as $row) {
            $bank = (int)$row['bank_account_id'];
            $numbersBy[$bank][] = (int)$row['check_number'];
            $accounts[$bank] ??= ['bank_account_id' => $bank, 'bank_account_name' => $names[$bank] ?? '', 'checks' => 0, 'cancelled' => 0, 'issued_cents' => 0];
            $accounts[$bank]['checks']++;
            if ($row['status'] === 'active') {
                $accounts[$bank]['issued_cents'] += (int)$row['amount_cents'];
            } else {
                $accounts[$bank]['cancelled']++;
            }
            $check = ['status' => (string)$row['status'], 'amount_cents' => (int)$row['amount_cents'], 'bank_account_id' => $bank,
                'entry_id' => $row['entry_id'] !== null ? (int)$row['entry_id'] : null];
            $entry = $row['e_id'] === null ? null : ['status' => (string)$row['e_status'], 'nature' => (string)$row['e_nature'], 'amount_cents' => (int)$row['e_amount'],
                'paid_on' => $row['e_paid_on'] !== null ? substr((string)$row['e_paid_on'], 0, 10) : null, 'bank_account_id' => $row['e_bank'] !== null ? (int)$row['e_bank'] : null];
            $found = self::issues($check, $entry);
            foreach ($found as $issue) {
                $summary[$issue]++;
            }
            if ($found !== []) {
                $items[] = ['check_copy_id' => (int)$row['id'], 'bank_account_id' => $bank, 'check_number' => (int)$row['check_number'],
                    'issued_on' => substr((string)$row['issued_on'], 0, 10), 'amount_cents' => $check['amount_cents'], 'status' => $check['status'],
                    'entry_id' => $check['entry_id'], 'entry_amount_cents' => $entry['amount_cents'] ?? null, 'issues' => $found];
            }
        }
        foreach ($accounts as $bank => $account) {
            $accounts[$bank] = $account + self::gaps($numbersBy[$bank]);
        }
        return ['condominium_id' => (int)$id, 'checks' => count($rows), 'accounts' => array_values($accounts),
            'missing_numbers' => array_sum(array_column($accounts, 'missing_count')), 'summary' => $summary,
            'items' => array_slice($items, 0, self::MAX_ITEMS), 'truncated' => count($items) > self::MAX_ITEMS];
    }
}
