<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Support\AppLogger;

/**
 * Push no celular do morador (ADR 0641). O app (apps/morador) registra o token do Expo depois do login e o apaga
 * ao sair; o cron (tools/resident-push-dispatch.php) envia os avisos pendentes de ResidentTicketNotices.
 *
 * - CONNECT_RESIDENT_PUSH=expo envia pelo serviço de push do Expo (EXPO_ACCESS_TOKEN opcional). Qualquer outro valor
 *   (padrão: log) não chama nada fora e marca o aviso como "skipped".
 * - O texto do push é o mesmo do sininho (título e uma frase curta), sem dado financeiro nem documento.
 * - "DeviceNotRegistered" do Expo apaga o token: o aparelho desinstalou o app ou trocou de conta.
 */
final class ResidentPush
{
    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    public const MAX_TOKENS_PER_USER = 10;
    private const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]{10,120}\]$/';

    private PDO $pdo;
    /** @var callable(list<array<string,mixed>>): list<array<string,mixed>> */
    private $transport;

    /** @param (callable(list<array<string,mixed>>): list<array<string,mixed>>)|null $transport recebe as mensagens e devolve um resultado por mensagem */
    public function __construct(?PDO $pdo = null, ?callable $transport = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->transport = $transport ?? [self::class, 'sendToExpo'];
    }

    public static function validToken(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /** Guarda o celular do morador. O mesmo aparelho em outra conta passa para a conta nova. */
    public function register(int $userId, string $token, string $platform = ''): void
    {
        $token = trim($token);
        if (!self::validToken($token)) {
            throw new InvalidArgumentException('Token de push inválido.');
        }
        $platform = in_array($platform, ['ios', 'android'], true) ? $platform : '';
        $this->pdo->prepare('INSERT INTO resident_push_tokens (user_id, token, platform) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), last_seen_at = NOW()')
            ->execute([$userId, $token, $platform]);
        // Limite por conta: os aparelhos mais antigos saem primeiro.
        $old = $this->pdo->prepare('SELECT id FROM resident_push_tokens WHERE user_id = ? ORDER BY last_seen_at DESC, id DESC LIMIT 1000 OFFSET ' . self::MAX_TOKENS_PER_USER);
        $old->execute([$userId]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->pdo->prepare('DELETE FROM resident_push_tokens WHERE id = ?')->execute([(int)$id]);
        }
    }

    /** Sair do app: o aparelho deixa de receber push desta conta. */
    public function forget(int $userId, string $token): void
    {
        $this->pdo->prepare('DELETE FROM resident_push_tokens WHERE user_id = ? AND token = ?')->execute([$userId, trim($token)]);
    }

    /**
     * Envia os avisos pendentes.
     *
     * @return array{mode:string, sent:int, skipped:int, failed:int, tokens_removed:int}
     */
    public function dispatch(int $limit = 100, ?string $mode = null): array
    {
        $mode ??= strtolower(trim((string)(getenv('CONNECT_RESIDENT_PUSH') ?: 'log')));
        $summary = ['mode' => $mode, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'tokens_removed' => 0];
        $stmt = $this->pdo->prepare("SELECT id, user_id, ticket_id, title, detail FROM resident_ticket_notices WHERE push_status = 'pending' ORDER BY id LIMIT " . max(1, min(500, $limit)));
        $stmt->execute();
        $notices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($notices === []) {
            return $summary;
        }
        if ($mode !== 'expo') {
            foreach ($notices as $notice) {
                $this->mark((int)$notice['id'], 'skipped');
                $summary['skipped']++;
            }
            return $summary;
        }
        foreach ($notices as $notice) {
            $tokens = $this->pdo->prepare('SELECT token FROM resident_push_tokens WHERE user_id = ?');
            $tokens->execute([(int)$notice['user_id']]);
            $list = $tokens->fetchAll(PDO::FETCH_COLUMN);
            if ($list === []) {
                $this->mark((int)$notice['id'], 'skipped');
                $summary['skipped']++;
                continue;
            }
            $messages = array_map(static fn (string $to): array => self::message($to, $notice), array_map('strval', $list));
            try {
                $results = ($this->transport)($messages);
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'resident_push', ['event_type' => 'resident_push_failed', 'notice_id' => (int)$notice['id']]);
                $this->mark((int)$notice['id'], 'failed');
                $summary['failed']++;
                continue;
            }
            $ok = false;
            foreach ($messages as $index => $message) {
                $result = $results[$index] ?? [];
                if (($result['status'] ?? '') === 'ok') {
                    $ok = true;
                } elseif (($result['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    $this->pdo->prepare('DELETE FROM resident_push_tokens WHERE token = ?')->execute([$message['to']]);
                    $summary['tokens_removed']++;
                }
            }
            $this->mark((int)$notice['id'], $ok ? 'sent' : 'failed');
            $summary[$ok ? 'sent' : 'failed']++;
        }
        return $summary;
    }

    /**
     * Mensagem no formato do Expo (regra pura). data.path leva o app direto para o chamado.
     *
     * @param array{ticket_id:int|string, title:string, detail:string} $notice
     * @return array{to:string, title:string, body:string, sound:string, data:array{path:string}}
     */
    public static function message(string $token, array $notice): array
    {
        return ['to' => $token, 'title' => (string)$notice['title'], 'body' => mb_strimwidth((string)$notice['detail'], 0, 178, '…'),
            'sound' => 'default', 'data' => ['path' => '/chamados/' . (int)$notice['ticket_id']]];
    }

    /**
     * Envio real ao Expo (até 100 mensagens por chamada).
     *
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    public static function sendToExpo(array $messages): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        $access = (string)(getenv('EXPO_ACCESS_TOKEN') ?: '');
        if ($access !== '') {
            $headers[] = 'Authorization: Bearer ' . $access;
        }
        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(array_values($messages), JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $raw = curl_exec($curl);
        $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($raw) || $code < 200 || $code >= 300) {
            throw new \RuntimeException('Expo push respondeu ' . $code . '.');
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded['data'] ?? null) ? array_values($decoded['data']) : [];
    }

    private function mark(int $noticeId, string $status): void
    {
        $this->pdo->prepare('UPDATE resident_ticket_notices SET push_status = ?, pushed_at = NOW() WHERE id = ?')->execute([$status, $noticeId]);
    }
}
