<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketService;

/**
 * Tempo de atendimento dos chamados do condomínio (ADR 0226), somente leitura. O Help Desk resume a fila inteira para o
 * Studio (TicketService::queueSummary) e o ERP lista os chamados um a um (TicketService::listFor); o síndico e o conselho
 * querem saber, por condomínio e período, quantos chamados abriram, quantos foram resolvidos no prazo e quanto tempo
 * levaram, para a prestação de contas do serviço da administradora.
 *
 * Reusa o que já existe: o prazo de cada prioridade (TicketService::slaHours), os rótulos de área e prioridade
 * (TicketService::AREA_LABELS e PRIORITY_LABELS) e as situações do chamado (resolvido e fechado = concluído).
 *
 * Regra pura summarize(), por área e prioridade (a "categoria" livre do chamado fica de fora: é texto digitado):
 * - created: chamados abertos no período; done: resolvidos ou fechados; open: os demais;
 * - timed: concluídos com data de resolução; on_time e late: resolvidos até ou depois do prazo (due_at);
 * - overdue_open: em aberto com o prazo vencido no fim do período;
 * - resolution_minutes_total e avg_resolution_hours (total ÷ timed, 1 casa); on_time_pct (on_time ÷ timed, 1 casa).
 * Os totais do topo são a soma das linhas; a média e o percentual do topo saem das somas.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * grupos e as somas: nenhum protocolo, assunto, mensagem, solicitante, unidade ou responsável.
 */
final class TicketServiceTimes
{
    public const MAX_DAYS = 366;
    public const MAX_TICKETS = 20000;
    public const DONE = ['resolved', 'closed'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo por área e prioridade (regra pura). $end = fim do período (AAAA-MM-DD HH:MM:SS) para o vencido em aberto.
     *
     * @param list<array{area:string, priority:string, status:string, created_at:string, due_at:?string, resolved_at:?string}> $tickets
     * @return array{rows:list<array>, totals:array<string, mixed>}
     */
    public static function summarize(array $tickets, string $end): array
    {
        $rows = [];
        foreach ($tickets as $t) {
            $area = in_array($t['area'], TicketService::AREAS, true) ? $t['area'] : 'general';
            $priority = in_array($t['priority'], TicketService::PRIORITIES, true) ? $t['priority'] : 'medium';
            $key = $area . '|' . $priority;
            $rows[$key] ??= ['area' => $area, 'area_label' => TicketService::AREA_LABELS[$area], 'priority' => $priority,
                'priority_label' => TicketService::PRIORITY_LABELS[$priority], 'sla_hours' => TicketService::slaHours($priority),
                'created' => 0, 'done' => 0, 'open' => 0, 'timed' => 0, 'on_time' => 0, 'late' => 0, 'overdue_open' => 0,
                'resolution_minutes_total' => 0, 'avg_resolution_hours' => null, 'on_time_pct' => null];
            $row = &$rows[$key];
            $row['created']++;
            $done = in_array($t['status'], self::DONE, true);
            $due = $t['due_at'] !== null && $t['due_at'] !== '' ? (string)$t['due_at'] : null;
            if ($done) {
                $row['done']++;
                $resolved = $t['resolved_at'] !== null && $t['resolved_at'] !== '' ? (string)$t['resolved_at'] : null;
                if ($resolved !== null) {
                    $row['timed']++;
                    $row['resolution_minutes_total'] += max(0, intdiv(strtotime($resolved) - strtotime((string)$t['created_at']), 60));
                    if ($due !== null && $resolved <= $due) {
                        $row['on_time']++;
                    } else {
                        $row['late']++;
                    }
                }
            } else {
                $row['open']++;
                if ($due !== null && $due < $end) {
                    $row['overdue_open']++;
                }
            }
            unset($row);
        }
        $fields = ['created', 'done', 'open', 'timed', 'on_time', 'late', 'overdue_open', 'resolution_minutes_total'];
        $totals = array_fill_keys($fields, 0) + ['avg_resolution_hours' => null, 'on_time_pct' => null];
        foreach ($rows as &$row) {
            $row['avg_resolution_hours'] = self::hours($row['resolution_minutes_total'], $row['timed']);
            $row['on_time_pct'] = $row['timed'] > 0 ? round(100 * $row['on_time'] / $row['timed'], 1) : null;
            foreach ($fields as $field) {
                $totals[$field] += $row[$field];
            }
        }
        unset($row);
        $totals['avg_resolution_hours'] = self::hours($totals['resolution_minutes_total'], $totals['timed']);
        $totals['on_time_pct'] = $totals['timed'] > 0 ? round(100 * $totals['on_time'] / $totals['timed'], 1) : null;
        $areaOrder = array_flip(TicketService::AREAS);
        $priorityOrder = array_flip(array_reverse(TicketService::PRIORITIES));   // urgente primeiro
        usort($rows, static fn (array $a, array $b): int => [$areaOrder[$a['area']], $priorityOrder[$a['priority']]]
            <=> [$areaOrder[$b['area']], $priorityOrder[$b['priority']]]);
        return ['rows' => array_values($rows), 'totals' => $totals];
    }

    private static function hours(int $minutes, int $count): ?float
    {
        return $count > 0 ? round($minutes / $count / 60, 1) : null;
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

        $end = $finish->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $stmt = $this->pdo->prepare('SELECT area, priority, status, created_at, due_at, resolved_at FROM studio_support_tickets
            WHERE condominium_id = ? AND created_at >= ? AND created_at < ? ORDER BY id LIMIT ' . (self::MAX_TICKETS + 1));
        $stmt->execute([(int)$id, $from . ' 00:00:00', $end]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($tickets) > self::MAX_TICKETS) {
            throw new InvalidArgumentException(sprintf('Mais de %d chamados no período: escolha um período menor.', self::MAX_TICKETS));
        }
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to] + self::summarize(array_map(static fn (array $t): array => ['area' => (string)$t['area'],
            'priority' => (string)$t['priority'], 'status' => (string)$t['status'], 'created_at' => (string)$t['created_at'],
            'due_at' => $t['due_at'] !== null ? (string)$t['due_at'] : null, 'resolved_at' => $t['resolved_at'] !== null ? (string)$t['resolved_at'] : null], $tickets), $end);
    }
}
