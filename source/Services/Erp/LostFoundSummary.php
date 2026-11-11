<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Resumo dos achados e perdidos do condomínio (ADR 0232), somente leitura. O cadastro (AP-134) lista item por item, com
 * quem achou e a quem foi devolvido; nenhum relatório resume a guarda. O síndico e a administradora querem saber quantos
 * itens foram achados no período, quantos voltaram ao dono e em quanto tempo, quantos foram descartados e há quanto tempo
 * os guardados estão na portaria, para decidir doação ou descarte conforme o regimento.
 *
 * Reusa o que já existe: as situações do cadastro (RegistryService::definition('lost_found'): Guardado, Devolvido,
 * Descartado) e a regra das listas (sem os excluídos).
 *
 * Regra pura summarize():
 * - rows: uma linha por situação do cadastro (sempre as três), com os itens achados no período (found_on de from a to);
 *   totals.items = soma das linhas;
 * - returns: dos devolvidos do período, dated (com data de devolução não anterior à de achado), days_total, avg_days
 *   (total ÷ dated, 1 casa) e max_days;
 * - custody: os guardados hoje que foram achados até to (de qualquer período), pela idade em to: até 30 dias, 31 a 90,
 *   91 a 180 e mais de 180; total = soma das faixas.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * situações e as contagens: nenhum item, local, quem achou, a quem foi devolvido ou observação.
 */
final class LostFoundSummary
{
    public const MAX_DAYS = 366;
    public const MAX_ROWS = 50000;
    public const AGES = ['ate_30' => 30, 'de_31_a_90' => 90, 'de_91_a_180' => 180, 'mais_de_180' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Situações do cadastro. @return array<string, string> */
    public static function statuses(): array
    {
        return RegistryService::definition('lost_found')['status'];
    }

    /** Faixa de idade de um item guardado. */
    public static function age(int $days): string
    {
        foreach (self::AGES as $name => $limit) {
            if ($limit !== null && $days <= $limit) {
                return $name;
            }
        }
        return 'mais_de_180';
    }

    private static function days(string $from, string $to): int
    {
        return (int)(new DateTimeImmutable(substr($from, 0, 10)))->diff(new DateTimeImmutable(substr($to, 0, 10)))->format('%r%a');
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{status:string, found_on:string, returned_on:?string}> $found itens achados no período
     * @param list<array{found_on:string}> $kept itens guardados hoje, achados até $to
     * @return array{rows:list<array>, totals:array<string, int>, returns:array<string, mixed>, custody:array<string, int>}
     */
    public static function summarize(array $found, array $kept, string $to): array
    {
        $rows = [];
        foreach (self::statuses() as $status => $label) {
            $rows[$status] = ['status' => $status, 'status_label' => $label, 'items' => 0];
        }
        $returns = ['count' => 0, 'dated' => 0, 'days_total' => 0, 'avg_days' => null, 'max_days' => null];
        foreach ($found as $item) {
            $status = isset($rows[$item['status']]) ? $item['status'] : 'active';
            $rows[$status]['items']++;
            if ($status !== 'returned') {
                continue;
            }
            $returns['count']++;
            $back = $item['returned_on'] !== null && $item['returned_on'] !== '' ? $item['returned_on'] : null;
            if ($back !== null && substr($back, 0, 10) >= substr($item['found_on'], 0, 10)) {
                $days = self::days($item['found_on'], $back);
                $returns['dated']++;
                $returns['days_total'] += $days;
                $returns['max_days'] = max($returns['max_days'] ?? 0, $days);
            }
        }
        $returns['avg_days'] = $returns['dated'] > 0 ? round($returns['days_total'] / $returns['dated'], 1) : null;
        $custody = array_fill_keys(array_keys(self::AGES), 0);
        foreach ($kept as $item) {
            $custody[self::age(max(0, self::days($item['found_on'], $to)))]++;
        }
        $custody['total'] = array_sum($custody);
        return ['rows' => array_values($rows), 'totals' => ['items' => array_sum(array_column($rows, 'items'))], 'returns' => $returns, 'custody' => $custody];
    }

    public function build(User $user, mixed $condominiumId, mixed $from = null, mixed $to = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $to = $to === null || $to === '' ? date('Y-m-d') : $to;
        $from = $from === null || $from === '' ? (is_string($to) ? substr($to, 0, 8) . '01' : $to) : $from;
        $start = is_string($from) ? DateTimeImmutable::createFromFormat('!Y-m-d', $from) : false;
        $finish = is_string($to) ? DateTimeImmutable::createFromFormat('!Y-m-d', $to) : false;
        if ($start === false || $finish === false || $start->format('Y-m-d') !== $from || $finish->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Período: AAAA-MM-DD.');
        }
        if ($from > $to || (int)$start->diff($finish)->days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('Período inválido: início até o fim, com até %d dias.', self::MAX_DAYS));
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT status, found_on, returned_on FROM erp_lost_found WHERE condominium_id = ? AND deleted_at IS NULL
            AND found_on BETWEEN ? AND ? ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare("SELECT found_on FROM erp_lost_found WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND found_on <= ?
            ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $to]);
        $kept = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($found) > self::MAX_ROWS || count($kept) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d itens: escolha um período menor.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to] + self::summarize(
            array_map(static fn (array $f): array => ['status' => (string)$f['status'], 'found_on' => substr((string)$f['found_on'], 0, 10),
                'returned_on' => $f['returned_on'] !== null ? substr((string)$f['returned_on'], 0, 10) : null], $found),
            array_map(static fn (array $k): array => ['found_on' => substr((string)$k['found_on'], 0, 10)], $kept),
            $to);
    }
}
