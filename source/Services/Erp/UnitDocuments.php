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
 * Documentos da unidade (AP-123, 124, 125 e 130; ADR 0044), gerados das contas a receber válidas:
 *
 * - debt_certificate (Certidão de Débitos): sempre emite; "nada consta" sem conta vencida, senão lista
 *   as vencidas com encargos (ADR 0040) e as a vencer em aberto.
 * - delinquency_declaration (Declaração de Inadimplência): só com conta vencida em aberto.
 * - quittance (Comprovante de Quitação): todas as contas até a competência informada pagas (ou
 *   canceladas); com alguma em aberto, recusa.
 * - consent_letter (Carta de Anuência): só sem conta vencida em aberto; registra a finalidade.
 * - receipt (Recibo, AP-040, ADR 0057): de contas a receber já pagas da unidade, escolhidas uma a uma;
 *   cada conta só tem um recibo válido (cancelar o recibo libera a conta para outro).
 *
 * O conteúdo fica gravado (foto) com código de verificação único; emitido não muda. Cancelar exige
 * motivo. Escopo por condomínio (ADR-002): fora do escopo = RegistryNotFound (404). Tudo auditado.
 */
final class UnitDocuments
{
    public const KINDS = [
        'debt_certificate' => 'Certidão de Débitos',
        'delinquency_declaration' => 'Declaração de Inadimplência',
        'quittance' => 'Comprovante de Quitação',
        'consent_letter' => 'Carta de Anuência',
    ];
    /** Todos os tipos emitidos aqui: os documentos de AP-123 a AP-130 (KINDS) e o recibo (AP-040, ADR 0057), que tem página própria. */
    public const ALL_KINDS = self::KINDS + ['receipt' => 'Recibo'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Código de verificação: 16 caracteres sem ambiguidade (sem 0/O, 1/I/L), em 4 grupos na tela. */
    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 16; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    /** Normaliza o código digitado ("ABCD-EFGH ..." → "ABCDEFGH...") ou null se inválido. */
    public static function normalizeCode(string $code): ?string
    {
        $code = strtoupper((string)preg_replace('/[\s-]+/', '', $code));
        return preg_match('/^[A-HJ-NP-Z2-9]{16}$/', $code) ? $code : null;
    }

    /**
     * Separa as contas a receber em aberto da unidade em vencidas e a vencer (regra pura).
     *
     * @param list<array{due_on:string, amount_cents:int|string}> $open
     * @return array{overdue:list<array>, upcoming:list<array>, overdue_cents:int, upcoming_cents:int}
     */
    public static function split(array $open, string $asOf): array
    {
        $result = ['overdue' => [], 'upcoming' => [], 'overdue_cents' => 0, 'upcoming_cents' => 0];
        foreach ($open as $row) {
            $key = (string)$row['due_on'] < $asOf ? 'overdue' : 'upcoming';
            $result[$key][] = $row;
            $result[$key . '_cents'] += (int)$row['amount_cents'];
        }
        return $result;
    }

    /**
     * @param array{kind?:mixed, unit_id?:mixed, as_of?:mixed, until_competence?:mixed, purpose?:mixed} $input
     */
    public function issue(User $user, array $input): array
    {
        $kind = is_string($input['kind'] ?? null) && isset(self::ALL_KINDS[$input['kind']]) ? $input['kind'] : null;
        if ($kind === null) {
            throw new InvalidArgumentException('Tipo de documento inválido.');
        }
        $asOf = is_string($input['as_of'] ?? null) && $input['as_of'] !== '' ? $input['as_of'] : date('Y-m-d');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
        if ($date === false || $date->format('Y-m-d') !== $asOf || $asOf > date('Y-m-d')) {
            throw new InvalidArgumentException('Data de referência inválida (AAAA-MM-DD, até hoje).');
        }
        $unit = $this->unit($user, (int)filter_var($input['unit_id'] ?? 0, FILTER_VALIDATE_INT));
        $reference = null;

        if ($kind === 'receipt') {
            return $this->receipt($user, $unit, $asOf, $input['entry_ids'] ?? null);
        }

        if ($kind === 'quittance') {
            $until = is_string($input['until_competence'] ?? null) ? $input['until_competence'] : '';
            if (!RecurringEntries::isCompetence($until)) {
                throw new InvalidArgumentException('Informe a competência final da quitação (AAAA-MM).');
            }
            $reference = $until;
            $stmt = $this->pdo->prepare("SELECT id, description, competence, due_on, amount_cents FROM erp_entries
                WHERE unit_id = :u AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL AND competence <= :m
                ORDER BY due_on, id");
            $stmt->execute(['u' => $unit['id'], 'm' => $until]);
            $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($pending !== []) {
                throw new DomainException(sprintf('Há %d conta(s) em aberto até %s. Não é possível emitir a quitação.', count($pending), $until));
            }
            $paid = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount_cents), 0) FROM erp_entries
                WHERE unit_id = :u AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NOT NULL AND competence <= :m");
            $paid->execute(['u' => $unit['id'], 'm' => $until]);
            [$count, $sum] = $paid->fetch(PDO::FETCH_NUM);
            $payload = ['until_competence' => $until, 'paid_entries' => (int)$count, 'paid_cents' => (int)$sum];
            return $this->store($user, $unit, $kind, $asOf, $reference, 'clear', (int)$sum, $payload);
        }

        $stmt = $this->pdo->prepare("SELECT id, description, competence, due_on, amount_cents FROM erp_entries
            WHERE unit_id = :u AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL
            ORDER BY due_on, id");
        $stmt->execute(['u' => $unit['id']]);
        $parts = self::split($stmt->fetchAll(PDO::FETCH_ASSOC), $asOf);

        // Encargos das vencidas pela regra do condomínio (ADR 0040).
        $rules = new BillingRules($this->pdo, $this->scope);
        $rule = $rules->rule((int)$unit['condominium_id']);
        $rates = $parts['overdue'] === [] ? [] : $rules->rates('IPCA', substr((string)$parts['overdue'][0]['due_on'], 0, 7), substr($asOf, 0, 7));
        $overdue = [];
        $charges = 0;
        foreach ($parts['overdue'] as $row) {
            $c = LateCharges::compute((int)$row['amount_cents'], (string)$row['due_on'], $asOf, $rule, $rates);
            $overdue[] = ['entry_id' => (int)$row['id'], 'description' => (string)$row['description'], 'competence' => (string)$row['competence'],
                'due_on' => (string)$row['due_on'], 'amount_cents' => (int)$row['amount_cents']] + $c;
            $charges += $c['charges_cents'];
        }
        $upcoming = array_map(static fn (array $r): array => ['entry_id' => (int)$r['id'], 'description' => (string)$r['description'],
            'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'], 'amount_cents' => (int)$r['amount_cents']], $parts['upcoming']);
        $hasDebts = $overdue !== [];

        if ($kind === 'delinquency_declaration' && !$hasDebts) {
            throw new DomainException('A unidade não tem contas vencidas em aberto nesta data.');
        }
        if ($kind === 'consent_letter') {
            if ($hasDebts) {
                throw new DomainException('A unidade tem contas vencidas em aberto. A carta de anuência exige não haver débitos vencidos.');
            }
            $purpose = is_string($input['purpose'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $input['purpose'])) : '';
            if (mb_strlen($purpose) < 5 || mb_strlen($purpose) > 40) {
                throw new InvalidArgumentException('Informe a finalidade da carta de anuência (5 a 40 caracteres, ex.: venda da unidade).');
            }
            $reference = $purpose;
        }
        $payload = ['overdue' => $overdue, 'upcoming' => $upcoming, 'overdue_cents' => $parts['overdue_cents'], 'charges_cents' => $charges,
            'upcoming_cents' => $parts['upcoming_cents'], 'rule' => $rule,
            'missing_index' => array_values(array_unique(array_merge([], ...array_map(static fn (array $o): array => $o['missing_index'], $overdue))))];
        return $this->store($user, $unit, $kind, $asOf, $reference, $hasDebts ? 'debts' : 'clear', $parts['overdue_cents'] + $charges, $payload);
    }

    /** Documento pelo código (verificação de autenticidade), dentro do escopo do usuário. */
    public function verify(User $user, string $code): array
    {
        $normalized = self::normalizeCode($code);
        if ($normalized === null) {
            throw new RegistryNotFound();
        }
        $stmt = $this->pdo->prepare('SELECT id FROM erp_unit_documents WHERE verification_code = :c');
        $stmt->execute(['c' => $normalized]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RegistryNotFound();
        }
        return $this->find($user, (int)$id);
    }

    /**
     * Recibo (AP-040): contas a receber pagas da unidade. Recusa conta em aberto, cancelada, de outra
     * unidade ou que já tem recibo válido. Grava o recibo e as contas cobertas na mesma transação.
     */
    private function receipt(User $user, array $unit, string $asOf, mixed $rawIds): array
    {
        $ids = is_array($rawIds) ? array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn (int $i): bool => $i > 0))) : [];
        if ($ids === [] || count($ids) > 60) {
            throw new InvalidArgumentException('Escolha de 1 a 60 contas pagas para o recibo.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT e.id, e.description, e.competence, e.due_on, e.paid_on, e.amount_cents, e.unit_id, e.nature, e.status, e.deleted_at,
                    b.name AS bank_account FROM erp_entries e LEFT JOIN erp_bank_accounts b ON b.id = e.bank_account_id
                WHERE e.id IN (' . implode(',', $ids) . ') ORDER BY e.paid_on, e.id FOR UPDATE');
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) {
                throw new InvalidArgumentException('Alguma conta escolhida não existe.');
            }
            foreach ($rows as $row) {
                if ((int)$row['unit_id'] !== (int)$unit['id'] || $row['nature'] !== 'receita' || $row['deleted_at'] !== null || $row['status'] !== 'active') {
                    throw new InvalidArgumentException('O recibo só cobre contas a receber válidas desta unidade.');
                }
                if ($row['paid_on'] === null || $row['paid_on'] > $asOf) {
                    throw new DomainException("A conta \"{$row['description']}\" não está paga nesta data. Recibo só de conta paga.");
                }
            }
            $taken = $this->pdo->query("SELECT de.entry_id FROM erp_unit_document_entries de JOIN erp_unit_documents d ON d.id = de.document_id
                WHERE d.status = 'issued' AND de.entry_id IN (" . implode(',', $ids) . ') LIMIT 1')->fetchColumn();
            if ($taken !== false) {
                throw new DomainException('Uma das contas já tem recibo válido. Cancele o recibo anterior para emitir outro.');
            }
            $entries = array_map(static fn (array $r): array => ['entry_id' => (int)$r['id'], 'description' => (string)$r['description'],
                'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'], 'paid_on' => (string)$r['paid_on'],
                'amount_cents' => (int)$r['amount_cents'], 'bank_account' => $r['bank_account']], $rows);
            $total = array_sum(array_column($entries, 'amount_cents'));
            $document = $this->store($user, $unit, 'receipt', $asOf, sprintf('%d conta(s)', count($entries)), 'clear', $total,
                ['entries' => $entries, 'paid_cents' => $total]);
            $link = $this->pdo->prepare('INSERT INTO erp_unit_document_entries (document_id, entry_id) VALUES (:d, :e)');
            foreach ($ids as $id) {
                $link->execute(['d' => $document['id'], 'e' => $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $document;
    }

    public function cancel(User $user, int $documentId, string $reason): array
    {
        $document = $this->row($user, $documentId);
        $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do cancelamento (5 a 255 caracteres).');
        }
        if ($document['status'] !== 'issued') {
            throw new DomainException('O documento já está cancelado.');
        }
        $this->pdo->prepare("UPDATE erp_unit_documents SET status = 'cancelled', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r WHERE id = :id AND status = 'issued'")
            ->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $documentId]);
        Audit::record('update', 'erp_unit_documents', $documentId, ['status' => 'issued'], ['status' => 'cancelled', 'cancel_reason' => $reason]);
        return $this->find($user, $documentId);
    }

    /** @return array{data:list<array>, total:int, page:int, per_page:int, pages:int} */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = array_map('intval', $this->scope->allowedIds($user));
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        $perPage = 25;
        $page = max(1, (int)(filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        if ($ids === []) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
        $where = 'd.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (is_string($filters['kind'] ?? null) && isset(self::ALL_KINDS[$filters['kind']])) {
            $where .= ' AND d.kind = :k';
            $params['k'] = $filters['kind'];
        }
        $unit = filter_var($filters['unit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($unit !== false && $unit !== null) {
            $where .= ' AND d.unit_id = :u';
            $params['u'] = $unit;
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM erp_unit_documents d WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT d.*, c.name AS condominium_name, u.label AS unit_label FROM erp_unit_documents d
            JOIN condominiums c ON c.id = d.condominium_id JOIN erp_units u ON u.id = d.unit_id
            WHERE {$where} ORDER BY d.created_at DESC, d.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return ['data' => array_map(fn (array $r): array => $this->present($r, false), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }

    public function find(User $user, int $documentId): array
    {
        $this->row($user, $documentId);
        $stmt = $this->pdo->prepare('SELECT d.*, c.name AS condominium_name, u.label AS unit_label FROM erp_unit_documents d
            JOIN condominiums c ON c.id = d.condominium_id JOIN erp_units u ON u.id = d.unit_id WHERE d.id = :id');
        $stmt->execute(['id' => $documentId]);
        return $this->present($stmt->fetch(PDO::FETCH_ASSOC), true);
    }

    private function store(User $user, array $unit, string $kind, string $asOf, ?string $reference, string $result, int $totalCents, array $payload): array
    {
        $payload += ['unit_label' => (string)$unit['label'], 'condominium_name' => (string)$unit['condominium_name']];
        for ($attempt = 0; ; $attempt++) {
            $code = self::newCode();
            try {
                $this->pdo->prepare('INSERT INTO erp_unit_documents (condominium_id, unit_id, kind, as_of, reference, result, total_cents, payload_json, verification_code, issued_by)
                    VALUES (:c, :u, :k, :d, :r, :res, :t, :p, :code, :by)')->execute([
                    'c' => $unit['condominium_id'], 'u' => $unit['id'], 'k' => $kind, 'd' => $asOf, 'r' => $reference, 'res' => $result,
                    't' => $totalCents, 'p' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'code' => $code, 'by' => (int)$user->id,
                ]);
                break;
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062 || $attempt >= 3) {
                    throw $error;
                }
            }
        }
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_unit_documents', $id, null, ['kind' => $kind, 'unit_id' => (int)$unit['id'], 'as_of' => $asOf, 'result' => $result, 'total_cents' => $totalCents]);
        return $this->find($user, $id);
    }

    private function unit(User $user, int $unitId): array
    {
        $stmt = $this->pdo->prepare('SELECT u.id, u.condominium_id, u.label, c.name AS condominium_name FROM erp_units u
            JOIN condominiums c ON c.id = u.condominium_id WHERE u.id = :id AND u.deleted_at IS NULL');
        $stmt->execute(['id' => $unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($unit) || !$this->scope->canAccess($user, (int)$unit['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $unit;
    }

    private function row(User $user, int $documentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_unit_documents WHERE id = :id');
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    private function present(array $row, bool $withPayload): array
    {
        $out = [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium_name' => $row['condominium_name'] ?? null,
            'unit_id' => (int)$row['unit_id'], 'unit_label' => $row['unit_label'] ?? null,
            'kind' => (string)$row['kind'], 'kind_label' => self::ALL_KINDS[$row['kind']] ?? (string)$row['kind'],
            'as_of' => (string)$row['as_of'], 'reference' => $row['reference'], 'result' => (string)$row['result'],
            'total_cents' => (int)$row['total_cents'], 'verification_code' => (string)$row['verification_code'],
            'verification_display' => implode('-', str_split((string)$row['verification_code'], 4)),
            'status' => (string)$row['status'], 'issued_at' => $row['created_at'],
            'cancelled_at' => $row['cancelled_at'], 'cancel_reason' => $row['cancel_reason'],
        ];
        if ($withPayload) {
            $out['content'] = json_decode((string)$row['payload_json'], true);
        }
        return $out;
    }
}
