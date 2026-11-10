<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Lançamentos patrimoniais com partida dobrada (ADR 0169). Cada lançamento debita uma conta patrimonial
 * analítica e credita outra, com o mesmo valor. Exemplos:
 *
 * - apropriar o fundo de reserva: débito 9.2 Resultado do exercício (ou 9.1), crédito 8.2 Fundo de reserva;
 * - usar o fundo numa despesa já lançada: débito 8.2, crédito 9.2;
 * - transferir entre fundos: débito 8.2, crédito 8.3;
 * - ajustar o PL: débito ou crédito em 9.1 contra outra conta patrimonial.
 *
 * Não passa pela DRE e não move o banco. A receita e a despesa continuam em erp_entries, e o Disponível
 * continua vindo das contas bancárias. Por isso as contas calculadas pelo balanço (7.1, 7.2, 8.1 e
 * subcontas) não recebem lançamento. A conta bancária e a receita/despesa ligadas são só referência.
 *
 * Não há exclusão: estornar cria o lançamento invertido e marca o original como 'reversed'. Os dois contam
 * no saldo e se anulam. Competência fechada não recebe lançamento (ADR 0031). Escopo por condomínio: fora
 * do escopo dá 404. Tudo é auditado.
 */
final class PatrimonialEntries
{
    public const NATURES = ['asset', 'liability', 'equity'];
    /** Contas calculadas pelo balanço (ADR 0167): não recebem lançamento. */
    public const COMPUTED = ['7.1', '7.2', '8.1'];
    public const MAX_ROWS = 5000;
    public const MAX_CENTS = 100000000000000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Valor com o sinal da natureza (regra pura): no ativo o débito soma; no passivo e no PL, o crédito soma. */
    public static function signed(string $nature, string $side, int $cents): int
    {
        $positive = $nature === 'asset' ? 'debit' : 'credit';
        return $side === $positive ? $cents : -$cents;
    }

    public static function computed(string $code): bool
    {
        foreach (self::COMPUTED as $computed) {
            if ($code === $computed || str_starts_with($code, $computed . '.')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Saldo dos movimentos por conta até a data (regra pura), na mesma regra do saldo inicial: movimento com data
     * anterior ao saldo inicial (AP-161) da conta fica de fora. Os movimentos na 9.2 de anos anteriores entram
     * nos acumulados (a virada do ano é só virtual, ADR 0167).
     *
     * @param list<array{code:string, nature:string, side:string, cents:int|string, entry_date:string}> $rows
     * @param array<string, string> $openedOn data do saldo inicial por "natureza|código"
     * @return array{accounts: array<string,int>, retained:int, current:int}
     */
    public static function balances(array $rows, array $openedOn, string $yearStart): array
    {
        $out = ['accounts' => [], 'retained' => 0, 'current' => 0];
        foreach ($rows as $row) {
            $key = $row['nature'] . '|' . $row['code'];
            if (isset($openedOn[$key]) && (string)$row['entry_date'] < $openedOn[$key]) {
                continue;
            }
            $cents = self::signed((string)$row['nature'], (string)$row['side'], (int)$row['cents']);
            if ($key === 'equity|9.2') {
                $out[(string)$row['entry_date'] < $yearStart ? 'retained' : 'current'] += $cents;
            } elseif ($key === 'equity|9.1') {
                $out['retained'] += $cents;
            } else {
                $out['accounts'][$key] = ($out['accounts'][$key] ?? 0) + $cents;
            }
        }
        return $out;
    }

    /**
     * Movimentos do condomínio até a data, por lado (débito e crédito), para o balanço. Somados no banco por conta e
     * dia (ADR 0172): o volume não cresce com o número de lançamentos, só com contas × dias com movimento.
     */
    public function movements(int $condominiumId, string $date): array
    {
        $rows = [];
        foreach (['debit' => 'debit_account_id', 'credit' => 'credit_account_id'] as $side => $column) {
            $stmt = $this->pdo->prepare("SELECT a.code, a.nature, SUM(m.amount_cents) AS cents, m.entry_date FROM erp_patrimonial_entries m
                JOIN erp_chart_accounts a ON a.id = m.{$column} WHERE m.condominium_id = :c AND m.entry_date <= :d
                GROUP BY a.code, a.nature, m.entry_date");
            $stmt->execute(['c' => $condominiumId, 'd' => $date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = $row + ['side' => $side];
            }
        }
        return $rows;
    }

    public function list(User $user, ?int $condominiumId, mixed $from, mixed $to): array
    {
        [$from, $to] = AccountingReports::period($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $base = ['condominium_id' => $condominiumId, 'from' => $from, 'to' => $to];
        if ($ids === []) {
            return $base + ['truncated' => false, 'rows' => [], 'totals' => ['entries' => 0, 'amount_cents' => 0]];
        }
        $stmt = $this->pdo->prepare('SELECT m.id, m.condominium_id, c.name AS condominium_name, m.entry_date, m.description, m.amount_cents, m.status,
                m.reversal_of_id, m.reversal_reason, m.bank_account_id, m.linked_entry_id, m.created_by,
                d.code AS debit_code, d.name AS debit_name, k.code AS credit_code, k.name AS credit_name
            FROM erp_patrimonial_entries m JOIN condominiums c ON c.id = m.condominium_id
            JOIN erp_chart_accounts d ON d.id = m.debit_account_id JOIN erp_chart_accounts k ON k.id = m.credit_account_id
            WHERE m.condominium_id IN (' . implode(',', $ids) . ') AND m.entry_date BETWEEN :f AND :t
            ORDER BY m.entry_date DESC, m.id DESC LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute(['f' => $from, 't' => $to]);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map([self::class, 'present'], array_slice($raw, 0, self::MAX_ROWS));
        return $base + ['truncated' => count($raw) > self::MAX_ROWS, 'rows' => $rows,
            'totals' => ['entries' => count($rows), 'amount_cents' => array_sum(array_column($rows, 'amount_cents'))]];
    }

    public function create(User $user, array $input, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $condominium = (int)$condominium;
        $this->scope->assertAccess($user, $condominium);   // 404 antes de validar o resto
        $date = self::date($input['entry_date'] ?? null, $today);
        $this->assertOpen($condominium, $date);
        $cents = ErpSettings::signedCents($input['amount_cents'] ?? null);
        if ($cents <= 0 || $cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('Informe um valor positivo.');
        }
        $description = self::text($input['description'] ?? null, 'Descreva o lançamento (3 a 255 caracteres).');
        $debit = $this->account($input['debit_account_id'] ?? null, $condominium, 'débito');
        $credit = $this->account($input['credit_account_id'] ?? null, $condominium, 'crédito');
        if ($debit['id'] === $credit['id']) {
            throw new InvalidArgumentException('O débito e o crédito precisam ser em contas diferentes.');
        }
        $bank = $this->reference('erp_bank_accounts', $input['bank_account_id'] ?? null, $condominium, 'Conta bancária inválida para este condomínio.');
        $linked = $this->reference('erp_entries', $input['linked_entry_id'] ?? null, $condominium, 'Lançamento de receita ou despesa inválido para este condomínio.');
        $this->pdo->prepare('INSERT INTO erp_patrimonial_entries (condominium_id, entry_date, description, amount_cents, debit_account_id, credit_account_id,
            bank_account_id, linked_entry_id, status, created_by) VALUES (:c, :d, :ds, :v, :db, :cr, :b, :l, :s, :u)')
            ->execute(['c' => $condominium, 'd' => $date, 'ds' => $description, 'v' => $cents, 'db' => $debit['id'], 'cr' => $credit['id'],
                'b' => $bank, 'l' => $linked, 's' => 'active', 'u' => (int)$user->id]);
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_patrimonial_entries', $id, null, ['condominium_id' => $condominium, 'entry_date' => $date, 'amount_cents' => $cents,
            'debit' => $debit['code'], 'credit' => $credit['code']]);
        return $this->find($id);
    }

    /** Estorno: lançamento invertido na data informada (padrão hoje) e o original marcado como 'reversed'. */
    public function reverse(User $user, int $id, mixed $reason, mixed $date = null, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT * FROM erp_patrimonial_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new CondominiumAccessDenied($row === false ? 0 : (int)$row['condominium_id']);   // 404, sem dizer se existe
        }
        if ($row['status'] !== 'active' || $row['reversal_of_id'] !== null) {
            throw new DomainException('Este lançamento já foi estornado ou é um estorno.');
        }
        $reason = self::text($reason, 'Informe o motivo do estorno (3 a 255 caracteres).');
        $date = self::date($date, $today);
        if ($date < (string)$row['entry_date']) {
            throw new InvalidArgumentException('O estorno não pode ter data anterior ao lançamento.');
        }
        $condominium = (int)$row['condominium_id'];
        $this->assertOpen($condominium, $date);
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare("UPDATE erp_patrimonial_entries SET status = 'reversed' WHERE id = :id AND status = 'active'");
            $update->execute(['id' => $id]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Este lançamento já foi estornado ou é um estorno.');
            }
            $this->pdo->prepare('INSERT INTO erp_patrimonial_entries (condominium_id, entry_date, description, amount_cents, debit_account_id, credit_account_id,
                bank_account_id, linked_entry_id, status, reversal_of_id, reversal_reason, created_by) VALUES (:c, :d, :ds, :v, :db, :cr, :b, :l, :s, :r, :rr, :u)')
                ->execute(['c' => $condominium, 'd' => $date, 'ds' => mb_substr('Estorno: ' . $row['description'], 0, 255), 'v' => (int)$row['amount_cents'],
                    'db' => (int)$row['credit_account_id'], 'cr' => (int)$row['debit_account_id'], 'b' => $row['bank_account_id'], 'l' => $row['linked_entry_id'],
                    's' => 'active', 'r' => $id, 'rr' => $reason, 'u' => (int)$user->id]);
            $reversal = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        Audit::record('update', 'erp_patrimonial_entries', $id, ['status' => 'active'], ['status' => 'reversed', 'reversal_id' => $reversal]);
        Audit::record('create', 'erp_patrimonial_entries', $reversal, null, ['condominium_id' => $condominium, 'entry_date' => $date, 'reversal_of_id' => $id]);
        return ['original' => $this->find($id), 'reversal' => $this->find($reversal)];
    }

    private function find(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT m.id, m.condominium_id, c.name AS condominium_name, m.entry_date, m.description, m.amount_cents, m.status,
                m.reversal_of_id, m.reversal_reason, m.bank_account_id, m.linked_entry_id, m.created_by,
                d.code AS debit_code, d.name AS debit_name, k.code AS credit_code, k.name AS credit_name
            FROM erp_patrimonial_entries m JOIN condominiums c ON c.id = m.condominium_id
            JOIN erp_chart_accounts d ON d.id = m.debit_account_id JOIN erp_chart_accounts k ON k.id = m.credit_account_id WHERE m.id = :id');
        $stmt->execute(['id' => $id]);
        return self::present($stmt->fetch(PDO::FETCH_ASSOC));
    }

    private static function present(array $r): array
    {
        return ['id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'],
            'entry_date' => (string)$r['entry_date'], 'description' => (string)$r['description'], 'amount_cents' => (int)$r['amount_cents'],
            'debit' => ['code' => (string)$r['debit_code'], 'name' => (string)$r['debit_name']], 'credit' => ['code' => (string)$r['credit_code'], 'name' => (string)$r['credit_name']],
            'status' => (string)$r['status'], 'reversal_of_id' => $r['reversal_of_id'] !== null ? (int)$r['reversal_of_id'] : null, 'reversal_reason' => $r['reversal_reason'],
            'bank_account_id' => $r['bank_account_id'] !== null ? (int)$r['bank_account_id'] : null,
            'linked_entry_id' => $r['linked_entry_id'] !== null ? (int)$r['linked_entry_id'] : null,
            'created_by' => $r['created_by'] !== null ? (int)$r['created_by'] : null];
    }

    /** @return array{id:int, code:string} */
    private function account(mixed $id, int $condominium, string $side): array
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $stmt = $this->pdo->prepare("SELECT id, code, nature, kind FROM erp_chart_accounts WHERE id = :id AND deleted_at IS NULL AND status = 'active'
            AND (condominium_id IS NULL OR condominium_id = :c)");
        $stmt->execute(['id' => $id === false ? 0 : (int)$id, 'c' => $condominium]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !in_array($row['nature'], self::NATURES, true) || $row['kind'] !== 'analitica') {
            throw new InvalidArgumentException("Escolha para o {$side} uma conta patrimonial analítica ativa (ativo, passivo ou PL) do padrão ou deste condomínio.");
        }
        if (self::computed((string)$row['code'])) {
            throw new InvalidArgumentException("A conta {$row['code']} é calculada pelo balanço (banco, contas a receber ou a pagar) e não recebe lançamento patrimonial.");
        }
        return ['id' => (int)$row['id'], 'code' => (string)$row['code']];
    }

    private function reference(string $table, mixed $id, int $condominium, string $message): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => $id === false ? 0 : (int)$id, 'c' => $condominium]);
        $found = $stmt->fetchColumn();
        if ($found === false) {
            throw new InvalidArgumentException($message);
        }
        return (int)$found;
    }

    private function assertOpen(int $condominium, string $date): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM erp_month_closings WHERE condominium_id = :c AND competence = :m AND status = 'closed'");
        $stmt->execute(['c' => $condominium, 'm' => substr($date, 0, 7)]);
        if ($stmt->fetchColumn() !== false) {
            throw new DomainException('A competência desta data está fechada. Reabra o mês para lançar.');
        }
    }

    private static function date(mixed $value, string $today): string
    {
        $value = $value === null || $value === '' ? $today : $value;
        $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > $today) {
            throw new InvalidArgumentException('Data inválida (AAAA-MM-DD, até hoje).');
        }
        return $value;
    }

    private static function text(mixed $value, string $message): string
    {
        $text = is_string($value) ? trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value))) : '';
        if (mb_strlen($text) < 3 || mb_strlen($text) > 255) {
            throw new InvalidArgumentException($message);
        }
        return $text;
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        if ($condominiumId !== null) {
            $this->scope->assertAccess($user, $condominiumId);
            return [$condominiumId];
        }
        return array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $id): bool => $id > 0));
    }
}
