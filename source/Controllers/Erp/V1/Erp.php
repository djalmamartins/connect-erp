<?php

namespace Source\Controllers\Erp\V1;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\Session\AppSession;
use Source\Models\User;


/**
 * ERP | Class Erp
 *
 * @author Djalma Martins
 * @package Source\Controllers\Erp\V1
 */
class Erp extends Controller
{
    /**
     * @var \Source\Models\User
     */
    protected $user;
    /**
     * Erp constructor.
     */
    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . "/");

        (new Access())->report();
        (new Online())->report();

        $this->user = Auth::user();
        $sessionUser = (new AppSession())->find("users_id = :u", "u={$this->user->id}")->fetch();

        if(!$sessionUser || $this->user->level < 2){
            redirect("/erp/permissions");
        }else {
            (new Session())->set("authCorporations", $sessionUser->corporations_id);
            $this->corporations = Auth::session();
        }

    }

    /**
     * Admin access redirect
     */
    public function root(): void
    {
        $user = Auth::user();

        if ($user && $user->level > 2) {
            redirect("/erp/dash/home");
        } else {
            redirect("/login");
        }
    }
}
