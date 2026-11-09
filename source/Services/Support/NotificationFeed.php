<?php

declare(strict_types=1);

namespace Source\Services\Support;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Environments;

/**
 * Notificações da pessoa nos três ambientes (ADR 0142).
 *
 * ERP, Studio e Help Desk gravam e leem a mesma tabela notifications. O sino do ERP mostra as
 * mesmas que o Studio mostra (novo chamado, resposta do Help Desk, compromisso...), e o clique marca
 * como lida e leva ao link, passando pela troca de ambiente quando o link é do outro host.
 */
final class NotificationFeed
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function unreadCount(User $user): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE users_id = :user AND view = 0 AND (expires_at IS NULL OR expires_at > NOW())');
        $stmt->execute(['user' => (int)$user->id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * As mais recentes, não lidas primeiro.
     *
     * @return list<array{id:int,title:string,body:string,severity:string,unread:bool,created_at:string}>
     */
    public function latest(User $user, int $limit = 8): array
    {
        $stmt = $this->pdo()->prepare('SELECT id, title, body, severity, view, created_at FROM notifications
            WHERE users_id = :user AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY view ASC, id DESC LIMIT ' . max(1, min(30, $limit)));
        $stmt->execute(['user' => (int)$user->id]);
        return array_map(static fn (array $row): array => [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'body' => (string)($row['body'] ?? ''),
            'severity' => (string)($row['severity'] ?: 'info'),
            'unread' => (int)$row['view'] === 0,
            'created_at' => (string)$row['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Marca como lida a notificação da pessoa e devolve para onde ir (ou null se não for dela
     * ou se o link não for de um host nosso).
     *
     * @return array{found:bool,link:?string}
     */
    public function open(User $user, int $id, ?string $host = null): array
    {
        $stmt = $this->pdo()->prepare('SELECT link FROM notifications WHERE id = :id AND users_id = :user');
        $stmt->execute(['id' => $id, 'user' => (int)$user->id]);
        $link = $stmt->fetchColumn();
        if ($link === false) {
            return ['found' => false, 'link' => null];
        }
        $this->pdo()->prepare('UPDATE notifications SET view = 1, read_at = COALESCE(read_at, NOW()) WHERE id = :id AND users_id = :user')
            ->execute(['id' => $id, 'user' => (int)$user->id]);
        return ['found' => true, 'link' => Environments::resolveLink((string)$link, $host)];
    }

    public function markAllRead(User $user): void
    {
        $this->pdo()->prepare('UPDATE notifications SET view = 1, read_at = COALESCE(read_at, NOW()) WHERE users_id = :user AND view = 0')
            ->execute(['user' => (int)$user->id]);
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }
}
