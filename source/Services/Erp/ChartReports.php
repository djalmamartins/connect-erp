<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Dados dos gráficos (ADR 0083), somente leitura, em séries mensais na faixa de competência (até
 * ManagementReports::MAX_MONTHS meses), com os mesmos lançamentos válidos dos ADR 0081 e 0082. A tela
 * desenha; aqui só vêm os números: {labels: [AAAA-MM...], series: [{key, label, data: [...]}]}.
 *
 * - general() (AP-102): receitas e despesas previstas (competência), resultado e inadimplência.
 * - accounts() (AP-103): por conta do plano de uma natureza; as TOP maiores e o resto em "Outras".
 * - consumption() (AP-104 por unidade, AP-105 geral): consumo por tipo.
 * - cash() (AP-106): recebido e pago por mês do pagamento (caixa) e o saldo do mês.
 * - delinquency() (AP-107): por competência, o faturado, o vencido em aberto hoje e a taxa (%).
 * - bankAccounts() (AP-108): saldo do mês (entradas − saídas, com transferências) por conta bancária; as TOP de
 *   maior movimento e o resto em "Outras", com os lançamentos e transferências já filtrados por condomínio (ADR 0069).
 * - commonAreas() (AP-109): reservas não canceladas por área comum e mês.
 *
 * Escopo por condomínio (ADR-002): um condomínio, ou todos os do usuário.
 */
final class ChartReports
{
    public const TOP = 8;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Meses da faixa (regra pura), já validada pelo ManagementReports::competences. */
    public static function months(string $from, string $to): array
    {
        [$from, $to] = ManagementReports::competences($from, $to);
        $months = [];
        for ($month = new \DateTimeImmutable($from . '-01'); $month->format('Y-m') <= $to; $month = $month->modify('+1 month')) {
            $months[] = $month->format('Y-m');
        }
        return $months;
    }

    /**
     * Série completa (regra pura): um valor por mês, zero onde não houve dado.
     *
     * @param list<string> $months
     * @param array<string, int|float> $values mês => valor
     * @return list<int|float>
     */
    public static function fill(array $months, array $values): array
    {
        return array_map(static fn (string $m): int|float => $values[$m] ?? 0, $months);
    }

    /**
     * Agrupa séries (regra pura): as $top de maior total ficam; as outras somam em "Outras".
     *
     * @param array<string, array<string, int>> $byKey chave => [mês => valor]
     * @param array<string, string> $labels chave => rótulo
     * @param bool $byMagnitude ordena pela soma dos valores absolutos (séries com sinal, como saldos; ADR 0069)
     * @return list<array{key:string, label:string, data:list<int|float>}>
     */
    public static function top(array $months, array $byKey, array $labels, int $top = self::TOP, bool $byMagnitude = false): array
    {
        $weight = static fn (array $values): int|float => $byMagnitude ? array_sum(array_map('abs', $values)) : array_sum($values);
        uksort($byKey, static fn (string $a, string $b): int => [$weight($byKey[$b]), $a] <=> [$weight($byKey[$a]), $b]);
        $series = [];
        $others = [];
        foreach ($byKey as $key => $values) {
            if (count($series) < $top) {
                $series[] = ['key' => (string)$key, 'label' => $labels[$key] ?? (string)$key, 'data' => self::fill($months, $values)];
                continue;
            }
            foreach ($values as $month => $value) {
                $others[$month] = ($others[$month] ?? 0) + $value;
            }
        }
        if ($others !== []) {
            $series[] = ['key' => 'others', 'label' => 'Outras', 'data' => self::fill($months, $others)];
        }
        return $series;
    }

    /** Taxa de inadimplência (regra pura): vencido em aberto ÷ faturado, em %, com 2 casas (null sem faturamento). */
    public static function rate(int $overdue, int $billed): ?float
    {
        return $billed > 0 ? round($overdue * 100 / $billed, 2) : null;
    }

    public function general(User $user, ?int $condominiumId, string $from, string $to): array
    {
        $months = self::months($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $data = ['receita' => [], 'despesa' => [], 'overdue' => []];
        if ($ids !== []) {
            $stmt = $this->pdo->prepare("SELECT competence, nature, SUM(amount_cents) AS total,
                    SUM(CASE WHEN nature = 'receita' AND paid_on IS NULL AND due_on < CURDATE() THEN amount_cents ELSE 0 END) AS overdue
                FROM erp_entries WHERE condominium_id IN (" . implode(',', $ids) . ") AND status = 'active' AND deleted_at IS NULL AND competence BETWEEN :f AND :t
                GROUP BY competence, nature");
            $stmt->execute(['f' => $months[0], 't' => end($months)]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $data[$r['nature']][$r['competence']] = (int)$r['total'];
                $data['overdue'][$r['competence']] = ($data['overdue'][$r['competence']] ?? 0) + (int)$r['overdue'];
            }
        }
        $result = array_map(static fn (string $m): int => ($data['receita'][$m] ?? 0) - ($data['despesa'][$m] ?? 0), $months);
        return ['labels' => $months, 'unit' => 'cents', 'series' => [
            ['key' => 'receita', 'label' => 'Receitas', 'data' => self::fill($months, $data['receita'])],
            ['key' => 'despesa', 'label' => 'Despesas', 'data' => self::fill($months, $data['despesa'])],
            ['key' => 'result', 'label' => 'Resultado', 'data' => $result],
            ['key' => 'overdue', 'label' => 'Vencido em aberto', 'data' => self::fill($months, $data['overdue'])],
        ]];
    }

    public function accounts(User $user, ?int $condominiumId, string $from, string $to, string $nature): array
    {
        if (!in_array($nature, ['receita', 'despesa'], true)) {
            throw new InvalidArgumentException('Natureza inválida: receita ou despesa.');
        }
        $months = self::months($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $byKey = [];
        $labels = [];
        if ($ids !== []) {
            $stmt = $this->pdo->prepare("SELECT e.competence, a.code, a.name, SUM(e.amount_cents) AS total FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
                WHERE e.condominium_id IN (" . implode(',', $ids) . ") AND e.status = 'active' AND e.deleted_at IS NULL AND e.nature = :n AND e.competence BETWEEN :f AND :t
                GROUP BY e.competence, a.code, a.name");
            $stmt->execute(['n' => $nature, 'f' => $months[0], 't' => end($months)]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byKey[(string)$r['code']][$r['competence']] = ($byKey[(string)$r['code']][$r['competence']] ?? 0) + (int)$r['total'];
                $labels[(string)$r['code']] ??= $r['code'] . ' ' . $r['name'];
            }
        }
        return ['labels' => $months, 'unit' => 'cents', 'nature' => $nature, 'series' => self::top($months, $byKey, $labels)];
    }

    public function consumption(User $user, ?int $condominiumId, string $from, string $to, ?int $unitId = null): array
    {
        $months = self::months($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $byKind = [];
        if ($ids !== []) {
            $sql = "SELECT r.competence, m.kind, SUM(r.consumption) AS total FROM erp_meter_readings r JOIN erp_meters m ON m.id = r.meter_id
                WHERE r.condominium_id IN (" . implode(',', $ids) . ') AND r.competence BETWEEN :f AND :t';
            $params = ['f' => $months[0], 't' => end($months)];
            if ($unitId !== null) {
                $unit = $this->pdo->prepare('SELECT condominium_id FROM erp_units WHERE id = :id AND deleted_at IS NULL');
                $unit->execute(['id' => $unitId]);
                $condo = $unit->fetchColumn();
                if ($condo === false || !in_array((int)$condo, $ids, true)) {
                    throw new InvalidArgumentException('Unidade não encontrada no escopo.');
                }
                $sql .= ' AND m.unit_id = :u';
                $params['u'] = $unitId;
            }
            $stmt = $this->pdo->prepare($sql . ' GROUP BY r.competence, m.kind');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byKind[(string)$r['kind']][$r['competence']] = round((float)$r['total'], 3);
            }
        }
        $labels = ['agua' => 'Água', 'gas' => 'Gás', 'energia' => 'Energia'];
        $series = [];
        foreach ($labels as $kind => $label) {
            if (isset($byKind[$kind])) {
                $series[] = ['key' => $kind, 'label' => $label, 'data' => self::fill($months, $byKind[$kind])];
            }
        }
        return ['labels' => $months, 'unit' => 'consumption', 'unit_id' => $unitId, 'series' => $series];
    }

    public function cash(User $user, ?int $condominiumId, string $from, string $to): array
    {
        $months = self::months($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $data = ['receita' => [], 'despesa' => []];
        if ($ids !== []) {
            $stmt = $this->pdo->prepare("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS month, nature, SUM(amount_cents) AS total FROM erp_entries
                WHERE condominium_id IN (" . implode(',', $ids) . ") AND status = 'active' AND deleted_at IS NULL AND paid_on IS NOT NULL
                  AND paid_on BETWEEN :f AND LAST_DAY(:t) GROUP BY month, nature");
            $stmt->execute(['f' => $months[0] . '-01', 't' => end($months) . '-01']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $data[$r['nature']][$r['month']] = (int)$r['total'];
            }
        }
        return ['labels' => $months, 'unit' => 'cents', 'series' => [
            ['key' => 'received', 'label' => 'Recebido', 'data' => self::fill($months, $data['receita'])],
            ['key' => 'paid', 'label' => 'Pago', 'data' => self::fill($months, $data['despesa'])],
            ['key' => 'net', 'label' => 'Saldo do mês', 'data' => array_map(static fn (string $m): int => ($data['receita'][$m] ?? 0) - ($data['despesa'][$m] ?? 0), $months)],
        ]];
    }

    public function delinquency(User $user, ?int $condominiumId, string $from, string $to): array
    {
        $months = self::months($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $billed = [];
        $overdue = [];
        if ($ids !== []) {
            $stmt = $this->pdo->prepare("SELECT competence, SUM(amount_cents) AS billed,
                    SUM(CASE WHEN paid_on IS NULL AND due_on < CURDATE() THEN amount_cents ELSE 0 END) AS overdue
                FROM erp_entries WHERE condominium_id IN (" . implode(',', $ids) . ") AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL
                  AND unit_id IS NOT NULL AND competence BETWEEN :f AND :t GROUP BY competence");
            $stmt->execute(['f' => $months[0], 't' => end($months)]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $billed[$r['competence']] = (int)$r['billed'];
                $overdue[$r['competence']] = (int)$r['overdue'];
            }
        }
        return ['labels' => $months, 'unit' => 'cents', 'series' => [
            ['key' => 'billed', 'label' => 'Cobrado das unidades', 'data' => self::fill($months, $billed)],
            ['key' => 'overdue', 'label' => 'Vencido em aberto hoje', 'data' => self::fill($months, $overdue)],
            ['key' => 'rate', 'label' => 'Inadimplência (%)', 'unit' => 'percent', 'data' => array_map(static fn (string $m): ?float => self::rate($overdue[$m] ?? 0, $billed[$m] ?? 0), $months)],
        ]];
    }

    public function bankAccounts(User $user, ?int $condominiumId, string $from, string $to): array
    {
        $months = self::months($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $byKey = [];
        $labels = [];
        if ($ids !== []) {
            $in = implode(',', $ids);
            $params = ['f' => $months[0] . '-01', 't' => end($months) . '-01', 'f2' => $months[0] . '-01', 't2' => end($months) . '-01',
                'f3' => $months[0] . '-01', 't3' => end($months) . '-01'];
            $stmt = $this->pdo->prepare("SELECT b.id, b.name, x.month, SUM(x.amount) AS total FROM erp_bank_accounts b JOIN (
                    SELECT e.bank_account_id AS bank, DATE_FORMAT(e.paid_on, '%Y-%m') AS month, CASE WHEN e.nature = 'receita' THEN e.amount_cents ELSE -CAST(e.amount_cents AS SIGNED) END AS amount
                      FROM erp_entries e WHERE e.paid_on BETWEEN :f AND LAST_DAY(:t) AND e.status = 'active' AND e.deleted_at IS NULL AND e.bank_account_id IS NOT NULL
                        AND e.condominium_id IN ({$in})
                    UNION ALL
                    SELECT t.to_bank_account_id, DATE_FORMAT(t.transferred_on, '%Y-%m'), t.amount_cents FROM erp_bank_transfers t
                      WHERE t.status = 'active' AND t.condominium_id IN ({$in}) AND t.transferred_on BETWEEN :f2 AND LAST_DAY(:t2)
                    UNION ALL
                    SELECT t.from_bank_account_id, DATE_FORMAT(t.transferred_on, '%Y-%m'), -CAST(t.amount_cents AS SIGNED) FROM erp_bank_transfers t
                      WHERE t.status = 'active' AND t.condominium_id IN ({$in}) AND t.transferred_on BETWEEN :f3 AND LAST_DAY(:t3)
                ) x ON x.bank = b.id
                WHERE b.condominium_id IN ({$in}) AND b.deleted_at IS NULL GROUP BY b.id, b.name, x.month");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byKey['b' . $r['id']][$r['month']] = (int)$r['total'];
                $labels['b' . $r['id']] = (string)$r['name'];
            }
        }
        return ['labels' => $months, 'unit' => 'cents', 'series' => self::top($months, $byKey, $labels, self::TOP, true)];
    }

    public function commonAreas(User $user, ?int $condominiumId, string $from, string $to): array
    {
        $months = self::months($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $byKey = [];
        $labels = [];
        if ($ids !== []) {
            $stmt = $this->pdo->prepare("SELECT a.id, a.name, DATE_FORMAT(r.reserved_on, '%Y-%m') AS month, COUNT(*) AS total FROM erp_reservations r
                JOIN erp_common_areas a ON a.id = r.common_area_id
                WHERE r.condominium_id IN (" . implode(',', $ids) . ") AND r.deleted_at IS NULL AND r.status <> 'inactive'
                  AND r.reserved_on BETWEEN :f AND LAST_DAY(:t) GROUP BY a.id, a.name, month");
            $stmt->execute(['f' => $months[0] . '-01', 't' => end($months) . '-01']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byKey['a' . $r['id']][$r['month']] = (int)$r['total'];
                $labels['a' . $r['id']] = (string)$r['name'];
            }
        }
        return ['labels' => $months, 'unit' => 'count', 'series' => self::top($months, $byKey, $labels)];
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
