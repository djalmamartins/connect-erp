<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;

/**
 * Cobrança e multas (ADR 0080, regras conservadoras da Connect, issue #46):
 *
 * - pendingFines() (AP-116): multas avulsas (ADR 0057) ainda não pagas, com a origem (ocorrência ou
 *   reserva) e se estão vencidas.
 * - desk() e recordContact() (AP-117): fila de atendimento das unidades em atraso, com o último
 *   contato, a próxima ação e a promessa de pagamento quebrada (data passou e a unidade segue em
 *   atraso). Contato é histórico: não se edita nem se apaga.
 * - Casos judiciais (AP-039): marcar e acompanhar. O caso guarda as contas cobradas e o valor na
 *   abertura, o advogado (cadastro AP-016), o número do processo e o andamento. **Nada é enviado a
 *   advogado ou tribunal e nenhum encargo é criado aqui.** Um caso aberto por unidade.
 *
 * Escopo por condomínio (ADR-002); tudo auditado.
 */
final class Collections
{
    public const CHANNELS = ['phone' => 'Telefone', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'in_person' => 'Presencial', 'letter' => 'Carta'];
    public const OUTCOMES = ['promise' => 'Promessa de pagamento', 'negotiating' => 'Em negociação', 'no_answer' => 'Sem resposta',
        'refused' => 'Recusou', 'paid_claimed' => 'Diz que pagou', 'other' => 'Outro'];
    public const CASE_STATUSES = ['preparing' => 'Em preparação', 'filed' => 'Ajuizado', 'suspended' => 'Suspenso', 'agreement' => 'Acordo judicial', 'closed' => 'Encerrado'];
    /** Mudanças de situação aceitas; encerrado é final. */
    public const TRANSITIONS = [
        'preparing' => ['filed', 'suspended', 'agreement', 'closed'],
        'filed' => ['suspended', 'agreement', 'closed'],
        'suspended' => ['filed', 'agreement', 'closed'],
        'agreement' => ['filed', 'closed'],
        'closed' => [],
    ];
    public const MAX_PROMISE_DAYS = 90;
    public const MAX_NEXT_ACTION_DAYS = 180;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** A situação do caso pode ir de $from para $to (regra pura)? */
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Promessa quebrada (regra pura): último contato prometeu, a data passou e a unidade segue em atraso. */
    public static function promiseBroken(?array $lastContact, string $asOf, bool $stillOverdue): bool
    {
        return $stillOverdue && $lastContact !== null && ($lastContact['outcome'] ?? null) === 'promise'
            && !empty($lastContact['promise_on']) && (string)$lastContact['promise_on'] < $asOf;
    }

    /** Data opcional entre hoje e hoje + $maxDays (regra pura). */
    public static function futureDate(mixed $value, int $maxDays, string $what, ?string $today = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $today ??= date('Y-m-d');
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Data {$what} inválida (AAAA-MM-DD).");
        }
        $limit = (new \DateTimeImmutable($today))->modify("+{$maxDays} days")->format('Y-m-d');
        if ($value < $today || $value > $limit) {
            throw new InvalidArgumentException("A data {$what} precisa estar entre hoje e {$maxDays} dias à frente.");
        }
        return $value;
    }

    /** Multas avulsas não pagas (AP-116); $includePaid traz também as pagas. */
    public function pendingFines(User $user, ?int $condominiumId = null, bool $includePaid = false): array
    {
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return ['data' => [], 'totals' => ['fines' => 0, 'open_cents' => 0, 'overdue' => 0]];
        }
        $rows = $this->pdo->query("SELECT x.id, x.condominium_id, c.name AS condominium_name, x.description, x.reason, x.due_on, x.occurrence_id, x.reservation_id,
                e.id AS entry_id, e.amount_cents, e.paid_on, u.id AS unit_id, u.label AS unit_label
            FROM erp_extra_charges x JOIN condominiums c ON c.id = x.condominium_id
            JOIN erp_entries e ON e.extra_charge_id = x.id AND e.deleted_at IS NULL AND e.status = 'active'
            JOIN erp_units u ON u.id = e.unit_id
            WHERE x.kind = 'fine' AND x.status = 'active' AND x.condominium_id IN (" . implode(',', $ids) . ')'
            . ($includePaid ? '' : ' AND e.paid_on IS NULL') . ' ORDER BY x.due_on, x.id')->fetchAll(PDO::FETCH_ASSOC);
        $today = date('Y-m-d');
        $data = [];
        $totals = ['fines' => 0, 'open_cents' => 0, 'overdue' => 0];
        foreach ($rows as $r) {
            $state = $r['paid_on'] !== null ? 'paid' : ((string)$r['due_on'] < $today ? 'overdue' : 'open');
            $data[] = ['id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'],
                'unit_id' => (int)$r['unit_id'], 'unit_label' => (string)$r['unit_label'], 'description' => (string)$r['description'], 'reason' => $r['reason'],
                'entry_id' => (int)$r['entry_id'], 'amount_cents' => (int)$r['amount_cents'], 'due_on' => (string)$r['due_on'], 'paid_on' => $r['paid_on'],
                'state' => $state, 'occurrence_id' => $r['occurrence_id'] !== null ? (int)$r['occurrence_id'] : null,
                'reservation_id' => $r['reservation_id'] !== null ? (int)$r['reservation_id'] : null];
            $totals['fines']++;
            if ($state !== 'paid') {
                $totals['open_cents'] += (int)$r['amount_cents'];
            }
            $totals['overdue'] += $state === 'overdue' ? 1 : 0;
        }
        return ['data' => $data, 'totals' => $totals];
    }

    /**
     * Fila de atendimento (AP-117): unidades em atraso, com o último contato, a próxima ação, a
     * promessa quebrada e o caso judicial aberto. Ordem: promessa quebrada, próxima ação vencida,
     * sem contato, maior atraso.
     */
    public function desk(User $user, ?int $condominiumId, ?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $base = (new Agreements($this->pdo, $this->scope))->delinquency($user, $condominiumId, $asOf);
        $unitIds = array_values(array_filter(array_map(static fn (array $u): ?int => $u['unit_id'], $base['units'])));
        $contacts = [];
        $cases = [];
        if ($unitIds !== []) {
            $in = implode(',', array_map('intval', $unitIds));
            foreach ($this->pdo->query("SELECT c.* FROM erp_collection_contacts c JOIN (SELECT unit_id, MAX(id) AS id FROM erp_collection_contacts
                    WHERE unit_id IN ({$in}) GROUP BY unit_id) last ON last.id = c.id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $contacts[(int)$row['unit_id']] = $row;
            }
            foreach ($this->pdo->query("SELECT id, unit_id, status FROM erp_legal_cases WHERE unit_id IN ({$in}) AND status <> 'closed'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cases[(int)$row['unit_id']] = ['id' => (int)$row['id'], 'status' => (string)$row['status']];
            }
        }
        $rows = [];
        foreach ($base['units'] as $unit) {
            $last = $unit['unit_id'] !== null ? ($contacts[(int)$unit['unit_id']] ?? null) : null;
            $broken = self::promiseBroken($last, $asOf, true);
            $due = $last !== null && $last['next_action_on'] !== null && (string)$last['next_action_on'] <= $asOf;
            $rows[] = [
                'condominium_id' => $unit['condominium_id'], 'condominium_name' => $unit['condominium_name'], 'unit_id' => $unit['unit_id'], 'unit_label' => $unit['unit_label'],
                'entries' => count($unit['entries']), 'amount_cents' => (int)$unit['amount_cents'], 'total_cents' => (int)$unit['total_cents'],
                'max_days_late' => (int)$unit['max_days_late'],
                'last_contact' => $last === null ? null : ['id' => (int)$last['id'], 'at' => (string)$last['created_at'], 'channel' => (string)$last['channel'],
                    'outcome' => (string)$last['outcome'], 'promise_on' => $last['promise_on'], 'next_action_on' => $last['next_action_on']],
                'promise_broken' => $broken, 'action_due' => $due,
                'legal_case' => $unit['unit_id'] !== null ? ($cases[(int)$unit['unit_id']] ?? null) : null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [!$a['promise_broken'], !$a['action_due'], $a['last_contact'] !== null, -$a['max_days_late']]
            <=> [!$b['promise_broken'], !$b['action_due'], $b['last_contact'] !== null, -$b['max_days_late']]);
        return ['as_of' => $asOf, 'data' => $rows, 'totals' => $base['totals'] + [
            'promise_broken' => count(array_filter($rows, static fn (array $r): bool => $r['promise_broken'])),
            'action_due' => count(array_filter($rows, static fn (array $r): bool => $r['action_due'])),
            'without_contact' => count(array_filter($rows, static fn (array $r): bool => $r['last_contact'] === null)),
        ]];
    }

    /**
     * @param array{unit_id?:mixed, channel?:mixed, outcome?:mixed, contact_name?:mixed, promise_on?:mixed, promise_cents?:mixed, next_action_on?:mixed, note?:mixed} $input
     */
    public function recordContact(User $user, array $input): array
    {
        $unit = $this->unit($user, $input['unit_id'] ?? null);
        $channel = is_string($input['channel'] ?? null) && isset(self::CHANNELS[$input['channel']]) ? $input['channel'] : null;
        $outcome = is_string($input['outcome'] ?? null) && isset(self::OUTCOMES[$input['outcome']]) ? $input['outcome'] : null;
        if ($channel === null || $outcome === null) {
            throw new InvalidArgumentException('Informe o canal e o resultado do contato.');
        }
        $note = Announcements::text($input['note'] ?? null, 5, 1000, 'a nota do contato');
        $name = is_string($input['contact_name'] ?? null) && trim($input['contact_name']) !== '' ? mb_substr(trim($input['contact_name']), 0, 160) : null;
        $promiseOn = self::futureDate($input['promise_on'] ?? null, self::MAX_PROMISE_DAYS, 'da promessa');
        $promiseCents = ($input['promise_cents'] ?? '') !== '' && ($input['promise_cents'] ?? null) !== null ? Invoices::cents($input['promise_cents']) : null;
        if ($outcome === 'promise' && $promiseOn === null) {
            throw new InvalidArgumentException('Promessa de pagamento precisa da data prometida.');
        }
        if ($outcome !== 'promise') {
            [$promiseOn, $promiseCents] = [null, null];
        }
        $next = self::futureDate($input['next_action_on'] ?? null, self::MAX_NEXT_ACTION_DAYS, 'da próxima ação');
        $open = $this->pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE unit_id = :u AND nature = 'receita' AND status = 'active'
            AND deleted_at IS NULL AND paid_on IS NULL AND due_on < CURDATE()");
        $open->execute(['u' => $unit['id']]);
        $openCents = (int)$open->fetchColumn();
        $this->pdo->prepare('INSERT INTO erp_collection_contacts (condominium_id, unit_id, channel, outcome, contact_name, promise_on, promise_cents, next_action_on, note, open_cents, created_by)
            VALUES (:c, :u, :ch, :o, :n, :p, :pc, :na, :note, :open, :by)')->execute(['c' => $unit['condominium_id'], 'u' => $unit['id'], 'ch' => $channel, 'o' => $outcome,
            'n' => $name, 'p' => $promiseOn, 'pc' => $promiseCents, 'na' => $next, 'note' => $note, 'open' => $openCents, 'by' => (int)$user->id]);
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_collection_contacts', $id, null, ['unit_id' => (int)$unit['id'], 'channel' => $channel, 'outcome' => $outcome, 'promise_on' => $promiseOn]);
        return $this->contacts($user, (int)$unit['id'])[0];
    }

    /** @return list<array> contatos da unidade, do mais novo ao mais antigo */
    public function contacts(User $user, int $unitId): array
    {
        $unit = $this->unit($user, $unitId);
        $stmt = $this->pdo->prepare('SELECT * FROM erp_collection_contacts WHERE unit_id = :u ORDER BY id DESC LIMIT 200');
        $stmt->execute(['u' => $unit['id']]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'unit_id' => (int)$r['unit_id'], 'channel' => (string)$r['channel'],
            'outcome' => (string)$r['outcome'], 'outcome_label' => self::OUTCOMES[$r['outcome']] ?? (string)$r['outcome'], 'contact_name' => $r['contact_name'],
            'promise_on' => $r['promise_on'], 'promise_cents' => $r['promise_cents'] !== null ? (int)$r['promise_cents'] : null, 'next_action_on' => $r['next_action_on'],
            'note' => (string)$r['note'], 'open_cents' => (int)$r['open_cents'], 'created_by' => $r['created_by'] !== null ? (int)$r['created_by'] : null,
            'created_at' => (string)$r['created_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Abre o caso judicial (AP-039) com contas vencidas em aberto da unidade (fora de acordo). Só
     * registra: nada é enviado e nenhum encargo é criado.
     *
     * @param array{unit_id?:mixed, entry_ids?:mixed, lawyer_id?:mixed, case_number?:mixed, court?:mixed, opened_on?:mixed, note?:mixed} $input
     */
    public function openCase(User $user, array $input): array
    {
        $unit = $this->unit($user, $input['unit_id'] ?? null);
        $note = Announcements::text($input['note'] ?? null, 5, 1000, 'a nota de abertura');
        $entryIds = is_array($input['entry_ids'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $input['entry_ids']), static fn (int $i): bool => $i > 0))) : [];
        if ($entryIds === [] || count($entryIds) > 200) {
            throw new InvalidArgumentException('Escolha de 1 a 200 contas vencidas da unidade.');
        }
        $openedOn = is_string($input['opened_on'] ?? null) && $input['opened_on'] !== '' ? $input['opened_on'] : date('Y-m-d');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $openedOn);
        if ($date === false || $date->format('Y-m-d') !== $openedOn || $openedOn > date('Y-m-d')) {
            throw new InvalidArgumentException('Data de abertura inválida (até hoje).');
        }
        $lawyer = $this->lawyer($input['lawyer_id'] ?? null);
        [$caseNumber, $court] = $this->details($input);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id, amount_cents, unit_id, nature, status, paid_on, due_on, agreement_id, deleted_at FROM erp_entries
                WHERE id IN (" . implode(',', $entryIds) . ') FOR UPDATE');
            $stmt->execute();
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($entries) !== count($entryIds)) {
                throw new InvalidArgumentException('Alguma conta escolhida não existe.');
            }
            foreach ($entries as $entry) {
                if ((int)$entry['unit_id'] !== (int)$unit['id'] || $entry['nature'] !== 'receita' || $entry['status'] !== 'active' || $entry['deleted_at'] !== null
                    || $entry['paid_on'] !== null || (string)$entry['due_on'] >= date('Y-m-d') || $entry['agreement_id'] !== null) {
                    throw new InvalidArgumentException('O caso só cobre contas a receber vencidas, em aberto e fora de acordo, desta unidade.');
                }
            }
            $claimed = array_sum(array_map(static fn (array $e): int => (int)$e['amount_cents'], $entries));
            try {
                $this->pdo->prepare('INSERT INTO erp_legal_cases (condominium_id, unit_id, lawyer_id, case_number, court, opened_on, claimed_cents, created_by)
                    VALUES (:c, :u, :l, :n, :ct, :o, :v, :by)')->execute(['c' => $unit['condominium_id'], 'u' => $unit['id'], 'l' => $lawyer, 'n' => $caseNumber,
                    'ct' => $court, 'o' => $openedOn, 'v' => $claimed, 'by' => (int)$user->id]);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) === 1062) {
                    throw new DomainException('A unidade já tem um caso judicial aberto. Acompanhe por ele.');
                }
                throw $error;
            }
            $caseId = (int)$this->pdo->lastInsertId();
            $link = $this->pdo->prepare('INSERT INTO erp_legal_case_entries (case_id, entry_id, amount_cents) VALUES (:c, :e, :v)');
            foreach ($entries as $entry) {
                $link->execute(['c' => $caseId, 'e' => $entry['id'], 'v' => $entry['amount_cents']]);
            }
            $this->event($user, $caseId, 'opened', null, 'preparing', $note, $openedOn);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_legal_cases', $caseId, null, ['unit_id' => (int)$unit['id'], 'entries' => $entryIds, 'claimed_cents' => $claimed, 'lawyer_id' => $lawyer]);
        return $this->findCase($user, $caseId);
    }

    /**
     * Andamento do caso: nota, ou mudança de situação (to_status) pelas TRANSITIONS. Encerrar exige
     * motivo (a nota). Também atualiza advogado, número e vara, se vierem.
     *
     * @param array{note?:mixed, to_status?:mixed, happened_on?:mixed, lawyer_id?:mixed, case_number?:mixed, court?:mixed} $input
     */
    public function addEvent(User $user, int $caseId, array $input): array
    {
        $case = $this->caseRow($user, $caseId);
        if ($case['status'] === 'closed') {
            throw new DomainException('O caso está encerrado.');
        }
        $note = Announcements::text($input['note'] ?? null, 5, 1000, 'a nota do andamento');
        $on = is_string($input['happened_on'] ?? null) && $input['happened_on'] !== '' ? $input['happened_on'] : date('Y-m-d');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        if ($date === false || $date->format('Y-m-d') !== $on || $on > date('Y-m-d') || $on < (string)$case['opened_on']) {
            throw new InvalidArgumentException('Data do andamento inválida (entre a abertura e hoje).');
        }
        $to = is_string($input['to_status'] ?? null) && $input['to_status'] !== '' ? $input['to_status'] : null;
        if ($to !== null && !self::canTransition((string)$case['status'], $to)) {
            throw new DomainException(sprintf('O caso não pode ir de "%s" para "%s".', self::CASE_STATUSES[$case['status']] ?? $case['status'], self::CASE_STATUSES[$to] ?? $to));
        }
        $changes = [];
        if (array_key_exists('lawyer_id', $input)) {
            $changes['lawyer_id'] = $this->lawyer($input['lawyer_id']);
        }
        if (array_key_exists('case_number', $input) || array_key_exists('court', $input)) {
            [$number, $court] = $this->details($input + ['case_number' => $case['case_number'], 'court' => $case['court']]);
            $changes['case_number'] = $number;
            $changes['court'] = $court;
        }
        $this->pdo->beginTransaction();
        try {
            if ($to !== null) {
                $changes['status'] = $to;
                if ($to === 'closed') {
                    $changes['closed_on'] = $on;
                    $changes['close_reason'] = mb_substr($note, 0, 255);
                }
            }
            if ($changes !== []) {
                $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = :{$c}", array_keys($changes)));
                $this->pdo->prepare("UPDATE erp_legal_cases SET {$sets} WHERE id = :id")->execute($changes + ['id' => $caseId]);
            }
            $this->event($user, $caseId, $to === null ? 'note' : ($to === 'closed' ? 'closed' : 'status'), $to === null ? null : (string)$case['status'], $to, $note, $on);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_legal_cases', $caseId, ['status' => $case['status']], $changes + ['note' => mb_substr($note, 0, 200)]);
        return $this->findCase($user, $caseId);
    }

    /** @return array{data:list<array>, total:int} */
    public function listCases(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return ['data' => [], 'total' => 0];
        }
        $where = 'k.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (isset(self::CASE_STATUSES[$filters['status'] ?? ''])) {
            $where .= ' AND k.status = :s';
            $params['s'] = $filters['status'];
        } elseif (!filter_var($filters['include_closed'] ?? false, FILTER_VALIDATE_BOOL)) {
            $where .= " AND k.status <> 'closed'";
        }
        $stmt = $this->pdo->prepare($this->caseSql() . " WHERE {$where} ORDER BY k.opened_on DESC, k.id DESC LIMIT 200");
        $stmt->execute($params);
        $data = array_map(fn (array $r): array => $this->presentCase($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $data, 'total' => count($data)];
    }

    public function findCase(User $user, int $caseId): array
    {
        $out = $this->presentCase($this->caseRow($user, $caseId));
        $stmt = $this->pdo->prepare('SELECT le.entry_id, le.amount_cents AS claimed_cents, e.description, e.due_on, e.paid_on, e.status, e.amount_cents
            FROM erp_legal_case_entries le JOIN erp_entries e ON e.id = le.entry_id WHERE le.case_id = :c ORDER BY e.due_on, e.id');
        $stmt->execute(['c' => $caseId]);
        $out['entries'] = array_map(static fn (array $r): array => ['entry_id' => (int)$r['entry_id'], 'description' => (string)$r['description'],
            'due_on' => (string)$r['due_on'], 'claimed_cents' => (int)$r['claimed_cents'], 'amount_cents' => (int)$r['amount_cents'],
            'paid_on' => $r['paid_on'], 'status' => (string)$r['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $out['still_open_cents'] = array_sum(array_map(static fn (array $e): int => $e['paid_on'] === null && $e['status'] === 'active' ? $e['amount_cents'] : 0, $out['entries']));
        $stmt = $this->pdo->prepare('SELECT id, kind, from_status, to_status, note, happened_on, created_by, created_at FROM erp_legal_case_events WHERE case_id = :c ORDER BY happened_on, id');
        $stmt->execute(['c' => $caseId]);
        $out['events'] = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'from_status' => $r['from_status'],
            'to_status' => $r['to_status'], 'note' => (string)$r['note'], 'happened_on' => (string)$r['happened_on'],
            'created_by' => $r['created_by'] !== null ? (int)$r['created_by'] : null, 'created_at' => (string)$r['created_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $out;
    }

    private function event(User $user, int $caseId, string $kind, ?string $from, ?string $to, string $note, string $on): void
    {
        $this->pdo->prepare('INSERT INTO erp_legal_case_events (case_id, kind, from_status, to_status, note, happened_on, created_by) VALUES (:c, :k, :f, :t, :n, :d, :u)')
            ->execute(['c' => $caseId, 'k' => $kind, 'f' => $from, 't' => $to, 'n' => $note, 'd' => $on, 'u' => (int)$user->id]);
    }

    /** @return array{0:?string, 1:?string} número do processo e vara, limpos */
    private function details(array $input): array
    {
        $number = is_string($input['case_number'] ?? null) ? trim($input['case_number']) : '';
        if ($number !== '' && !preg_match('/^[0-9.\-\/ ]{5,40}$/', $number)) {
            throw new InvalidArgumentException('Número do processo inválido (dígitos, pontos, traços e barras).');
        }
        $court = is_string($input['court'] ?? null) ? mb_substr(trim((string)preg_replace('/\s+/u', ' ', $input['court'])), 0, 160) : '';
        return [$number !== '' ? $number : null, $court !== '' ? $court : null];
    }

    private function lawyer(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $stmt = $this->pdo->prepare("SELECT id FROM erp_lawyers WHERE id = :id AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => (int)$id]);
        if ($id === false || $stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Escolha um advogado ativo do cadastro (AP-016).');
        }
        return (int)$id;
    }

    private function unit(User $user, mixed $value): array
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $stmt = $this->pdo->prepare('SELECT id, condominium_id, label FROM erp_units WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id === false ? 0 : (int)$id]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($unit) || !$this->scope->canAccess($user, (int)$unit['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $unit;
    }

    private function caseSql(): string
    {
        return 'SELECT k.*, c.name AS condominium_name, u.label AS unit_label, l.name AS lawyer_name FROM erp_legal_cases k
            JOIN condominiums c ON c.id = k.condominium_id JOIN erp_units u ON u.id = k.unit_id LEFT JOIN erp_lawyers l ON l.id = k.lawyer_id';
    }

    private function caseRow(User $user, int $caseId): array
    {
        $stmt = $this->pdo->prepare($this->caseSql() . ' WHERE k.id = :id');
        $stmt->execute(['id' => $caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function presentCase(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => $r['condominium_name'] ?? null,
            'unit_id' => (int)$r['unit_id'], 'unit_label' => $r['unit_label'] ?? null, 'lawyer_id' => $r['lawyer_id'] !== null ? (int)$r['lawyer_id'] : null,
            'lawyer_name' => $r['lawyer_name'] ?? null, 'status' => (string)$r['status'], 'status_label' => self::CASE_STATUSES[$r['status']] ?? (string)$r['status'],
            'case_number' => $r['case_number'], 'court' => $r['court'], 'opened_on' => (string)$r['opened_on'], 'claimed_cents' => (int)$r['claimed_cents'],
            'closed_on' => $r['closed_on'], 'close_reason' => $r['close_reason'], 'next_statuses' => self::TRANSITIONS[$r['status']] ?? [],
        ];
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $i): bool => $i > 0));
        if ($condominiumId !== null) {
            return in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        return $ids;
    }
}
