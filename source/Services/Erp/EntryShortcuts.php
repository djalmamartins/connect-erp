<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Audit;

/**
 * Lançamentos avulsos (ADR 0057, regras conservadoras definidas pela Connect, issue #46), como atalhos
 * sobre o motor de lançamentos (ADR 0022). Lançar despesa e receita (AP-024/025) já são as visões
 * de contas a pagar e a receber (ADR 0024). Aqui:
 *
 * - discount() (AP-026): desconto numa conta a receber em aberto. Reduz o valor, com trilha (antes,
 *   depois, motivo). Não zera a conta (para perdoar, cancele), não vale em parcela de acordo nem com
 *   boleto ativo (o valor no banco ficaria diferente).
 * - createCharge() (AP-027 multa avulsa e AP-028 cobrança extra): gera uma conta a receber por
 *   unidade, valor fixo por unidade, total dividido igual ou pela fração ideal. As contas ficam
 *   travadas no cadastro (origin_lock) e se cancelam pela origem, como a fatura.
 * - transfer() (AP-029, "outros lançamentos"): transferência entre contas bancárias do mesmo
 *   condomínio. Não é receita nem despesa: move saldo, e entra nos saldos e na conciliação.
 *
 * Tudo respeita o fechamento mensal (ADR 0031), o escopo por condomínio (ADR-002) e é auditado.
 */
final class EntryShortcuts
{
    public const CHARGE_KINDS = ['extra' => 'Cobrança extra', 'fine' => 'Multa avulsa'];
    public const METHODS = ['fixed' => 'Valor fixo por unidade', 'equal' => 'Total dividido igualmente', 'fraction' => 'Total rateado pela fração ideal'];
    /** Unidades por cobrança extra: limita o IN (...) e as contas geradas numa só transação (ADR 0062). */
    public const MAX_UNITS = 1000;

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?RegistryService $registry = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = $registry ?? new RegistryService($this->pdo, $this->scope);
    }

    /** Motivo obrigatório (regra pura): espaços normalizados, 5 a 255 caracteres. */
    public static function reason(mixed $value, string $what): string
    {
        $reason = is_string($value) ? trim((string)preg_replace('/\s+/u', ' ', $value)) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException("Informe o motivo {$what} (5 a 255 caracteres).");
        }
        return $reason;
    }

    /**
     * Unidades escolhidas (regra pura): inteiros positivos, sem repetição, de 1 a MAX_UNITS.
     *
     * @return list<int>
     */
    public static function unitIds(mixed $raw): array
    {
        $ids = is_array($raw) ? array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $i): bool => $i > 0))) : [];
        if ($ids === []) {
            throw new InvalidArgumentException('Escolha ao menos uma unidade.');
        }
        if (count($ids) > self::MAX_UNITS) {
            throw new InvalidArgumentException('Escolha no máximo ' . self::MAX_UNITS . ' unidades por cobrança.');
        }
        return $ids;
    }

    /** Desconto aceito (regra pura): maior que zero e menor que o valor da conta (nunca zera). */
    public static function assertDiscount(int $discountCents, int $amountCents): void
    {
        if ($discountCents <= 0) {
            throw new InvalidArgumentException('Informe o valor do desconto.');
        }
        if ($discountCents >= $amountCents) {
            throw new InvalidArgumentException('O desconto precisa ser menor que o valor da conta. Para perdoar a conta inteira, cancele o lançamento.');
        }
    }

    /**
     * Valor de cada unidade (regra pura). fixed: o valor é por unidade; equal: total dividido igual
     * (centavos que sobram vão para as primeiras); fraction: pela fração ideal (Invoices::apportion).
     *
     * @param array<int, mixed> $fractions fração ideal por id de unidade, na ordem das unidades
     * @return array<int, int> centavos por id de unidade
     */
    public static function split(string $method, int $amountCents, array $fractions): array
    {
        if ($fractions === []) {
            throw new InvalidArgumentException('Escolha ao menos uma unidade.');
        }
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Informe um valor maior que zero.');
        }
        if ($method === 'fixed') {
            return array_map(static fn (): int => $amountCents, $fractions);
        }
        if ($method === 'fraction') {
            return Invoices::apportion($amountCents, $fractions);
        }
        if ($method !== 'equal') {
            throw new InvalidArgumentException('Forma de cálculo inválida.');
        }
        $count = count($fractions);
        if ($amountCents < $count) {
            throw new InvalidArgumentException('O total é menor que um centavo por unidade.');
        }
        $base = intdiv($amountCents, $count);
        $left = $amountCents - $base * $count;
        $out = [];
        $i = 0;
        foreach (array_keys($fractions) as $unitId) {
            $out[$unitId] = $base + ($i++ < $left ? 1 : 0);
        }
        return $out;
    }

    /** Desconto numa conta a receber em aberto (AP-026), com trilha e auditoria. */
    public function discount(User $user, int $entryId, mixed $amount, mixed $reason): array
    {
        $reason = self::reason($reason, 'do desconto');
        $discount = Invoices::cents($amount) ?? 0;
        $this->pdo->beginTransaction();
        try {
            $entry = $this->entry($user, $entryId, true);
            if ($entry['nature'] !== 'receita' || $entry['status'] !== 'active' || $entry['paid_on'] !== null) {
                throw new DomainException('Desconto só em conta a receber válida e em aberto.');
            }
            if ($entry['agreement_id'] !== null) {
                throw new DomainException('Conta renegociada ou parcela de acordo: o valor segue o acordo.');
            }
            if ($this->registry->isClosed($entry['condominium_id'], $entry['competence'])) {
                throw new DomainException("A competência {$entry['competence']} está fechada. Reabra o fechamento mensal para dar desconto.");
            }
            $this->assertNoActiveCharge($entryId, 'Há boleto ativo para esta conta. Cancele o boleto antes de dar desconto.');
            $before = (int)$entry['amount_cents'];
            self::assertDiscount($discount, $before);
            $after = $before - $discount;
            $this->pdo->prepare('UPDATE erp_entries SET amount_cents = :a, updated_by = :u WHERE id = :id')->execute(['a' => $after, 'u' => (int)$user->id, 'id' => $entryId]);
            $this->pdo->prepare("INSERT INTO erp_entry_adjustments (condominium_id, entry_id, kind, amount_cents, before_cents, after_cents, reason, created_by)
                VALUES (:c, :e, 'discount', :v, :b, :a, :r, :u)")->execute(['c' => $entry['condominium_id'], 'e' => $entryId, 'v' => $discount,
                'b' => $before, 'a' => $after, 'r' => $reason, 'u' => (int)$user->id]);
            $adjustment = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_entries', $entryId, ['amount_cents' => $before], ['amount_cents' => $after, 'discount_cents' => $discount,
            'adjustment_id' => $adjustment, 'reason' => $reason]);
        return ['entry_id' => $entryId, 'adjustment_id' => $adjustment, 'before_cents' => $before, 'discount_cents' => $discount, 'after_cents' => $after,
            'adjustments' => $this->adjustments($user, $entryId)];
    }

    /** @return list<array> descontos de uma conta, do mais antigo ao mais novo */
    public function adjustments(User $user, int $entryId): array
    {
        $this->entry($user, $entryId);
        $stmt = $this->pdo->prepare('SELECT id, kind, amount_cents, before_cents, after_cents, reason, created_by, created_at FROM erp_entry_adjustments WHERE entry_id = :e ORDER BY id');
        $stmt->execute(['e' => $entryId]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'amount_cents' => (int)$r['amount_cents'],
            'before_cents' => (int)$r['before_cents'], 'after_cents' => (int)$r['after_cents'], 'reason' => (string)$r['reason'],
            'created_by' => $r['created_by'] !== null ? (int)$r['created_by'] : null, 'created_at' => (string)$r['created_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Multa avulsa (kind fine, exatamente uma unidade, motivo obrigatório) ou cobrança extra (kind
     * extra, uma ou mais unidades). Gera as contas a receber na mesma transação.
     *
     * @param array{condominium_id?:mixed, kind?:mixed, description?:mixed, chart_account_id?:mixed, competence?:mixed, due_on?:mixed,
     *   method?:mixed, amount_cents?:mixed, unit_ids?:mixed, related_entry_id?:mixed, reason?:mixed} $input
     */
    public function createCharge(User $user, array $input): array
    {
        $condominium = $this->int($input['condominium_id'] ?? null);
        if ($condominium === null || !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
        $kind = is_string($input['kind'] ?? null) && isset(self::CHARGE_KINDS[$input['kind']]) ? $input['kind'] : null;
        if ($kind === null) {
            throw new InvalidArgumentException('Tipo inválido: use extra (cobrança extra) ou fine (multa avulsa).');
        }
        $method = $kind === 'fine' ? 'fixed' : (is_string($input['method'] ?? null) && isset(self::METHODS[$input['method']]) ? $input['method'] : null);
        if ($method === null) {
            throw new InvalidArgumentException('Forma de cálculo inválida: fixed, equal ou fraction.');
        }
        $description = is_string($input['description'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $input['description'])) : '';
        if (mb_strlen($description) < 3 || mb_strlen($description) > 120) {
            throw new InvalidArgumentException('Informe a descrição (3 a 120 caracteres).');
        }
        $competence = is_string($input['competence'] ?? null) ? $input['competence'] : '';
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência inválida. Use AAAA-MM.');
        }
        $dueOn = is_string($input['due_on'] ?? null) ? $input['due_on'] : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if ($date === false || $date->format('Y-m-d') !== $dueOn) {
            throw new InvalidArgumentException('Informe o vencimento (AAAA-MM-DD).');
        }
        if ($dueOn < date('Y-m-d')) {
            throw new InvalidArgumentException('O vencimento não pode ser no passado.');
        }
        $amount = Invoices::cents($input['amount_cents'] ?? null);
        if ($amount === null) {
            throw new InvalidArgumentException($method === 'fixed' ? 'Informe o valor por unidade.' : 'Informe o valor total a ratear.');
        }
        $reason = $kind === 'fine' ? self::reason($input['reason'] ?? null, 'da multa (regulamento ou ocorrência)') : null;
        $account = $this->revenueAccount($this->int($input['chart_account_id'] ?? null), $condominium);
        if ($this->registry->isClosed($condominium, $competence)) {
            throw new DomainException("A competência {$competence} está fechada. Reabra o fechamento mensal para lançar.");
        }
        $unitIds = self::unitIds($input['unit_ids'] ?? null);
        if ($kind === 'fine' && count($unitIds) !== 1) {
            throw new InvalidArgumentException('A multa avulsa é de uma unidade só.');
        }
        $stmt = $this->pdo->prepare('SELECT id, label, ideal_fraction FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL AND status = \'active\'
            AND id IN (' . implode(',', $unitIds) . ') ORDER BY label, id');
        $stmt->execute(['c' => $condominium]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($units) !== count($unitIds)) {
            throw new InvalidArgumentException('Alguma unidade escolhida não existe ou não é deste condomínio.');
        }
        $related = $this->int($input['related_entry_id'] ?? null);
        if ($kind === 'fine' && $related !== null) {
            $rel = $this->pdo->prepare("SELECT id FROM erp_entries WHERE id = :id AND unit_id = :u AND condominium_id = :c AND nature = 'receita' AND deleted_at IS NULL");
            $rel->execute(['id' => $related, 'u' => $unitIds[0], 'c' => $condominium]);
            if ($rel->fetchColumn() === false) {
                throw new InvalidArgumentException('A conta de referência da multa precisa ser uma conta a receber desta unidade.');
            }
        } else {
            $related = null;
        }
        // Origem da multa (ADR 0080, AP-116): ocorrência do condomínio (da unidade ou sem unidade) ou reserva da unidade.
        $occurrence = $kind === 'fine' ? $this->int($input['occurrence_id'] ?? null) : null;
        $reservation = $kind === 'fine' ? $this->int($input['reservation_id'] ?? null) : null;
        if ($occurrence !== null) {
            $check = $this->pdo->prepare('SELECT id FROM erp_occurrences WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND (unit_id IS NULL OR unit_id = :u)');
            $check->execute(['id' => $occurrence, 'c' => $condominium, 'u' => $unitIds[0]]);
            if ($check->fetchColumn() === false) {
                throw new InvalidArgumentException('A ocorrência da multa precisa ser deste condomínio e desta unidade.');
            }
        }
        if ($reservation !== null) {
            $check = $this->pdo->prepare('SELECT id FROM erp_reservations WHERE id = :id AND condominium_id = :c AND unit_id = :u AND deleted_at IS NULL');
            $check->execute(['id' => $reservation, 'c' => $condominium, 'u' => $unitIds[0]]);
            if ($check->fetchColumn() === false) {
                throw new InvalidArgumentException('A reserva da multa precisa ser desta unidade.');
            }
        }
        $fractions = [];
        foreach ($units as $unit) {
            $fractions[(int)$unit['id']] = $unit['ideal_fraction'];
        }
        $shares = self::split($method, $amount, $fractions);
        $total = array_sum($shares);
        $prefix = $kind === 'fine' ? 'MUL' : 'EXT';

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO erp_extra_charges (condominium_id, kind, description, chart_account_id, method, total_cents, units_count, competence, due_on,
                    related_entry_id, occurrence_id, reservation_id, reason, created_by) VALUES (:c, :k, :d, :a, :m, :t, :n, :comp, :due, :rel, :occ, :res, :r, :u)')->execute([
                'c' => $condominium, 'k' => $kind, 'd' => $description, 'a' => $account, 'm' => $method, 't' => $total, 'n' => count($units),
                'comp' => $competence, 'due' => $dueOn, 'rel' => $related, 'occ' => $occurrence, 'res' => $reservation, 'r' => $reason, 'u' => (int)$user->id,
            ]);
            $chargeId = (int)$this->pdo->lastInsertId();
            $link = $this->pdo->prepare('UPDATE erp_entries SET extra_charge_id = :x WHERE id = :id');
            foreach ($units as $unit) {
                $entry = $this->registry->save($user, 'entries', [
                    'condominium_id' => $condominium, 'nature' => 'receita', 'chart_account_id' => $account,
                    'description' => mb_substr("{$description} — {$unit['label']}", 0, 160),
                    'amount_cents' => number_format($shares[(int)$unit['id']] / 100, 2, ',', ''),
                    'competence' => $competence, 'due_on' => $dueOn, 'unit_id' => (int)$unit['id'], 'document_number' => "{$prefix}-{$chargeId}",
                ]);
                $link->execute(['x' => $chargeId, 'id' => (int)$entry['id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_extra_charges', $chargeId, null, ['kind' => $kind, 'method' => $method, 'total_cents' => $total, 'units' => array_keys($shares), 'reason' => $reason]);
        return $this->findCharge($user, $chargeId);
    }

    /** Cancela a cobrança extra ou multa e as contas geradas (nenhuma paga, sem boleto ativo, competência aberta). */
    public function cancelCharge(User $user, int $chargeId, mixed $reason): array
    {
        $reason = self::reason($reason, 'do cancelamento');
        $charge = $this->chargeRow($user, $chargeId);
        if ($charge['status'] !== 'active') {
            throw new DomainException('Já está cancelada.');
        }
        $this->pdo->beginTransaction();
        try {
            if ($this->registry->isClosed($charge['condominium_id'], $charge['competence'])) {
                throw new DomainException("A competência {$charge['competence']} está fechada. Reabra o fechamento mensal para cancelar.");
            }
            $stmt = $this->pdo->prepare('SELECT id, paid_on, agreement_id, status FROM erp_entries WHERE extra_charge_id = :x AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute(['x' => $chargeId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                if ($entry['status'] === 'active' && $entry['paid_on'] !== null) {
                    throw new DomainException('Há conta paga. Estorne a baixa antes de cancelar.');
                }
                if ($entry['agreement_id'] !== null) {
                    throw new DomainException('Há conta renegociada em acordo. Cancele o acordo antes.');
                }
                $this->assertNoActiveCharge((int)$entry['id'], 'Há boleto ativo numa das contas. Cancele o boleto antes.');
            }
            $this->pdo->prepare("UPDATE erp_entries SET status = 'inactive', updated_by = :u WHERE extra_charge_id = :x AND deleted_at IS NULL")
                ->execute(['u' => (int)$user->id, 'x' => $chargeId]);
            $this->pdo->prepare("UPDATE erp_extra_charges SET status = 'cancelled', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r WHERE id = :id AND status = 'active'")
                ->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $chargeId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_extra_charges', $chargeId, ['status' => 'active'], ['status' => 'cancelled', 'cancel_reason' => $reason]);
        return $this->findCharge($user, $chargeId);
    }

    /** @return array{data:list<array>, total:int} */
    public function listCharges(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return ['data' => [], 'total' => 0];
        }
        $where = 'x.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (isset(self::CHARGE_KINDS[$filters['kind'] ?? ''])) {
            $where .= ' AND x.kind = :k';
            $params['k'] = $filters['kind'];
        }
        if (in_array($filters['status'] ?? null, ['active', 'cancelled'], true)) {
            $where .= ' AND x.status = :s';
            $params['s'] = $filters['status'];
        }
        $stmt = $this->pdo->prepare("SELECT x.*, c.name AS condominium_name FROM erp_extra_charges x JOIN condominiums c ON c.id = x.condominium_id
            WHERE {$where} ORDER BY x.due_on DESC, x.id DESC LIMIT 200");
        $stmt->execute($params);
        $rows = array_map(fn (array $r): array => $this->presentCharge($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'total' => count($rows)];
    }

    public function findCharge(User $user, int $chargeId): array
    {
        $out = $this->presentCharge($this->chargeRow($user, $chargeId));
        $stmt = $this->pdo->prepare('SELECT e.id, e.unit_id, u.label AS unit_label, e.amount_cents, e.status, e.paid_on FROM erp_entries e
            LEFT JOIN erp_units u ON u.id = e.unit_id WHERE e.extra_charge_id = :x AND e.deleted_at IS NULL ORDER BY u.label, e.id');
        $stmt->execute(['x' => $chargeId]);
        $out['entries'] = array_map(static fn (array $r): array => ['entry_id' => (int)$r['id'], 'unit_id' => (int)$r['unit_id'], 'unit_label' => (string)$r['unit_label'],
            'amount_cents' => (int)$r['amount_cents'], 'status' => (string)$r['status'], 'paid_on' => $r['paid_on']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $out;
    }

    /**
     * Transferência entre contas bancárias ativas do mesmo condomínio (AP-029). Data até hoje, numa
     * competência aberta. Não gera lançamento: os saldos (BankBalances) e a conciliação a consideram.
     *
     * @param array{condominium_id?:mixed, from_bank_account_id?:mixed, to_bank_account_id?:mixed, amount_cents?:mixed, transferred_on?:mixed, description?:mixed} $input
     */
    public function transfer(User $user, array $input): array
    {
        $condominium = $this->int($input['condominium_id'] ?? null);
        if ($condominium === null || !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
        $from = $this->int($input['from_bank_account_id'] ?? null);
        $to = $this->int($input['to_bank_account_id'] ?? null);
        if ($from === null || $to === null || $from === $to) {
            throw new InvalidArgumentException('Escolha duas contas bancárias diferentes.');
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM erp_bank_accounts WHERE id IN (:a, :b) AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['a' => $from, 'b' => $to, 'c' => $condominium]);
        if ((int)$stmt->fetchColumn() !== 2) {
            throw new InvalidArgumentException('As duas contas precisam ser contas bancárias ativas deste condomínio.');
        }
        $amount = Invoices::cents($input['amount_cents'] ?? null);
        if ($amount === null) {
            throw new InvalidArgumentException('Informe o valor da transferência.');
        }
        $on = is_string($input['transferred_on'] ?? null) ? $input['transferred_on'] : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        if ($date === false || $date->format('Y-m-d') !== $on || $on > date('Y-m-d')) {
            throw new InvalidArgumentException('Informe a data da transferência (AAAA-MM-DD, até hoje).');
        }
        if ($this->registry->isClosed($condominium, substr($on, 0, 7))) {
            throw new DomainException('A competência ' . substr($on, 0, 7) . ' está fechada. Reabra o fechamento mensal para lançar.');
        }
        $description = is_string($input['description'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $input['description'])) : '';
        if (mb_strlen($description) < 3 || mb_strlen($description) > 160) {
            throw new InvalidArgumentException('Informe a descrição (3 a 160 caracteres).');
        }
        $id = $this->closingGuarded($condominium, substr($on, 0, 7), 'lançar', function () use ($condominium, $from, $to, $amount, $on, $description, $user): int {
            $this->pdo->prepare('INSERT INTO erp_bank_transfers (condominium_id, from_bank_account_id, to_bank_account_id, amount_cents, transferred_on, description, created_by)
                VALUES (:c, :f, :t, :v, :d, :desc, :u)')->execute(['c' => $condominium, 'f' => $from, 't' => $to, 'v' => $amount, 'd' => $on, 'desc' => $description, 'u' => (int)$user->id]);
            return (int)$this->pdo->lastInsertId();
        });
        Audit::record('create', 'erp_bank_transfers', $id, null, ['from' => $from, 'to' => $to, 'amount_cents' => $amount, 'transferred_on' => $on]);
        return $this->findTransfer($user, $id);
    }

    public function cancelTransfer(User $user, int $transferId, mixed $reason): array
    {
        $reason = self::reason($reason, 'do cancelamento');
        $row = $this->transferRow($user, $transferId);
        if ($row['status'] !== 'active') {
            throw new DomainException('A transferência já está cancelada.');
        }
        if ($this->registry->isClosed($row['condominium_id'], substr((string)$row['transferred_on'], 0, 7))) {
            throw new DomainException('A competência ' . substr((string)$row['transferred_on'], 0, 7) . ' está fechada. Reabra o fechamento mensal para cancelar.');
        }
        $this->closingGuarded((int)$row['condominium_id'], substr((string)$row['transferred_on'], 0, 7), 'cancelar', function () use ($user, $reason, $transferId): int {
            $this->pdo->prepare("UPDATE erp_bank_transfers SET status = 'cancelled', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r WHERE id = :id AND status = 'active'")
                ->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $transferId]);
            return $transferId;
        });
        Audit::record('update', 'erp_bank_transfers', $transferId, ['status' => 'active'], ['status' => 'cancelled', 'cancel_reason' => $reason]);
        return $this->findTransfer($user, $transferId);
    }

    /**
     * Grava na mesma transação em que reconfere a competência com leitura travada (ADR 0100), para não
     * correr com o fechamento mensal. A conferência feita antes só adianta o erro.
     */
    private function closingGuarded(int $condominium, string $competence, string $action, callable $write): int
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            if ($this->registry->isClosed($condominium, $competence)) {
                throw new DomainException("A competência {$competence} está fechada. Reabra o fechamento mensal para {$action}.");
            }
            $id = $write();
            if ($own) {
                $this->pdo->commit();
            }
            return $id;
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{data:list<array>, total:int} */
    public function listTransfers(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return ['data' => [], 'total' => 0];
        }
        $where = 't.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (in_array($filters['status'] ?? null, ['active', 'cancelled'], true)) {
            $where .= ' AND t.status = :s';
            $params['s'] = $filters['status'];
        }
        $stmt = $this->pdo->prepare($this->transferSql() . " WHERE {$where} ORDER BY t.transferred_on DESC, t.id DESC LIMIT 200");
        $stmt->execute($params);
        $rows = array_map(fn (array $r): array => $this->presentTransfer($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'total' => count($rows)];
    }

    public function findTransfer(User $user, int $transferId): array
    {
        return $this->presentTransfer($this->transferRow($user, $transferId));
    }

    private function assertNoActiveCharge(int $entryId, string $message): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM erp_bank_charges WHERE entry_id = :e AND status IN ('pending','registered')");
        $stmt->execute(['e' => $entryId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new DomainException($message);
        }
    }

    /** Conta analítica de receita, ativa, do padrão da Connect ou do próprio condomínio. */
    private function revenueAccount(?int $accountId, int $condominium): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM erp_chart_accounts WHERE id = :id AND deleted_at IS NULL AND status = 'active' AND kind = 'analitica'
            AND nature = 'receita' AND (condominium_id IS NULL OR condominium_id = :c)");
        $stmt->execute(['id' => (int)$accountId, 'c' => $condominium]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException('Escolha uma conta analítica de receita ativa do padrão ou deste condomínio.');
        }
        return (int)$id;
    }

    private function entry(User $user, int $entryId, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_entries WHERE id = :id AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function chargeRow(User $user, int $chargeId): array
    {
        $stmt = $this->pdo->prepare('SELECT x.*, c.name AS condominium_name FROM erp_extra_charges x JOIN condominiums c ON c.id = x.condominium_id WHERE x.id = :id');
        $stmt->execute(['id' => $chargeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function transferSql(): string
    {
        return 'SELECT t.*, c.name AS condominium_name, bf.name AS from_name, bt.name AS to_name FROM erp_bank_transfers t
            JOIN condominiums c ON c.id = t.condominium_id JOIN erp_bank_accounts bf ON bf.id = t.from_bank_account_id
            JOIN erp_bank_accounts bt ON bt.id = t.to_bank_account_id';
    }

    private function transferRow(User $user, int $transferId): array
    {
        $stmt = $this->pdo->prepare($this->transferSql() . ' WHERE t.id = :id');
        $stmt->execute(['id' => $transferId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function presentCharge(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => $r['condominium_name'] ?? null,
            'kind' => (string)$r['kind'], 'kind_label' => self::CHARGE_KINDS[$r['kind']] ?? (string)$r['kind'], 'description' => (string)$r['description'],
            'chart_account_id' => (int)$r['chart_account_id'], 'method' => (string)$r['method'], 'total_cents' => (int)$r['total_cents'],
            'units_count' => (int)$r['units_count'], 'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'],
            'related_entry_id' => $r['related_entry_id'] !== null ? (int)$r['related_entry_id'] : null, 'reason' => $r['reason'],
            'occurrence_id' => isset($r['occurrence_id']) ? (int)$r['occurrence_id'] : null, 'reservation_id' => isset($r['reservation_id']) ? (int)$r['reservation_id'] : null,
            'status' => (string)$r['status'], 'created_at' => (string)$r['created_at'], 'cancelled_at' => $r['cancelled_at'], 'cancel_reason' => $r['cancel_reason'],
        ];
    }

    private function presentTransfer(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => $r['condominium_name'] ?? null,
            'from_bank_account_id' => (int)$r['from_bank_account_id'], 'from_name' => (string)$r['from_name'],
            'to_bank_account_id' => (int)$r['to_bank_account_id'], 'to_name' => (string)$r['to_name'], 'amount_cents' => (int)$r['amount_cents'],
            'transferred_on' => (string)$r['transferred_on'], 'description' => (string)$r['description'], 'status' => (string)$r['status'],
            'created_at' => (string)$r['created_at'], 'cancelled_at' => $r['cancelled_at'], 'cancel_reason' => $r['cancel_reason'],
        ];
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $id): bool => $id > 0));
        if ($condominiumId !== null) {
            return in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        return $ids;
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
