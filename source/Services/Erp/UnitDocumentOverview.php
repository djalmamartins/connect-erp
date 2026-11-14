<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Documentos emitidos para as unidades do condomínio, por tipo e mês a mês (ADR 0292), somente leitura. Os documentos da
 * unidade (certidão de débitos, declaração de inadimplência, comprovante de quitação, carta de anuência e recibo; AP-123 a
 * AP-130 e AP-040, ADR 0044 e 0057) ficam gravados com o resultado (nada consta ou com débitos) e o código de verificação.
 * A lista mostra um por vez, e ninguém vê, de uma vez, quantos de cada tipo saíram no período, quantos foram cancelados,
 * quantos saíram com débito e o valor apontado, nem como isso se distribui pelos meses.
 *
 * Reusa o que já existe: os tipos e rótulos de UnitDocuments::ALL_KINDS (o teste confere com a enum da migration), as
 * situações issued e cancelled e o resultado clear ou debts gravados pela emissão, a permissão da lista
 * (erp.unit_documents.view) e a janela de meses do relatório de descontos (DiscountOverview::months, ADR 0290). Os meses
 * são os do relógio do banco, porque created_at foi gravado por ele.
 *
 * Regra pura summarize():
 * - rows: uma linha por tipo de ALL_KINDS, sempre todos, com issued (emitidos válidos), cancelled, clear e debts (que
 *   somam issued), debts_cents (o total apontado nos emitidos com débito) e units (unidades distintas com emitido no
 *   tipo); totals soma as linhas;
 * - by_month: os emitidos válidos por mês da janela, sempre todos, somando totals.issued;
 * - cancelled_ids: os cancelados, do mais recente para o mais antigo, até MAX_IDS, com truncated.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * tipos, meses, contagens, valores e ids de documentos: nenhum código de verificação, conteúdo, finalidade, unidade, motivo
 * nem quem emitiu.
 */
final class UnitDocumentOverview
{
    public const MAX_ROWS = 50000;
    public const MAX_IDS = 200;
    public const DEFAULT_MONTHS = 12;
    public const MAX_MONTHS = 36;

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
     * @param list<array{id:int, kind:string, unit_id:int, month:string, status:string, result:string, total_cents:int, created_at:string}> $documents
     * @param list<string> $months
     */
    public static function summarize(array $documents, array $months): array
    {
        $zero = ['issued' => 0, 'cancelled' => 0, 'clear' => 0, 'debts' => 0, 'debts_cents' => 0, 'units' => 0];
        $counts = array_fill_keys(array_keys(UnitDocuments::ALL_KINDS), $zero);
        $units = array_fill_keys(array_keys(UnitDocuments::ALL_KINDS), []);
        $byMonth = array_fill_keys($months, 0);
        $cancelled = [];
        $unknown = 0;
        foreach ($documents as $d) {
            if (!isset($counts[$d['kind']]) || !array_key_exists($d['month'], $byMonth) || !in_array($d['status'], ['issued', 'cancelled'], true)) {
                $unknown += isset($counts[$d['kind']]) ? 0 : 1;
                continue;   // tipo desconhecido ou fora da janela
            }
            if ($d['status'] === 'cancelled') {
                $counts[$d['kind']]['cancelled']++;
                $cancelled[] = [$d['created_at'], $d['id']];
                continue;
            }
            $counts[$d['kind']]['issued']++;
            $byMonth[$d['month']]++;
            $units[$d['kind']][$d['unit_id']] = true;
            if ($d['result'] === 'debts') {
                $counts[$d['kind']]['debts']++;
                $counts[$d['kind']]['debts_cents'] += $d['total_cents'];
            } else {
                $counts[$d['kind']]['clear']++;
            }
        }
        $rows = [];
        $totals = $zero;
        foreach (UnitDocuments::ALL_KINDS as $kind => $label) {
            $counts[$kind]['units'] = count($units[$kind]);
            $rows[] = ['kind' => $kind, 'kind_label' => $label] + $counts[$kind];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$kind][$key];
            }
        }
        rsort($cancelled);
        $ids = array_column($cancelled, 1);
        return ['months' => count($months), 'from' => $months[0] ?? null, 'to' => $months[count($months) - 1] ?? null, 'rows' => $rows, 'totals' => $totals,
            'by_month' => array_map(static fn (string $m, int $n): array => ['month' => $m, 'issued' => $n], array_keys($byMonth), array_values($byMonth)),
            'unknown' => $unknown, 'cancelled_ids' => array_slice($ids, 0, self::MAX_IDS), 'cancelled_truncated' => count($ids) > self::MAX_IDS];
    }

    public function build(User $user, mixed $condominiumId, mixed $months = null, ?string $today = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $id = $int($condominiumId);
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $count = self::DEFAULT_MONTHS;
        if ($months !== null && $months !== '') {
            $count = $int($months);
            if ($count === false || $count > self::MAX_MONTHS) {
                throw new InvalidArgumentException(sprintf('Meses: de 1 a %d.', self::MAX_MONTHS));
            }
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        // O dia do banco: created_at foi gravado por ele.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $window = DiscountOverview::months($today, (int)$count);
        // Só documentos de unidades do próprio condomínio.
        $stmt = $this->pdo->prepare('SELECT d.id, d.kind, d.unit_id, d.status, d.result, d.total_cents, d.created_at FROM erp_unit_documents d
            JOIN erp_units u ON u.id = d.unit_id AND u.condominium_id = d.condominium_id
            WHERE d.condominium_id = ? AND d.created_at >= ? ORDER BY d.id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $window[0] . '-01 00:00:00']);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($raw) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d documentos no período: escolha menos meses.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id] + self::summarize(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'],
            'unit_id' => (int)$r['unit_id'], 'month' => substr((string)$r['created_at'], 0, 7), 'status' => (string)$r['status'], 'result' => (string)$r['result'],
            'total_cents' => (int)$r['total_cents'], 'created_at' => (string)$r['created_at']], $raw), $window);
    }
}
