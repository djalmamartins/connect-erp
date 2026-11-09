<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Models\Auth;
use Source\Services\Support\NotificationFeed;
use Source\Support\AppLogger;

/**
 * Sino do ERP (ADR 0142): abre uma notificação (marca como lida e segue o link) e marca todas
 * como lidas. As notificações são as mesmas do Studio e do Help Desk.
 */
final class Inbox
{
    /** GET /notificacoes/{id} */
    public function open(array $data): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        header('Cache-Control: no-store, private');
        $id = (int)filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
        $target = null;
        try {
            $result = $id > 0 ? (new NotificationFeed())->open($user, $id) : ['found' => false, 'link' => null];
            $target = $result['link'];
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'notifications', ['event_type' => 'notification_open_failed', 'users_id' => (int)$user->id]);
        }
        redirect($target ?? erp_url('/dashboard'));
    }

    /** POST /notificacoes/lidas */
    public function readAll(): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        if (csrf_verify($_POST)) {
            try {
                (new NotificationFeed())->markAllRead($user);
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'notifications', ['event_type' => 'notification_read_all_failed', 'users_id' => (int)$user->id]);
            }
        }
        $back = (string)($_POST['back'] ?? '');
        redirect($back !== '' && $back[0] === '/' && !str_starts_with($back, '//') && !preg_match('/[\\\\\x00-\x1f]/', $back) ? erp_url($back) : erp_url('/dashboard'));
    }
}
