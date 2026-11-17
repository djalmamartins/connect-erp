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
use Source\Services\Erp\BillingRules;
use Source\Services\Erp\NotificationOutbox;
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
 * - Adaptador da conta bancária (ADR 0156): sandbox ou Inter. Consulta que traz pagamento ou baixa
 *   do banco vira evento e passa pela mesma conciliação do retorno (BankReturns::recordEvent).
 * - Boleto único (ADR 0331): o boleto da conta de uma fatura leva junto as contas da mesma unidade e competência
 *   marcadas com o tipo daquela fatura (bundle_invoice_type_id, ex.: rateio da Nova Receita), em aberto e sem boleto
 *   próprio. O valor é a soma; o vencimento é o da fatura; o pagamento baixa todas (erp_bank_charge_entries).
 *   Unidade com "Bloquear unificação de boletos" fica fora: cada conta sai com o próprio boleto.
 */
final class BankCharges
{
    public const STATUSES = ['pending' => 'Aguardando banco', 'registered' => 'Registrado', 'rejected' => 'Recusado', 'paid' => 'Pago', 'cancelled' => 'Cancelado'];

    /**
     * Todas as contas cobertas por boleto (ADR 0331), como tabela derivada (entry_id, charge_id, status): a principal
     * de cada boleto e as que entraram junto. Usar no lugar de erp_bank_charges nas travas por conta a receber.
     */
    public const COVERAGE = '(SELECT entry_id, id AS charge_id, status FROM erp_bank_charges
        UNION ALL SELECT ce.entry_id, ce.charge_id, c.status FROM erp_bank_charge_entries ce JOIN erp_bank_charges c ON c.id = ce.charge_id)';

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
        $existing = $this->activeFor($entryId) ?? $this->bundledIn($entryId);
        if ($existing !== null) {
            return $this->present($existing);
        }
        $adapter = BankAdapters::forAccount($account, $this->pdo);
        $companions = [];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM erp_bank_accounts WHERE id = :id FOR UPDATE')->execute(['id' => $bankAccountId]);
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(CAST(our_number AS UNSIGNED)), 0) FROM erp_bank_charges WHERE bank_account_id = :b');
            $stmt->execute(['b' => $bankAccountId]);
            $ourNumber = self::formatOurNumber((int)$stmt->fetchColumn() + 1);
            if ($this->bundledIn($entryId) !== null) {
                throw new DomainException('Esta conta já entrou no boleto da fatura da unidade.');
            }
            $companions = $this->companions($entry);
            $total = (int)$entry['amount_cents'] + array_sum(array_column($companions, 'amount_cents'));
            $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on, created_by)
                VALUES (:c, :b, :e, :a, 'pending', :n, :v, :d, :u)")->execute([
                'c' => $entry['condominium_id'], 'b' => $bankAccountId, 'e' => $entryId, 'a' => $adapter->name(),
                'n' => $ourNumber, 'v' => $total, 'd' => $entry['due_on'], 'u' => (int)$user->id,
            ]);
            $chargeId = (int)$this->pdo->lastInsertId();
            $link = $this->pdo->prepare('INSERT INTO erp_bank_charge_entries (charge_id, entry_id, amount_cents) VALUES (:c, :e, :v)');
            foreach ($companions as $companion) {
                $link->execute(['c' => $chargeId, 'e' => (int)$companion['id'], 'v' => (int)$companion['amount_cents']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062 && ($again = $this->activeFor($entryId)) !== null) {
                return $this->present($again);   // pedido concorrente criou o título primeiro
            }
            throw $error;
        }
        Audit::record('create', 'erp_bank_charges', $chargeId, null, ['status' => 'pending', 'entry_id' => $entryId, 'our_number' => $ourNumber,
            'bundled_entries' => array_map(static fn (array $c): int => (int)$c['id'], $companions)]);
        $description = (string)$entry['description'] . ($companions === [] ? '' : ' + ' . count($companions) . (count($companions) === 1 ? ' cobrança' : ' cobranças'));
        $rule = (new BillingRules($this->pdo))->rule((int)$entry['condominium_id']);

        try {
            $result = $adapter->registerCharge([
                'our_number' => $ourNumber, 'amount_cents' => $total, 'due_on' => (string)$entry['due_on'],
                'payer_name' => $entry['unit_label'], 'description' => mb_substr($description, 0, 160),
                'bank_code' => (string)$account['bank_code'], 'branch' => (string)$account['branch'],
                'account_number' => (string)$account['account_number'], 'wallet' => $account['wallet'],
                'payer' => $this->payer($entry), 'fine_bp' => $rule['fine_bp'], 'monthly_interest_bp' => $rule['monthly_interest_bp'],
            ]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'bank_charge_register_failed', 'charge_id' => $chargeId]);
            $result = ['status' => 'pending', 'error' => 'Sem resposta do banco. Consulte o título de novo mais tarde.'];
        }
        return $this->apply($chargeId, $result, 'pending', $user);
    }

    /** Consulta no banco um título pendente (ou registrado) e grava a resposta. */
    /**
     * Boletos de uma fatura emitida de uma vez (pedido do Djalma, 16/11/2026): emite um boleto para cada conta a
     * receber da fatura que ainda está em aberto e sem boleto, em lotes de $limit para caber no tempo de uma
     * requisição (a tela repete até remaining = 0). Cada boleto é independente: recusado ou sem resposta não para
     * os outros. Conta que já teve boleto recusado fica fora (corrigir o cadastro e emitir pela tela de boletos),
     * para o lote não repetir a mesma recusa para sempre. Escopo pela fatura (fora = 404).
     *
     * @return array{processed:list<array>, counts:array{registered:int, pending:int, rejected:int}, remaining:int, skipped_rejected:int}
     */
    public function issueForInvoice(User $user, int $invoiceId, int $bankAccountId, int $limit = 20, ?\Closure $pause = null): array
    {
        $invoice = (new \Source\Services\Erp\Invoices($this->pdo, $this->scope))->find($user, $invoiceId);
        if ($invoice['status'] !== 'issued') {
            throw new DomainException('Só fatura emitida tem contas a receber para gerar boletos.');
        }
        $this->bankAccount($bankAccountId, (int)$invoice['condominium_id']);
        $pending = function (bool $count, int $limit = 0) use ($invoiceId): mixed {
            $sql = "FROM erp_invoice_items it JOIN erp_entries e ON e.invoice_item_id = it.id AND e.deleted_at IS NULL
                WHERE it.invoice_id = :i AND e.paid_on IS NULL AND e.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM erp_bank_charges c WHERE c.entry_id = e.id)";
            $stmt = $this->pdo->prepare($count ? "SELECT COUNT(*) {$sql}" : "SELECT e.id, it.unit_label {$sql} ORDER BY it.unit_label, it.id LIMIT " . max(1, min($limit, 50)));
            $stmt->execute(['i' => $invoiceId]);
            return $count ? (int)$stmt->fetchColumn() : $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        $processed = [];
        $counts = ['registered' => 0, 'pending' => 0, 'rejected' => 0];
        foreach ($pending(false, $limit) as $i => $row) {
            if ($i > 0 && $pause !== null) {
                $pause();
            }
            $charge = $this->issue($user, (int)$row['id'], $bankAccountId);
            $status = (string)$charge['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            $processed[] = ['entry_id' => (int)$row['id'], 'unit_label' => (string)$row['unit_label'], 'charge_id' => (int)$charge['id'],
                'our_number' => (string)$charge['our_number'], 'status' => $status, 'status_label' => $charge['status_label'] ?? $status,
                'error' => $charge['last_error'] ?? null];
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT e.id) FROM erp_invoice_items it JOIN erp_entries e ON e.invoice_item_id = it.id AND e.deleted_at IS NULL
            JOIN erp_bank_charges c ON c.entry_id = e.id
            WHERE it.invoice_id = :i AND e.paid_on IS NULL AND e.status = 'active' AND c.status = 'rejected'
              AND NOT EXISTS (SELECT 1 FROM erp_bank_charges a WHERE a.entry_id = e.id AND a.status IN ('pending','registered','paid'))");
        $stmt->execute(['i' => $invoiceId]);
        return ['processed' => $processed, 'counts' => $counts, 'remaining' => $pending(true), 'skipped_rejected' => (int)$stmt->fetchColumn()];
    }

    public function refresh(User $user, int $chargeId): array
    {
        $charge = $this->row($user, $chargeId);
        if (!in_array($charge['status'], ['pending', 'registered'], true)) {
            return $this->present($charge);
        }
        try {
            $result = $this->adapterOf($charge)->getCharge((string)$charge['our_number'], $charge['external_id']);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'bank_charge_query_failed', 'charge_id' => $chargeId]);
            $result = ['status' => 'pending', 'error' => 'Sem resposta do banco. Consulte de novo mais tarde.'];
        }
        return $this->apply($chargeId, $result, (string)$charge['status'], $user);
    }

    /**
     * Sandbox do Inter (ADR 0156) e stage da Cora (ADR 0322), homologação: simula o pagamento no banco e consulta
     * o título, que passa pela mesma baixa e conciliação do webhook. Só para boleto registrado desses bancos.
     */
    public function simulatePayment(User $user, int $chargeId, string $method = 'BOLETO'): array
    {
        $charge = $this->row($user, $chargeId);
        if ((string)$charge['status'] !== 'registered') {
            throw new DomainException('Só boleto registrado pode ter o pagamento simulado.');
        }
        $adapter = $this->adapterOf($charge);
        if (!$adapter instanceof BankPaymentSimulator) {
            throw new DomainException('A simulação de pagamento existe só no ambiente de teste do Inter e da Cora.');
        }
        $bankName = BankCredentials::BANK_NAMES[$adapter->name()] ?? 'banco';
        try {
            $adapter->simulatePayment($charge['external_id'] === null ? null : (string)$charge['external_id'], $method);
        } catch (\RuntimeException $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'bank_charge_simulate_failed', 'charge_id' => $chargeId]);
            throw new DomainException($adapter instanceof Inter\InterBankAdapter
                ? 'O Inter não respondeu à simulação. Tente de novo em instantes (o sandbox funciona de segunda a sexta, das 8h às 20h).'
                : 'O ' . $bankName . ' não respondeu à simulação. Tente de novo em instantes.');
        }
        Audit::record('update', 'erp_bank_charges', $chargeId, null, ['simulated_payment' => $method]);
        return $this->refresh($user, $chargeId);
    }

    public function cancel(User $user, int $chargeId): array
    {
        $charge = $this->row($user, $chargeId);
        if (!in_array($charge['status'], ['pending', 'registered'], true)) {
            throw new DomainException('Só boleto aguardando banco ou registrado pode ser cancelado.');
        }
        $result = $this->adapterOf($charge)->cancelCharge((string)$charge['our_number'], $charge['external_id']);
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
        $stmt = $this->pdo->prepare("SELECT ch.*, (SELECT COUNT(*) FROM erp_bank_charge_entries ce WHERE ce.charge_id = ch.id) AS bundled_count
            FROM erp_bank_charges ch WHERE {$where} ORDER BY ch.due_on DESC, ch.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return ['data' => array_map(fn (array $r): array => $this->present($r), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }

    public function find(User $user, int $chargeId): array
    {
        return $this->present($this->row($user, $chargeId)) + ['bundled' => $this->bundledEntries($chargeId)];
    }

    /** Grava a resposta do banco num título ainda pendente ou registrado. */
    private function apply(int $chargeId, array $result, string $before = 'pending', ?User $user = null): array
    {
        $status = in_array($result['status'] ?? null, ['registered', 'pending', 'rejected'], true) ? $result['status'] : 'pending';
        $this->pdo->beginTransaction();
        try {
            $this->update($chargeId, $status, $result);
            $row = $this->rawRow($chargeId);
            if ($before !== 'registered' && $row['status'] === 'registered') {
                $this->recordIssued($row);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_bank_charges', $chargeId, ['status' => $before], ['status' => $row['status']]);
        if (is_array($result['event'] ?? null) && in_array($row['status'], ['pending', 'registered'], true)) {
            (new BankReturns($this->pdo, $this->scope))->recordEvent($user, $this->accountRow((int)$row['bank_account_id']), $result['event']);
            $row = $this->rawRow($chargeId);
        }
        return $this->present($row);
    }

    /**
     * Consulta de um título vinda do webhook (ADR 0156): sem usuário, sem escopo (a conta já foi
     * identificada pelo segredo do endereço). Só títulos daquela conta.
     */
    /**
     * Consulta automática (ADR 0156, decisão de 16/11/2026): passa pelos boletos em aberto de bancos reais
     * (adaptador diferente do sandbox interno) e consulta cada um no banco, como o webhook faria. Pagamento,
     * baixa e divergência seguem a mesma conciliação. Sem usuário: roda pelo agendador do servidor.
     * Um boleto que falha não para os outros. $pause roda entre consultas (limite de chamadas do banco).
     *
     * @return array{checked:int, paid:int, cancelled:int, failed:int}
     */
    public function syncOpen(int $limit = 200, ?\Closure $pause = null): array
    {
        $summary = ['checked' => 0, 'paid' => 0, 'cancelled' => 0, 'failed' => 0];
        $stmt = $this->pdo->prepare("SELECT a.*, c.id AS charge_id, c.external_id AS charge_external_id, c.adapter AS charge_adapter FROM erp_bank_charges c
            JOIN erp_bank_accounts a ON a.id = c.bank_account_id
            WHERE c.status IN ('pending','registered') AND c.external_id IS NOT NULL AND c.adapter <> :sandbox
            ORDER BY c.updated_at, c.id LIMIT " . max(1, min($limit, 1000)));
        $stmt->execute(['sandbox' => BankAdapters::DEFAULT]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
            if ($i > 0 && $pause !== null) {
                $pause();
            }
            $chargeId = (int)$row['charge_id'];
            $externalId = (string)$row['charge_external_id'];
            $account = $row;   // a.* = a conta bancária (id, adapter, número...)
            $account['adapter'] = (string)$row['charge_adapter'];   // o título continua no banco onde nasceu
            try {
                $summary['checked']++;
                $status = $this->refreshFromBank($account, $externalId);
                if ($status === 'paid' || $status === 'cancelled') {
                    $summary[$status]++;
                }
            } catch (\Throwable $error) {
                $summary['failed']++;
                AppLogger::exception($error, 'erp', ['event_type' => 'bank_charge_sync_failed', 'charge_id' => $chargeId]);
            }
        }
        return $summary;
    }

    public function refreshFromBank(array $account, string $externalId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_charges WHERE bank_account_id = :b AND external_id = :x LIMIT 1');
        $stmt->execute(['b' => $account['id'], 'x' => $externalId]);
        $charge = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($charge)) {
            return null;
        }
        if (!in_array($charge['status'], ['pending', 'registered'], true)) {
            return (string)$charge['status'];
        }
        $result = BankAdapters::forAccount($account, $this->pdo)->getCharge((string)$charge['our_number'], $externalId);
        return $this->apply((int)$charge['id'], $result, (string)$charge['status'], null)['status'];
    }

    /** PDF do boleto, quando o adaptador oferece (Inter). */
    public function pdf(User $user, int $chargeId): string
    {
        $charge = $this->row($user, $chargeId);
        if (!in_array($charge['status'], ['registered', 'paid'], true)) {
            throw new DomainException('O PDF só existe para boleto registrado.');
        }
        $adapter = $this->adapterOf($charge);
        if (!$adapter instanceof BankChargeExtras) {
            throw new DomainException('Este banco não fornece o PDF do boleto.');
        }
        Audit::record('read', 'erp_bank_charges', $chargeId, null, ['pdf' => true]);
        return $adapter->downloadBoleto((string)$charge['our_number'], $charge['external_id']);
    }

    /**
     * PDF do boleto para o aplicativo do morador (ADR 0302): o boleto precisa ser de uma conta da unidade
     * do vínculo, no condomínio do vínculo. Mesmas regras do pdf() da equipe; qualquer dúvida = não encontrado.
     */
    public function residentPdf(int $chargeId, int $condominiumId, int $unitId): string
    {
        $stmt = $this->pdo->prepare("SELECT b.* FROM erp_bank_charges b JOIN erp_entries e ON e.id = b.entry_id
            WHERE b.id = :id AND b.condominium_id = :c AND e.condominium_id = :c2 AND e.unit_id = :u AND e.nature = 'receita' AND e.deleted_at IS NULL");
        $stmt->execute(['id' => $chargeId, 'c' => $condominiumId, 'c2' => $condominiumId, 'u' => $unitId]);
        $charge = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($charge)) {
            throw new RegistryNotFound();
        }
        if (!in_array($charge['status'], ['registered', 'paid'], true)) {
            throw new DomainException('O PDF só existe para boleto registrado.');
        }
        $adapter = $this->adapterOf($charge);
        if (!$adapter instanceof BankChargeExtras) {
            throw new DomainException('Este banco não fornece o PDF do boleto.');
        }
        Audit::record('read', 'erp_bank_charges', $chargeId, null, ['pdf' => true, 'channel' => 'resident_app']);
        return $adapter->downloadBoleto((string)$charge['our_number'], $charge['external_id']);
    }

    /**
     * Pagador do boleto: o pagador gravado na conta (troca de inquilino/proprietário, ADR 0311); senão o responsável
     * financeiro da unidade; senão o "Responsável pela cobrança" da unidade (inquilino ou proprietário, ADR 0311), com o
     * proprietário como reserva (ativos, com documento primeiro). Endereço de cobrança = endereço do condomínio, com a
     * unidade no complemento.
     */
    /** O mesmo pagador do registro no banco, para a carta de cobrança impressa (ADR 0320). */
    public function payerFor(array $entry): ?array
    {
        return $this->payer($entry);
    }

    private function payer(array $entry): ?array
    {
        if (empty($entry['unit_id'])) {
            return null;
        }
        $person = false;
        if (!empty($entry['id'])) {
            $stmt = $this->pdo->prepare('SELECT r.name, r.document, r.email FROM erp_entries e JOIN erp_residents r ON r.id = e.payer_resident_id
                WHERE e.id = :e AND r.deleted_at IS NULL');
            $stmt->execute(['e' => $entry['id']]);
            $person = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($person)) {
            $stmt = $this->pdo->prepare("SELECT r.name, r.document, r.email FROM erp_residents r JOIN erp_units u ON u.id = r.unit_id
                WHERE r.unit_id = :u AND r.relationship IN ('financial','owner','tenant') AND r.status = 'active' AND r.deleted_at IS NULL
                  AND (r.ends_on IS NULL OR r.ends_on >= CURDATE())
                  AND (r.relationship <> 'tenant' OR u.billing_responsible = 'inquilino')
                ORDER BY r.relationship = 'financial' DESC, (r.relationship = 'tenant') = (u.billing_responsible = 'inquilino') DESC, r.document IS NULL, r.id LIMIT 1");
            $stmt->execute(['u' => $entry['unit_id']]);
            $person = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $stmt = $this->pdo->prepare('SELECT street, number, district, city, state, zip_code FROM condominiums WHERE id = :c');
        $stmt->execute(['c' => $entry['condominium_id']]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!is_array($person)) {
            return null;
        }
        return ['name' => (string)$person['name'], 'document' => $person['document'], 'email' => $person['email'],
            'street' => $address['street'] ?? null, 'number' => $address['number'] ?? null, 'complement' => (string)($entry['unit_label'] ?? ''),
            'district' => $address['district'] ?? null, 'city' => $address['city'] ?? null, 'state' => $address['state'] ?? null, 'zip_code' => $address['zip_code'] ?? null];
    }

    private function adapterOf(array $charge): BankAdapter
    {
        $account = $this->accountRow((int)$charge['bank_account_id']);
        if ((string)$account['adapter'] !== (string)$charge['adapter']) {
            // Conta trocou de banco depois da emissão: o título continua no banco onde nasceu.
            $account['adapter'] = $charge['adapter'];
        }
        return BankAdapters::forAccount($account, $this->pdo);
    }

    private function accountRow(int $bankAccountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_accounts WHERE id = :id');
        $stmt->execute(['id' => $bankAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RegistryNotFound();
    }

    /**
     * Aviso do boleto registrado à unidade (ADR 0056), na mesma transação da gravação. Só vai para
     * quem autorizou receber cobrança por e-mail; a linha digitável não é segredo e segue no aviso.
     */
    private function recordIssued(array $charge): void
    {
        $stmt = $this->pdo->prepare('SELECT e.unit_id, e.description, u.label FROM erp_entries e LEFT JOIN erp_units u ON u.id = e.unit_id WHERE e.id = :id');
        $stmt->execute(['id' => (int)$charge['entry_id']]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($entry) || $entry['unit_id'] === null) {
            return;   // conta a receber sem unidade: não há a quem avisar
        }
        (new NotificationOutbox($this->pdo))->record('bank_charge.issued', 'bank_charge.issued:' . $charge['id'], (int)$charge['condominium_id'],
            'erp_bank_charges', (int)$charge['id'], [
                'unit_id' => (int)$entry['unit_id'], 'unit_label' => (string)($entry['label'] ?? ''), 'description' => (string)$entry['description'],
                'amount_cents' => (int)$charge['amount_cents'], 'due_on' => (string)$charge['due_on'], 'digitable_line' => (string)($charge['digitable_line'] ?? ''),
            ], isset($charge['created_by']) ? (int)$charge['created_by'] : null);
    }

    private function update(int $chargeId, string $status, array $result): void
    {
        $this->pdo->prepare("UPDATE erp_bank_charges SET status = :s,
                external_id = COALESCE(:x, external_id), digitable_line = COALESCE(:l, digitable_line), barcode = COALESCE(:bc, barcode),
                bank_our_number = COALESCE(:bn, bank_our_number), pix_txid = COALESCE(:pt, pix_txid), pix_copy_paste = COALESCE(:pc, pix_copy_paste),
                last_error = :e, registered_at = CASE WHEN :s2 = 'registered' AND registered_at IS NULL THEN NOW() ELSE registered_at END
            WHERE id = :id AND status IN ('pending','registered')")->execute([
            's' => $status, 's2' => $status, 'x' => $result['external_id'] ?? null, 'l' => $result['digitable_line'] ?? null,
            'bc' => $result['barcode'] ?? null, 'bn' => $result['bank_our_number'] ?? null, 'pt' => $result['pix_txid'] ?? null,
            'pc' => $result['pix_copy_paste'] ?? null, 'e' => isset($result['error']) ? mb_substr((string)$result['error'], 0, 255) : null, 'id' => $chargeId,
        ]);
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

    /** Boleto ativo (aguardando, registrado ou pago) em que a conta entrou junto com a fatura (ADR 0331). */
    private function bundledIn(int $entryId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.* FROM erp_bank_charge_entries ce JOIN erp_bank_charges c ON c.id = ce.charge_id
            WHERE ce.entry_id = :e AND c.active_key = 1 LIMIT 1');
        $stmt->execute(['e' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Contas que entram no boleto da conta de fatura (ADR 0331): mesma unidade e competência, marcadas com o tipo da
     * fatura, receita ativa em aberto e sem boleto ativo (próprio ou junto de outro). Conta fora de fatura: nenhuma.
     * @return list<array{id:int|string, amount_cents:int|string}>
     */
    private function companions(array $entry): array
    {
        if ($entry['invoice_item_id'] === null || $entry['unit_id'] === null) {
            return [];
        }
        $blocked = $this->pdo->prepare('SELECT block_bill_merge FROM erp_units WHERE id = :u');
        $blocked->execute(['u' => (int)$entry['unit_id']]);
        if ((int)$blocked->fetchColumn() === 1) {
            return [];   // "Bloquear unificação de boletos" na unidade (ADR 0311): cada conta com o próprio boleto
        }
        $stmt = $this->pdo->prepare("SELECT e.id, e.amount_cents FROM erp_entries e
            JOIN erp_invoice_items it ON it.id = :item JOIN erp_invoices i ON i.id = it.invoice_id
            WHERE e.condominium_id = :c AND e.unit_id = :u AND e.competence = :m AND e.bundle_invoice_type_id = i.invoice_type_id
              AND e.id <> :id AND e.nature = 'receita' AND e.status = 'active' AND e.paid_on IS NULL AND e.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM " . self::COVERAGE . " cv WHERE cv.entry_id = e.id AND cv.status IN ('pending','registered','paid'))
            ORDER BY e.id FOR UPDATE");
        $stmt->execute(['item' => (int)$entry['invoice_item_id'], 'c' => (int)$entry['condominium_id'], 'u' => (int)$entry['unit_id'],
            'm' => (string)$entry['competence'], 'id' => (int)$entry['id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{entry_id:int, amount_cents:int, description:string}> contas que entraram junto no boleto */
    public function bundledEntries(int $chargeId): array
    {
        $stmt = $this->pdo->prepare('SELECT ce.entry_id, ce.amount_cents, e.description FROM erp_bank_charge_entries ce JOIN erp_entries e ON e.id = ce.entry_id
            WHERE ce.charge_id = :c ORDER BY ce.entry_id');
        $stmt->execute(['c' => $chargeId]);
        return array_map(static fn (array $r): array => ['entry_id' => (int)$r['entry_id'], 'amount_cents' => (int)$r['amount_cents'], 'description' => (string)$r['description']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
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
            'bank_our_number' => $row['bank_our_number'] ?? null, 'pix_copy_paste' => $row['pix_copy_paste'] ?? null,
            'pdf_available' => in_array($row['adapter'] ?? '', BankAdapters::REAL, true) && !empty($row['external_id']) && in_array($row['status'], ['registered', 'paid'], true),
            'last_error' => $row['last_error'], 'paid_cents' => $row['paid_cents'] !== null ? (int)$row['paid_cents'] : null,
            'paid_on' => $row['paid_on'], 'registered_at' => $row['registered_at'], 'cancelled_at' => $row['cancelled_at'],
            'bundled_count' => (int)($row['bundled_count'] ?? 0),
        ];
    }
}
