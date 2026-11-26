<?php

declare(strict_types=1);

namespace Source\Services\Team;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Services\Resident\ResidentPush;
use Source\Support\AppLogger;

/**
 * Push nos apps da equipe (SAC e Meu Dia, ADR 0660).
 * - register()/forget(): o app grava o token do Expo depois do login e apaga ao sair; um aparelho por (token, app).
 * - send(): põe o aviso na fila (team_push_queue) só para quem tem aquele app; não chama nada fora na requisição.
 * - dispatch(): o cron (tools/team-push-dispatch.php, todo minuto) envia. TEAM_PUSH_DELIVERY=expo envia pelo Expo
 *   (EXPO_ACCESS_TOKEN opcional); qualquer outro valor (padrão: log) marca como "skipped" sem sair da máquina.
 * Texto curto, sem dado financeiro nem documento; data.path abre a tela certa no app.
 */
final class TeamPush
{
    public const APPS = ['sac', 'meu-dia'];
    public const MAX_DEVICES_PER_APP = 5;

    private PDO $pdo;
    /** @var callable(list<array<string,mixed>>): list<array<string,mixed>> */
    private $transport;

    public function __construct(?PDO $pdo = null, ?callable $transport = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->transport = $transport ?? [ResidentPush::class, 'sendToExpo'];
    }

    public static function validApp(mixed $app): bool
    {
        return is_string($app) && in_array($app, self::APPS, true);
    }

    public function register(int $userId, string $app, string $token, string $platform = ''): void
    {
        $token = trim($token);
        if (!self::validApp($app)) {
            throw new InvalidArgumentException('App inválido.');
        }
        if (!ResidentPush::validToken($token)) {
            throw new InvalidArgumentException('Token de push inválido.');
        }
        $platform = in_array($platform, ['ios', 'android'], true) ? $platform : '';
        $this->pdo->prepare('INSERT INTO team_push_devices (user_id, app, token, platform) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), last_seen_at = NOW()')
            ->execute([$userId, $app, $token, $platform]);
        $old = $this->pdo->prepare('SELECT id FROM team_push_devices WHERE user_id = ? AND app = ? ORDER BY last_seen_at DESC, id DESC LIMIT 1000 OFFSET ' . self::MAX_DEVICES_PER_APP);
        $old->execute([$userId, $app]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->pdo->prepare('DELETE FROM team_push_devices WHERE id = ?')->execute([(int)$id]);
        }
    }

    public function forget(int $userId, string $app, string $token): void
    {
        $this->pdo->prepare('DELETE FROM team_push_devices WHERE user_id = ? AND app = ? AND token = ?')->execute([$userId, $app, trim($token)]);
    }

    /** Todos os usuários com aparelho ativo no app (ex.: resumo das 7h do Meu Dia). @return list<int> */
    public function usersWithDevices(string $app): array
    {
        if (!self::validApp($app)) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT DISTINCT user_id FROM team_push_devices WHERE app = ? ORDER BY user_id');
        $stmt->execute([$app]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Quem desta lista tem o app instalado (para não encher a fila à toa). @param list<int> $userIds @return list<int> */
    public function withApp(array $userIds, string $app): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || !self::validApp($app)) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT DISTINCT user_id FROM team_push_devices WHERE app = ? AND user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([$app, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Põe o aviso na fila para cada usuário que tem o app. Devolve quantos entraram. Nunca lança: push é extra.
     *
     * @param int|list<int> $userIds
     * @param array<string, scalar> $data ex.: ['path' => '/chamados/12']
     */
    public function send(int|array $userIds, string $app, string $title, string $body, array $data = []): int
    {
        try {
            $targets = $this->withApp(is_int($userIds) ? [$userIds] : $userIds, $app);
            if ($targets === []) {
                return 0;
            }
            $json = $data === [] ? null : mb_substr((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 500);
            $insert = $this->pdo->prepare('INSERT INTO team_push_queue (user_id, app, title, body, data_json) VALUES (?, ?, ?, ?, ?)');
            foreach ($targets as $userId) {
                $insert->execute([$userId, $app, self::clip($title, 120), self::clip($body, 178), $json]);
            }
            return count($targets);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'team_push', ['event_type' => 'team_push_queue_failed', 'app' => $app]);
            return 0;
        }
    }

    /** @return array{mode:string, sent:int, skipped:int, failed:int, tokens_removed:int} */
    public function dispatch(int $limit = 100, ?string $mode = null): array
    {
        $mode ??= strtolower(trim((string)(getenv('TEAM_PUSH_DELIVERY') ?: 'log')));
        $summary = ['mode' => $mode, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'tokens_removed' => 0];
        $stmt = $this->pdo->prepare("SELECT id, user_id, app, title, body, data_json FROM team_push_queue WHERE status = 'pending' ORDER BY id LIMIT " . max(1, min(500, $limit)));
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ($mode !== 'expo') {
                $this->mark((int)$item['id'], 'skipped');
                $summary['skipped']++;
                continue;
            }
            $tokens = $this->pdo->prepare('SELECT token FROM team_push_devices WHERE user_id = ? AND app = ?');
            $tokens->execute([(int)$item['user_id'], (string)$item['app']]);
            $list = array_map('strval', $tokens->fetchAll(PDO::FETCH_COLUMN));
            if ($list === []) {
                $this->mark((int)$item['id'], 'skipped');
                $summary['skipped']++;
                continue;
            }
            $messages = array_map(static fn (string $to): array => self::message($to, $item), $list);
            try {
                $results = ($this->transport)($messages);
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'team_push', ['event_type' => 'team_push_failed', 'queue_id' => (int)$item['id']]);
                $this->mark((int)$item['id'], 'failed');
                $summary['failed']++;
                continue;
            }
            $ok = false;
            foreach ($messages as $index => $message) {
                $result = $results[$index] ?? [];
                if (($result['status'] ?? '') === 'ok') {
                    $ok = true;
                } elseif (($result['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    $this->pdo->prepare('DELETE FROM team_push_devices WHERE token = ? AND app = ?')->execute([$message['to'], (string)$item['app']]);
                    $summary['tokens_removed']++;
                }
            }
            $this->mark((int)$item['id'], $ok ? 'sent' : 'failed');
            $summary[$ok ? 'sent' : 'failed']++;
        }
        return $summary;
    }

    /**
     * Mensagem no formato do Expo (regra pura).
     *
     * @param array{title:string, body:string, data_json:?string} $item
     * @return array{to:string, title:string, body:string, sound:string, data:array<string,mixed>}
     */
    public static function message(string $token, array $item): array
    {
        $data = is_string($item['data_json'] ?? null) ? json_decode((string)$item['data_json'], true) : null;
        return ['to' => $token, 'title' => (string)$item['title'], 'body' => (string)$item['body'], 'sound' => 'default', 'data' => is_array($data) ? $data : []];
    }

    private static function clip(string $text, int $width): string
    {
        return mb_strimwidth(trim((string)preg_replace('/\s+/', ' ', strip_tags($text))), 0, $width, '…');
    }

    private function mark(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE team_push_queue SET status = ?, sent_at = NOW() WHERE id = ?')->execute([$status, $id]);
    }
}
