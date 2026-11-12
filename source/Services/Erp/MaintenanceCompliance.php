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
 * Cumprimento do plano de manutenções num período (ADR 0248), somente leitura. O cadastro de manutenções (AP-110) guarda o
 * serviço, a data prevista, a periodicidade, a conclusão e o laudo. O resumo operacional (OperationsSummary) conta as
 * previstas e as concluídas do mês, o painel operacional conta as atrasadas e as da semana, e a conclusão em lote
 * (MaintenanceCompletion) dá baixa. Ninguém mede, porém, o cumprimento do plano no ano: quantas manutenções previstas
 * foram feitas no prazo, quantas com atraso e com quantos dias em média, quantas seguem atrasadas sem baixa, por
 * periodicidade, e quantas concluídas estão sem o laudo. A administradora usa isso na prestação de contas e com o
 * conselho (manutenção preventiva de elevador, bombas, extintores e para-raios).
 *
 * Reusa o que já existe: as periodicidades do cadastro (RegistryService::definition: maintenances), a regra das listas
 * (sem excluídas) e a regra de anexo do cadastro (laudo no Disco Virtual do mesmo condomínio, não removido).
 *
 * Regra pura classify() e summarize(), por manutenção com a data prevista no período [from, to] (padrão os últimos 12
 * meses até hoje; até 3 anos):
 * - cancelled: cancelada;
 * - done_on_time: concluída até a data prevista (ou sem data de conclusão);
 * - done_late: concluída depois da data prevista, somando os dias de atraso;
 * - overdue: agendada ou em execução com a data prevista anterior à data de referência (o fim do período ou hoje, o que
 *   vier antes);
 * - upcoming: agendada ou em execução com a data prevista a partir da data de referência.
 * Uma linha por periodicidade, sempre as do cadastro, com planned (todas menos as canceladas), as cinco contagens,
 * avg_delay_days (média dos dias de atraso das concluídas com atraso; null sem nenhuma), on_time_rate (concluídas no
 * prazo sobre as que venceram até a data de referência, em %; null sem nenhuma) e done_without_document; totals soma as
 * linhas e recalcula as médias.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * periodicidades, as contagens e as médias: nenhum serviço, fornecedor, descrição ou documento.
 */
final class MaintenanceCompliance
{
    public const MAX_ROWS = 20000;
    public const MAX_DAYS = 1096;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Situação de uma manutenção na data de referência (regra pura).
     *
     * @return array{0:string, 1:int} situação e dias de atraso (só em done_late)
     */
    public static function classify(string $status, string $plannedOn, ?string $doneOn, string $reference): array
    {
        $planned = substr($plannedOn, 0, 10);
        $done = substr((string)$doneOn, 0, 10);
        if ($status === 'inactive') {
            return ['cancelled', 0];
        }
        if ($status === 'done') {
            if ($done === '' || $done <= $planned) {
                return ['done_on_time', 0];
            }
            return ['done_late', (int)(new DateTimeImmutable($planned))->diff(new DateTimeImmutable($done))->days];
        }
        return [$planned < $reference ? 'overdue' : 'upcoming', 0];
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{frequency:string, status:string, planned_on:string, done_on:?string, has_document:bool}> $maintenances
     */
    public static function summarize(array $maintenances, string $reference): array
    {
        $frequencies = RegistryService::definition('maintenances')['fields']['frequency']['options'];
        $blank = ['planned' => 0, 'done_on_time' => 0, 'done_late' => 0, 'overdue' => 0, 'upcoming' => 0, 'cancelled' => 0, 'delay_days' => 0,
            'done_without_document' => 0];
        $rows = [];
        foreach ($frequencies as $frequency => $label) {
            $rows[$frequency] = ['frequency' => $frequency, 'frequency_label' => $label] + $blank;
        }
        foreach ($maintenances as $m) {
            $frequency = isset($rows[$m['frequency']]) ? $m['frequency'] : 'unica';
            [$situation, $delay] = self::classify($m['status'], $m['planned_on'], $m['done_on'], $reference);
            $rows[$frequency][$situation]++;
            $rows[$frequency]['delay_days'] += $delay;
            if ($situation !== 'cancelled') {
                $rows[$frequency]['planned']++;
            }
            if (in_array($situation, ['done_on_time', 'done_late'], true) && !$m['has_document']) {
                $rows[$frequency]['done_without_document']++;
            }
        }
        $totals = $blank;
        foreach ($rows as $row) {
            foreach (array_keys($blank) as $key) {
                $totals[$key] += $row[$key];
            }
        }
        $finish = static function (array $r): array {
            $due = $r['done_on_time'] + $r['done_late'] + $r['overdue'];
            $r['avg_delay_days'] = $r['done_late'] > 0 ? (int)round($r['delay_days'] / $r['done_late']) : null;
            $r['on_time_rate'] = $due > 0 ? (int)round(100 * $r['done_on_time'] / $due) : null;
            unset($r['delay_days']);
            return $r;
        };
        return ['rows' => array_values(array_map($finish, $rows)), 'totals' => $finish($totals)];
    }

    public function build(User $user, mixed $condominiumId, mixed $from = null, mixed $to = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $today = date('Y-m-d');
        $to = $to === null || $to === '' ? $today : $to;
        $toDate = is_string($to) ? DateTimeImmutable::createFromFormat('!Y-m-d', $to) : false;
        if ($toDate === false || $toDate->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Fim do período: AAAA-MM-DD.');
        }
        $from = $from === null || $from === '' ? $toDate->modify('-1 year +1 day')->format('Y-m-d') : $from;
        $fromDate = is_string($from) ? DateTimeImmutable::createFromFormat('!Y-m-d', $from) : false;
        if ($fromDate === false || $fromDate->format('Y-m-d') !== $from || $from > $to) {
            throw new InvalidArgumentException('Início do período: AAAA-MM-DD, até o fim.');
        }
        if ($fromDate->diff($toDate)->days >= self::MAX_DAYS) {
            throw new InvalidArgumentException('Período de até 3 anos.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT m.frequency, m.status, m.planned_on, m.done_on, d.id AS doc FROM erp_maintenances m
            LEFT JOIN erp_documents d ON d.id = m.document_id AND d.condominium_id = m.condominium_id AND d.deleted_at IS NULL
            WHERE m.condominium_id = ? AND m.deleted_at IS NULL AND m.planned_on BETWEEN ? AND ? ORDER BY m.id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d manutenções: use um período menor.', self::MAX_ROWS));
        }
        $reference = min($to, $today);
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to, 'reference_on' => $reference] + self::summarize(
            array_map(static fn (array $r): array => ['frequency' => (string)$r['frequency'], 'status' => (string)$r['status'],
                'planned_on' => substr((string)$r['planned_on'], 0, 10), 'done_on' => $r['done_on'] !== null ? substr((string)$r['done_on'], 0, 10) : null,
                'has_document' => $r['doc'] !== null], $rows),
            $reference);
    }
}
