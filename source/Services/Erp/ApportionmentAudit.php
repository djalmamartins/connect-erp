<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Auditoria contábil de um condomínio de teste do rateio (ApportionmentSample, ADR 0640). Confere os números do sistema
 * contra contas refeitas aqui, de forma independente (somas diretas no banco e as fórmulas escritas de novo), e os
 * relatórios uns contra os outros. Cada linha: área, o que se confere, esperado, encontrado, tipo do número e ok.
 * Linhas 'info' só contam o que aconteceu (sempre ok).
 */
final class ApportionmentAudit
{
    private PDO $pdo;
    private User $user;
    private CondominiumScope $scope;
    private int $condo;
    private array $s;
    private string $today;
    private array $checks = [];

    public function __construct(PDO $pdo, User $user, int $condominiumId, array $scenario, string $today)
    {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->scope = new CondominiumScope($pdo);
        $this->condo = $condominiumId;
        $this->s = $scenario;
        $this->today = $today;
    }

    /** @return list<array{area:string, check:string, expected:int, actual:int, kind:string, ok:bool}> */
    public function run(): array
    {
        $this->checks = [];
        $this->apportionment();
        $this->consumption();
        $this->charges();
        $this->agreements();
        $this->banks();
        $this->closings();
        $this->delinquency();
        $this->reports();
        return $this->checks;
    }

    private function add(string $area, string $check, int $expected, int $actual, string $kind = 'cents'): void
    {
        $this->checks[] = ['area' => $area, 'check' => $check, 'expected' => $expected, 'actual' => $actual, 'kind' => $kind, 'ok' => $expected === $actual];
    }

    private function info(string $area, string $check, int $value, string $kind = 'count'): void
    {
        $this->checks[] = ['area' => $area, 'check' => $check, 'expected' => $value, 'actual' => $value, 'kind' => $kind, 'ok' => true, 'info' => true];
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute((str_contains($sql, ':c') ? ['c' => $this->condo] : []) + $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function value(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute((str_contains($sql, ':c') ? ['c' => $this->condo] : []) + $params);
        return (int)$stmt->fetchColumn();
    }

    /** Soma direta das contas válidas do condomínio. */
    private function sum(string $where, array $params = []): int
    {
        return $this->value("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE condominium_id = :c AND status = 'active' AND deleted_at IS NULL AND {$where}", $params);
    }

    // ------------------------------------------------------------------ rateio

    private function apportionment(): void
    {
        $units = [];
        foreach ($this->rows("SELECT u.id, u.label, u.number, u.block, u.ideal_fraction, u.apportion_percent, u.apportionment_exempt,
                (SELECT COUNT(*) FROM erp_linkable_areas a WHERE a.unit_id = u.id AND a.kind = 'vaga' AND a.status = 'active' AND a.deleted_at IS NULL) AS spots
            FROM erp_units u WHERE u.condominium_id = :c AND u.deleted_at IS NULL AND u.status = 'active'") as $u) {
            $units[(int)$u['id']] = $u;
        }
        $byLabel = array_column($units, 'id', 'label');
        $this->info('Rateio', 'Unidades ativas do condomínio', count($units));
        $methods = array_unique(array_column($this->s['lines'], 'method'));
        if (in_array('fraction', $methods, true)) {
            $this->add('Rateio', 'Frações ideais somam 1 (100%), em milionésimos', 1_000_000, (int)round(array_sum(array_map(static fn (array $u): float => (float)$u['ideal_fraction'], $units)) * 1_000_000), 'millionths');
        }
        if (in_array('percent', $methods, true)) {
            $this->add('Rateio', 'Percentuais definidos somam 100%, em décimos de milésimo', 1_000_000, (int)round(array_sum(array_map(static fn (array $u): float => (float)$u['apportion_percent'], $units)) * 10_000), 'ten_thousandths');
        }
        if (in_array('parking', $methods, true)) {
            $this->info('Rateio', 'Vagas de garagem vinculadas', (int)array_sum(array_column($units, 'spots')));
            $this->info('Rateio', 'Unidades sem vaga (ficam fora do rateio da garagem)', count(array_filter($units, static fn (array $u): bool => (int)$u['spots'] === 0)));
        }
        foreach ($this->s['lines'] as $line) {
            $invoices = $this->rows("SELECT i.* FROM erp_invoices i JOIN erp_invoice_types t ON t.id = i.invoice_type_id WHERE i.condominium_id = :c AND t.name = :t
                AND i.status = 'issued' ORDER BY i.competence", ['t' => $line['type']]);
            $expectedMonths = isset($line['months']) ? count(array_filter($line['months'], fn (string $m): bool => $m <= substr($this->today, 0, 7)))
                : count(ApportionmentSample::months($this->today));
            $name = $line['type'];
            $this->add('Rateio', "{$name}: faturas emitidas no ano", $expectedMonths, count($invoices), 'count');
            $requested = 0;
            $billed = 0;
            $wrongShare = 0;
            $wrongPeople = 0;
            $people = 0;
            foreach ($invoices as $invoice) {
                $items = [];
                foreach ($this->rows('SELECT unit_id, amount_cents FROM erp_invoice_items WHERE invoice_id = :i', ['i' => (int)$invoice['id']]) as $item) {
                    $items[(int)$item['unit_id']] = (int)$item['amount_cents'];
                }
                // Quem deveria participar, refeito aqui a partir do cadastro.
                $expected = match (true) {
                    ($line['participants'] ?? 'all') === 'units' => array_map(static fn (string $l): int => (int)$byLabel[$l], array_keys($line['percents'] ?? $line['amounts'])),
                    ($line['participants'] ?? 'all') === 'blocks' => array_keys(array_filter($units, static fn (array $u): bool => in_array((string)$u['block'], $line['blocks'], true) && (int)$u['apportionment_exempt'] === 0)),
                    default => array_keys(array_filter($units, static fn (array $u): bool => (int)$u['apportionment_exempt'] === 0)),
                };
                if ($line['method'] === 'parking') {
                    $expected = array_values(array_filter($expected, static fn (int $id): bool => (int)$units[$id]['spots'] > 0));
                }
                sort($expected);
                $got = array_keys($items);
                sort($got);
                $wrongPeople += $expected === $got ? 0 : 1;
                $people = count($got);
                // Valor esperado de cada unidade, refeito com a base dela (sem usar o rateio do sistema).
                $weights = [];
                foreach ($expected as $id) {
                    $weights[$id] = match ($line['method']) {
                        'fixed' => 1.0, 'equal' => 1.0, 'fraction' => (float)$units[$id]['ideal_fraction'],
                        'percent' => (float)$units[$id]['apportion_percent'], 'parking' => (float)$units[$id]['spots'],
                        'custom' => isset($line['percents']) ? (float)$line['percents'][$units[$id]['label']] : 0.0,
                    };
                }
                $total = $line['method'] === 'fixed' ? $line['amount'] * count($expected) : ($line['amount'] ?? array_sum($line['amounts'] ?? []));
                $requested += $total;
                $billed += array_sum($items);
                $sumWeights = array_sum($weights);
                foreach ($items as $id => $cents) {
                    $exact = match (true) {
                        $line['method'] === 'fixed' => (float)$line['amount'],
                        isset($line['amounts']) => (float)$line['amounts'][$units[$id]['label']],
                        default => $sumWeights > 0 && isset($weights[$id]) ? $total * $weights[$id] / $sumWeights : -1.0,
                    };
                    // O rateio por maiores restos dá o valor exato arredondado para baixo ou para cima: diferença < 1 centavo.
                    if (abs($cents - $exact) >= 1.0) {
                        $wrongShare++;
                    }
                }
            }
            $this->add('Rateio', "{$name}: soma das unidades = total a ratear (todas as faturas)", $requested, $billed);
            $this->add('Rateio', "{$name}: unidades fora da sua base (mais de 1 centavo de diferença)", 0, $wrongShare, 'count');
            $this->add('Rateio', "{$name}: faturas com participantes errados", 0, $wrongPeople, 'count');
            $this->info('Rateio', "{$name}: unidades que participam", $people);
            if (isset($line['percents'])) {
                $this->add('Rateio', "{$name}: percentuais combinados somam 100%", 100, (int)array_sum(array_map('floatval', $line['percents'])), 'percent');
            }
        }
    }

    // ------------------------------------------------------------------ consumo

    private function consumption(): void
    {
        foreach ($this->s['meters'] ?? [] as $kind) {
            $label = $kind === 'agua' ? 'Água' : 'Gás';
            $readings = $this->rows('SELECT r.* FROM erp_meter_readings r JOIN erp_meters m ON m.id = r.meter_id WHERE r.condominium_id = :c AND m.kind = :k', ['k' => $kind]);
            $bands = $this->rows("SELECT from_amount, to_amount, price_cents FROM erp_tariffs WHERE condominium_id = :c AND kind = :k AND status = 'active' AND deleted_at IS NULL
                ORDER BY from_amount", ['k' => $kind]);
            $wrongValue = 0;
            $wrongConsumption = 0;
            foreach ($readings as $r) {
                $consumption = round((float)$r['reading'] - (float)$r['previous_reading'], 3);
                $wrongConsumption += abs($consumption - (float)$r['consumption']) > 0.0005 ? 1 : 0;
                // Tarifa progressiva refeita aqui: cada faixa cobra o consumo dentro dela.
                $cents = 0.0;
                foreach ($bands as $b) {
                    $low = (float)$b['from_amount'];
                    $high = $b['to_amount'] === null ? INF : (float)$b['to_amount'];
                    $in = min($consumption, $high) - $low;
                    if ($in > 0) {
                        $cents += $in * (int)$b['price_cents'];
                    }
                }
                $wrongValue += abs((int)round($cents) - (int)$r['amount_cents']) > 1 ? 1 : 0;
            }
            $this->info('Consumo', "{$label}: leituras no ano", count($readings));
            $this->add('Consumo', "{$label}: consumo = leitura atual − anterior (leituras erradas)", 0, $wrongConsumption, 'count');
            $this->add('Consumo', "{$label}: valor de cada leitura pela tarifa (leituras com mais de 1 centavo de diferença)", 0, $wrongValue, 'count');
            $this->add('Consumo', "{$label}: soma das leituras = contas a receber de consumo", (int)array_sum(array_column($readings, 'amount_cents')),
                $this->value("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e JOIN erp_meter_readings r ON r.entry_id = e.id JOIN erp_meters m ON m.id = r.meter_id
                    WHERE r.condominium_id = :c AND m.kind = :k AND e.deleted_at IS NULL", ['k' => $kind]));
            $this->add('Consumo', "{$label}: leituras com valor e sem cobrança", 0, count(array_filter($readings, static fn (array $r): bool => $r['entry_id'] === null && (int)$r['amount_cents'] > 0)), 'count');
        }
    }

    // ------------------------------------------------------------------ boletos, multa e juros

    private function charges(): void
    {
        $this->info('Boletos', 'Boletos emitidos', $this->value('SELECT COUNT(*) FROM erp_bank_charges WHERE condominium_id = :c'));
        $this->add('Boletos', 'Boletos pagos (valor do título) = contas a receber baixadas por boleto',
            $this->value("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_bank_charges WHERE condominium_id = :c AND status = 'paid'"),
            $this->value("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e JOIN erp_bank_charges b ON b.entry_id = e.id AND b.status = 'paid' WHERE e.condominium_id = :c AND e.paid_on IS NOT NULL")
            + $this->value("SELECT COALESCE(SUM(ce.amount_cents), 0) FROM erp_bank_charge_entries ce JOIN erp_bank_charges b ON b.id = ce.charge_id AND b.status = 'paid' WHERE b.condominium_id = :c"));
        $this->add('Boletos', 'Divergências pendentes no retorno bancário', 0,
            $this->value("SELECT COUNT(*) FROM erp_bank_events ev WHERE ev.condominium_id = :c AND ev.outcome IN ('divergence','unknown_charge') AND ev.resolved_at IS NULL"), 'count');

        $late = $this->rows("SELECT b.id, b.our_number, b.amount_cents, b.paid_cents, b.due_on, b.paid_on FROM erp_bank_charges b WHERE b.condominium_id = :c AND b.status = 'paid'
            AND b.paid_on > b.due_on AND b.paid_cents > b.amount_cents");
        $fees = [];
        foreach ($this->rows("SELECT document_number, amount_cents, paid_on, competence FROM erp_entries WHERE condominium_id = :c AND document_number LIKE 'ENC-%' AND status = 'active' AND deleted_at IS NULL") as $e) {
            $fees[substr((string)$e['document_number'], 4)] = $e;
        }
        $missing = 0;
        $wrong = 0;
        $afterClosing = 0;
        foreach ($late as $b) {
            $fee = $fees[(string)$b['our_number']] ?? null;
            if ($fee === null || (int)$fee['amount_cents'] !== (int)$b['paid_cents'] - (int)$b['amount_cents']) {
                $missing++;
                continue;
            }
            // Multa de 2% e juros de 1% ao mês pro rata (30 dias), refeitos aqui.
            $days = (int)(new \DateTimeImmutable((string)$b['due_on']))->diff(new \DateTimeImmutable((string)$b['paid_on']))->days;
            $expected = (int)round((int)$b['amount_cents'] * 0.02) + (int)round((int)$b['amount_cents'] * 0.01 * $days / 30);
            $wrong += abs($expected - (int)$fee['amount_cents']) > 0 ? 1 : 0;
            // O mês da conta fecha no dia 5 do mês seguinte (ApportionmentSample::CLOSING_DAY): pago depois disso, entrou sem reabrir.
            $closedOn = (new \DateTimeImmutable(substr((string)$b['due_on'], 0, 7) . '-01'))->modify('+1 month')->format('Y-m-') . sprintf('%02d', ApportionmentSample::CLOSING_DAY);
            $afterClosing += (string)$b['paid_on'] > $closedOn ? 1 : 0;
        }
        $this->info('Multa e juros', 'Boletos pagos com atraso, com multa e juros', count($late));
        $this->add('Multa e juros', 'Boletos atrasados sem a receita de multa e juros (ou com outro valor)', 0, $missing, 'count');
        $this->add('Multa e juros', 'Multa de 2% + juros de 1% a.m. pro rata refeitos (diferenças)', 0, $wrong, 'count');
        $this->add('Multa e juros', 'Receita de multas e juros = valor recebido a mais nos boletos', (int)array_sum(array_map(static fn (array $b): int => (int)$b['paid_cents'] - (int)$b['amount_cents'], $late)),
            $this->sum("document_number LIKE 'ENC-%'"));
        $this->add('Boletos', 'Recebido pelo banco = títulos pagos + multa e juros', $this->value("SELECT COALESCE(SUM(paid_cents), 0) FROM erp_bank_charges WHERE condominium_id = :c AND status = 'paid'"),
            $this->value("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_bank_charges WHERE condominium_id = :c AND status = 'paid'") + $this->sum("document_number LIKE 'ENC-%'"));
        $this->info('Multa e juros', 'Pagamentos atrasados baixados com o mês da conta já fechado (sem reabrir)', $afterClosing);
    }

    // ------------------------------------------------------------------ acordos

    private function agreements(): void
    {
        $list = $this->rows('SELECT * FROM erp_agreements WHERE condominium_id = :c ORDER BY id');
        $this->add('Acordos', 'Acordos feitos', 1, count($list), 'count');
        $rates = (new BillingRules($this->pdo, $this->scope))->rates('IPCA', '2025-01', '2026-12');
        foreach ($list as $a) {
            $id = (int)$a['id'];
            $label = "Acordo {$id}";
            $this->add('Acordos', "{$label}: total = dívida + encargos − desconto", (int)$a['original_cents'] + (int)$a['fees_cents'] - (int)$a['discount_cents'], (int)$a['total_cents']);
            $this->add('Acordos', "{$label}: soma das parcelas = total do acordo", (int)$a['total_cents'],
                $this->value("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE agreement_id = :a AND agreement_role = 'installment' AND deleted_at IS NULL", ['a' => $id]));
            $this->add('Acordos', "{$label}: parcelas", (int)$a['installments'],
                $this->value("SELECT COUNT(*) FROM erp_entries WHERE agreement_id = :a AND agreement_role = 'installment' AND deleted_at IS NULL", ['a' => $id]), 'count');
            $originals = $this->rows("SELECT e.amount_cents, e.due_on, e.status, e.paid_on, e.bank_account_id FROM erp_agreement_originals o JOIN erp_entries e ON e.id = o.entry_id WHERE o.agreement_id = :a AND e.condominium_id = :c", ['a' => $id]);
            $this->add('Acordos', "{$label}: dívida = soma das contas renegociadas", (int)$a['original_cents'], (int)array_sum(array_column($originals, 'amount_cents')));
            // ADR 0642: conta de mês fechado fica ativa, quitada por compensação na data do acordo, sem banco; a de mês aberto é cancelada.
            $compensated = array_filter($originals, static fn (array $o): bool => $o['status'] === 'active');
            $this->add('Acordos', "{$label}: contas renegociadas ainda em aberto (cobradas duas vezes)", 0,
                count(array_filter($originals, static fn (array $o): bool => $o['status'] === 'active' && $o['paid_on'] === null)), 'count');
            $this->add('Acordos', "{$label}: contas de mês fechado quitadas por compensação na data do acordo, sem banco", count($compensated),
                count(array_filter($compensated, static fn (array $o): bool => (string)$o['paid_on'] === ApportionmentSample::AGREEMENT_ON && $o['bank_account_id'] === null)), 'count');
            $this->add('Acordos', "{$label}: compensação na 2.92 no mês do acordo = contas compensadas", (int)array_sum(array_column($compensated, 'amount_cents')),
                $this->value("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id WHERE e.agreement_id = :a
                    AND e.agreement_role IS NULL AND e.nature = 'despesa' AND a.code = '2.92' AND e.competence = :m AND e.status = 'active' AND e.bank_account_id IS NULL",
                    ['a' => $id, 'm' => substr(ApportionmentSample::AGREEMENT_ON, 0, 7)]));
            $this->info('Acordos', "{$label}: contas de mês fechado no acordo (compensadas sem reabrir)", count($compensated));
            // Encargos refeitos: correção pelo IPCA do mês do vencimento até o mês anterior ao acordo, multa 2% e juros 1% a.m.
            $fees = 0;
            $asOf = ApportionmentSample::AGREEMENT_ON;
            foreach ($originals as $o) {
                $factor = 1.0;
                foreach (LateCharges::indexMonths((string)$o['due_on'], $asOf) as $m) {
                    $factor *= 1 + (float)($rates[$m] ?? 0) / 100;
                }
                $base = (int)round((int)$o['amount_cents'] * $factor);
                $days = (int)(new \DateTimeImmutable((string)$o['due_on']))->diff(new \DateTimeImmutable($asOf))->days;
                $fees += $base - (int)$o['amount_cents'] + (int)round($base * 0.02) + (int)round($base * 0.01 * $days / 30);
            }
            $this->add('Acordos', "{$label}: encargos (IPCA + multa 2% + juros 1% a.m.) refeitos em " . $asOf, $fees, (int)$a['fees_cents']);
            $paid = $this->value("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE agreement_id = :a AND agreement_role = 'installment' AND paid_on IS NOT NULL", ['a' => $id]);
            $open = $this->value("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE agreement_id = :a AND agreement_role = 'installment' AND paid_on IS NULL AND status = 'active'", ['a' => $id]);
            $this->add('Acordos', "{$label}: recebido + em aberto = total", (int)$a['total_cents'], $paid + $open);
            $this->add('Acordos', "{$label}: situação (1 = ativo, 2 = quebrado)", empty($this->s['breach']) ? 1 : 2, $a['status'] === 'breached' ? 2 : ($a['status'] === 'active' ? 1 : 0), 'count');
            $this->add('Acordos', "{$label}: unidade sem conta original vencida em aberto", 0,
                $this->sum("nature = 'receita' AND paid_on IS NULL AND due_on < :d AND unit_id = :u AND agreement_id IS NULL AND competence IN ('2026-02','2026-03','2026-04')",
                    ['d' => $this->today, 'u' => (int)$a['unit_id']]));
        }
    }

    // ------------------------------------------------------------------ bancos

    private function banks(): void
    {
        $byAccount = [];
        foreach ((new BankBalances($this->pdo, $this->scope))->forUser($this->user, $this->condo) as $row) {
            $byAccount[(int)$row['id']] = (int)($row['balance_cents'] ?? $row['current_cents'] ?? 0);
        }
        $asOf = (new AccountingReports($this->pdo, $this->scope))->accountBalances($this->user, $this->condo, $this->today);
        $direct = 0;
        $opening = 0;
        foreach ($this->rows('SELECT id, name, opening_balance_cents, opened_on FROM erp_bank_accounts WHERE condominium_id = :c AND deleted_at IS NULL ORDER BY id') as $bank) {
            $id = (int)$bank['id'];
            $opening += (int)$bank['opening_balance_cents'];
            $expected = (int)$bank['opening_balance_cents']
                + $this->value("SELECT COALESCE(SUM(CASE WHEN nature = 'receita' THEN amount_cents ELSE -amount_cents END), 0) FROM erp_entries WHERE bank_account_id = :b
                    AND status = 'active' AND deleted_at IS NULL AND paid_on IS NOT NULL AND paid_on <= :d AND condominium_id = :c", ['b' => $id, 'd' => $this->today])
                + $this->value("SELECT COALESCE(SUM(CASE WHEN to_bank_account_id = :b THEN amount_cents ELSE -amount_cents END), 0) FROM erp_bank_transfers
                    WHERE (to_bank_account_id = :b2 OR from_bank_account_id = :b3) AND status = 'active' AND transferred_on <= :d AND condominium_id = :c", ['b' => $id, 'b2' => $id, 'b3' => $id, 'd' => $this->today]);
            $direct += $expected;
            $this->add('Bancos', "Saldo de {$bank['name']} (tela Contas bancárias)", $expected, $byAccount[$id] ?? PHP_INT_MIN);
            $report = PHP_INT_MIN;
            foreach ($asOf['rows'] ?? $asOf['accounts'] ?? [] as $row) {
                if ((int)($row['bank_account_id'] ?? $row['id'] ?? 0) === $id) {
                    $report = (int)($row['closing_cents'] ?? $row['balance_cents'] ?? 0);
                }
            }
            $this->add('Bancos', "Saldo de {$bank['name']} (relatório Saldos de contas)", $expected, $report);
        }
        $this->add('Bancos', 'Soma dos bancos = abertura + receitas recebidas − despesas pagas (transferências não mudam o total)',
            $opening + $this->sum("nature = 'receita' AND paid_on IS NOT NULL AND paid_on <= :d", ['d' => $this->today]) - $this->sum("nature = 'despesa' AND paid_on IS NOT NULL AND paid_on <= :d", ['d' => $this->today]),
            $direct);
        $this->c['direct_banks'] = $direct;
    }

    private array $c = [];

    // ------------------------------------------------------------------ fechamentos e prestação de contas

    private function closings(): void
    {
        $months = ApportionmentSample::months($this->today);
        $expectedClosed = array_slice($months, 0, -1);
        $closed = $this->rows("SELECT * FROM erp_month_closings WHERE condominium_id = :c AND status = 'closed' ORDER BY competence");
        $this->add('Fechamentos', 'Meses fechados (janeiro até o mês passado)', count($expectedClosed), count($closed), 'count');
        $this->add('Fechamentos', 'Meses reabertos (o acordo não reabre mês fechado)', 0, $this->value("SELECT COUNT(*) FROM erp_month_closings WHERE condominium_id = :c AND reopened_at IS NOT NULL"), 'count');
        $changed = 0;
        foreach ($closed as $row) {
            $revenue = $this->sum("nature = 'receita' AND competence = :m", ['m' => $row['competence']]);
            $expense = $this->sum("nature = 'despesa' AND competence = :m", ['m' => $row['competence']]);
            $changed += ($revenue !== (int)$row['revenue_cents'] || $expense !== (int)$row['expense_cents']) ? 1 : 0;
        }
        $this->add('Fechamentos', 'Meses fechados cuja receita ou despesa mudou depois do fechamento', 0, $changed, 'count');
        $publications = new AccountabilityPublications($this->pdo, $this->scope);
        $broken = 0;
        $outdated = 0;
        $current = 0;
        foreach ($this->rows("SELECT id, competence, sha256 FROM erp_accountability_publications WHERE condominium_id = :c AND kind = 'monthly' AND status = 'current'") as $p) {
            $current++;
            $found = $publications->find($this->user, (int)$p['id']);
            $broken += empty($found['intact']) ? 1 : 0;
            $rebuilt = hash('sha256', AccountabilityPublications::canonical((new Accountability($this->pdo, $this->scope))->build($this->user, $this->condo, $p['competence'])));
            $outdated += hash_equals((string)$p['sha256'], $rebuilt) ? 0 : 1;
        }
        $this->add('Prestação de contas', 'Prestações publicadas (uma por mês fechado)', count($expectedClosed), $current, 'count');
        $this->add('Prestação de contas', 'Prestações publicadas com o conteúdo alterado (sha256 não confere)', 0, $broken, 'count');
        $this->add('Prestação de contas', 'Prestações publicadas de novo depois de fechadas (versão 2 ou mais)', 0, $this->value("SELECT COUNT(*) FROM erp_accountability_publications WHERE condominium_id = :c AND version > 1"), 'count');
        $this->info('Prestação de contas', 'Prestações que sairiam diferentes se geradas hoje (pagamentos atrasados depois da publicação)', $outdated);
    }

    // ------------------------------------------------------------------ inadimplência

    private function delinquency(): void
    {
        $agreements = new Agreements($this->pdo, $this->scope);
        $overdue = $this->sum("nature = 'receita' AND paid_on IS NULL AND due_on < :d", ['d' => $this->today]);
        $this->add('Inadimplência', 'Inadimplência de hoje = contas a receber vencidas em aberto', $overdue,
            (int)($agreements->delinquency($this->user, $this->condo, $this->today)['totals']['amount_cents'] ?? PHP_INT_MIN));
        $report = (new CollectionReports($this->pdo, $this->scope))->delinquents($this->user, $this->condo, $this->today, 'general');
        $this->add('Inadimplência', 'Relatório de inadimplentes = contas vencidas em aberto', $overdue, (int)($report['totals']['amount_cents'] ?? PHP_INT_MIN));
        // Numa data passada: em aberto naquele dia = não paga ou paga depois dele (como o Balanço).
        $wrong = 0;
        foreach (array_slice(ApportionmentSample::months($this->today), 0, -1) as $m) {
            $end = (new \DateTimeImmutable($m . '-01'))->modify('last day of this month')->format('Y-m-d');
            $then = $this->sum("nature = 'receita' AND (paid_on IS NULL OR paid_on > :p) AND due_on < :d", ['p' => $end, 'd' => $end]);
            $wrong += $then === (int)($agreements->delinquency($this->user, $this->condo, $end)['totals']['amount_cents'] ?? PHP_INT_MIN) ? 0 : 1;
        }
        $this->add('Inadimplência', 'Fins de mês em que a inadimplência daquela data não confere', 0, $wrong, 'count');
        $from = ApportionmentSample::FIRST_MONTH . '-01';
        $receipts = (new CollectionReports($this->pdo, $this->scope))->receipts($this->user, $this->condo, $from, $this->today);
        $this->add('Inadimplência', 'Recebimentos do ano = receitas recebidas', $this->sum("nature = 'receita' AND paid_on BETWEEN :a AND :b", ['a' => $from, 'b' => $this->today]),
            (int)($receipts['totals']['amount_cents'] ?? PHP_INT_MIN));
    }

    // ------------------------------------------------------------------ relatórios contábeis

    private function reports(): void
    {
        $months = ApportionmentSample::months($this->today);
        $from = $months[0];
        $to = end($months);
        $fromDate = $from . '-01';
        $toDate = (new \DateTimeImmutable($to . '-01'))->modify('last day of this month')->format('Y-m-d');
        $period = ['f' => $from, 't' => $to];
        $financial = new FinancialReports($this->pdo, $this->scope);
        $accounting = new AccountingReports($this->pdo, $this->scope);
        $statement = $financial->statement($this->user, $this->condo, $fromDate, $this->today);
        $this->add('Relatórios', 'Prestação de contas por caixa: diferença da conciliação', 0, (int)($statement['reconciliation']['difference_cents'] ?? $statement['totals']['difference_cents'] ?? self::findKey($statement, 'difference_cents')));
        $revenue = $this->sum("nature = 'receita' AND competence BETWEEN :f AND :t", $period);
        $expense = $this->sum("nature = 'despesa' AND competence BETWEEN :f AND :t", $period);
        $roots = [];
        foreach ($financial->trialBalance($this->user, $this->condo, $from, $to)['rows'] ?? [] as $row) {
            $roots[(string)$row['code']] = (int)($row['expected_cents'] ?? $row['planned_cents'] ?? $row['competence_cents'] ?? 0);
        }
        $this->add('Relatórios', 'Balancete: receitas do ano (conta 1)', $revenue, $roots['1'] ?? PHP_INT_MIN);
        $this->add('Relatórios', 'Balancete: despesas do ano (conta 2)', $expense, $roots['2'] ?? PHP_INT_MIN);
        $dre = $accounting->incomeStatement($this->user, $this->condo, $fromDate, $toDate, 'competence');
        $this->add('Relatórios', 'DRE: resultado do ano = receitas − despesas', $revenue - $expense, (int)($dre['result_cents'] ?? $dre['totals']['result_cents'] ?? self::findKey($dre, 'result_cents')));
        $closing = new MonthClosing($this->pdo, $this->scope);
        $wrongMonth = 0;
        $wrongPack = 0;
        foreach ($months as $m) {
            $summary = $closing->status($this->user, $this->condo, $m)['summary'];
            $r = $this->sum("nature = 'receita' AND competence = :m", ['m' => $m]);
            $e = $this->sum("nature = 'despesa' AND competence = :m", ['m' => $m]);
            $wrongMonth += ((int)$summary['revenue_cents'] !== $r || (int)$summary['expense_cents'] !== $e) ? 1 : 0;
            $pack = (new Accountability($this->pdo, $this->scope))->build($this->user, $this->condo, $m);
            $wrongPack += self::findKey($pack['income'] ?? $pack['dre'] ?? $pack, 'result_cents') === $r - $e ? 0 : 1;
        }
        $this->add('Relatórios', 'Meses em que o fechamento não bate com a soma direta', 0, $wrongMonth, 'count');
        $this->add('Relatórios', 'Meses em que a prestação de contas não bate com a soma direta', 0, $wrongPack, 'count');
        $balance = (new BalanceSheet($this->pdo, $this->scope))->build($this->user, $this->condo, $this->today);
        $this->add('Balanço', 'Balanço patrimonial: ativo − (passivo + patrimônio líquido)', 0, (int)($balance['diferenca'] ?? $balance['difference_cents'] ?? PHP_INT_MIN));
        $this->add('Balanço', 'Balanço: disponível = soma dos bancos', (int)($this->c['direct_banks'] ?? 0), self::findCode($balance, '7.1'));
        $this->add('Balanço', 'Balanço: contas a receber = receitas vencidas e a vencer em aberto',
            $this->sum("nature = 'receita' AND competence <= :m AND paid_on IS NULL", ['m' => substr($this->today, 0, 7)]), self::findCode($balance, '7.2'));
    }

    /** Primeira chave com esse nome em qualquer nível (os relatórios têm formatos diferentes). */
    private static function findKey(array $data, string $key): int
    {
        if (array_key_exists($key, $data) && is_numeric($data[$key])) {
            return (int)$data[$key];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = self::findKey($value, $key);
                if ($found !== PHP_INT_MIN) {
                    return $found;
                }
            }
        }
        return PHP_INT_MIN;
    }

    /** Valor da linha do relatório com esse código de conta. */
    private static function findCode(array $data, string $code): int
    {
        if (($data['code'] ?? null) === $code) {
            foreach (['amount_cents', 'balance_cents', 'value_cents', 'total_cents'] as $key) {
                if (isset($data[$key]) && is_numeric($data[$key])) {
                    return (int)$data[$key];
                }
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = self::findCode($value, $code);
                if ($found !== PHP_INT_MIN) {
                    return $found;
                }
            }
        }
        return PHP_INT_MIN;
    }
}
