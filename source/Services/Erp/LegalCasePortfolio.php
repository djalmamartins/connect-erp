<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Carteira da cobrança judicial do condomínio (ADR 0250), somente leitura. A cobrança (Collections)
 * abre o processo de uma unidade com as contas em atraso, registra os andamentos e lista os processos um a um; a mesa de
 * cobrança mostra o processo de cada inadimplente. Ninguém vê, porém, a carteira: quantos processos há em cada fase, quanto
 * foi cobrado, quanto das contas cobradas já foi pago e quanto segue em aberto, há quanto tempo os processos correm, quais
 * estão parados sem andamento e quantos estão sem advogado. A administradora usa isso na prestação de contas e na
 * conversa com o escritório.
 *
 * Reusa o que já existe: as fases de Collections::CASE_STATUSES, o valor cobrado gravado na abertura (claimed_cents) e,
 * para cada conta ligada ao processo, a mesma regra de "ainda em aberto" do detalhe do processo (Collections::findCase:
 * conta ativa sem pagamento, pelo valor atual).
 *
 * Regra pura summarize(), por processo do condomínio, na data de hoje:
 * - uma linha por fase, sempre as cinco, com cases, claimed_cents, recovered_cents (contas cobradas já pagas, pelo valor
 *   cobrado), still_open_cents (contas ainda em aberto, pelo valor atual), stale (processo não encerrado sem andamento
 *   há mais de stale_days dias; padrão 90, de 30 a 365), without_lawyer e avg_age_days (média dos dias desde a abertura
 *   até hoje ou até o encerramento; null sem processos);
 * - totals soma as linhas e recalcula a média; opened_12m e closed_12m contam os abertos e os encerrados nos últimos 12
 *   meses.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * fases, as contagens, as somas e as médias: nenhuma unidade, devedor, advogado, número de processo, vara ou andamento.
 */
final class LegalCasePortfolio
{
    public const MAX_CASES = 20000;
    public const DEFAULT_STALE_DAYS = 90;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{status:string, opened_on:string, closed_on:?string, claimed_cents:int, recovered_cents:int, still_open_cents:int,
     *     has_lawyer:bool, last_event_on:?string}> $cases
     */
    public static function summarize(array $cases, string $today, int $staleDays = self::DEFAULT_STALE_DAYS): array
    {
        $blank = ['cases' => 0, 'claimed_cents' => 0, 'recovered_cents' => 0, 'still_open_cents' => 0, 'stale' => 0, 'without_lawyer' => 0, 'age_days' => 0];
        $rows = [];
        foreach (Collections::CASE_STATUSES as $status => $label) {
            $rows[$status] = ['status' => $status, 'status_label' => $label] + $blank;
        }
        $staleBefore = (new DateTimeImmutable($today))->modify("-{$staleDays} days")->format('Y-m-d');
        $yearAgo = (new DateTimeImmutable($today))->modify('-1 year')->format('Y-m-d');
        $opened12 = 0;
        $closed12 = 0;
        $now = new DateTimeImmutable($today);
        foreach ($cases as $c) {
            $status = isset($rows[$c['status']]) ? $c['status'] : 'preparing';
            $opened = substr($c['opened_on'], 0, 10);
            $closed = $c['closed_on'] !== null ? substr($c['closed_on'], 0, 10) : null;
            $end = $status === 'closed' && $closed !== null ? new DateTimeImmutable($closed) : $now;
            $rows[$status]['cases']++;
            $rows[$status]['claimed_cents'] += $c['claimed_cents'];
            $rows[$status]['recovered_cents'] += $c['recovered_cents'];
            $rows[$status]['still_open_cents'] += $c['still_open_cents'];
            $rows[$status]['age_days'] += max(0, (int)(new DateTimeImmutable($opened))->diff($end)->format('%r%a'));
            if (!$c['has_lawyer']) {
                $rows[$status]['without_lawyer']++;
            }
            $last = $c['last_event_on'] !== null ? substr($c['last_event_on'], 0, 10) : null;
            if ($status !== 'closed' && ($last ?? $opened) < $staleBefore) {
                $rows[$status]['stale']++;
            }
            if ($opened > $yearAgo && $opened <= $today) {
                $opened12++;
            }
            if ($status === 'closed' && $closed !== null && $closed > $yearAgo && $closed <= $today) {
                $closed12++;
            }
        }
        $totals = $blank;
        foreach ($rows as $row) {
            foreach (array_keys($blank) as $key) {
                $totals[$key] += $row[$key];
            }
        }
        $finish = static function (array $r): array {
            $r['avg_age_days'] = $r['cases'] > 0 ? (int)round($r['age_days'] / $r['cases']) : null;
            unset($r['age_days']);
            return $r;
        };
        return ['rows' => array_values(array_map($finish, $rows)), 'totals' => $finish($totals), 'opened_12m' => $opened12, 'closed_12m' => $closed12];
    }

    public function build(User $user, mixed $condominiumId, mixed $staleDays = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $staleDays = $staleDays === null || $staleDays === '' ? self::DEFAULT_STALE_DAYS : $staleDays;
        $staleDays = is_int($staleDays) || is_string($staleDays) ? filter_var($staleDays, FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 365]]) : false;
        if ($staleDays === false) {
            throw new InvalidArgumentException('Dias sem andamento: de 30 a 365.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT k.id, k.status, k.opened_on, k.closed_on, k.claimed_cents, k.lawyer_id,
                (SELECT MAX(ev.happened_on) FROM erp_legal_case_events ev WHERE ev.case_id = k.id) AS last_event_on
            FROM erp_legal_cases k WHERE k.condominium_id = ? ORDER BY k.id LIMIT ' . (self::MAX_CASES + 1));
        $stmt->execute([(int)$id]);
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($cases) > self::MAX_CASES) {
            throw new InvalidArgumentException(sprintf('Mais de %d processos: fale com o suporte.', self::MAX_CASES));
        }
        // Contas ligadas: pagas (pelo valor cobrado) e ainda em aberto (conta ativa sem pagamento, pelo valor atual, como no detalhe).
        $stmt = $this->pdo->prepare("SELECT le.case_id,
                SUM(CASE WHEN e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on IS NOT NULL THEN le.amount_cents ELSE 0 END) AS recovered,
                SUM(CASE WHEN e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on IS NULL THEN e.amount_cents ELSE 0 END) AS still_open
            FROM erp_legal_case_entries le JOIN erp_legal_cases k ON k.id = le.case_id JOIN erp_entries e ON e.id = le.entry_id
            WHERE k.condominium_id = ? GROUP BY le.case_id");
        $stmt->execute([(int)$id]);
        $sums = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $sums[(int)$s['case_id']] = [(int)$s['recovered'], (int)$s['still_open']];
        }
        $today = date('Y-m-d');
        return ['condominium_id' => (int)$id, 'on' => $today, 'stale_days' => (int)$staleDays] + self::summarize(
            array_map(static fn (array $c): array => ['status' => (string)$c['status'], 'opened_on' => substr((string)$c['opened_on'], 0, 10),
                'closed_on' => $c['closed_on'] !== null ? substr((string)$c['closed_on'], 0, 10) : null, 'claimed_cents' => (int)$c['claimed_cents'],
                'recovered_cents' => $sums[(int)$c['id']][0] ?? 0, 'still_open_cents' => $sums[(int)$c['id']][1] ?? 0, 'has_lawyer' => $c['lawyer_id'] !== null,
                'last_event_on' => $c['last_event_on'] !== null ? substr((string)$c['last_event_on'], 0, 10) : null], $cases),
            $today, (int)$staleDays);
    }
}
