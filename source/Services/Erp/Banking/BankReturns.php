<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Audit;

/**
 * Retorno bancário e conciliação (AP-036/038, ADR 0035).
 *
 * - O arquivo inteiro é validado pelo adaptador antes de qualquer gravação (layout, trailer, totais).
 * - O mesmo arquivo (sha256 por conta) não é processado de novo; o mesmo evento (event_key) também não.
 * - Pagamento só dá baixa automática quando o valor pago é igual ao do título e o título está
 *   registrado ou aguardando banco. Valor diferente, título já pago ou cancelado, conta a receber já
 *   baixada à mão ou competência fechada vão para a fila de divergências: nunca baixa silenciosa.
 * - A baixa grava paid_on e a conta bancária do retorno no lançamento (a trava de fatura deixa a baixa).
 */
final class BankReturns
{
    public const OUTCOMES = ['applied' => 'Aplicado', 'duplicate' => 'Repetido', 'divergence' => 'Divergência', 'unknown_charge' => 'Título não encontrado'];
    private const MAX_BYTES = 2_000_000;

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = new RegistryService($this->pdo, $this->scope);
    }

    /**
     * Decide o que fazer com um evento (regra pura). $charge null = título não encontrado.
     *
     * @param array{kind:string, amount_cents:?int} $event
     * @param array{status:string, amount_cents:int|string}|null $charge
     * @return array{outcome:string, status:?string, note:?string} status = novo estado do título
     */
    public static function decide(array $event, ?array $charge): array
    {
        if ($charge === null) {
            return ['outcome' => 'unknown_charge', 'status' => null, 'note' => 'Nosso número não encontrado nesta conta bancária.'];
        }
        $status = $charge['status'];
        switch ($event['kind']) {
            case 'paid':
                if ($status === 'paid') {
                    return ['outcome' => 'divergence', 'status' => null, 'note' => 'Pagamento de título que já estava pago.'];
                }
                if (!in_array($status, ['registered', 'pending'], true)) {
                    return ['outcome' => 'divergence', 'status' => null, 'note' => "Pagamento de título {$status}."];
                }
                if ($event['amount_cents'] === null || (int)$event['amount_cents'] !== (int)$charge['amount_cents']) {
                    return ['outcome' => 'divergence', 'status' => null, 'note' => sprintf('Valor pago (%s) diferente do título (%s).', $event['amount_cents'] ?? 'vazio', (int)$charge['amount_cents'])];
                }
                return ['outcome' => 'applied', 'status' => 'paid', 'note' => null];
            case 'registered':
                return $status === 'pending' ? ['outcome' => 'applied', 'status' => 'registered', 'note' => null] : ['outcome' => 'duplicate', 'status' => null, 'note' => null];
            case 'rejected':
                return in_array($status, ['pending', 'registered'], true)
                    ? ['outcome' => 'applied', 'status' => 'rejected', 'note' => null]
                    : ['outcome' => 'divergence', 'status' => null, 'note' => "Recusa de título {$status}."];
            case 'cancelled':
                if ($status === 'cancelled') {
                    return ['outcome' => 'duplicate', 'status' => null, 'note' => null];
                }
                return in_array($status, ['pending', 'registered'], true)
                    ? ['outcome' => 'applied', 'status' => 'cancelled', 'note' => null]
                    : ['outcome' => 'divergence', 'status' => null, 'note' => "Baixa pelo banco de título {$status}."];
        }
        return ['outcome' => 'divergence', 'status' => null, 'note' => 'Ocorrência desconhecida.'];
    }

    /** @return array{file:array, duplicate_file:bool, events:list<array>} */
    public function process(User $user, int $bankAccountId, string $filename, string $content): array
    {
        $account = $this->bankAccount($user, $bankAccountId);
        if ($content === '' || strlen($content) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Arquivo de retorno vazio ou maior que 2 MB.');
        }
        $filename = mb_substr((string)preg_replace('/[^\w.\- ]+/u', '_', basename($filename)), 0, 120) ?: 'retorno.txt';
        $hash = hash('sha256', $content);
        $existing = $this->fileByHash($bankAccountId, $hash);
        if ($existing !== null) {
            return ['file' => $existing, 'duplicate_file' => true, 'events' => $this->eventsOfFile((int)$existing['id'])];
        }
        $adapter = BankAdapters::forAccount($account, $this->pdo);
        $events = $adapter->parseReturn($content);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO erp_bank_return_files (condominium_id, bank_account_id, adapter, filename, sha256, uploaded_by)
                VALUES (:c, :b, :a, :f, :h, :u)')->execute(['c' => $account['condominium_id'], 'b' => $bankAccountId, 'a' => $adapter->name(), 'f' => $filename, 'h' => $hash, 'u' => (int)$user->id]);
            $fileId = (int)$this->pdo->lastInsertId();
            $counts = ['events' => 0, 'applied' => 0, 'divergence' => 0, 'total' => 0];
            foreach ($events as $event) {
                $counts['events']++;
                $counts['total'] += (int)($event['amount_cents'] ?? 0);
                $outcome = $this->applyEvent($user, $account, $fileId, $event);
                if ($outcome === 'applied') {
                    $counts['applied']++;
                } elseif (in_array($outcome, ['divergence', 'unknown_charge'], true)) {
                    $counts['divergence']++;
                }
            }
            $this->pdo->prepare('UPDATE erp_bank_return_files SET events_count = :e, applied_count = :a, divergence_count = :d, total_cents = :t WHERE id = :id')
                ->execute(['e' => $counts['events'], 'a' => $counts['applied'], 'd' => $counts['divergence'], 't' => $counts['total'], 'id' => $fileId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_bank_return_files', $fileId, null, ['filename' => $filename, 'events' => $counts['events'], 'applied' => $counts['applied'], 'divergence' => $counts['divergence']]);
        return ['file' => $this->fileRow($fileId), 'duplicate_file' => false, 'events' => $this->eventsOfFile($fileId)];
    }

    /**
     * Arquivos de retorno importados, mais recentes primeiro ($condominiumId null = todos do escopo;
     * condomínio fora do escopo = lista vazia, sem revelar se existe).
     *
     * @param array{page?:mixed, bank_account_id?:mixed} $filters
     * @return array{data:list<array>, total:int, page:int, per_page:int, pages:int}
     */
    public function files(User $user, ?int $condominiumId = null, array $filters = []): array
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
        $where = 'f.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        $bank = filter_var($filters['bank_account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($bank !== false && $bank !== null) {
            $where .= ' AND f.bank_account_id = :b';
            $params['b'] = $bank;
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM erp_bank_return_files f WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT f.*, b.name AS bank_account_name, c.name AS condominium_name FROM erp_bank_return_files f
            JOIN erp_bank_accounts b ON b.id = f.bank_account_id JOIN condominiums c ON c.id = f.condominium_id
            WHERE {$where} ORDER BY f.created_at DESC, f.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return ['data' => array_map(static fn (array $r): array => self::presentFile($r) + [
                'bank_account_name' => (string)$r['bank_account_name'], 'condominium_name' => (string)$r['condominium_name'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }

    /**
     * Fila de revisão: eventos com divergência ou título não encontrado ainda não resolvidos.
     *
     * @return list<array>
     */
    public function divergences(User $user, ?int $condominiumId = null): array
    {
        $ids = $this->scope->allowedIds($user);
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->query("SELECT * FROM erp_bank_events WHERE condominium_id IN (" . implode(',', array_map('intval', $ids)) . ")
            AND outcome IN ('divergence','unknown_charge') AND resolved_at IS NULL ORDER BY id DESC LIMIT 200");
        return array_map(fn (array $r): array => $this->presentEvent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Marca a divergência como revisada (a correção em si é feita à mão no lançamento ou no título). */
    public function resolve(User $user, int $eventId, string $note): array
    {
        $note = trim((string)preg_replace('/\s+/u', ' ', $note));
        if (mb_strlen($note) < 5 || mb_strlen($note) > 255) {
            throw new InvalidArgumentException('Descreva o que foi feito (5 a 255 caracteres).');
        }
        $stmt = $this->pdo->prepare("SELECT * FROM erp_bank_events WHERE id = :id AND outcome IN ('divergence','unknown_charge')");
        $stmt->execute(['id' => $eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($event) || !$this->scope->canAccess($user, (int)$event['condominium_id'])) {
            throw new RegistryNotFound();
        }
        if ($event['resolved_at'] !== null) {
            throw new \DomainException('Esta divergência já foi resolvida.');
        }
        $this->pdo->prepare('UPDATE erp_bank_events SET resolved_by = :u, resolved_at = NOW(), note = :n WHERE id = :id AND resolved_at IS NULL')
            ->execute(['u' => (int)$user->id, 'n' => mb_substr(trim(((string)$event['note']) . ' | Resolução: ' . $note), 0, 255), 'id' => $eventId]);
        Audit::record('update', 'erp_bank_events', $eventId, ['resolved' => false], ['resolved' => true, 'note' => $note]);
        $stmt->execute(['id' => $eventId]);
        return $this->presentEvent($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Um evento fora de arquivo (consulta do boleto ou webhook, ADR 0156), na própria transação.
     * $user null = processamento do sistema (webhook). Mesmo event_key = 'duplicate'.
     */
    public function recordEvent(?User $user, array $account, array $event): string
    {
        $this->pdo->beginTransaction();
        try {
            $outcome = $this->applyEvent($user, $account, null, $event);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof \PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                return 'duplicate';   // o mesmo evento chegou ao mesmo tempo por outro caminho
            }
            throw $error;
        }
        return $outcome;
    }

    /** Aplica um evento dentro da transação de quem chama. */
    private function applyEvent(?User $user, array $account, ?int $fileId, array $event): string
    {
        $seen = $this->pdo->prepare('SELECT 1 FROM erp_bank_events WHERE bank_account_id = :b AND event_key = :k');
        $seen->execute(['b' => $account['id'], 'k' => $event['event_key']]);
        if ($seen->fetchColumn()) {
            return 'duplicate';   // evento já processado em outro arquivo: não grava de novo
        }
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_charges WHERE bank_account_id = :b AND our_number = :n FOR UPDATE');
        $stmt->execute(['b' => $account['id'], 'n' => $event['our_number']]);
        $charge = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $decision = self::decide($event, $charge);

        if ($decision['outcome'] === 'applied' && $decision['status'] === 'paid') {
            $decision = $this->settle($user, $account, $charge, $event, $fileId) ?? $decision;
        }
        if ($decision['outcome'] === 'applied' && $decision['status'] !== null) {
            $this->pdo->prepare('UPDATE erp_bank_charges SET status = :s, paid_cents = :p, paid_on = :d,
                    registered_at = CASE WHEN :s2 = \'registered\' THEN COALESCE(registered_at, NOW()) ELSE registered_at END,
                    cancelled_at = CASE WHEN :s3 = \'cancelled\' THEN NOW() ELSE cancelled_at END WHERE id = :id')
                ->execute(['s' => $decision['status'], 's2' => $decision['status'], 's3' => $decision['status'],
                    'p' => $decision['status'] === 'paid' ? $event['amount_cents'] : $charge['paid_cents'],
                    'd' => $decision['status'] === 'paid' ? $event['occurred_on'] : $charge['paid_on'], 'id' => $charge['id']]);
            Audit::record('update', 'erp_bank_charges', (int)$charge['id'], ['status' => $charge['status']], ['status' => $decision['status'], 'event_key' => $event['event_key']]);
        }
        $this->pdo->prepare('INSERT INTO erp_bank_events (condominium_id, bank_account_id, return_file_id, charge_id, event_key, kind, our_number, amount_cents, occurred_on, outcome, note, raw)
            VALUES (:c, :b, :f, :ch, :k, :kind, :n, :a, :d, :o, :note, :raw)')->execute([
            'c' => $account['condominium_id'], 'b' => $account['id'], 'f' => $fileId, 'ch' => $charge['id'] ?? null,
            'k' => $event['event_key'], 'kind' => $event['kind'], 'n' => $event['our_number'], 'a' => $event['amount_cents'],
            'd' => $event['occurred_on'], 'o' => $decision['outcome'], 'note' => $decision['note'],
            'raw' => json_encode($event, JSON_UNESCAPED_UNICODE),
        ]);
        return $decision['outcome'];
    }

    /** Baixa da conta a receber; devolve uma divergência quando não dá para baixar com segurança. */
    private function settle(?User $user, array $account, array $charge, array $event, ?int $fileId = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_entries WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $charge['entry_id']]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($entry) || $entry['deleted_at'] !== null || $entry['status'] !== 'active') {
            return ['outcome' => 'divergence', 'status' => null, 'note' => 'Conta a receber do título foi cancelada ou excluída.'];
        }
        if ($entry['paid_on'] !== null) {
            return ['outcome' => 'divergence', 'status' => null, 'note' => 'Conta a receber já estava baixada à mão.'];
        }
        if ($this->registry->isClosed($entry['condominium_id'], $entry['competence'])) {
            return ['outcome' => 'divergence', 'status' => null, 'note' => "Competência {$entry['competence']} fechada: reabra para baixar."];
        }
        if ($event['occurred_on'] === null) {
            return ['outcome' => 'divergence', 'status' => null, 'note' => 'Pagamento sem data.'];
        }
        $this->pdo->prepare('UPDATE erp_entries SET paid_on = :d, bank_account_id = :b, updated_by = :u WHERE id = :id AND paid_on IS NULL')
            ->execute(['d' => $event['occurred_on'], 'b' => $account['id'], 'u' => $user !== null ? (int)$user->id : null, 'id' => $entry['id']]);
        Audit::record('update', 'erp_entries', (int)$entry['id'], ['paid_on' => null], ['paid_on' => $event['occurred_on'], 'bank_account_id' => (int)$account['id'],
            'source' => $fileId === null ? 'bank_api' : 'bank_return']);
        return null;
    }

    private function bankAccount(User $user, int $bankAccountId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM erp_bank_accounts WHERE id = :id AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => $bankAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account) || !$this->scope->canAccess($user, (int)$account['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $account;
    }

    private function fileByHash(int $bankAccountId, string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_return_files WHERE bank_account_id = :b AND sha256 = :h');
        $stmt->execute(['b' => $bankAccountId, 'h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->presentFile($row) : null;
    }

    private function fileRow(int $fileId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_return_files WHERE id = :id');
        $stmt->execute(['id' => $fileId]);
        return $this->presentFile($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /** @return list<array> */
    private function eventsOfFile(int $fileId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_events WHERE return_file_id = :f ORDER BY id');
        $stmt->execute(['f' => $fileId]);
        return array_map(fn (array $r): array => $this->presentEvent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Arquivo de retorno para a API (regra pura). applied = conciliados (baixa ou mudança de estado);
     * divergence = fila de revisão (inclui título não encontrado); other = repetidos.
     */
    public static function presentFile(array $row): array
    {
        $events = (int)$row['events_count'];
        $applied = (int)$row['applied_count'];
        $divergence = (int)$row['divergence_count'];
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'bank_account_id' => (int)$row['bank_account_id'],
            'adapter' => (string)$row['adapter'], 'filename' => (string)$row['filename'], 'sha256' => (string)$row['sha256'],
            'events_count' => $events, 'applied_count' => $applied, 'divergence_count' => $divergence,
            'other_count' => max(0, $events - $applied - $divergence),
            'total_cents' => (int)$row['total_cents'], 'created_at' => $row['created_at'],
        ];
    }

    private function presentEvent(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'bank_account_id' => (int)$row['bank_account_id'],
            'return_file_id' => $row['return_file_id'] !== null ? (int)$row['return_file_id'] : null,
            'charge_id' => $row['charge_id'] !== null ? (int)$row['charge_id'] : null,
            'event_key' => (string)$row['event_key'], 'kind' => (string)$row['kind'], 'our_number' => (string)$row['our_number'],
            'amount_cents' => $row['amount_cents'] !== null ? (int)$row['amount_cents'] : null, 'occurred_on' => $row['occurred_on'],
            'outcome' => (string)$row['outcome'], 'outcome_label' => self::OUTCOMES[$row['outcome']] ?? (string)$row['outcome'],
            'note' => $row['note'], 'resolved_at' => $row['resolved_at'],
        ];
    }
}
