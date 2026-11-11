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
 * Cobertura de seguros do condomínio numa data (ADR 0244), somente leitura. O cadastro de seguros (AP-118) guarda a
 * vigência, o prêmio, a cobertura e a apólice em PDF; o painel operacional conta os vencidos e os a renovar em 30 dias e o
 * calendário de obrigações lista os fins de vigência. Ninguém responde, porém, se o condomínio está coberto hoje, até
 * quando a cobertura segue sem interrupção (somando a renovação já cadastrada), quanto se paga de prêmio e quanto está
 * coberto por faixa de vigência, nem quais apólices estão sem o PDF. A administradora usa isso na prestação de contas, na
 * assembleia e para não deixar o condomínio descoberto entre uma apólice e outra (o seguro é obrigatório pelo Código
 * Civil, art. 1.346).
 *
 * Reusa o que já existe: a regra das listas do cadastro (só vigentes, sem excluídos) e a mesma regra de anexo do cadastro
 * (o PDF vale quando o documento existe no Disco Virtual do mesmo condomínio e não foi removido).
 *
 * Regra pura bucket(), coveredUntil() e summarize(), por apólice vigente no cadastro:
 * - expired: o fim da vigência é anterior à data on;
 * - renew_0_30, renew_31_90, in_force_over_90: em vigor, com o fim em tantos dias (0 = termina na data on);
 * - not_started: a vigência começa depois de on (a renovação já cadastrada).
 * Uma linha por faixa, sempre as cinco, com policies, premium_cents, coverage_cents e without_pdf; totals soma as linhas.
 * covered diz se há apólice em vigor em on; covered_until é o último dia coberto sem interrupção a partir de on, emendando
 * as apólices que começam até o dia seguinte ao fim da anterior (null = descoberto em on); cancelled conta as canceladas.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * faixas, as contagens, as somas e as datas da cobertura: nenhuma seguradora, apólice, corretora ou observação.
 */
final class InsuranceCoverage
{
    public const MAX_ROWS = 10000;
    public const BUCKETS = [
        'expired' => 'Vencida e ainda vigente no cadastro',
        'renew_0_30' => 'Em vigor, termina em até 30 dias',
        'renew_31_90' => 'Em vigor, termina em 31 a 90 dias',
        'in_force_over_90' => 'Em vigor, termina depois de 90 dias',
        'not_started' => 'Começa depois da data (renovação)',
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa da apólice pela vigência (regra pura). */
    public static function bucket(string $startsOn, string $endsOn, string $on): string
    {
        $starts = substr($startsOn, 0, 10);
        $ends = substr($endsOn, 0, 10);
        if ($ends < $on) {
            return 'expired';
        }
        if ($starts > $on) {
            return 'not_started';
        }
        $days = (int)(new DateTimeImmutable($on))->diff(new DateTimeImmutable($ends))->days;
        return match (true) {
            $days <= 30 => 'renew_0_30',
            $days <= 90 => 'renew_31_90',
            default => 'in_force_over_90',
        };
    }

    /**
     * Último dia coberto sem interrupção a partir de on (regra pura): emenda as apólices que começam até o dia seguinte ao
     * fim da anterior. null = nenhuma apólice em vigor em on.
     *
     * @param list<array{starts_on:string, ends_on:string}> $policies
     */
    public static function coveredUntil(array $policies, string $on): ?string
    {
        $reach = $on;
        $until = null;
        while (true) {
            $best = null;
            foreach ($policies as $p) {
                $starts = substr($p['starts_on'], 0, 10);
                $ends = substr($p['ends_on'], 0, 10);
                if ($starts <= $reach && $ends >= $reach && ($best === null || $ends > $best)) {
                    $best = $ends;
                }
            }
            if ($best === null) {
                return $until;
            }
            $until = $best;
            $reach = (new DateTimeImmutable($best))->modify('+1 day')->format('Y-m-d');
        }
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{starts_on:string, ends_on:string, premium_cents:int, coverage_cents:int, has_pdf:bool}> $policies vigentes no cadastro
     */
    public static function summarize(array $policies, string $on, int $cancelled = 0): array
    {
        $rows = [];
        foreach (self::BUCKETS as $bucket => $label) {
            $rows[$bucket] = ['bucket' => $bucket, 'bucket_label' => $label, 'policies' => 0, 'premium_cents' => 0, 'coverage_cents' => 0, 'without_pdf' => 0];
        }
        foreach ($policies as $p) {
            $bucket = self::bucket($p['starts_on'], $p['ends_on'], $on);
            $rows[$bucket]['policies']++;
            $rows[$bucket]['premium_cents'] += $p['premium_cents'];
            $rows[$bucket]['coverage_cents'] += $p['coverage_cents'];
            if (!$p['has_pdf']) {
                $rows[$bucket]['without_pdf']++;
            }
        }
        $totals = ['policies' => 0, 'premium_cents' => 0, 'coverage_cents' => 0, 'without_pdf' => 0];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }
        $inForce = $rows['renew_0_30']['policies'] + $rows['renew_31_90']['policies'] + $rows['in_force_over_90']['policies'];
        return ['rows' => array_values($rows), 'totals' => $totals, 'covered' => $inForce > 0,
            'covered_until' => self::coveredUntil($policies, $on), 'cancelled' => $cancelled];
    }

    public function build(User $user, mixed $condominiumId, mixed $on = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $on = $on === null || $on === '' ? date('Y-m-d') : $on;
        $date = is_string($on) ? DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
        if ($date === false || $date->format('Y-m-d') !== $on) {
            throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare("SELECT i.starts_on, i.ends_on, i.premium_cents, i.coverage_cents, d.id AS pdf FROM erp_insurances i
            LEFT JOIN erp_documents d ON d.id = i.document_id AND d.condominium_id = i.condominium_id AND d.deleted_at IS NULL
            WHERE i.condominium_id = ? AND i.deleted_at IS NULL AND i.status = 'active' ORDER BY i.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d apólices: fale com o suporte.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM erp_insurances WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'inactive'");
        $stmt->execute([(int)$id]);
        $cancelled = (int)$stmt->fetchColumn();
        return ['condominium_id' => (int)$id, 'on' => $on] + self::summarize(
            array_map(static fn (array $r): array => ['starts_on' => substr((string)$r['starts_on'], 0, 10), 'ends_on' => substr((string)$r['ends_on'], 0, 10),
                'premium_cents' => (int)$r['premium_cents'], 'coverage_cents' => (int)$r['coverage_cents'], 'has_pdf' => $r['pdf'] !== null], $rows),
            $on, $cancelled);
    }
}
