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
 * Idade das ocorrências em aberto do condomínio por prioridade (ADR 0256), somente leitura. O relatório de ocorrências
 * (RegistryReports::occurrences) lista as ocorrências de um período, uma por uma; o resumo do síndico conta as abertas.
 * Ninguém responde, porém, há quanto tempo as abertas esperam: quantas de prioridade alta estão abertas há mais de 30 ou
 * 90 dias, quantas dependem de um chamado no Help Desk e quantas, sem chamado, já podem ser encerradas em lote (ADR 0255).
 *
 * Reusa o que já existe: as prioridades do cadastro (RegistryService::definition('occurrences')) e a regra das listas
 * (sem excluídas).
 *
 * Regra pura summarize(), uma linha por prioridade do cadastro, sempre todas, com as ocorrências até a data on:
 * - open e running: abertas e em andamento;
 * - age_0_30, age_31_90 e age_over_90: as abertas e em andamento pelos dias desde a data da ocorrência (somam
 *   open + running);
 * - with_ticket: das abertas e em andamento, as com chamado no Help Desk;
 * - stale_without_ticket: das abertas e em andamento, as sem chamado há mais de 90 dias (o padrão do ADR 0255);
 * - done: resolvidas.
 * Prioridade desconhecida conta como "Média". totals soma as linhas. Fora das linhas: oldest_open_on (a data da
 * ocorrência aberta mais antiga; null sem nenhuma).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * prioridades, as contagens e a data mais antiga: nenhum assunto, descrição, unidade, documento ou chamado.
 */
final class OccurrenceAging
{
    public const MAX_ROWS = 50000;
    public const FALLBACK = 'media';
    public const STALE_DAYS = 90;

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
     * @param list<array{priority:string, status:string, occurred_on:string, has_ticket:bool}> $occurrences até a data on
     */
    public static function summarize(array $occurrences, string $on): array
    {
        $keys = ['open', 'running', 'age_0_30', 'age_31_90', 'age_over_90', 'with_ticket', 'stale_without_ticket', 'done'];
        $rows = [];
        foreach (RegistryService::definition('occurrences')['fields']['priority']['options'] as $priority => $label) {
            $rows[$priority] = ['priority' => $priority, 'priority_label' => $label] + array_fill_keys($keys, 0);
        }
        $day = new DateTimeImmutable($on);
        $oldest = null;
        foreach ($occurrences as $o) {
            $priority = isset($rows[$o['priority']]) ? $o['priority'] : self::FALLBACK;
            if ($o['status'] === 'done') {
                $rows[$priority]['done']++;
                continue;
            }
            $rows[$priority][$o['status'] === 'running' ? 'running' : 'open']++;
            $date = substr($o['occurred_on'], 0, 10);
            $days = $date >= $on ? 0 : (int)(new DateTimeImmutable($date))->diff($day)->days;
            $rows[$priority][match (true) {
                $days <= 30 => 'age_0_30',
                $days <= 90 => 'age_31_90',
                default => 'age_over_90',
            }]++;
            if ($o['has_ticket']) {
                $rows[$priority]['with_ticket']++;
            } elseif ($days > self::STALE_DAYS) {
                $rows[$priority]['stale_without_ticket']++;
            }
            if ($oldest === null || $date < $oldest) {
                $oldest = $date;
            }
        }
        $totals = array_fill_keys($keys, 0);
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $totals[$key] += $row[$key];
            }
        }
        return ['rows' => array_values($rows), 'totals' => $totals, 'oldest_open_on' => $oldest];
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

        $stmt = $this->pdo->prepare('SELECT priority, status, occurred_on, CASE WHEN ticket_id IS NULL THEN 0 ELSE 1 END AS has_ticket
            FROM erp_occurrences WHERE condominium_id = ? AND deleted_at IS NULL AND occurred_on <= ? ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $on]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d ocorrências: fale com o suporte.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id, 'on' => $on] + self::summarize(array_map(static fn (array $r): array => ['priority' => (string)$r['priority'],
            'status' => (string)$r['status'], 'occurred_on' => substr((string)$r['occurred_on'], 0, 10), 'has_ticket' => (int)$r['has_ticket'] === 1], $rows), $on);
    }
}
