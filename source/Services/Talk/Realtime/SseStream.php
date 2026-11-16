<?php
declare(strict_types=1);

namespace Source\Services\Talk\Realtime;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Service\AccessPolicy;
use Source\Services\Talk\Support\Db;

/**
 * Tempo real por Server-Sent Events.
 *
 * O navegador abre `new EventSource('/talk/events')`; este loop entrega os eventos
 * novos que o usuário pode ver e encerra depois de ~25s (o EventSource reconecta
 * sozinho enviando Last-Event-ID). Assim nenhum processo PHP fica preso por muito tempo.
 *
 * IMPORTANTE: chamar session_write_close() antes, senão a sessão do ERP fica travada.
 */
final class SseStream
{
    public function __construct(private Db $db, private AccessPolicy $policy)
    {
    }

    /**
     * Eventos visíveis para o usuário depois de $lastId.
     * @return list<array{id:int,type:string,data:array}>
     */
    public function fetch(StaffMember $u, int $lastId, int $limit = 100): array
    {
        $rows = $this->db->all(
            'SELECT e.id, e.type, e.payload, e.sector_id AS ev_sector, e.conversation_id,
                    c.sector_id, c.assigned_user_id, c.status
             FROM talk_events e LEFT JOIN talk_conversations c ON c.id = e.conversation_id
             WHERE e.id > ? ORDER BY e.id LIMIT ' . (int) $limit,
            [$lastId]
        );
        $out = [];
        foreach ($rows as $r) {
            $data = json_decode($r['payload'], true) ?: [];
            $visible = $r['conversation_id'] === null
                ? $u->supervisor
                : $this->policy->canView($u, $r)
                    // quem perdeu a conversa (transferência) precisa saber para tirá-la da lista
                    || ($r['type'] === 'conversation.transferred' && $this->wasVisibleBefore($u, $r, $data));
            if ($visible) {
                $out[] = ['id' => (int) $r['id'], 'type' => $r['type'], 'data' => $data + ['conversation_id' => $r['conversation_id'] !== null ? (int) $r['conversation_id'] : null]];
            }
        }
        return $out;
    }

    private function wasVisibleBefore(StaffMember $u, array $row, array $data): bool
    {
        $from = $data['from_sector_id'] ?? null;
        return $from !== null && in_array((int) $from, $u->sectors(), true);
    }

    /**
     * Laço de streaming (usado pelo controller). $tick roda a cada ~$tickSeconds enquanto o stream
     * está aberto: no laboratório (sem worker) é ele que faz a triagem e os envios sem esperar.
     */
    public function stream(StaffMember $u, int $lastId, int $maxSeconds = 25, float $pollSeconds = 1.0, ?\Closure $tick = null, float $tickSeconds = 2.0): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        echo "retry: 2000\n\n";
        $deadline = microtime(true) + $maxSeconds;
        $lastBeat = microtime(true);
        $lastTick = 0.0;
        while (microtime(true) < $deadline && !connection_aborted()) {
            if ($tick !== null && microtime(true) - $lastTick >= $tickSeconds) {
                $lastTick = microtime(true);
                try {
                    $tick();
                } catch (\Throwable $e) {
                    error_log('[talk] worker no stream: ' . $e->getMessage());
                }
            }
            foreach ($this->fetch($u, $lastId) as $ev) {
                $lastId = $ev['id'];
                echo "id: {$ev['id']}\nevent: {$ev['type']}\ndata: " . json_encode($ev['data'], JSON_UNESCAPED_UNICODE) . "\n\n";
            }
            if (microtime(true) - $lastBeat > 10) {
                echo ": ping\n\n";
                $lastBeat = microtime(true);
            }
            flush();
            usleep((int) ($pollSeconds * 1_000_000));
        }
    }
}
