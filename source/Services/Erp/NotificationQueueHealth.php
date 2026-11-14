<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Controllers\Erp\Connect\NotificationQueue;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Saúde da fila de avisos do condomínio (ADR 0280), somente leitura. A fila de avisos (ADR 0054 e 0055) guarda os eventos
 * da outbox e como cada um foi entregue. A tela da fila (CN-005) mostra até 200 avisos, um a um; ninguém vê, de uma vez,
 * quantos eventos estão em cada situação e de cada tipo, há quanto tempo os pendentes esperam, quantas tentativas os com
 * falha gastaram, de que cadastro vêm os eventos e por qual modo as entregas saíram.
 *
 * Reusa o que já existe: as situações, os tipos e os modos de entrega da tela da fila (NotificationQueue::STATUSES, EVENTS e
 * MODES, com os rótulos dela) e os tipos registrados pela outbox (NotificationOutbox::EVENTS). Os eventos e as entregas
 * contam pelo condomínio do evento.
 *
 * Regra pura summarize():
 * - rows: uma linha por tipo de NotificationOutbox::EVENTS, sempre todos, com events e uma contagem por situação de
 *   STATUSES, que somam events; totals soma as linhas; unknown_events conta os eventos de tipo fora da lista (fora das
 *   linhas);
 * - pending_by_age: os pendentes e em processamento por tempo desde a criação, em AGES (até 1 hora, até 1 dia, mais de 1
 *   dia), somando os pendentes e em processamento das linhas;
 * - attempts: média (duas casas) e máximo das tentativas dos eventos com falha;
 * - failed_ids (do mais antigo ao mais novo) e oldest_pending_ids (do que espera há mais tempo), até MAX_IDS cada, com
 *   truncated;
 * * - subjects: eventos por cadastro de origem (subject_table), em ordem alfabética, somando os eventos das linhas;
 * - deliveries: entregas registradas por modo de MODES, com o total.
 *
 * O tempo de espera usa o relógio do banco (created_at e NOW() no mesmo fuso).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * tipos, situações, modos, cadastros de origem, contagens e ids: nenhum destinatário, e-mail, telefone, assunto, texto,
 * conteúdo do evento nem mensagem de erro.
 */
final class NotificationQueueHealth
{
    public const MAX_ROWS = 100000;
    public const MAX_IDS = 200;
    /** Tempo de espera dos pendentes, em minutos desde a criação: faixa => até quantos minutos (null = sem limite). */
    public const AGES = ['ate_1_hora' => 60, 'ate_1_dia' => 1440, 'mais_de_1_dia' => null];
    /** Situações que ainda esperam envio. */
    public const WAITING = ['pending', 'processing'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa de espera (regra pura) para quantos minutos se passaram desde a criação. */
    public static function age(int $minutes): string
    {
        foreach (self::AGES as $age => $limit) {
            if ($limit === null || $minutes <= $limit) {
                return $age;
            }
        }
        return (string)array_key_last(self::AGES);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, event_type:string, subject_table:string, status:string, attempts:int, created_at:string}> $events
     * @param array<string, int> $deliveries modo de entrega => entregas
     */
    public static function summarize(array $events, array $deliveries, \DateTimeImmutable $now): array
    {
        $statuses = array_keys(NotificationQueue::STATUSES);
        $zero = ['events' => 0] + array_fill_keys($statuses, 0);
        $counts = array_fill_keys(NotificationOutbox::EVENTS, $zero);
        $ages = array_fill_keys(array_keys(self::AGES), 0);
        $subjects = [];
        $failed = [];
        $waiting = [];
        $unknown = 0;
        foreach ($events as $e) {
            if (!isset($counts[$e['event_type']]) || !in_array($e['status'], $statuses, true)) {
                $unknown++;
                continue;
            }
            $counts[$e['event_type']]['events']++;
            $counts[$e['event_type']][$e['status']]++;
            $subjects[$e['subject_table']] = ($subjects[$e['subject_table']] ?? 0) + 1;
            if ($e['status'] === 'failed') {
                $failed[] = ['id' => $e['id'], 'attempts' => $e['attempts']];
            } elseif (in_array($e['status'], self::WAITING, true)) {
                $created = new \DateTimeImmutable($e['created_at']);
                $minutes = max(0, intdiv($now->getTimestamp() - $created->getTimestamp(), 60));
                $ages[self::age($minutes)]++;
                $waiting[] = [$e['created_at'], $e['id']];
            }
        }
        $rows = [];
        $totals = $zero;
        foreach (NotificationOutbox::EVENTS as $type) {
            $rows[] = ['event_type' => $type, 'event_label' => NotificationQueue::EVENTS[$type] ?? $type] + $counts[$type];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$type][$key];
            }
        }
        ksort($subjects);
        $failedIds = array_column($failed, 'id');
        sort($failedIds);
        sort($waiting);
        $waitingIds = array_column($waiting, 1);
        $attempts = array_column($failed, 'attempts');
        $byMode = [];
        foreach (NotificationQueue::MODES as $mode => $label) {
            $byMode[] = ['mode' => $mode, 'mode_label' => $label, 'deliveries' => max(0, (int)($deliveries[$mode] ?? 0))];
        }
        return ['on' => $now->format('Y-m-d H:i:s'), 'rows' => $rows, 'totals' => $totals, 'unknown_events' => $unknown, 'pending_by_age' => $ages,
            'attempts' => ['failed' => count($attempts), 'average' => $attempts === [] ? 0.0 : round(array_sum($attempts) / count($attempts), 2),
                'max' => $attempts === [] ? 0 : max($attempts)],
            'failed_ids' => array_slice($failedIds, 0, self::MAX_IDS), 'failed_truncated' => count($failedIds) > self::MAX_IDS,
            'oldest_pending_ids' => array_slice($waitingIds, 0, self::MAX_IDS), 'pending_truncated' => count($waitingIds) > self::MAX_IDS,
            'subjects' => array_map(static fn (string $table, int $n): array => ['subject_table' => $table, 'events' => $n], array_keys($subjects), array_values($subjects)),
            'deliveries' => ['rows' => $byMode, 'total' => array_sum(array_column($byMode, 'deliveries'))]];
    }

    public function build(User $user, mixed $condominiumId, ?\DateTimeImmutable $now = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT id, event_type, subject_table, status, attempts, created_at FROM erp_notification_outbox WHERE condominium_id = ? ORDER BY id LIMIT '
            . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d avisos na fila: fale com o suporte.', self::MAX_ROWS));
        }
        // As entregas dos eventos deste condomínio (o escopo vem do evento), só a contagem por modo.
        $stmt = $this->pdo->prepare('SELECT d.delivery_mode, COUNT(*) AS n FROM erp_notification_deliveries d JOIN erp_notification_outbox o ON o.id = d.outbox_id
            WHERE o.condominium_id = ? GROUP BY d.delivery_mode');
        $stmt->execute([(int)$id]);
        $deliveries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $deliveries[(string)$r['delivery_mode']] = (int)$r['n'];
        }
        return ['condominium_id' => (int)$id] + self::summarize(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'event_type' => (string)$r['event_type'],
            'subject_table' => (string)$r['subject_table'], 'status' => (string)$r['status'], 'attempts' => (int)$r['attempts'], 'created_at' => (string)$r['created_at']],
            $rows), $deliveries, $now ?? new \DateTimeImmutable((string)$this->pdo->query('SELECT NOW()')->fetchColumn()));
    }
}
