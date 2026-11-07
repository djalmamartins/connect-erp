<?php

namespace Source\Controllers\App\V1;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\Session\AppSession;
use Source\Models\User;

class App extends Controller
{
    /** @var User */
    private $user;

    /**
     * App constructor.
     */
    public function __construct()
    {
        parent::__construct(moves_container_path('residents', CONF_VIEW_APP) . "/");

        (new Access())->report();
        (new Online())->report();

        $this->user = Auth::user();
        $sessionUser = (new AppSession())->find("users_id = :u", "u={$this->user->id}")->fetch();

        if(!$sessionUser){
            redirect("/app/dash/home");
        }else {
            redirect("/login");
        }
    }

}
