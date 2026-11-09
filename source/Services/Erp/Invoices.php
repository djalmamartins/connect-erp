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
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Audit;

/**
 * Faturas (AP-032, ADR 0033): cobrança de um tipo de fatura a todas as unidades ativas de um
 * condomínio numa competência.
 *
 * - prepare(): rascunho com um item por unidade, pelo valor fixo por unidade ou pelo rateio de um
 *   total pela fração ideal. Uma fatura ativa por condomínio, tipo e competência (409).
 * - setItemAmount(): ajusta o valor de uma unidade enquanto é rascunho.
 * - issue(): cada item vira uma conta a receber (erp_entries) da unidade, pelo RegistryService (mesmas
 *   regras dos lançamentos, inclusive a trava do fechamento). Tudo ou nada; a chave única em
 *   erp_entries.invoice_item_id barra emitir duas vezes.
 * - cancel(): exige motivo. Emitida só cancela se nenhuma conta a receber foi paga e a competência
 *   não está fechada; os lançamentos gerados ficam cancelados (status inactive).
 * - Escopo por condomínio (ADR-002): fora do escopo = RegistryNotFound (404). Tudo auditado.
 */
final class Invoices
{
    public const STATUSES = ['draft' => 'Em preparação', 'issued' => 'Emitida', 'cancelled' => 'Cancelada'];
    public const METHODS = ['fixed' => 'Valor fixo por unidade', 'fraction' => 'Rateio pela fração ideal'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?RegistryService $registry = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = $registry ?? new RegistryService($this->pdo, $this->scope);
    }

    /**
     * Rateio de um total pelas frações (regra pura): cada parte é arredondada para baixo e os
     * centavos que sobram vão, um a um, para as maiores sobras (empate: ordem de entrada). A soma
     * das partes é sempre igual ao total.
     *
     * @param array<int|string, float|int|string> $fractions chave => fração (> 0)
     * @return array<int|string, int> chave => centavos
     */
    public static function apportion(int $totalCents, array $fractions): array
    {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException('Informe um valor total maior que zero.');
        }
        $sum = 0.0;
        foreach ($fractions as $fraction) {
            if (!is_numeric($fraction) || (float)$fraction <= 0) {
                throw new InvalidArgumentException('Todas as unidades precisam de fração ideal maior que zero para o rateio.');
            }
            $sum += (float)$fraction;
        }
        if ($fractions === [] || $sum <= 0) {
            throw new InvalidArgumentException('Não há unidades para ratear.');
        }
        $shares = [];
        $remainders = [];
        $position = 0;
        foreach ($fractions as $key => $fraction) {
            $exact = $totalCents * ((float)$fraction / $sum);
            $shares[$key] = (int)floor($exact + 1e-9);
            $remainders[] = [$key, $exact - $shares[$key], $position++];
        }
        $left = $totalCents - array_sum($shares);
        usort($remainders, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        for ($i = 0; $i < $left; $i++) {
            $shares[$remainders[$i % count($remainders)][0]]++;
        }
        return $shares;
    }

    /** Centavos a partir de int (centavos) ou texto em reais ("1.234,56"); inválido = null. */
    public static function cents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^\d{1,3}(\.?\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/', trim($value))) {
            return null;
        }
        [$int, $dec] = array_pad(explode(',', str_replace('.', '', trim($value))), 2, '0');
        $cents = (int)$int * 100 + (int)str_pad(substr($dec, 0, 2), 2, '0');
        return $cents > 0 ? $cents : null;
    }

    /**
     * @param array{condominium_id?:mixed, invoice_type_id?:mixed, competence?:mixed, due_on?:mixed, method?:mixed, amount_cents?:mixed, notes?:mixed} $input
     */
    public function prepare(User $user, array $input): array
    {
        $condominium = $this->int($input['condominium_id'] ?? null);
        $typeId = $this->int($input['invoice_type_id'] ?? null);
        $competence = is_string($input['competence'] ?? null) ? $input['competence'] : '';
        $dueOn = is_string($input['due_on'] ?? null) ? $input['due_on'] : '';
        $method = is_string($input['method'] ?? null) && isset(self::METHODS[$input['method']]) ? $input['method'] : 'fixed';
        $notes = is_string($input['notes'] ?? null) ? mb_substr(trim($input['notes']), 0, 255) : null;

        if ($condominium === null || !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência inválida. Use AAAA-MM.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if ($date === false || $date->format('Y-m-d') !== $dueOn) {
            throw new InvalidArgumentException('Informe o vencimento (AAAA-MM-DD).');
        }
        $type = $this->invoiceType($typeId, $condominium);
        if ($this->registry->isClosed($condominium, $competence)) {
            throw new DomainException("A competência {$competence} está fechada. Reabra o fechamento mensal para faturar.");
        }
        $amount = array_key_exists('amount_cents', $input) && $input['amount_cents'] !== null && $input['amount_cents'] !== ''
            ? self::cents($input['amount_cents'])
            : ((int)$type['default_amount_cents'] > 0 ? (int)$type['default_amount_cents'] : null);
        if ($amount === null) {
            throw new InvalidArgumentException($method === 'fraction' ? 'Informe o valor total a ratear.' : 'Informe o valor por unidade (o tipo de fatura não tem valor padrão).');
        }

        $stmt = $this->pdo->prepare("SELECT id, label, ideal_fraction FROM erp_units
            WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' ORDER BY label, id");
        $stmt->execute(['c' => $condominium]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($units === []) {
            throw new InvalidArgumentException('O condomínio não tem unidades ativas para faturar.');
        }
        $amounts = $method === 'fraction'
            ? self::apportion($amount, array_column($units, 'ideal_fraction', 'id'))
            : array_fill_keys(array_map('intval', array_column($units, 'id')), $amount);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO erp_invoices (condominium_id, invoice_type_id, competence, due_on, status, notes, created_by)
                VALUES (:c, :t, :m, :d, \'draft\', :n, :u)')
                ->execute(['c' => $condominium, 't' => $typeId, 'm' => $competence, 'd' => $dueOn, 'n' => $notes, 'u' => (int)$user->id]);
            $invoiceId = (int)$this->pdo->lastInsertId();
            $item = $this->pdo->prepare('INSERT INTO erp_invoice_items (invoice_id, unit_id, unit_label, amount_cents) VALUES (:i, :u, :l, :a)');
            foreach ($units as $unit) {
                $item->execute(['i' => $invoiceId, 'u' => (int)$unit['id'], 'l' => mb_substr((string)$unit['label'], 0, 40), 'a' => $amounts[(int)$unit['id']]]);
            }
            $this->refreshTotals($invoiceId);
            $this->pdo->commit();
        } catch (PDOException $error) {
            $this->pdo->rollBack();
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new DomainException("Já existe uma fatura de {$type['name']} para {$competence} neste condomínio. Cancele-a para preparar outra.");
            }
            throw $error;
        }
        $invoice = $this->find($user, $invoiceId);
        Audit::record('create', 'erp_invoices', $invoiceId, null, ['status' => 'draft', 'competence' => $competence, 'invoice_type_id' => $typeId,
            'method' => $method, 'units_count' => $invoice['units_count'], 'amount_cents' => $invoice['amount_cents']]);
        return $invoice;
    }

    public function setItemAmount(User $user, int $invoiceId, int $itemId, mixed $amount): array
    {
        $invoice = $this->invoiceRow($user, $invoiceId);
        if ($invoice['status'] !== 'draft') {
            throw new DomainException('Só é possível ajustar valores de fatura em preparação.');
        }
        $cents = self::cents($amount);
        if ($cents === null) {
            throw new InvalidArgumentException('Informe um valor maior que zero.');
        }
        $stmt = $this->pdo->prepare('SELECT amount_cents FROM erp_invoice_items WHERE id = :id AND invoice_id = :invoice');
        $stmt->execute(['id' => $itemId, 'invoice' => $invoiceId]);
        $before = $stmt->fetchColumn();
        if ($before === false) {
            throw new RegistryNotFound();
        }
        $this->pdo->prepare('UPDATE erp_invoice_items SET amount_cents = :a WHERE id = :id')->execute(['a' => $cents, 'id' => $itemId]);
        $this->refreshTotals($invoiceId);
        Audit::record('update', 'erp_invoice_items', $itemId, ['amount_cents' => (int)$before], ['amount_cents' => $cents]);
        return $this->find($user, $invoiceId);
    }

    public function issue(User $user, int $invoiceId): array
    {
        $this->invoiceRow($user, $invoiceId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT i.*, t.name AS type_name, t.chart_account_id FROM erp_invoices i
                JOIN erp_invoice_types t ON t.id = i.invoice_type_id WHERE i.id = :id FOR UPDATE');
            $stmt->execute(['id' => $invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invoice['status'] !== 'draft') {
                throw new DomainException('Só fatura em preparação pode ser emitida.');
            }
            $items = $this->pdo->prepare('SELECT * FROM erp_invoice_items WHERE invoice_id = :id ORDER BY unit_label, id');
            $items->execute(['id' => $invoiceId]);
            $link = $this->pdo->prepare('UPDATE erp_entries SET invoice_item_id = :item WHERE id = :id');
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $entry = $this->registry->save($user, 'entries', [
                    'condominium_id' => $invoice['condominium_id'], 'nature' => 'receita', 'chart_account_id' => $invoice['chart_account_id'],
                    'description' => mb_substr("{$invoice['type_name']} {$invoice['competence']} — {$item['unit_label']}", 0, 160),
                    'amount_cents' => number_format(((int)$item['amount_cents']) / 100, 2, ',', ''),
                    'competence' => $invoice['competence'], 'due_on' => $invoice['due_on'], 'unit_id' => $item['unit_id'],
                    'document_number' => 'FAT-' . $invoiceId,
                ]);
                $link->execute(['item' => (int)$item['id'], 'id' => (int)$entry['id']]);
            }
            $this->pdo->prepare("UPDATE erp_invoices SET status = 'issued', issued_by = :u, issued_at = NOW() WHERE id = :id")
                ->execute(['u' => (int)$user->id, 'id' => $invoiceId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                throw new DomainException('Esta fatura já foi emitida.');
            }
            throw $error;
        }
        Audit::record('update', 'erp_invoices', $invoiceId, ['status' => 'draft'], ['status' => 'issued']);
        return $this->find($user, $invoiceId);
    }

    public function cancel(User $user, int $invoiceId, string $reason): array
    {
        $invoice = $this->invoiceRow($user, $invoiceId);
        $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do cancelamento (5 a 255 caracteres).');
        }
        if ($invoice['status'] === 'cancelled') {
            throw new DomainException('A fatura já está cancelada.');
        }
        $this->pdo->beginTransaction();
        try {
            if ($invoice['status'] === 'issued') {
                if ($this->registry->isClosed($invoice['condominium_id'], $invoice['competence'])) {
                    throw new DomainException("A competência {$invoice['competence']} está fechada. Reabra o fechamento mensal para cancelar a fatura.");
                }
                $paid = $this->pdo->prepare("SELECT COUNT(*) FROM erp_entries e JOIN erp_invoice_items it ON it.id = e.invoice_item_id
                    WHERE it.invoice_id = :id AND e.paid_on IS NOT NULL AND e.deleted_at IS NULL AND e.status = 'active' FOR UPDATE");
                $paid->execute(['id' => $invoiceId]);
                if ((int)$paid->fetchColumn() > 0) {
                    throw new DomainException('Há contas a receber desta fatura já pagas. Estorne a baixa antes de cancelar.');
                }
                $this->pdo->prepare("UPDATE erp_entries e JOIN erp_invoice_items it ON it.id = e.invoice_item_id
                    SET e.status = 'inactive', e.updated_by = :u WHERE it.invoice_id = :id AND e.deleted_at IS NULL")
                    ->execute(['u' => (int)$user->id, 'id' => $invoiceId]);
            }
            $this->pdo->prepare("UPDATE erp_invoices SET status = 'cancelled', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r
                WHERE id = :id AND status <> 'cancelled'")->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $invoiceId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_invoices', $invoiceId, ['status' => $invoice['status']], ['status' => 'cancelled', 'cancel_reason' => $reason]);
        return $this->find($user, $invoiceId);
    }

    /**
     * Faturas do escopo; $condominiumId null = todos os condomínios do usuário (contexto "Todos").
     *
     * @param array{competence?:mixed, status?:mixed, page?:mixed} $filters
     * @return array{data: list<array>, total:int, page:int, per_page:int, pages:int}
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
        $where = ['i.condominium_id IN (' . implode(',', array_map('intval', $ids)) . ')'];
        $params = [];
        if (is_string($filters['competence'] ?? null) && RecurringEntries::isCompetence($filters['competence'])) {
            $where[] = 'i.competence = :m';
            $params['m'] = $filters['competence'];
        }
        if (is_string($filters['status'] ?? null) && isset(self::STATUSES[$filters['status']])) {
            $where[] = 'i.status = :s';
            $params['s'] = $filters['status'];
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM erp_invoices i WHERE {$sqlWhere}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT i.*, c.name AS condominium_name, t.name AS type_name FROM erp_invoices i
            JOIN condominiums c ON c.id = i.condominium_id JOIN erp_invoice_types t ON t.id = i.invoice_type_id
            WHERE {$sqlWhere} ORDER BY i.competence DESC, c.name, t.name, i.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return ['data' => array_map(fn (array $r): array => $this->present($r), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }

    /** Fatura com os itens e a situação da conta a receber de cada unidade. */
    public function find(User $user, int $invoiceId): array
    {
        $this->invoiceRow($user, $invoiceId);
        $stmt = $this->pdo->prepare('SELECT i.*, c.name AS condominium_name, t.name AS type_name FROM erp_invoices i
            JOIN condominiums c ON c.id = i.condominium_id JOIN erp_invoice_types t ON t.id = i.invoice_type_id WHERE i.id = :id');
        $stmt->execute(['id' => $invoiceId]);
        $invoice = $this->present($stmt->fetch(PDO::FETCH_ASSOC));
        $items = $this->pdo->prepare('SELECT it.id, it.unit_id, it.unit_label, it.amount_cents, e.id AS entry_id, e.paid_on, e.status AS entry_status
            FROM erp_invoice_items it LEFT JOIN erp_entries e ON e.invoice_item_id = it.id AND e.deleted_at IS NULL
            WHERE it.invoice_id = :id ORDER BY it.unit_label, it.id');
        $items->execute(['id' => $invoiceId]);
        $invoice['items'] = array_map(static fn (array $r): array => [
            'id' => (int)$r['id'], 'unit_id' => (int)$r['unit_id'], 'unit_label' => (string)$r['unit_label'], 'amount_cents' => (int)$r['amount_cents'],
            'entry_id' => $r['entry_id'] !== null ? (int)$r['entry_id'] : null, 'paid_on' => $r['paid_on'],
            'entry_status' => $r['entry_status'],
        ], $items->fetchAll(PDO::FETCH_ASSOC));
        return $invoice;
    }

    /** Linha da fatura no escopo do usuário; fora dele ou inexistente = RegistryNotFound (404). */
    private function invoiceRow(User $user, int $invoiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_invoices WHERE id = :id');
        $stmt->execute(['id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    /** Tipo de fatura ativo do padrão da Connect ou do próprio condomínio. */
    private function invoiceType(?int $typeId, int $condominium): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM erp_invoice_types WHERE id = :id AND deleted_at IS NULL AND status = 'active'
            AND (condominium_id IS NULL OR condominium_id = :c)");
        $stmt->execute(['id' => (int)$typeId, 'c' => $condominium]);
        $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($type)) {
            throw new InvalidArgumentException('Escolha um tipo de fatura ativo do padrão ou deste condomínio.');
        }
        return $type;
    }

    private function refreshTotals(int $invoiceId): void
    {
        $this->pdo->prepare('UPDATE erp_invoices i SET
                units_count = (SELECT COUNT(*) FROM erp_invoice_items WHERE invoice_id = i.id),
                amount_cents = (SELECT COALESCE(SUM(amount_cents), 0) FROM erp_invoice_items WHERE invoice_id = i.id)
            WHERE i.id = :id')->execute(['id' => $invoiceId]);
    }

    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium_name' => $row['condominium_name'] ?? null,
            'invoice_type_id' => (int)$row['invoice_type_id'], 'type_name' => $row['type_name'] ?? null,
            'competence' => (string)$row['competence'], 'due_on' => (string)$row['due_on'],
            'status' => (string)$row['status'], 'status_label' => self::STATUSES[$row['status']] ?? (string)$row['status'],
            'units_count' => (int)$row['units_count'], 'amount_cents' => (int)$row['amount_cents'], 'notes' => $row['notes'],
            'issued_at' => $row['issued_at'], 'cancelled_at' => $row['cancelled_at'], 'cancel_reason' => $row['cancel_reason'],
        ];
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
