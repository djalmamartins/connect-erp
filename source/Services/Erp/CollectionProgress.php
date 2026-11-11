<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Acompanhamento da arrecadação (ADR 0199), somente leitura, para o síndico e a administradora: quanto da cobrança de uma
 * competência já entrou, em que ritmo os moradores pagam e se o mês está pior que os anteriores. Contas a receber
 * válidas (ativas, não excluídas) das unidades do condomínio (receita sem unidade, como rendimento, fica de fora), por competência, olhando a situação numa data (as_of, padrão hoje):
 *
 * - cobrado, recebido até a data, em aberto e vencido (em aberto com vencimento antes da data), e o % recebido;
 * - faixas de pagamento em relação ao vencimento (regra pura bucket): em dia (até o vencimento), 1–5, 6–15, 16–30, 31–60
 *   e mais de 60 dias depois, e em aberto; cada uma com valor, quantidade e % do cobrado;
 * - as mesmas faixas da competência anterior, e a diferença no % em dia;
 * - tendência dos últimos 6 meses (a competência e as 5 anteriores): % em dia e % recebido de cada uma até a data. Meses
 *   sem cobrança ficam de fora; com menos de 2 meses cobrados vem o aviso short_history.
 *
 * Só números agregados: nenhuma unidade nem morador. Um condomínio só, do escopo (fora = CondominiumAccessDenied, 404).
 */
final class CollectionProgress
{
    public const BUCKETS = ['on_time', 'late_1_5', 'late_6_15', 'late_16_30', 'late_31_60', 'late_over_60', 'open'];
    public const MONTHS = 6;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa de pagamento (regra pura): pela data do pagamento em relação ao vencimento; em aberto na data = open. */
    public static function bucket(string $dueOn, ?string $paidOn, string $asOf): string
    {
        if ($paidOn === null || $paidOn > $asOf) {
            return 'open';
        }
        $days = (int)(new \DateTimeImmutable($dueOn))->diff(new \DateTimeImmutable($paidOn))->format('%r%a');
        return match (true) {
            $days <= 0 => 'on_time',
            $days <= 5 => 'late_1_5',
            $days <= 15 => 'late_6_15',
            $days <= 30 => 'late_16_30',
            $days <= 60 => 'late_31_60',
            default => 'late_over_60',
        };
    }

    public function build(User $user, ?int $condominiumId, mixed $competence, mixed $asOf = null, ?string $today = null): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        if (!is_string($competence) || !RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Informe a competência (AAAA-MM).');
        }
        $date = $asOf ?? $today ?? date('Y-m-d');
        $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
        }
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar

        $month = new \DateTimeImmutable($competence . '-01');
        $competences = [];
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $competences[] = $month->modify("-{$i} months")->format('Y-m');
        }
        $marks = implode(',', array_fill(0, count($competences), '?'));
        $stmt = $this->pdo->prepare("SELECT competence, due_on, paid_on, amount_cents FROM erp_entries
            WHERE condominium_id = ? AND nature = 'receita' AND unit_id IS NOT NULL AND status = 'active' AND deleted_at IS NULL AND competence IN ({$marks})");
        $stmt->execute(array_merge([$condominiumId], $competences));
        $data = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (string)$r['competence'];
            $data[$c] ??= ['billed' => 0, 'count' => 0, 'overdue' => 0, 'buckets' => array_fill_keys(self::BUCKETS, ['cents' => 0, 'count' => 0])];
            $cents = (int)$r['amount_cents'];
            $bucket = self::bucket((string)$r['due_on'], $r['paid_on'] === null ? null : (string)$r['paid_on'], $date);
            $data[$c]['billed'] += $cents;
            $data[$c]['count']++;
            $data[$c]['buckets'][$bucket]['cents'] += $cents;
            $data[$c]['buckets'][$bucket]['count']++;
            if ($bucket === 'open' && (string)$r['due_on'] < $date) {
                $data[$c]['overdue'] += $cents;
            }
        }
        $previous = $month->modify('-1 month')->format('Y-m');
        $current = $this->summary($data[$competence] ?? null);
        $before = isset($data[$previous]) ? $this->summary($data[$previous]) : null;
        $trend = [];
        foreach ($competences as $c) {
            if (isset($data[$c])) {
                $s = $this->summary($data[$c]);
                $trend[] = ['competence' => $c, 'billed_cents' => $s['billed_cents'], 'on_time_percent' => $s['on_time_percent'], 'received_percent' => $s['received_percent']];
            }
        }
        $warnings = [];
        if (count($trend) < 2) {
            $warnings[] = ['code' => 'short_history', 'message' => sprintf('Só %d mês(es) com cobrança nos últimos %d: sem tendência.', count($trend), self::MONTHS)];
        }
        if ($current['billed_cents'] === 0) {
            $warnings[] = ['code' => 'no_billing', 'message' => "Nada cobrado na competência {$competence}."];
        }
        return ['condominium_id' => $condominiumId, 'competence' => $competence, 'as_of' => $date, 'previous_competence' => $previous] + $current + [
            'previous' => $before,
            'on_time_delta_points' => $before !== null && $current['on_time_percent'] !== null && $before['on_time_percent'] !== null
                ? round($current['on_time_percent'] - $before['on_time_percent'], 2) : null,
            'trend' => count($trend) < 2 ? [] : $trend,
            'warnings' => $warnings,
        ];
    }

    /** @param array{billed:int, count:int, overdue:int, buckets:array<string, array{cents:int, count:int}>}|null $d */
    private function summary(?array $d): array
    {
        $d ??= ['billed' => 0, 'count' => 0, 'overdue' => 0, 'buckets' => array_fill_keys(self::BUCKETS, ['cents' => 0, 'count' => 0])];
        $pct = static fn (int $part): ?float => $d['billed'] > 0 ? round($part * 100 / $d['billed'], 2) : null;
        $received = $d['billed'] - $d['buckets']['open']['cents'];
        $buckets = [];
        foreach ($d['buckets'] as $key => $b) {
            $buckets[$key] = $b + ['percent' => $pct($b['cents'])];
        }
        return ['billed_cents' => $d['billed'], 'billed_count' => $d['count'], 'received_cents' => $received, 'open_cents' => $d['buckets']['open']['cents'],
            'overdue_cents' => $d['overdue'], 'received_percent' => $pct($received), 'on_time_percent' => $pct($d['buckets']['on_time']['cents']), 'buckets' => $buckets];
    }
}
