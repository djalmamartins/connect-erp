<?php

namespace Source\Models\Session;

use Source\Core\Model;
use Source\Support\AppLogger;


/**
 * ERP | Class Log
 *
 * @author Djalma Martins
 * @package Source\Models\Session
 */
class AppLog extends Model
{
    /**
     * Session constructor.
     */
    public function __construct()
    {
        parent::__construct("app_log", ["id"], ["users_id", "ip", "msg", "url"]);
    }

    /**
     * @param int $users_id
     * @param string $msg
     * @param int|null $corporations_id
     * @param int|null $condominium_id
     * @return void
     */
    public function register(int $users_id, string $msg, ?int $corporations_id = null, ?int $condominium_id = null): void
    {
        AppLogger::log('info', $msg, [
            'users_id' => $users_id,
            'corporations_id' => $corporations_id,
            'condominium_id' => $condominium_id,
            'event_type' => 'user_activity',
            'status' => 'resolved'
        ], 'authentication');
    }
}
