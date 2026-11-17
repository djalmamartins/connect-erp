<?php

declare(strict_types=1);

namespace Source\Services\Support;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Support\Access;

/**
 * Anexo de chamado só para quem pode ver o chamado (ADR 0339). Antes o anexo ia direto de storage/files, sem login, e o
 * link montado (url('/files/...')) nem abria. Regras:
 *  - equipe do Help Desk (support.manage, a mesma regra que mostra as notas internas no ERP) vê qualquer anexo, inclusive o das notas internas;
 *  - quem não é da equipe vê só se o chamado é dele ou do condomínio dele (TicketService::findFor) e o anexo está numa
 *    mensagem pública (não interna);
 *  - o arquivo precisa estar dentro de storage/files ou storage/images (sem ../ nem link para fora; as imagens do widget
 *    público vão para storage/images).
 * Fora disso: null (a rota responde 404, sem dizer se o anexo existe).
 */
final class TicketAttachmentAccess
{
    private PDO $pdo;
    private string $storage;

    public function __construct(?PDO $pdo = null, ?string $storageRoot = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->storage = rtrim($storageRoot ?? dirname(__DIR__, 3) . '/storage', '/');
    }

    public static function url(int $id): string
    {
        return url('/api/v1/ticket-attachments/' . $id);
    }

    /**
     * Regra pura de quem vê.
     *
     * @param array{message_id:?int, is_internal:?int} $attachment
     */
    public static function allowed(array $attachment, bool $team, bool $canSeeTicket): bool
    {
        if ($team) {
            return true;
        }
        return $canSeeTicket && $attachment['message_id'] !== null && (int)$attachment['is_internal'] === 0;
    }

    /**
     * $canSeeTicket e $team só para teste; em uso, TicketService::findFor e a permissão support.manage.
     *
     * @return array{path:string, name:string}|null
     */
    public function find(User $user, int $id, ?callable $canSeeTicket = null, ?bool $team = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT a.ticket_id, a.message_id, a.file_path, a.original_name, m.is_internal
            FROM studio_support_ticket_attachments a LEFT JOIN studio_support_ticket_messages m ON m.id = a.message_id WHERE a.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $team ??= Access::can('support.manage', $user);
        $sees = $team ? true : ($canSeeTicket ?? static fn (User $u, int $ticket): bool => (new TicketService())->findFor($u, $ticket) !== null)($user, (int)$row['ticket_id']);
        $attachment = ['message_id' => $row['message_id'] !== null ? (int)$row['message_id'] : null, 'is_internal' => $row['is_internal'] !== null ? (int)$row['is_internal'] : null];
        if (!self::allowed($attachment, $team, $sees)) {
            return null;
        }
        $path = $this->resolve((string)$row['file_path']);
        return $path === null ? null : ['path' => $path, 'name' => (string)$row['original_name']];
    }

    /** Caminho real dentro de storage/files ou storage/images, ou null. */
    public function resolve(string $filePath): ?string
    {
        $candidate = realpath($this->storage . '/' . ltrim($filePath, '/'));
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        foreach (['files', 'images'] as $dir) {
            $base = realpath($this->storage . '/' . $dir);
            if ($base !== false && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
                return $candidate;
            }
        }
        return null;
    }
}
