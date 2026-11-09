<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;

/**
 * Outbox de notificações do ERP (ADR 0054). record() grava o evento na conexão (e na transação) de
 * quem chama: se a ação desfizer, o evento some junto. Nada é enviado aqui; a fila do container send
 * (ADR 0055) consome os pendentes. event_key único: registrar o mesmo evento de novo não duplica.
 */
final class NotificationOutbox
{
    /** Tipos de aviso (ADR 0054, 0056 e 0059); cada um tem modelo e regra de destinatários no NotificationDispatcher. */
    public const EVENTS = ['waitlist.called', 'invoice.issued', 'bank_charge.issued', 'agreement.created', 'agreement.breached', 'reservation.approved',
        'announcement.published', 'collection.reminder', 'resident.invitation'];

    public function __construct(private PDO $pdo)
    {
    }

    /** Payload limpo (regra pura): só escalares e listas simples, sem chaves de segredo. */
    public static function cleanPayload(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key) || preg_match('/pass|token|secret|senha|key/i', $key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }
        return $clean;
    }

    /** @return bool true se gravou; false se o evento já existia (idempotente) */
    /** @param ?string $availableAt AAAA-MM-DD HH:MM:SS: envio agendado (ADR 0059); null = já */
    public function record(string $eventType, string $eventKey, int $condominiumId, string $subjectTable, int $subjectId, array $payload, ?int $userId, ?string $availableAt = null): bool
    {
        if (!in_array($eventType, self::EVENTS, true)) {
            throw new InvalidArgumentException("Evento de notificação desconhecido: {$eventType}.");
        }
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO erp_notification_outbox (condominium_id, event_type, event_key, subject_table, subject_id, payload_json, created_by, available_at)
            VALUES (:c, :t, :k, :st, :sid, :p, :u, COALESCE(:a, NOW()))');
        $stmt->execute(['c' => $condominiumId, 't' => $eventType, 'k' => mb_substr($eventKey, 0, 120), 'st' => $subjectTable, 'sid' => $subjectId,
            'p' => json_encode(self::cleanPayload($payload), JSON_UNESCAPED_UNICODE), 'u' => $userId, 'a' => $availableAt]);
        return $stmt->rowCount() > 0;
    }
}
