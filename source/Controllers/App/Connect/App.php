<?php

namespace Source\Controllers\App\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Support\Access as AccessControl;

/**
 * APP | Class APP
 *
 * @author Djalma Martins
 * @package Source\Controllers\App\Connect
 */
class App extends Controller
{
    protected $user;

    public function __construct()
    {
        parent::__construct(moves_container_path('residents', CONF_VIEW_APP) . "/");


        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar o APP.")->flash();
            redirect("/login");
        }
        if (!AccessControl::can("app.access", $this->user)) {
            $this->message->warning("Sua conta não possui acesso ao aplicativo.")->flash();
            redirect("/login");
        }

        (new Access())->report();
        (new Online())->report();

        redirect("/app/dash/home");

    }

    /**
     * Admin access redirect
     */
    public function root(): void
    {
        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar o APP.")->flash();
            redirect("/login");
        }
        if (!AccessControl::can("app.access", $this->user)) {
            $this->message->warning("Sua conta não possui acesso ao aplicativo.")->flash();
            redirect("/login");
        }

        redirect("/app/dash/home");

    }

}
