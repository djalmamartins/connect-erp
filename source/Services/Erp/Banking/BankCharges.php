<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;
use Source\Support\AppLogger;

/**
 * Boletos (AP-035, ADR 0035) das contas a receber, pelo adaptador da conta bancária.
 *
 * - issue(): um título ativo por lançamento (pedir de novo devolve o mesmo, sem chamar o banco).
 *   O título é gravado como 'pending' ANTES da chamada ao banco; sem confirmação (erro, timeout),
 *   continua 'pending' e verificável por refresh(). Só vira 'registered' com confirmação.
 * - Nosso número sequencial por conta bancária, sob lock da conta.
 * - cancel(): pending ou registered; pago não cancela (estorno é outro fluxo).
 * - Escopo por condomínio (ADR-002): fora do escopo = RegistryNotFound (404). Tudo auditado.
 */
final class BankCharges
{
    public const STATUSES = ['pending' => 'Aguardando banco', 'registered' => 'Registrado', 'rejected' => 'Recusado', 'paid' => 'Pago', 'cancelled' => 'Cancelado'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Nosso número com 10 dígitos (regra pura). */
    public static function formatOurNumber(int $sequence): string
    {
        if ($sequence < 1 || $sequence > 9999999999) {
            throw new InvalidArgumentException('Sequência do nosso número fora do intervalo.');
        }
        return str_pad((string)$sequence, 10, '0', STR_PAD_LEFT);
    }

    public function issue(User $user, int $entryId, int $bankAccountId): array
    {
        $entry = $this->entry($user, $entryId);
        if ($entry['nature'] !== 'receita' || $entry['status'] !== 'active') {
            throw new InvalidArgumentException('Boleto só para conta a receber válida.');
        }
        if ($entry['paid_on'] !== null) {
            throw new DomainException('Esta conta a receber já foi paga.');
        }
        $account = $this->bankAccount($bankAccountId, (int)$entry['condominium_id']);
        $existing = $this->activeFor($entryId);
        if ($existing !== null) {
            return $this->present($existing);
        }
        $adapter = BankAdapters::get(BankAdapters::DEFAULT);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM erp_bank_accounts WHERE id = :id FOR UPDATE')->execute(['id' => $bankAccountId]);
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(CAST(our_number AS UNSIGNED)), 0) FROM erp_bank_charges WHERE bank_account_id = :b');
            $stmt->execute(['b' => $bankAccountId]);
            $ourNumber = self::formatOurNumber((int)$stmt->fetchColumn() + 1);
            $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on, created_by)
                VALUES (:c, :b, :e, :a, 'pending', :n, :v, :d, :u)")->execute([
                'c' => $entry['condominium_id'], 'b' => $bankAccountId, 'e' => $entryId, 'a' => $adapter->name(),
                'n' => $ourNumber, 'v' => $entry['amount_cents'], 'd' => $entry['due_on'], 'u' => (int)$user->id,
            ]);
            $chargeId = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (PDOException $error) {
            $this->pdo->rollBack();
            if (($error->errorInfo[1] ?? null) === 1062 && ($again = $this->activeFor($entryId)) !== null) {
                return $this->present($again);   // pedido concorrente criou o título primeiro
            }
            throw $error;
        }
        Audit::record('create', 'erp_bank_charges', $chargeId, null, ['status' => 'pending', 'entry_id' => $entryId, 'our_number' => $ourNumber]);

        try {
            $result = $adapter->registerCharge([
                'our_number' => $ourNumber, 'amount_cents' => (int)$entry['amount_cents'], 'due_on' => (string)$entry['due_on'],
                'payer_name' => $entry['unit_label'], 'description' => (string)$entry['description'],
                'bank_code' => (string)$account['bank_code'], 'branch' => (string)$account['branch'],
                'account_number' => (string)$account['account_number'], 'wallet' => $account['wallet'],
            ]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'bank_charge_register_failed', 'charge_id' => $chargeId]);
            $result = ['status' => 'pending', 'error' => 'Sem resposta do banco. Consulte o título de novo mais tarde.'];
        }
        return $this->apply($chargeId, $result);
    }

    /** Consulta no banco um título pendente (ou registrado) e grava a resposta. */
    public function refresh(User $user, int $chargeId): array
    {
        $charge = $this->row($user, $chargeId);
        if (!in_array($charge['status'], ['pending', 'registered'], true)) {
            return $this->present($charge);
        }
        try {
            $result = BankAdapters::get($charge['adapter'])->getCharge((string)$charge['our_number'], $charge['external_id']);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'bank_charge_query_failed', 'charge_id' => $chargeId]);
            $result = ['status' => 'pending', 'error' => 'Sem resposta do banco. Consulte de novo mais tarde.'];
        }
        return $this->apply($chargeId, $result, (string)$charge['status']);
    }

    public function cancel(User $user, int $chargeId): array
    {
        $charge = $this->row($user, $chargeId);
        if (!in_array($charge['status'], ['pending', 'registered'], true)) {
            throw new DomainException('Só boleto aguardando banco ou registrado pode ser cancelado.');
        }
        $result = BankAdapters::get($charge['adapter'])->cancelCharge((string)$charge['our_number'], $charge['external_id']);
        if (($result['status'] ?? null) !== 'cancelled') {
            $this->pdo->prepare('UPDATE erp_bank_charges SET last_error = :e WHERE id = :id')
                ->execute(['e' => mb_substr((string)($result['error'] ?? 'Cancelamento não confirmado pelo banco.'), 0, 255), 'id' => $chargeId]);
            throw new DomainException('O banco não confirmou o cancelamento. Consulte o título e tente de novo.');
        }
        $this->pdo->prepare("UPDATE erp_bank_charges SET status = 'cancelled', cancelled_at = NOW(), last_error = NULL
            WHERE id = :id AND status IN ('pending','registered')")->execute(['id' => $chargeId]);
        Audit::record('update', 'erp_bank_charges', $chargeId, ['status' => $charge['status']], ['status' => 'cancelled']);
        return $this->find($user, $chargeId);
    }

    /**
     * @param array{status?:mixed, page?:mixed} $filters
     * @return array{data:list<array>, total:int, page:int, per_page:int, pages:int}
     */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = $this->scope->allowedIds($user);
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        $perPage = 25;
        $page = max(1, (int)(filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        if ($ids === []) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
        $where = 'ch.condominium_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        $params = [];
        if (is_string($filters['status'] ?? null) && isset(self::STATUSES[$filters['status']])) {
            $where .= ' AND ch.status = :s';
            $params['s'] = $filters['status'];
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM erp_bank_charges ch WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT ch.* FROM erp_bank_charges ch WHERE {$where} ORDER BY ch.due_on DESC, ch.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return ['data' => array_map(fn (array $r): array => $this->present($r), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }

    public function find(User $user, int $chargeId): array
    {
        return $this->present($this->row($user, $chargeId));
    }

    /** Grava a resposta do banco num título ainda pendente ou registrado. */
    private function apply(int $chargeId, array $result, string $before = 'pending'): array
    {
        $status = in_array($result['status'] ?? null, ['registered', 'pending', 'rejected'], true) ? $result['status'] : 'pending';
        $this->pdo->prepare("UPDATE erp_bank_charges SET status = :s,
                external_id = COALESCE(:x, external_id), digitable_line = COALESCE(:l, digitable_line), barcode = COALESCE(:bc, barcode),
                last_error = :e, registered_at = CASE WHEN :s2 = 'registered' AND registered_at IS NULL THEN NOW() ELSE registered_at END
            WHERE id = :id AND status IN ('pending','registered')")->execute([
            's' => $status, 's2' => $status, 'x' => $result['external_id'] ?? null, 'l' => $result['digitable_line'] ?? null,
            'bc' => $result['barcode'] ?? null, 'e' => isset($result['error']) ? mb_substr((string)$result['error'], 0, 255) : null, 'id' => $chargeId,
        ]);
        $row = $this->rawRow($chargeId);
        Audit::record('update', 'erp_bank_charges', $chargeId, ['status' => $before], ['status' => $row['status']]);
        return $this->present($row);
    }

    private function entry(User $user, int $entryId): array
    {
        $stmt = $this->pdo->prepare('SELECT e.*, u.label AS unit_label FROM erp_entries e LEFT JOIN erp_units u ON u.id = e.unit_id
            WHERE e.id = :id AND e.deleted_at IS NULL');
        $stmt->execute(['id' => $entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($entry) || !$this->scope->canAccess($user, (int)$entry['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $entry;
    }

    private function bankAccount(int $bankAccountId, int $condominiumId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM erp_bank_accounts WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => $bankAccountId, 'c' => $condominiumId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new InvalidArgumentException('Escolha uma conta bancária ativa do mesmo condomínio da conta a receber.');
        }
        return $account;
    }

    private function activeFor(int $entryId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_charges WHERE entry_id = :e AND active_key = 1 LIMIT 1');
        $stmt->execute(['e' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function row(User $user, int $chargeId): array
    {
        $row = $this->rawRow($chargeId);
        if ($row === null || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function rawRow(int $chargeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_charges WHERE id = :id');
        $stmt->execute(['id' => $chargeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'bank_account_id' => (int)$row['bank_account_id'],
            'entry_id' => (int)$row['entry_id'], 'adapter' => (string)$row['adapter'],
            'status' => (string)$row['status'], 'status_label' => self::STATUSES[$row['status']] ?? (string)$row['status'],
            'our_number' => (string)$row['our_number'], 'amount_cents' => (int)$row['amount_cents'], 'due_on' => (string)$row['due_on'],
            'external_id' => $row['external_id'], 'digitable_line' => $row['digitable_line'], 'barcode' => $row['barcode'],
            'last_error' => $row['last_error'], 'paid_cents' => $row['paid_cents'] !== null ? (int)$row['paid_cents'] : null,
            'paid_on' => $row['paid_on'], 'registered_at' => $row['registered_at'], 'cancelled_at' => $row['cancelled_at'],
        ];
    }
}
