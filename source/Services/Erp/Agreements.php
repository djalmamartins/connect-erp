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
 * Inadimplência e acordos (AP-033/034, ADR 0039; encargos e quebra, ADR 0040).
 *
 * - delinquency(): contas a receber vencidas e em aberto, agrupadas por unidade, com faixas de atraso.
 * - create(): acordo das contas vencidas escolhidas de uma unidade. Na mesma transação, as contas
 *   originais ficam canceladas (status inactive, agreement_role 'original') e as parcelas viram contas
 *   a receber novas (agreement_role 'installment'), pelo RegistryService (mesmas regras dos lançamentos,
 *   inclusive a trava do fechamento). Conta de competência fechada, com boleto ativo, já paga, de outra
 *   unidade ou já em outro acordo é recusada.
 * - cancel(): só sem parcela paga e sem boleto ativo nas parcelas; as parcelas ficam canceladas e as
 *   contas originais voltam a valer.
 * - Multa, juros e desconto são valores informados (a regra de cálculo ainda não foi aprovada).
 * - Escopo por condomínio (ADR-002): fora do escopo = RegistryNotFound (404). Tudo auditado.
 */
final class Agreements
{
    public const MAX_INSTALLMENTS = 60;
    /** Contas por acordo ou simulação: limita o IN (...) e o FOR UPDATE de um único pedido. */
    public const MAX_ENTRIES = 120;

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = new RegistryService($this->pdo, $this->scope);
    }

    /** Dias de atraso em $asOf (regra pura); vence hoje = 0. */
    public static function daysLate(string $dueOn, string $asOf): int
    {
        $due = new \DateTimeImmutable($dueOn);
        $ref = new \DateTimeImmutable($asOf);
        return $ref > $due ? (int)$due->diff($ref)->days : 0;
    }

    /** Faixa de atraso (regra pura): 1-30, 31-60, 61-90 ou 90+. */
    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 30 => '1-30',
            $days <= 60 => '31-60',
            $days <= 90 => '61-90',
            default => '90+',
        };
    }

    /**
     * Parcelas iguais e mensais (regra pura): os centavos que sobram vão para a primeira; o dia da
     * primeira se repete, limitado ao último dia do mês (31/01 → 28/02 → 31/03).
     *
     * @return list<array{number:int, due_on:string, amount_cents:int}>
     */
    public static function plan(int $totalCents, int $installments, string $firstDueOn): array
    {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException('O total do acordo precisa ser maior que zero.');
        }
        if ($installments < 1 || $installments > self::MAX_INSTALLMENTS) {
            throw new InvalidArgumentException('Número de parcelas entre 1 e ' . self::MAX_INSTALLMENTS . '.');
        }
        if ($installments > $totalCents) {
            throw new InvalidArgumentException('Parcelas demais para o total do acordo.');
        }
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $firstDueOn);
        if ($first === false || $first->format('Y-m-d') !== $firstDueOn) {
            throw new InvalidArgumentException('Informe o vencimento da primeira parcela (AAAA-MM-DD).');
        }
        $base = intdiv($totalCents, $installments);
        $rest = $totalCents - $base * $installments;
        $day = (int)$first->format('d');
        $plan = [];
        for ($i = 0; $i < $installments; $i++) {
            $month = $first->modify('first day of +' . $i . ' month');
            $due = $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min($day, (int)$month->format('t')));
            $plan[] = ['number' => $i + 1, 'due_on' => $due->format('Y-m-d'), 'amount_cents' => $base + ($i === 0 ? $rest : 0)];
        }
        return $plan;
    }

    /**
     * Contas a receber vencidas e em aberto por unidade ($condominiumId null = todos do escopo).
     *
     * @return array{as_of:string, units:list<array>, totals:array{units:int, entries:int, amount_cents:int, buckets:array<string,int>}}
     */
    public function delinquency(User $user, ?int $condominiumId, ?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $ref = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
        if ($ref === false || $ref->format('Y-m-d') !== $asOf) {
            throw new InvalidArgumentException('Data de referência inválida (AAAA-MM-DD).');
        }
        $ids = $this->ids($user, $condominiumId);
        $empty = ['1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $result = ['as_of' => $asOf, 'units' => [], 'totals' => ['units' => 0, 'entries' => 0, 'amount_cents' => 0, 'buckets' => $empty]];
        if ($ids === []) {
            return $result;
        }
        $stmt = $this->pdo->prepare("SELECT e.id, e.condominium_id, c.name AS condominium_name, e.unit_id, u.label AS unit_label,
                e.description, e.competence, e.due_on, e.amount_cents
            FROM erp_entries e JOIN condominiums c ON c.id = e.condominium_id LEFT JOIN erp_units u ON u.id = e.unit_id
            WHERE e.condominium_id IN (" . implode(',', $ids) . ") AND e.nature = 'receita' AND e.status = 'active'
              AND e.deleted_at IS NULL AND e.paid_on IS NULL AND e.due_on < :d
            ORDER BY c.name, u.label, e.due_on, e.id");
        $stmt->execute(['d' => $asOf]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Encargos sugeridos (ADR 0040): regra de cada condomínio e índice dos meses de atraso.
        $rules = new BillingRules($this->pdo, $this->scope);
        $ruleBy = [];
        $oldest = $rows === [] ? $asOf : min(array_column($rows, 'due_on'));
        $rates = $rules->rates('IPCA', substr((string)$oldest, 0, 7), substr($asOf, 0, 7));
        $result['totals'] += ['charges_cents' => 0, 'total_cents' => 0, 'missing_index' => []];
        $units = [];
        foreach ($rows as $row) {
            $key = $row['condominium_id'] . ':' . ($row['unit_id'] ?? '0');
            $days = self::daysLate((string)$row['due_on'], $asOf);
            $rule = $ruleBy[(int)$row['condominium_id']] ??= $rules->rule((int)$row['condominium_id']);
            $charges = LateCharges::compute((int)$row['amount_cents'], (string)$row['due_on'], $asOf, $rule, $rates);
            $units[$key] ??= ['condominium_id' => (int)$row['condominium_id'], 'condominium_name' => (string)$row['condominium_name'],
                'unit_id' => $row['unit_id'] !== null ? (int)$row['unit_id'] : null, 'unit_label' => $row['unit_label'] ?? 'Sem unidade',
                'entries' => [], 'amount_cents' => 0, 'charges_cents' => 0, 'total_cents' => 0, 'oldest_due_on' => (string)$row['due_on'], 'max_days_late' => 0];
            $units[$key]['entries'][] = ['id' => (int)$row['id'], 'description' => (string)$row['description'], 'competence' => (string)$row['competence'],
                'due_on' => (string)$row['due_on'], 'amount_cents' => (int)$row['amount_cents'], 'days_late' => $days, 'bucket' => self::bucket($days),
                'charges' => $charges];
            $units[$key]['amount_cents'] += (int)$row['amount_cents'];
            $units[$key]['charges_cents'] += $charges['charges_cents'];
            $units[$key]['total_cents'] += $charges['total_cents'];
            $units[$key]['max_days_late'] = max($units[$key]['max_days_late'], $days);
            $result['totals']['entries']++;
            $result['totals']['amount_cents'] += (int)$row['amount_cents'];
            $result['totals']['charges_cents'] += $charges['charges_cents'];
            $result['totals']['total_cents'] += $charges['total_cents'];
            $result['totals']['missing_index'] = array_values(array_unique(array_merge($result['totals']['missing_index'], $charges['missing_index'])));
            $result['totals']['buckets'][self::bucket($days)] += (int)$row['amount_cents'];
        }
        sort($result['totals']['missing_index']);
        $result['units'] = array_values($units);
        $result['totals']['units'] = count($units);
        return $result;
    }

    /**
     * Encargos sugeridos para um acordo (ADR 0040): soma de correção, multa e juros das contas
     * escolhidas da unidade em $asOf. Quem faz o acordo pode mudar o valor (fees_cents).
     *
     * @param list<int> $entryIds
     */
    public function suggestion(User $user, int $unitId, array $entryIds, ?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $unit = $this->unit($user, $unitId);
        $ids = self::entryIds($entryIds);
        if ($ids === []) {
            throw new InvalidArgumentException('Escolha as contas vencidas.');
        }
        // Só contas do condomínio da unidade: id de outro condomínio responde igual a id inexistente.
        $stmt = $this->pdo->prepare('SELECT * FROM erp_entries WHERE id IN (' . implode(',', $ids) . ') AND condominium_id = :c');
        $stmt->execute(['c' => (int)$unit['condominium_id']]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($entries) !== count($ids)) {
            throw new InvalidArgumentException('Alguma conta escolhida não existe.');
        }
        $rules = new BillingRules($this->pdo, $this->scope);
        $rule = $rules->rule((int)$unit['condominium_id']);
        $rates = $rules->rates('IPCA', substr((string)min(array_column($entries, 'due_on')), 0, 7), substr($asOf, 0, 7));
        $sum = ['original_cents' => 0, 'correction_cents' => 0, 'fine_cents' => 0, 'interest_cents' => 0, 'fees_cents' => 0];
        $missing = [];
        $lines = [];
        foreach ($entries as $entry) {
            $this->assertRenegotiable($entry, $unit);
            $charges = LateCharges::compute((int)$entry['amount_cents'], (string)$entry['due_on'], $asOf, $rule, $rates);
            $lines[] = ['entry_id' => (int)$entry['id'], 'amount_cents' => (int)$entry['amount_cents'], 'due_on' => (string)$entry['due_on']] + $charges;
            $sum['original_cents'] += (int)$entry['amount_cents'];
            $sum['correction_cents'] += $charges['correction_cents'];
            $sum['fine_cents'] += $charges['fine_cents'];
            $sum['interest_cents'] += $charges['interest_cents'];
            $sum['fees_cents'] += $charges['charges_cents'];
            $missing = array_merge($missing, $charges['missing_index']);
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        return ['as_of' => $asOf, 'unit_id' => (int)$unit['id'], 'rule' => $rule, 'entries' => $lines,
            'missing_index' => $missing, 'total_cents' => $sum['original_cents'] + $sum['fees_cents']] + $sum;
    }

    /**
     * Situação de quebra calculada pela regra do condomínio (nada muda sozinho).
     *
     * @return array{due:bool, overdue_installments:int, max_days_late:int, reasons:list<string>}
     */
    public function breachStatus(User $user, int $agreementId, ?string $asOf = null): array
    {
        $agreement = $this->row($user, $agreementId);
        return $this->computeBreach($agreement, $asOf ?? date('Y-m-d'));
    }

    /** Grava a quebra do acordo (ação explícita, com motivo, auditada). As parcelas ficam como estão. */
    public function breach(User $user, int $agreementId, string $reason, ?string $asOf = null): array
    {
        $agreement = $this->row($user, $agreementId);
        $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo da quebra (5 a 255 caracteres).');
        }
        if ($agreement['status'] !== 'active') {
            throw new DomainException('Só acordo ativo pode ser marcado como quebrado.');
        }
        $status = $this->computeBreach($agreement, $asOf ?? date('Y-m-d'));
        if (!$status['due']) {
            throw new DomainException('O acordo não atende à regra de quebra do condomínio.');
        }
        $this->pdo->prepare("UPDATE erp_agreements SET status = 'breached', breached_by = :u, breached_at = NOW(), breach_reason = :r
            WHERE id = :id AND status = 'active'")->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $agreementId]);
        Audit::record('update', 'erp_agreements', $agreementId, ['status' => 'active'], ['status' => 'breached', 'breach_reason' => $reason, 'rule' => $status['reasons']]);
        return $this->find($user, $agreementId);
    }

    private function computeBreach(array $agreement, string $asOf): array
    {
        $stmt = $this->pdo->prepare("SELECT agreement_installment AS number, due_on, paid_on, status FROM erp_entries
            WHERE agreement_id = :a AND agreement_role = 'installment' AND deleted_at IS NULL ORDER BY agreement_installment");
        $stmt->execute(['a' => $agreement['id']]);
        $parcels = array_map(static fn (array $r): array => ['number' => (int)$r['number'], 'due_on' => (string)$r['due_on'],
            'paid_on' => $r['paid_on'], 'status' => (string)$r['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $rule = (new BillingRules($this->pdo, $this->scope))->rule((int)$agreement['condominium_id']);
        return LateCharges::breach($parcels, $rule, $asOf);
    }

    /**
     * @param array{unit_id?:mixed, entry_ids?:mixed, installments?:mixed, first_due_on?:mixed, chart_account_id?:mixed,
     *   fees_cents?:mixed, discount_cents?:mixed, notes?:mixed} $input
     */
    public function create(User $user, array $input): array
    {
        $unit = $this->unit($user, (int)filter_var($input['unit_id'] ?? 0, FILTER_VALIDATE_INT));
        $entryIds = self::entryIds(is_array($input['entry_ids'] ?? null) ? $input['entry_ids'] : []);
        if ($entryIds === []) {
            throw new InvalidArgumentException('Escolha as contas vencidas que entram no acordo.');
        }
        $fees = $this->cents($input['fees_cents'] ?? 0, 'Encargos');
        $discount = $this->cents($input['discount_cents'] ?? 0, 'Desconto');
        $installments = (int)filter_var($input['installments'] ?? 0, FILTER_VALIDATE_INT);
        $firstDue = is_string($input['first_due_on'] ?? null) ? $input['first_due_on'] : '';
        $accountId = (int)filter_var($input['chart_account_id'] ?? 0, FILTER_VALIDATE_INT);
        $notes = is_string($input['notes'] ?? null) ? mb_substr(trim($input['notes']), 0, 255) : null;
        $condominium = (int)$unit['condominium_id'];

        $this->pdo->beginTransaction();
        try {
            $in = implode(',', $entryIds);
            // Trava só contas do condomínio da unidade (nunca linhas de outro condomínio).
            $stmt = $this->pdo->prepare("SELECT * FROM erp_entries WHERE id IN ({$in}) AND condominium_id = :c FOR UPDATE");
            $stmt->execute(['c' => $condominium]);
            $originals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($originals) !== count($entryIds)) {
                throw new InvalidArgumentException('Alguma conta escolhida não existe.');
            }
            $original = 0;
            foreach ($originals as $entry) {
                $this->assertRenegotiable($entry, $unit);
                $original += (int)$entry['amount_cents'];
            }
            if ($discount > $original + $fees) {
                throw new InvalidArgumentException('O desconto não pode ser maior que a dívida mais os encargos.');
            }
            $total = $original + $fees - $discount;
            $plan = self::plan($total, $installments, $firstDue);

            $this->pdo->prepare('INSERT INTO erp_agreements (condominium_id, unit_id, status, chart_account_id, original_cents, fees_cents, discount_cents,
                    total_cents, installments, first_due_on, notes, created_by)
                VALUES (:c, :u, \'active\', :a, :o, :f, :d, :t, :n, :due, :notes, :by)')->execute([
                'c' => $condominium, 'u' => $unit['id'], 'a' => $accountId, 'o' => $original, 'f' => $fees, 'd' => $discount,
                't' => $total, 'n' => $installments, 'due' => $firstDue, 'notes' => $notes, 'by' => (int)$user->id,
            ]);
            $agreementId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare("UPDATE erp_entries SET status = 'inactive', agreement_id = :a, agreement_role = 'original', updated_by = :u
                WHERE id IN ({$in})")->execute(['a' => $agreementId, 'u' => (int)$user->id]);
            $history = $this->pdo->prepare('INSERT INTO erp_agreement_originals (agreement_id, entry_id, amount_cents) VALUES (:a, :e, :v)');
            foreach ($originals as $entry) {
                $history->execute(['a' => $agreementId, 'e' => $entry['id'], 'v' => $entry['amount_cents']]);
            }
            $link = $this->pdo->prepare("UPDATE erp_entries SET agreement_id = :a, agreement_role = 'installment', agreement_installment = :n WHERE id = :id");
            foreach ($plan as $parcel) {
                $entry = $this->registry->save($user, 'entries', [
                    'condominium_id' => $condominium, 'nature' => 'receita', 'chart_account_id' => $accountId,
                    'description' => mb_substr(sprintf('Acordo %d — parcela %d/%d — %s', $agreementId, $parcel['number'], $installments, $unit['label']), 0, 160),
                    'amount_cents' => number_format($parcel['amount_cents'] / 100, 2, ',', ''),
                    'competence' => substr($parcel['due_on'], 0, 7), 'due_on' => $parcel['due_on'], 'unit_id' => $unit['id'],
                    'document_number' => 'ACO-' . $agreementId . '-' . $parcel['number'],
                ]);
                $link->execute(['a' => $agreementId, 'n' => $parcel['number'], 'id' => $entry['id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_agreements', $agreementId, null, ['unit_id' => (int)$unit['id'], 'entries' => $entryIds,
            'original_cents' => $original, 'fees_cents' => $fees, 'discount_cents' => $discount, 'total_cents' => $total, 'installments' => $installments]);
        return $this->find($user, $agreementId);
    }

    public function cancel(User $user, int $agreementId, string $reason): array
    {
        $agreement = $this->row($user, $agreementId);
        $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do cancelamento (5 a 255 caracteres).');
        }
        if ($agreement['status'] !== 'active') {
            throw new DomainException($agreement['status'] === 'breached' ? 'Acordo quebrado não pode ser cancelado.' : 'O acordo já está cancelado.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM erp_entries WHERE agreement_id = :a FOR UPDATE');
            $stmt->execute(['a' => $agreementId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                if ($this->registry->isClosed($entry['condominium_id'], $entry['competence'])) {
                    throw new DomainException("A competência {$entry['competence']} está fechada. Reabra o fechamento mensal para cancelar o acordo.");
                }
                if ($entry['agreement_role'] === 'installment' && $entry['paid_on'] !== null && $entry['status'] === 'active') {
                    throw new DomainException('Há parcela paga. Estorne a baixa antes de cancelar o acordo.');
                }
            }
            $charges = $this->pdo->prepare("SELECT COUNT(*) FROM erp_bank_charges ch JOIN erp_entries e ON e.id = ch.entry_id
                WHERE e.agreement_id = :a AND e.agreement_role = 'installment' AND ch.status IN ('pending','registered','paid')");
            $charges->execute(['a' => $agreementId]);
            if ((int)$charges->fetchColumn() > 0) {
                throw new DomainException('Há boleto ativo em parcela do acordo. Cancele o boleto antes.');
            }
            $this->pdo->prepare("UPDATE erp_entries SET status = 'inactive', updated_by = :u WHERE agreement_id = :a AND agreement_role = 'installment'")
                ->execute(['u' => (int)$user->id, 'a' => $agreementId]);
            $this->pdo->prepare("UPDATE erp_entries SET status = 'active', agreement_id = NULL, agreement_role = NULL, updated_by = :u
                WHERE agreement_id = :a AND agreement_role = 'original'")->execute(['u' => (int)$user->id, 'a' => $agreementId]);
            $this->pdo->prepare("UPDATE erp_agreements SET status = 'cancelled', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r WHERE id = :id")
                ->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $agreementId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_agreements', $agreementId, ['status' => 'active'], ['status' => 'cancelled', 'cancel_reason' => $reason]);
        return $this->find($user, $agreementId);
    }

    /** @return array{data:list<array>, total:int, page:int, per_page:int, pages:int} */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = $this->ids($user, $condominiumId, false);
        $perPage = 25;
        $page = max(1, (int)(filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        if ($ids === []) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
        $where = 'a.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (in_array($filters['status'] ?? null, ['active', 'cancelled', 'breached'], true)) {
            $where .= ' AND a.status = :s';
            $params['s'] = $filters['status'];
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM erp_agreements a WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT a.*, c.name AS condominium_name, u.label AS unit_label FROM erp_agreements a
            JOIN condominiums c ON c.id = a.condominium_id JOIN erp_units u ON u.id = a.unit_id
            WHERE {$where} ORDER BY a.created_at DESC, a.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return ['data' => array_map(fn (array $r): array => $this->present($r) + $this->progress((int)$r['id']), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }

    /** Acordo com as contas renegociadas e as parcelas (situação de cada uma). */
    public function find(User $user, int $agreementId): array
    {
        $this->row($user, $agreementId);
        $stmt = $this->pdo->prepare('SELECT a.*, c.name AS condominium_name, u.label AS unit_label FROM erp_agreements a
            JOIN condominiums c ON c.id = a.condominium_id JOIN erp_units u ON u.id = a.unit_id WHERE a.id = :id');
        $stmt->execute(['id' => $agreementId]);
        $agreement = $this->present($stmt->fetch(PDO::FETCH_ASSOC)) + $this->progress($agreementId);
        $entries = $this->pdo->prepare("SELECT e.id, CASE WHEN o.entry_id IS NOT NULL THEN 'original' ELSE e.agreement_role END AS agreement_role,
                e.agreement_installment, e.description, e.competence, e.due_on, e.amount_cents, e.paid_on, e.status
            FROM erp_entries e LEFT JOIN erp_agreement_originals o ON o.entry_id = e.id AND o.agreement_id = :a1
            WHERE e.deleted_at IS NULL AND (o.entry_id IS NOT NULL OR (e.agreement_id = :a2 AND e.agreement_role = 'installment'))
            ORDER BY agreement_role = 'installment', e.agreement_installment, e.due_on, e.id");
        $entries->execute(['a1' => $agreementId, 'a2' => $agreementId]);
        $agreement['originals'] = [];
        $agreement['parcels'] = [];
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = ['entry_id' => (int)$row['id'], 'description' => (string)$row['description'], 'competence' => (string)$row['competence'],
                'due_on' => (string)$row['due_on'], 'amount_cents' => (int)$row['amount_cents'], 'paid_on' => $row['paid_on'], 'status' => (string)$row['status']];
            if ($row['agreement_role'] === 'installment') {
                $agreement['parcels'][] = ['number' => (int)$row['agreement_installment']] + $item;
            } else {
                $agreement['originals'][] = $item;
            }
        }
        return $agreement;
    }

    /**
     * Ids das contas escolhidas: inteiros positivos, sem repetição, no máximo MAX_ENTRIES (regra pura).
     *
     * @param array<mixed> $raw
     * @return list<int>
     */
    public static function entryIds(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            $id = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[$id] = $id;
            }
        }
        if (count($ids) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('Escolha no máximo ' . self::MAX_ENTRIES . ' contas por acordo.');
        }
        return array_values($ids);
    }

    private function assertRenegotiable(array $entry, array $unit): void
    {
        $label = "Conta {$entry['id']}";
        if ((int)$entry['condominium_id'] !== (int)$unit['condominium_id'] || (int)($entry['unit_id'] ?? 0) !== (int)$unit['id']) {
            throw new InvalidArgumentException("{$label} não é desta unidade.");
        }
        if ($entry['nature'] !== 'receita' || $entry['status'] !== 'active' || $entry['deleted_at'] !== null) {
            throw new InvalidArgumentException("{$label} não é uma conta a receber válida.");
        }
        if ($entry['paid_on'] !== null) {
            throw new DomainException("{$label} já foi paga.");
        }
        if ($entry['agreement_id'] !== null) {
            throw new DomainException("{$label} já está em outro acordo.");
        }
        if ($this->registry->isClosed($entry['condominium_id'], $entry['competence'])) {
            throw new DomainException("{$label}: a competência {$entry['competence']} está fechada. Reabra o fechamento mensal para renegociar.");
        }
        $charge = $this->pdo->prepare("SELECT 1 FROM erp_bank_charges WHERE entry_id = :e AND status IN ('pending','registered') LIMIT 1");
        $charge->execute(['e' => $entry['id']]);
        if ($charge->fetchColumn()) {
            throw new DomainException("{$label} tem boleto ativo. Cancele o boleto antes do acordo.");
        }
    }

    /** @return array{paid_installments:int, paid_cents:int, open_cents:int, settled:bool} */
    private function progress(int $agreementId): array
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(CASE WHEN paid_on IS NOT NULL THEN 1 END) AS paid_n,
                COALESCE(SUM(CASE WHEN paid_on IS NOT NULL THEN amount_cents END), 0) AS paid,
                COALESCE(SUM(CASE WHEN paid_on IS NULL THEN amount_cents END), 0) AS open_, COUNT(*) AS n
            FROM erp_entries WHERE agreement_id = :a AND agreement_role = 'installment' AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute(['a' => $agreementId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['paid_installments' => (int)$r['paid_n'], 'paid_cents' => (int)$r['paid'], 'open_cents' => (int)$r['open_'],
            'settled' => (int)$r['n'] > 0 && (int)$r['paid_n'] === (int)$r['n']];
    }

    private function unit(User $user, int $unitId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, condominium_id, label FROM erp_units WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute(['id' => $unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($unit) || !$this->scope->canAccess($user, (int)$unit['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $unit;
    }

    private function row(User $user, int $agreementId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_agreements WHERE id = :id');
        $stmt->execute(['id' => $agreementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId, bool $strict = true): array
    {
        $allowed = array_map('intval', $this->scope->allowedIds($user));
        if ($condominiumId === null) {
            return $allowed;
        }
        if (!in_array($condominiumId, $allowed, true)) {
            if ($strict) {
                throw new RegistryNotFound();
            }
            return [];
        }
        return [$condominiumId];
    }

    private function cents(mixed $value, string $label): int
    {
        if ($value === null || $value === '' || $value === 0) {
            return 0;
        }
        $cents = Invoices::cents($value);
        if ($cents === null) {
            throw new InvalidArgumentException("{$label}: valor inválido.");
        }
        return $cents;
    }

    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium_name' => $row['condominium_name'] ?? null,
            'unit_id' => (int)$row['unit_id'], 'unit_label' => $row['unit_label'] ?? null, 'status' => (string)$row['status'],
            'chart_account_id' => (int)$row['chart_account_id'], 'original_cents' => (int)$row['original_cents'],
            'fees_cents' => (int)$row['fees_cents'], 'discount_cents' => (int)$row['discount_cents'], 'total_cents' => (int)$row['total_cents'],
            'installments' => (int)$row['installments'], 'first_due_on' => (string)$row['first_due_on'], 'notes' => $row['notes'],
            'cancelled_at' => $row['cancelled_at'], 'cancel_reason' => $row['cancel_reason'], 'created_at' => $row['created_at'],
            'breached_at' => $row['breached_at'] ?? null, 'breach_reason' => $row['breach_reason'] ?? null,
            // Quebra calculada pela regra (ADR 0040); só vira 'breached' pela ação explícita.
            'breach' => $row['status'] === 'active' ? $this->computeBreach($row, date('Y-m-d')) : null,
        ];
    }
}
