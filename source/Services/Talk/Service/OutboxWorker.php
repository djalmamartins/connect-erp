<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Gateway\GatewayFactory;
use Source\Services\Talk\Media\MediaStore;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Support\Db;

/**
 * Envia o que está na talk_outbox. Rode em loop (bin/talk-worker) ou pelo cron.
 * Usa SKIP LOCKED: dá para ter mais de um worker sem enviar duas vezes.
 */
final class OutboxWorker
{
    public const MAX_ATTEMPTS = 5;

    public const MEDIA_ATTEMPTS = 3;

    public function __construct(private Db $db, private GatewayFactory $gateways, private EventBus $events, private ?MediaStore $media = null)
    {
    }

    /** Processa até $limit envios. Retorna quantos foram enviados com sucesso. */
    public function runOnce(int $limit = 20): int
    {
        $jobs = $this->db->transaction(function () use ($limit) {
            $rows = $this->db->all(
                "SELECT o.*, m.body, m.conversation_id, m.type, m.media_path, m.media_mime, m.media_name FROM talk_outbox o JOIN talk_messages m ON m.id = o.message_id
                 WHERE o.status = 'queued' AND o.next_attempt_at <= NOW()
                 ORDER BY o.id LIMIT " . (int) $limit . ' FOR UPDATE SKIP LOCKED'
            );
            foreach ($rows as $r) {
                $this->db->run("UPDATE talk_outbox SET status = 'sending', attempts = attempts + 1 WHERE id = ?", [$r['id']]);
            }
            return $rows;
        });

        $sent = 0;
        foreach ($jobs as $job) {
            if ((int) $job['typing_seconds'] > 0) {
                $this->startTyping($job);
                continue;
            }
            try {
                $gw = $this->gateways->forChannel((int) $job['channel_id']);
                $result = $job['media_path'] !== null && $this->media !== null
                    ? $gw->sendMedia($job['to_phone'], (string) $job['type'], $this->media->absolute((string) $job['media_path']),
                        (string) $job['media_mime'], (string) $job['media_name'], $job['body'])
                    : $gw->sendText($job['to_phone'], (string) $job['body']);
                $this->db->run("UPDATE talk_outbox SET status = 'sent', last_error = NULL WHERE id = ?", [$job['id']]);
                $this->db->run("UPDATE talk_messages SET ack = 'sent', provider_message_id = ? WHERE id = ?", [$result->providerMessageId, $job['message_id']]);
                $this->ack((int) $job['conversation_id'], (int) $job['message_id'], 'sent');
                $sent++;
            } catch (\Throwable $e) {
                $attempts = (int) $job['attempts'] + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->db->run("UPDATE talk_outbox SET status = 'failed', last_error = ? WHERE id = ?", [$e->getMessage(), $job['id']]);
                    $this->db->run("UPDATE talk_messages SET ack = 'failed' WHERE id = ?", [$job['message_id']]);
                    $this->ack((int) $job['conversation_id'], (int) $job['message_id'], 'failed');
                } else {
                    $delay = 2 ** $attempts * 15; // 30s, 60s, 120s, 240s
                    $this->db->run(
                        "UPDATE talk_outbox SET status = 'queued', last_error = ?, next_attempt_at = NOW() + INTERVAL ? SECOND WHERE id = ?",
                        [$e->getMessage(), $delay, $job['id']]
                    );
                }
            }
        }
        return $sent;
    }

    /**
     * Baixa as mídias recebidas (imagem, áudio, PDF...) que ainda estão no WhatsApp.
     * media_path: "pending:<n>:<ref>" (n = tentativas) → caminho no storage, ou "failed:<ref>" depois de 3 erros.
     */
    public function fetchMedia(int $limit = 10): int
    {
        if ($this->media === null) {
            return 0;
        }
        $rows = $this->db->all(
            "SELECT m.id, m.conversation_id, m.media_path, m.media_mime, m.type, m.body, c.channel_id, c.sector_id
               FROM talk_messages m JOIN talk_conversations c ON c.id = m.conversation_id
              WHERE m.direction = 'in' AND m.media_path LIKE 'pending:%' ORDER BY m.id LIMIT " . (int) $limit
        );
        $done = 0;
        foreach ($rows as $r) {
            [, $tries, $ref] = self::pending((string) $r['media_path']);
            // reserva a linha: outro worker que pegar a mesma não faz nada
            $claim = 'fetching:' . $ref;
            if ($this->db->run('UPDATE talk_messages SET media_path = ? WHERE id = ? AND media_path = ?', [mb_substr($claim, 0, 255), $r['id'], $r['media_path']])->rowCount() !== 1) {
                continue;
            }
            try {
                $got = $this->gateways->forChannel((int) $r['channel_id'])->downloadMedia($ref);
                $name = $r['type'] === 'document' ? $r['body'] : null; // documento: o nome do arquivo vem no corpo
                $file = $this->media->save($got['bytes'], $got['mime'] ?? $r['media_mime'], $name);
                $this->db->run('UPDATE talk_messages SET media_path = ?, media_mime = ?, media_name = ?, media_size = ? WHERE id = ?',
                    [$file['path'], $file['mime'], $file['name'], $file['size'], $r['id']]);
                $done++;
                $status = 'ok';
            } catch (\Throwable $e) {
                error_log('[talk] mídia ' . $r['id'] . ': ' . $e->getMessage());
                $next = $tries + 1 >= self::MEDIA_ATTEMPTS ? 'failed:' . $ref : 'pending:' . ($tries + 1) . ':' . $ref;
                $this->db->run('UPDATE talk_messages SET media_path = ? WHERE id = ?', [mb_substr($next, 0, 255), $r['id']]);
                $status = str_starts_with($next, 'failed:') ? 'failed' : 'retry';
            }
            if ($status !== 'retry') {
                $this->events->publish('message.media', (int) $r['conversation_id'], $r['sector_id'] !== null ? (int) $r['sector_id'] : null, null,
                    ['message_id' => (int) $r['id'], 'status' => $status]);
            }
        }
        return $done;
    }

    /** "pending:<ref>" (como chega) ou "pending:<n>:<ref>" → [, tentativas, ref] */
    private static function pending(string $path): array
    {
        $rest = substr($path, strlen('pending:'));
        if (preg_match('/^(\d):(.+)$/s', $rest, $m)) {
            return [null, (int) $m[1], $m[2]];
        }
        return [null, 0, $rest];
    }

    /**
     * Primeiro passo das mensagens da Jaqueline: mostra "digitando…" e reagenda o envio para quando
     * a "digitação" terminar. Se o indicador falhar, segue igual (é só cosmético).
     */
    private function startTyping(array $job): void
    {
        $seconds = (int) $job['typing_seconds'];
        try {
            $last = $this->db->one(
                "SELECT provider_message_id FROM talk_messages WHERE conversation_id = ? AND direction = 'in' ORDER BY id DESC LIMIT 1",
                [$job['conversation_id']]
            );
            $this->gateways->forChannel((int) $job['channel_id'])->sendTyping($job['to_phone'], $seconds, $last['provider_message_id'] ?? null);
        } catch (\Throwable $e) {
            error_log('[talk] digitando: ' . $e->getMessage());
        }
        $this->db->run(
            "UPDATE talk_outbox SET status = 'queued', attempts = 0, typing_seconds = 0, next_attempt_at = NOW() + INTERVAL ? SECOND WHERE id = ?",
            [$seconds, $job['id']]
        );
    }

    private function ack(int $convId, int $messageId, string $ack): void
    {
        $sector = $this->db->one('SELECT sector_id FROM talk_conversations WHERE id = ?', [$convId])['sector_id'] ?? null;
        $this->events->publish('message.ack', $convId, $sector !== null ? (int) $sector : null, null, ['message_id' => $messageId, 'ack' => $ack]);
    }
}
