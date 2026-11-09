<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Relatórios de pagamentos, memória de cálculo e transferências (ADR 0089), somente leitura, sobre os
 * dados que já existem. Lançamento válido = status active e não excluído.
 *
 * - payments() (AP-074): lançamentos pagos no período (data do pagamento), com fornecedor (documento
 *   mascarado), conta do plano, conta bancária e documento. Padrão: despesas; nature=receita ou all.
 * - calculation() (AP-075): memória de cálculo dos encargos de cada conta de receita vencida e em aberto
 *   na data as_of. Mostra a regra do condomínio (ADR 0040), cada mês de índice com a taxa e o fator
 *   acumulado, a correção, a multa, os juros e o total, pelo mesmo LateCharges::compute da cobrança.
 * - transfers() (AP-076): transferências entre contas no período (ADR 0057), com origem, destino e
 *   situação; as canceladas aparecem, mas não entram no total.
 *
 * Escopo por condomínio (ADR-002); período de até CollectionReports::MAX_DAYS dias; teto de MAX_ROWS linhas.
 */
final class PaymentReports
{
    public const MAX_ROWS = 20000;
    public const NATURES = ['despesa', 'receita', 'all'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Passos do índice (regra pura): cada mês com a taxa e o fator acumulado (6 casas). Mês sem taxa fica
     * com rate null e não muda o fator, como em LateCharges::compute.
     *
     * @param array<string, float|string> $rates
     * @return list<array{competence:string, rate_percent:?string, factor:string}>
     */
    public static function indexSteps(string $dueOn, string $asOf, string $priceIndex, array $rates): array
    {
        if (strtoupper($priceIndex) === 'NONE') {
            return [];
        }
        $factor = 1.0;
        $steps = [];
        foreach (LateCharges::indexMonths($dueOn, $asOf) as $month) {
            $rate = $rates[$month] ?? null;
            if ($rate !== null) {
                $factor *= 1 + ((float)$rate) / 100;
            }
            $steps[] = ['competence' => $month, 'rate_percent' => $rate === null ? null : number_format((float)$rate, 4, '.', ''), 'factor' => number_format($factor, 6, '.', '')];
        }
        return $steps;
    }

    /** Pagamentos do período (AP-074). */
    public function payments(User $user, ?int $condominiumId, string $from, string $to, string $nature = 'despesa'): array
    {
        [$from, $to] = CollectionReports::period($from, $to);
        if (!in_array($nature, self::NATURES, true)) {
            throw new InvalidArgumentException('Natureza inválida: despesa, receita ou all.');
        }
        $ids = $this->ids($user, $condominiumId);
        $result = ['from' => $from, 'to' => $to, 'nature' => $nature, 'rows' => [], 'totals' => ['entries' => 0, 'amount_cents' => 0, 'by_bank_account' => []]];
        if ($ids === []) {
            return $result;
        }
        $natureSql = $nature === 'all' ? '' : ' AND e.nature = :n';
        $stmt = $this->pdo->prepare("SELECT e.id, e.condominium_id, c.name AS condominium_name, e.nature, e.paid_on, e.due_on, e.description, e.document_number, e.amount_cents,
                a.code AS account_code, a.name AS account_name, b.name AS bank_account_name, e.bank_account_id,
                COALESCE(s.trade_name, s.legal_name) AS supplier_name, s.document AS supplier_document, u.label AS unit_label
            FROM erp_entries e JOIN condominiums c ON c.id = e.condominium_id JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            LEFT JOIN erp_bank_accounts b ON b.id = e.bank_account_id LEFT JOIN erp_suppliers s ON s.id = e.supplier_id LEFT JOIN erp_units u ON u.id = e.unit_id
            WHERE e.condominium_id IN (" . implode(',', $ids) . ") AND e.status = 'active' AND e.deleted_at IS NULL AND e.paid_on BETWEEN :f AND :t{$natureSql}
            ORDER BY e.paid_on, c.name, e.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute(['f' => $from, 't' => $to] + ($nature === 'all' ? [] : ['n' => $nature]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->cap($rows);
        foreach ($rows as $r) {
            $amount = (int)$r['amount_cents'];
            $result['rows'][] = [
                'id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'], 'nature' => (string)$r['nature'],
                'paid_on' => (string)$r['paid_on'], 'due_on' => (string)$r['due_on'], 'paid_late' => (string)$r['paid_on'] > (string)$r['due_on'],
                'description' => (string)$r['description'], 'document_number' => $r['document_number'], 'account' => trim($r['account_code'] . ' ' . $r['account_name']),
                'bank_account' => $r['bank_account_name'], 'supplier' => $r['supplier_name'], 'supplier_document' => CollectionReports::maskDocument($r['supplier_document']),
                'unit_label' => $r['unit_label'], 'amount_cents' => $amount,
            ];
            $result['totals']['entries']++;
            $result['totals']['amount_cents'] += $amount;
            $key = (string)($r['bank_account_name'] ?? 'Sem conta');
            $result['totals']['by_bank_account'][$key] = ($result['totals']['by_bank_account'][$key] ?? 0) + $amount;
        }
        return $result;
    }

    /** Memória de cálculo dos encargos (AP-075) de um condomínio, opcionalmente de uma unidade. */
    public function calculation(User $user, int $condominiumId, ?int $unitId = null, ?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $ref = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
        if ($ref === false || $ref->format('Y-m-d') !== $asOf) {
            throw new InvalidArgumentException('Data de referência inválida (AAAA-MM-DD).');
        }
        $this->scope->assertAccess($user, $condominiumId);
        $rules = new BillingRules($this->pdo, $this->scope);
        $rule = $rules->rule($condominiumId);
        $sql = "SELECT e.id, e.unit_id, u.label AS unit_label, e.description, e.competence, e.due_on, e.amount_cents FROM erp_entries e LEFT JOIN erp_units u ON u.id = e.unit_id
            WHERE e.condominium_id = :c AND e.nature = 'receita' AND e.status = 'active' AND e.deleted_at IS NULL AND e.paid_on IS NULL AND e.due_on < :d";
        $params = ['c' => $condominiumId, 'd' => $asOf];
        if ($unitId !== null) {
            $sql .= ' AND e.unit_id = :u';
            $params['u'] = $unitId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY u.label, e.due_on, e.id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->cap($rows);
        $index = (string)$rule['price_index'];
        $oldest = $rows === [] ? $asOf : min(array_column($rows, 'due_on'));
        $rates = strtoupper($index) === 'NONE' ? [] : $rules->rates($index, substr((string)$oldest, 0, 7), substr($asOf, 0, 7));
        $totals = ['entries' => 0, 'principal_cents' => 0, 'correction_cents' => 0, 'fine_cents' => 0, 'interest_cents' => 0, 'total_cents' => 0, 'missing_index' => []];
        $out = [];
        foreach ($rows as $r) {
            $charges = LateCharges::compute((int)$r['amount_cents'], (string)$r['due_on'], $asOf, $rule, $rates);
            $out[] = [
                'id' => (int)$r['id'], 'unit_id' => $r['unit_id'] === null ? null : (int)$r['unit_id'], 'unit_label' => $r['unit_label'] ?? 'Sem unidade',
                'description' => (string)$r['description'], 'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'],
                'principal_cents' => (int)$r['amount_cents'], 'index_steps' => self::indexSteps((string)$r['due_on'], $asOf, $index, $rates),
            ] + $charges;
            $totals['entries']++;
            foreach (['correction_cents', 'fine_cents', 'interest_cents', 'total_cents'] as $k) {
                $totals[$k] += $charges[$k];
            }
            $totals['principal_cents'] += (int)$r['amount_cents'];
            $totals['missing_index'] = array_values(array_unique(array_merge($totals['missing_index'], $charges['missing_index'])));
        }
        sort($totals['missing_index']);
        return ['as_of' => $asOf, 'condominium_id' => $condominiumId, 'unit_id' => $unitId,
            'rule' => ['fine_bp' => (int)$rule['fine_bp'], 'monthly_interest_bp' => (int)$rule['monthly_interest_bp'], 'price_index' => $index,
                'source' => (string)($rule['source'] ?? 'builtin'),
                'formula' => 'corrigido = principal × fator do índice; multa = corrigido × multa%; juros = corrigido × juros% ao mês × dias / 30'],
            'rows' => $out, 'totals' => $totals];
    }

    /** Transferências entre contas no período (AP-076). */
    public function transfers(User $user, ?int $condominiumId, string $from, string $to, bool $withCancelled = true): array
    {
        [$from, $to] = CollectionReports::period($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $result = ['from' => $from, 'to' => $to, 'rows' => [], 'totals' => ['transfers' => 0, 'amount_cents' => 0, 'cancelled' => 0]];
        if ($ids === []) {
            return $result;
        }
        $stmt = $this->pdo->prepare("SELECT t.id, t.condominium_id, c.name AS condominium_name, t.transferred_on, t.description, t.amount_cents, t.status, t.cancel_reason,
                fa.name AS from_name, ta.name AS to_name
            FROM erp_bank_transfers t JOIN condominiums c ON c.id = t.condominium_id
            JOIN erp_bank_accounts fa ON fa.id = t.from_bank_account_id JOIN erp_bank_accounts ta ON ta.id = t.to_bank_account_id
            WHERE t.condominium_id IN (" . implode(',', $ids) . ") AND t.transferred_on BETWEEN :f AND :t" . ($withCancelled ? '' : " AND t.status = 'active'") . "
            ORDER BY t.transferred_on, t.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute(['f' => $from, 't' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->cap($rows);
        foreach ($rows as $r) {
            $active = $r['status'] === 'active';
            $result['rows'][] = ['id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'],
                'transferred_on' => (string)$r['transferred_on'], 'from_account' => (string)$r['from_name'], 'to_account' => (string)$r['to_name'],
                'description' => (string)$r['description'], 'amount_cents' => (int)$r['amount_cents'], 'status' => (string)$r['status'], 'cancel_reason' => $r['cancel_reason']];
            if ($active) {
                $result['totals']['transfers']++;
                $result['totals']['amount_cents'] += (int)$r['amount_cents'];
            } else {
                $result['totals']['cancelled']++;
            }
        }
        return $result;
    }

    /**
     * Tabela do CSV (regra pura); cada célula passa por CollectionReports::csvCell (ADR 0081).
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(string $kind, array $report): array
    {
        $columns = match ($kind) {
            'payments' => ['Condomínio' => 'condominium_name', 'Natureza' => 'nature', 'Pago em' => 'paid_on', 'Vencimento' => 'due_on', 'Descrição' => 'description',
                'Documento' => 'document_number', 'Conta' => 'account', 'Conta bancária' => 'bank_account', 'Fornecedor' => 'supplier',
                'CPF/CNPJ' => 'supplier_document', 'Unidade' => 'unit_label', 'Valor (centavos)' => 'amount_cents'],
            'calculation' => ['Unidade' => 'unit_label', 'Descrição' => 'description', 'Competência' => 'competence', 'Vencimento' => 'due_on', 'Dias' => 'days_late',
                'Principal (centavos)' => 'principal_cents', 'Correção' => 'correction_cents', 'Multa' => 'fine_cents', 'Juros' => 'interest_cents', 'Total' => 'total_cents',
                'Índice sem taxa' => 'missing_index'],
            'transfers' => ['Condomínio' => 'condominium_name', 'Data' => 'transferred_on', 'Origem' => 'from_account', 'Destino' => 'to_account',
                'Descrição' => 'description', 'Valor (centavos)' => 'amount_cents', 'Situação' => 'status', 'Motivo do cancelamento' => 'cancel_reason'],
            default => throw new InvalidArgumentException('Relatório inválido.'),
        };
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = array_map(static function (string $key) use ($row): string {
                $value = $row[$key] ?? null;
                return CollectionReports::csvCell(is_array($value) ? implode(' ', $value) : (string)($value ?? ''));
            }, array_values($columns));
        }
        return ['header' => array_keys($columns), 'rows' => $rows];
    }

    /** @param list<array> $rows */
    private function cap(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d linhas: reduza o período ou escolha um condomínio.', self::MAX_ROWS));
        }
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
