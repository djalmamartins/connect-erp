<?php

namespace Source\Models\Session;

use Source\Core\Model;
use Source\Models\User;

/**
 * ERP | Class Session
 *
 * @author Djalma Martins
 * @package Source\Models\Session
 */
class AppSession extends Model
{
    /**
     * Session constructor.
     */
    public function __construct()
    {
        parent::__construct("app_session", ["id"], ["users_id"]);
    }

    public function appSession(User $user)
    {
        $appSession = (new AppSession())->find("users_id = :u", "u={$user->id}")->fetch(true);
        return $appSession;
    }
}