<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Resumo operacional do mês para o síndico (ADR 0210), somente leitura: o que aconteceu no condomínio no mês (ocorrências
 * abertas, manutenções previstas e feitas, reservas confirmadas por área) e o que está pendente hoje (ocorrências abertas
 * por idade e prioridade, manutenções atrasadas, as dos próximos 30 dias, reservas aguardando aprovação). Hoje isso fica
 * espalhado em três telas e a administradora monta o resumo à mão para a reunião ou o relatório mensal.
 *
 * - Mês (competence): contagens pelas datas do próprio registro (occurred_on, planned_on, done_on, reserved_on).
 * - Hoje (today): a situação atual, porque o cadastro guarda a situação de agora e não o histórico; por isso o
 *   pendente é "hoje", não "no fim do mês".
 * - Idade das ocorrências abertas pela regra pura ageBucket (0-7, 8-30, 31+ dias desde a data da ocorrência).
 * - Manutenções atrasadas listadas pelo serviço (até MAX_ITEMS, com truncated), mais atrasadas primeiro.
 *
 * Só contagens, serviços, nomes de área e valores de taxa: nenhum assunto ou descrição de ocorrência, nenhum morador,
 * nenhuma unidade. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Não grava nem envia.
 */
final class OperationsSummary
{
    public const MAX_ITEMS = 50;
    public const BUCKETS = ['0-7', '8-30', '31+'];
    public const PRIORITIES = ['alta', 'media', 'baixa'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa de idade de uma ocorrência aberta (regra pura). */
    public static function ageBucket(int $days): string
    {
        return $days <= 7 ? '0-7' : ($days <= 30 ? '8-30' : '31+');
    }

    /** Dias entre duas datas AAAA-MM-DD (regra pura); negativo se $to vem antes. */
    public static function days(string $from, string $to): int
    {
        $diff = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to));
        return $diff->invert === 1 ? -(int)$diff->days : (int)$diff->days;
    }

    public function build(User $user, mixed $condominiumId, mixed $competence, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        [$month, $from, $to] = Accountability::month($competence);   // 422 com mês inválido
        $this->scope->assertAccess($user, (int)$id);                  // 404 antes de consultar
        $c = (int)$id;
        $soon = (new \DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');

        $occurrences = ['opened_in_month' => ['total' => 0, 'by_priority' => array_fill_keys(self::PRIORITIES, 0)],
            'open_today' => ['total' => 0, 'by_priority' => array_fill_keys(self::PRIORITIES, 0), 'by_age' => array_fill_keys(self::BUCKETS, 0), 'running' => 0]];
        foreach ($this->rows("SELECT priority, COUNT(*) AS n FROM erp_occurrences WHERE condominium_id = ? AND deleted_at IS NULL AND occurred_on BETWEEN ? AND ?
            GROUP BY priority", [$c, $from, $to]) as $r) {
            $occurrences['opened_in_month']['by_priority'][(string)$r['priority']] = (int)$r['n'];
            $occurrences['opened_in_month']['total'] += (int)$r['n'];
        }
        foreach ($this->rows("SELECT priority, occurred_on, status FROM erp_occurrences WHERE condominium_id = ? AND deleted_at IS NULL AND status IN ('active', 'running')",
            [$c]) as $r) {
            $occurrences['open_today']['total']++;
            $occurrences['open_today']['by_priority'][(string)$r['priority']]++;
            $occurrences['open_today']['by_age'][self::ageBucket(max(0, self::days(substr((string)$r['occurred_on'], 0, 10), $today)))]++;
            $occurrences['open_today']['running'] += $r['status'] === 'running' ? 1 : 0;
        }

        $planned = $this->rows("SELECT COUNT(*) AS n FROM erp_maintenances WHERE condominium_id = ? AND deleted_at IS NULL AND status <> 'inactive'
            AND planned_on BETWEEN ? AND ?", [$c, $from, $to]);
        $done = $this->rows("SELECT COUNT(*) AS n FROM erp_maintenances WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'done'
            AND done_on BETWEEN ? AND ?", [$c, $from, $to]);
        $next = $this->rows("SELECT COUNT(*) AS n FROM erp_maintenances WHERE condominium_id = ? AND deleted_at IS NULL AND status IN ('active', 'running')
            AND planned_on BETWEEN ? AND ?", [$c, $today, $soon]);
        $overdue = $this->rows("SELECT service, planned_on, frequency FROM erp_maintenances WHERE condominium_id = ? AND deleted_at IS NULL
            AND status IN ('active', 'running') AND planned_on < ? ORDER BY planned_on, id LIMIT " . (self::MAX_ITEMS + 1), [$c, $today]);
        $overdueTotal = $this->rows("SELECT COUNT(*) AS n FROM erp_maintenances WHERE condominium_id = ? AND deleted_at IS NULL
            AND status IN ('active', 'running') AND planned_on < ?", [$c, $today]);
        $maintenances = ['planned_in_month' => (int)$planned[0]['n'], 'done_in_month' => (int)$done[0]['n'], 'next_30_days' => (int)$next[0]['n'],
            'overdue_today' => ['total' => (int)$overdueTotal[0]['n'], 'truncated' => count($overdue) > self::MAX_ITEMS,
                'items' => array_map(static fn (array $r): array => ['service' => (string)$r['service'], 'frequency' => (string)$r['frequency'],
                    'planned_on' => substr((string)$r['planned_on'], 0, 10), 'days_late' => self::days(substr((string)$r['planned_on'], 0, 10), $today)],
                    array_slice($overdue, 0, self::MAX_ITEMS))]];

        $byArea = $this->rows("SELECT a.name, COUNT(*) AS n, COALESCE(SUM(r.fee_cents), 0) AS fees FROM erp_reservations r JOIN erp_common_areas a ON a.id = r.common_area_id
            WHERE r.condominium_id = ? AND r.deleted_at IS NULL AND r.status = 'confirmed' AND r.reserved_on BETWEEN ? AND ? GROUP BY a.id, a.name ORDER BY n DESC, a.name",
            [$c, $from, $to]);
        $pending = $this->rows("SELECT COUNT(*) AS n FROM erp_reservations WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND reserved_on >= ?",
            [$c, $today]);
        $reservations = ['confirmed_in_month' => array_sum(array_map(static fn (array $r): int => (int)$r['n'], $byArea)),
            'fees_in_month_cents' => array_sum(array_map(static fn (array $r): int => (int)$r['fees'], $byArea)),
            'by_area' => array_map(static fn (array $r): array => ['area' => (string)$r['name'], 'confirmed' => (int)$r['n'], 'fees_cents' => (int)$r['fees']], $byArea),
            'pending_approval_today' => (int)$pending[0]['n']];

        return ['condominium_id' => $c, 'competence' => $month, 'from' => $from, 'to' => $to, 'today' => $today,
            'occurrences' => $occurrences, 'maintenances' => $maintenances, 'reservations' => $reservations];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
