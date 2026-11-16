<?php
declare(strict_types=1);

namespace Source\Services\Talk\Realtime;

use Source\Services\Talk\Support\Db;

/**
 * Publica eventos para as telas. Hoje grava em talk_events e o endpoint SSE lê.
 * Se um dia trocar por Centrifugo, só esta classe muda.
 */
class EventBus
{
    public function __construct(private Db $db)
    {
    }

    public function publish(string $type, ?int $conversationId, ?int $sectorId, ?int $userId, array $payload = []): int
    {
        return $this->db->insert('talk_events', [
            'type' => $type,
            'conversation_id' => $conversationId,
            'sector_id' => $sectorId,
            'user_id' => $userId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }
}
