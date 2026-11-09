<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Relatórios de cobrança (ADR 0057), somente leitura, calculados dos lançamentos e acordos que já
 * existem (sem tabela própria):
 *
 * - agreements(): relatório de acordos (AP-047), um por linha, com o andamento das parcelas;
 * - agreementFees(): taxas de acordos (AP-048), encargos e descontos por condomínio;
 * - delinquents(): inadimplentes (AP-057 a AP-061) nas visões geral, unidade, detalhada, taxa e
 *   morador, sobre a mesma conta da inadimplência (Agreements::delinquency, com os encargos do ADR 0040);
 * - receipts(): recebimentos (AP-071) e recebimentos por conta (AP-072), por data de pagamento.
 *
 * Escopo (ADR-002): um condomínio, ou todos os do escopo do usuário (null = contexto "Todos").
 * csvTable() dá a mesma informação em tabela para exportar.
 */
final class CollectionReports
{
    public const DELINQUENT_VIEWS = ['general', 'unit', 'detailed', 'fee', 'resident'];
    public const RECEIPT_GROUPS = ['entry', 'bank_account', 'chart_account'];
    public const MAX_DAYS = 366;
    /** Teto de recebimentos lidos numa consulta (ADR 0067): acima disso, reduza o período ou escolha um condomínio. */
    public const MAX_RECEIPT_ROWS = 20000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Valida o período (regra pura): datas AAAA-MM-DD, início até o fim, no máximo MAX_DAYS dias. */
    public static function period(string $from, string $to): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Informe o período com datas válidas (AAAA-MM-DD).');
        }
        if ($end < $start) {
            throw new InvalidArgumentException('A data final não pode ser anterior à inicial.');
        }
        if ((int)$start->diff($end)->format('%a') > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('O período pode ter no máximo %d dias.', self::MAX_DAYS));
        }
        return [$from, $to];
    }

    /** CPF/CNPJ mascarado (regra pura): só os 2 últimos dígitos aparecem. */
    public static function maskDocument(?string $document): string
    {
        $digits = preg_replace('/\D/', '', (string)$document) ?? '';
        return $digits === '' ? '' : str_repeat('*', max(0, strlen($digits) - 2)) . substr($digits, -2);
    }

    /**
     * Relatório de acordos (AP-047): criados no período, com parcelas pagas, em aberto e vencidas.
     *
     * @return array{from:string, to:string, agreements:list<array>, totals:array}
     */
    public function agreements(User $user, ?int $condominiumId, string $from, string $to, ?string $status = null, ?string $asOf = null): array
    {
        [$from, $to] = self::period($from, $to);
        $asOf ??= date('Y-m-d');
        $totals = ['agreements' => 0, 'total_cents' => 0, 'paid_cents' => 0, 'open_cents' => 0, 'overdue_cents' => 0,
            'by_status' => ['active' => 0, 'breached' => 0, 'cancelled' => 0]];
        $result = ['from' => $from, 'to' => $to, 'as_of' => $asOf, 'agreements' => [], 'totals' => $totals];
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return $result;
        }
        $params = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59', 'd' => $asOf];
        $where = '';
        if (in_array($status, ['active', 'breached', 'cancelled'], true)) {
            $where = ' AND a.status = :s';
            $params['s'] = $status;
        }
        $stmt = $this->pdo->prepare("SELECT a.id, a.condominium_id, c.name AS condominium_name, a.unit_id, u.label AS unit_label, a.status,
                DATE(a.created_at) AS created_on, a.installments, a.original_cents, a.fees_cents, a.discount_cents, a.total_cents,
                COALESCE(SUM(CASE WHEN e.paid_on IS NOT NULL THEN 1 ELSE 0 END), 0) AS paid_installments,
                COALESCE(SUM(CASE WHEN e.paid_on IS NOT NULL THEN e.amount_cents ELSE 0 END), 0) AS paid_cents,
                COALESCE(SUM(CASE WHEN e.paid_on IS NULL AND e.status = 'active' THEN e.amount_cents ELSE 0 END), 0) AS open_cents,
                COALESCE(SUM(CASE WHEN e.paid_on IS NULL AND e.status = 'active' AND e.due_on < :d THEN 1 ELSE 0 END), 0) AS overdue_installments,
                COALESCE(SUM(CASE WHEN e.paid_on IS NULL AND e.status = 'active' AND e.due_on < :d2 THEN e.amount_cents ELSE 0 END), 0) AS overdue_cents,
                MIN(CASE WHEN e.paid_on IS NULL AND e.status = 'active' THEN e.due_on END) AS next_due_on
            FROM erp_agreements a JOIN condominiums c ON c.id = a.condominium_id JOIN erp_units u ON u.id = a.unit_id
            LEFT JOIN erp_entries e ON e.agreement_id = a.id AND e.agreement_role = 'installment' AND e.deleted_at IS NULL
            WHERE a.condominium_id IN (" . implode(',', $ids) . ") AND a.created_at BETWEEN :f AND :t{$where}
            GROUP BY a.id ORDER BY c.name, a.created_at, a.id");
        $stmt->execute($params + ['d2' => $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = [
                'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'condominium_name' => (string)$row['condominium_name'],
                'unit_id' => (int)$row['unit_id'], 'unit_label' => (string)$row['unit_label'], 'status' => (string)$row['status'],
                'created_on' => (string)$row['created_on'], 'installments' => (int)$row['installments'], 'paid_installments' => (int)$row['paid_installments'],
                'original_cents' => (int)$row['original_cents'], 'fees_cents' => (int)$row['fees_cents'], 'discount_cents' => (int)$row['discount_cents'],
                'total_cents' => (int)$row['total_cents'], 'paid_cents' => (int)$row['paid_cents'],
                'open_cents' => $row['status'] === 'cancelled' ? 0 : (int)$row['open_cents'],
                'overdue_installments' => $row['status'] === 'cancelled' ? 0 : (int)$row['overdue_installments'],
                'overdue_cents' => $row['status'] === 'cancelled' ? 0 : (int)$row['overdue_cents'],
                'next_due_on' => $row['status'] === 'cancelled' ? null : $row['next_due_on'],
            ];
            $result['agreements'][] = $item;
            $result['totals']['agreements']++;
            $result['totals']['by_status'][$item['status']] = ($result['totals']['by_status'][$item['status']] ?? 0) + 1;
            if ($item['status'] !== 'cancelled') {
                foreach (['total_cents', 'paid_cents', 'open_cents', 'overdue_cents'] as $field) {
                    $result['totals'][$field] += $item[$field];
                }
            }
        }
        return $result;
    }

    /**
     * Taxas de acordos (AP-048): encargos e descontos dos acordos do período, por condomínio.
     * Acordo cancelado fica de fora das somas (aparece só na contagem).
     */
    public function agreementFees(User $user, ?int $condominiumId, string $from, string $to): array
    {
        $report = $this->agreements($user, $condominiumId, $from, $to);
        return ['from' => $report['from'], 'to' => $report['to']] + self::feeSummary($report['agreements']);
    }

    /**
     * Soma encargos e descontos por condomínio (regra pura). net_fees = encargos − desconto.
     *
     * @param list<array> $agreements linhas de agreements()
     * @return array{condominiums:list<array>, totals:array}
     */
    public static function feeSummary(array $agreements): array
    {
        $zero = ['agreements' => 0, 'cancelled' => 0, 'original_cents' => 0, 'fees_cents' => 0, 'discount_cents' => 0, 'net_fees_cents' => 0, 'total_cents' => 0];
        $by = [];
        $totals = $zero;
        foreach ($agreements as $row) {
            $key = (int)$row['condominium_id'];
            $by[$key] ??= ['condominium_id' => $key, 'condominium_name' => (string)$row['condominium_name']] + $zero;
            if ($row['status'] === 'cancelled') {
                $by[$key]['cancelled']++;
                $totals['cancelled']++;
                continue;
            }
            $net = (int)$row['fees_cents'] - (int)$row['discount_cents'];
            foreach ([&$by[$key], &$totals] as &$bucket) {
                $bucket['agreements']++;
                $bucket['original_cents'] += (int)$row['original_cents'];
                $bucket['fees_cents'] += (int)$row['fees_cents'];
                $bucket['discount_cents'] += (int)$row['discount_cents'];
                $bucket['net_fees_cents'] += $net;
                $bucket['total_cents'] += (int)$row['total_cents'];
            }
            unset($bucket);
        }
        return ['condominiums' => array_values($by), 'totals' => $totals];
    }

    /**
     * Inadimplentes (AP-057 a AP-061) numa das visões de DELINQUENT_VIEWS. A visão "morador" mostra o
     * responsável da unidade (responsável financeiro, depois proprietário) com o documento mascarado.
     */
    public function delinquents(User $user, ?int $condominiumId, ?string $asOf, string $view): array
    {
        if (!in_array($view, self::DELINQUENT_VIEWS, true)) {
            throw new InvalidArgumentException('Visão inválida. Use: ' . implode(', ', self::DELINQUENT_VIEWS) . '.');
        }
        $base = (new Agreements($this->pdo, $this->scope))->delinquency($user, $condominiumId, $asOf);
        $accounts = [];
        $people = [];
        $entryIds = [];
        $unitIds = [];
        foreach ($base['units'] as $unit) {
            $entryIds = array_merge($entryIds, array_column($unit['entries'], 'id'));
            if ($unit['unit_id'] !== null) {
                $unitIds[] = (int)$unit['unit_id'];
            }
        }
        if ($view === 'fee' && $entryIds !== []) {
            $rows = $this->pdo->query('SELECT e.id, a.code, a.name FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
                WHERE e.id IN (' . implode(',', array_map('intval', $entryIds)) . ')')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $accounts[(int)$row['id']] = trim($row['code'] . ' ' . $row['name']);
            }
        }
        if ($view === 'resident' && $unitIds !== []) {
            $rows = $this->pdo->query("SELECT unit_id, name, document, email, phone, relationship FROM erp_residents
                WHERE unit_id IN (" . implode(',', array_unique($unitIds)) . ") AND deleted_at IS NULL AND status = 'active'
                ORDER BY relationship = 'financial' DESC, relationship = 'owner' DESC, id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $people[(int)$row['unit_id']] ??= ['name' => (string)$row['name'], 'document' => self::maskDocument($row['document']),
                    'email' => (string)($row['email'] ?? ''), 'phone' => (string)($row['phone'] ?? ''), 'relationship' => (string)$row['relationship']];
            }
        }
        return ['as_of' => $base['as_of'], 'view' => $view, 'rows' => self::delinquentView($base, $view, $accounts, $people), 'totals' => $base['totals']];
    }

    /**
     * Visões da inadimplência (regra pura) a partir do resultado de Agreements::delinquency.
     *
     * @param array<int, string> $accounts conta do plano por id de lançamento (visão "fee")
     * @param array<int, array> $people responsável por unidade (visão "resident")
     * @return list<array>
     */
    public static function delinquentView(array $base, string $view, array $accounts = [], array $people = []): array
    {
        $out = [];
        foreach ($base['units'] as $unit) {
            $common = ['condominium_id' => $unit['condominium_id'], 'condominium_name' => $unit['condominium_name'],
                'unit_id' => $unit['unit_id'], 'unit_label' => $unit['unit_label']];
            if ($view === 'general') {
                $key = (int)$unit['condominium_id'];
                $out[$key] ??= ['condominium_id' => $key, 'condominium_name' => $unit['condominium_name'], 'units' => 0, 'entries' => 0,
                    'amount_cents' => 0, 'charges_cents' => 0, 'total_cents' => 0, 'max_days_late' => 0];
                $out[$key]['units']++;
                $out[$key]['entries'] += count($unit['entries']);
                foreach (['amount_cents', 'charges_cents', 'total_cents'] as $field) {
                    $out[$key][$field] += (int)$unit[$field];
                }
                $out[$key]['max_days_late'] = max($out[$key]['max_days_late'], (int)$unit['max_days_late']);
                continue;
            }
            if ($view === 'unit' || $view === 'resident') {
                $row = $common + ['entries' => count($unit['entries']), 'amount_cents' => (int)$unit['amount_cents'], 'charges_cents' => (int)$unit['charges_cents'],
                    'total_cents' => (int)$unit['total_cents'], 'oldest_due_on' => $unit['oldest_due_on'], 'max_days_late' => (int)$unit['max_days_late']];
                if ($view === 'resident') {
                    $row['responsible'] = $unit['unit_id'] !== null ? ($people[(int)$unit['unit_id']] ?? null) : null;
                }
                $out[] = $row;
                continue;
            }
            foreach ($unit['entries'] as $entry) {
                $charges = (int)($entry['charges']['charges_cents'] ?? 0);
                if ($view === 'detailed') {
                    $out[] = $common + ['entry_id' => $entry['id'], 'description' => $entry['description'], 'competence' => $entry['competence'],
                        'due_on' => $entry['due_on'], 'days_late' => $entry['days_late'], 'bucket' => $entry['bucket'],
                        'amount_cents' => (int)$entry['amount_cents'], 'charges_cents' => $charges, 'total_cents' => (int)$entry['amount_cents'] + $charges];
                    continue;
                }
                $account = $accounts[(int)$entry['id']] ?? 'Sem conta';   // visão "fee": por taxa (conta do plano)
                $key = $unit['condominium_id'] . '|' . $account;
                $out[$key] ??= ['condominium_id' => $unit['condominium_id'], 'condominium_name' => $unit['condominium_name'], 'account' => $account,
                    'units' => [], 'entries' => 0, 'amount_cents' => 0, 'charges_cents' => 0, 'total_cents' => 0];
                $out[$key]['units'][(string)$unit['unit_label']] = true;
                $out[$key]['entries']++;
                $out[$key]['amount_cents'] += (int)$entry['amount_cents'];
                $out[$key]['charges_cents'] += $charges;
                $out[$key]['total_cents'] += (int)$entry['amount_cents'] + $charges;
            }
        }
        if ($view === 'fee') {
            foreach ($out as &$row) {
                $row['units'] = count($row['units']);
            }
            unset($row);
        }
        return array_values($out);
    }

    /**
     * Recebimentos (AP-071) e por conta (AP-072): receitas pagas no período (data do pagamento),
     * em linhas ou agrupadas por conta bancária ou conta do plano.
     */
    public function receipts(User $user, ?int $condominiumId, string $from, string $to, string $group = 'entry'): array
    {
        [$from, $to] = self::period($from, $to);
        if (!in_array($group, self::RECEIPT_GROUPS, true)) {
            throw new InvalidArgumentException('Agrupamento inválido. Use: ' . implode(', ', self::RECEIPT_GROUPS) . '.');
        }
        $result = ['from' => $from, 'to' => $to, 'group' => $group, 'rows' => [], 'totals' => ['entries' => 0, 'amount_cents' => 0, 'late' => 0]];
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return $result;
        }
        $stmt = $this->pdo->prepare("SELECT e.id, e.condominium_id, c.name AS condominium_name, u.label AS unit_label, e.description, e.competence,
                e.due_on, e.paid_on, e.amount_cents, e.bank_account_id, b.name AS bank_account_name, a.code AS account_code, a.name AS account_name
            FROM erp_entries e JOIN condominiums c ON c.id = e.condominium_id LEFT JOIN erp_units u ON u.id = e.unit_id
            LEFT JOIN erp_bank_accounts b ON b.id = e.bank_account_id JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN (" . implode(',', $ids) . ") AND e.nature = 'receita' AND e.status = 'active' AND e.deleted_at IS NULL
              AND e.paid_on BETWEEN :f AND :t
            ORDER BY c.name, e.paid_on, e.id LIMIT " . (self::MAX_RECEIPT_ROWS + 1));
        $stmt->execute(['f' => $from, 't' => $to]);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($raw) > self::MAX_RECEIPT_ROWS) {
            throw new InvalidArgumentException(sprintf('O período tem mais de %d recebimentos. Reduza o período ou escolha um condomínio.', self::MAX_RECEIPT_ROWS));
        }
        $rows = array_map(static fn (array $r): array => [
            'entry_id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'],
            'unit_label' => $r['unit_label'] ?? 'Sem unidade', 'description' => (string)$r['description'], 'competence' => (string)$r['competence'],
            'due_on' => (string)$r['due_on'], 'paid_on' => (string)$r['paid_on'], 'amount_cents' => (int)$r['amount_cents'],
            'days_late' => max(0, Agreements::daysLate((string)$r['due_on'], (string)$r['paid_on'])),
            'bank_account_id' => $r['bank_account_id'] !== null ? (int)$r['bank_account_id'] : null, 'bank_account' => $r['bank_account_name'] ?? 'Sem conta bancária',
            'chart_account' => trim($r['account_code'] . ' ' . $r['account_name']),
        ], $raw);
        $result['rows'] = self::groupReceipts($rows, $group);
        $result['totals'] = ['entries' => count($rows), 'amount_cents' => array_sum(array_column($rows, 'amount_cents')),
            'late' => count(array_filter($rows, static fn (array $r): bool => $r['days_late'] > 0))];
        return $result;
    }

    /** Agrupa os recebimentos (regra pura) por conta bancária ou do plano, dentro de cada condomínio. */
    public static function groupReceipts(array $rows, string $group): array
    {
        if ($group === 'entry') {
            return $rows;
        }
        $field = $group === 'bank_account' ? 'bank_account' : 'chart_account';
        $out = [];
        foreach ($rows as $row) {
            $key = $row['condominium_id'] . '|' . $row[$field];
            $out[$key] ??= ['condominium_id' => $row['condominium_id'], 'condominium_name' => $row['condominium_name'], 'account' => $row[$field],
                'entries' => 0, 'amount_cents' => 0, 'late' => 0];
            $out[$key]['entries']++;
            $out[$key]['amount_cents'] += $row['amount_cents'];
            $out[$key]['late'] += $row['days_late'] > 0 ? 1 : 0;
        }
        return array_values($out);
    }

    /**
     * Tabela para exportar (regra pura): cabeçalho e linhas com os valores em reais (1234,56) e as
     * datas em DD/MM/AAAA. Células são saneadas contra injeção de fórmula na planilha.
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(string $kind, array $report): array
    {
        $money = static fn (mixed $c): string => number_format(((int)$c) / 100, 2, ',', '');
        $date = static fn (mixed $d): string => is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : (string)($d ?? '');
        $columns = match ($kind) {
            'agreements' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Acordo' => 'id', 'Situação' => 'status', 'Criado em' => ['created_on', $date],
                'Parcelas' => 'installments', 'Pagas' => 'paid_installments', 'Total' => ['total_cents', $money], 'Pago' => ['paid_cents', $money],
                'Em aberto' => ['open_cents', $money], 'Vencido' => ['overdue_cents', $money], 'Próximo vencimento' => ['next_due_on', $date]],
            'agreement-fees' => ['Condomínio' => 'condominium_name', 'Acordos' => 'agreements', 'Cancelados' => 'cancelled', 'Dívida original' => ['original_cents', $money],
                'Encargos' => ['fees_cents', $money], 'Descontos' => ['discount_cents', $money], 'Encargos líquidos' => ['net_fees_cents', $money], 'Total' => ['total_cents', $money]],
            'delinquents-general' => ['Condomínio' => 'condominium_name', 'Unidades' => 'units', 'Contas' => 'entries', 'Valor' => ['amount_cents', $money],
                'Encargos' => ['charges_cents', $money], 'Total' => ['total_cents', $money], 'Maior atraso (dias)' => 'max_days_late'],
            'delinquents-unit' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Contas' => 'entries', 'Valor' => ['amount_cents', $money],
                'Encargos' => ['charges_cents', $money], 'Total' => ['total_cents', $money], 'Mais antiga' => ['oldest_due_on', $date], 'Maior atraso (dias)' => 'max_days_late'],
            'delinquents-detailed' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Descrição' => 'description', 'Competência' => 'competence',
                'Vencimento' => ['due_on', $date], 'Dias de atraso' => 'days_late', 'Valor' => ['amount_cents', $money], 'Encargos' => ['charges_cents', $money], 'Total' => ['total_cents', $money]],
            'delinquents-fee' => ['Condomínio' => 'condominium_name', 'Taxa (conta)' => 'account', 'Unidades' => 'units', 'Contas' => 'entries',
                'Valor' => ['amount_cents', $money], 'Encargos' => ['charges_cents', $money], 'Total' => ['total_cents', $money]],
            'delinquents-resident' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Responsável' => 'responsible.name', 'Documento' => 'responsible.document',
                'Telefone' => 'responsible.phone', 'E-mail' => 'responsible.email', 'Contas' => 'entries', 'Total' => ['total_cents', $money], 'Maior atraso (dias)' => 'max_days_late'],
            'receipts-entry' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Descrição' => 'description', 'Competência' => 'competence',
                'Vencimento' => ['due_on', $date], 'Pagamento' => ['paid_on', $date], 'Dias de atraso' => 'days_late', 'Valor' => ['amount_cents', $money],
                'Conta bancária' => 'bank_account', 'Conta do plano' => 'chart_account'],
            'receipts-bank_account', 'receipts-chart_account' => ['Condomínio' => 'condominium_name', 'Conta' => 'account', 'Recebimentos' => 'entries',
                'Pagos com atraso' => 'late', 'Valor' => ['amount_cents', $money]],
            default => throw new InvalidArgumentException('Relatório sem exportação.'),
        };
        $source = $report['agreements'] ?? $report['condominiums'] ?? $report['rows'] ?? [];
        $rows = [];
        foreach ($source as $row) {
            $line = [];
            foreach ($columns as $spec) {
                [$path, $format] = is_array($spec) ? $spec : [$spec, null];
                $value = $row;
                foreach (explode('.', $path) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                $line[] = self::csvCell($format !== null ? $format($value) : (string)($value ?? ''));
            }
            $rows[] = $line;
        }
        return ['header' => array_keys($columns), 'rows' => $rows];
    }

    /**
     * Célula segura para planilha (regra pura, ADR 0081 e 0067): todo texto que começa com = + - @ (ou
     * tab, CR e LF, que as planilhas também interpretam) ganha um apóstrofo na frente, sem exceção.
     * Vale também quando o sinal vem depois de espaços ou caracteres de controle, que algumas
     * planilhas descartam ao importar, e para as formas de largura total (＝ ＋ － ＠).
     */
    public static function csvCell(string $value): string
    {
        return preg_match('/^[\p{Z}\s\p{Cc}\x{FEFF}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]|^[\t\r\n]/u', $value) === 1 ? "'" . $value : $value;
    }

    /** Arquivo CSV (regra pura): BOM UTF-8, separador ";", linhas CRLF, cada célula já saneada pelo csvTable. */
    public static function csv(array $table): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach (array_merge([array_map([self::class, 'csvCell'], $table['header'])], $table['rows']) as $row) {
            fputcsv($handle, $row, ';', '"', '', "\r\n");
        }
        rewind($handle);
        $content = (string)stream_get_contents($handle);
        fclose($handle);
        return $content;
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
