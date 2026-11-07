<?php

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\Erp\AppWallet;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\Session\AppSession;
use Source\Models\User;
use Source\Support\Access as AccessControl;

/**
 * ERP | Class Erp
 *
 * @author Djalma Martins
 * @package Source\Controllers\Erp\Connect
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

        $this->user = Auth::user();
        if (!$this->user) {
            redirect("/login");
            return;
        }

        (new Access())->report();
        (new Online())->report();

        $sessionUser = (new AppSession())->find("users_id = :u", "u={$this->user->id}")->fetch();

        if (!Auth::session()) {
            $this->condo = null;
        }

        if (Auth::session()) {
            $this->condo = Auth::session();
        }

        if (!$sessionUser || !AccessControl::can("erp.access", $this->user)) {
            $this->message->warning("{$this->user->first_name}, você precisa de permissão, entre em contato com o administrador do sistema.")->flash();
            Auth::logout();
            redirect("/login");
        }
    }

    /**
     * Admin access redirect
     */
    public function root(): void
    {
        redirect("/erp/dash");
    }

    public function dash(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data["condominium_id"])) {
            Auth::logoutCondo();
            $sessionCondo = sprintf("%04d", $data["condominium_id"]);
            (new Session())->set("authCondo", $sessionCondo);
            $this->condo = Auth::session();
            $this->message->success("Condomínio alterada com sucesso...")->flash();

            $userOpt = (new User())->findById($this->user->id);
            $userOpt->session_condo = $this->condo->id;
            if (!$userOpt->save()) {
                $json["message"] = $userOpt->message()->render();
                echo json_encode($json);
                return;
            }

            (new AppWallet())->start($this->condo);
            redirect("/erp/condo/home");
        }

        if (!empty($this->user->session_condo)) {
            $sessionCondo = sprintf("%04d", $this->user->session_condo);
            (new Session())->set("authCondo", $sessionCondo);
            redirect("/erp/condo/home");
        }

        if (!$data["condominium_id"]) {
            $sessionCondo = sprintf("%04d", 1);
            (new Session())->set("authCondo", $sessionCondo);
            redirect("/erp/dash/home");
        }

//        if (!$data["condominium_id"]) {
//            $session = new Session();
//            $session->unset("authCondo");
//            redirect("/erp/dash/home");
//        }

        redirect("/erp/dash/home");
    }
}
