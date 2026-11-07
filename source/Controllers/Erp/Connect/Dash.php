<?php

namespace Source\Controllers\Erp\Connect;

use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Session\AppLog;
use Source\Models\Session\AppSession;
use Source\Models\User;

/**
 * ERP | Class Dash
 *
 * @author Djalma Martins
 * @package Source\Controllers\Erp\Connect
 */
class Dash extends Erp
{
    /**
     * Dash constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function home(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if(!empty($data["search"])){
            $list = (new AppCondominium())->search($data["search"]);
        }

        if(empty($data["search"])){
            $list = (new AppCondominium())->find()->fetch(true);
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Dashboard",
            CONF_SITE_DESC,
            url("/erp"),
            theme("/assets/images/image.jpg", CONF_VIEW_ERP),
            false
        );
        echo $this->view->render("components/dash/home", [
            "app" => "dash/home",
            "head" => $head,
            "user" => $this->user,
            "condo" => (object)[
                "select" => $this->condo,
                "list" => $list,
            ],

        ]);
    }

    /**
     * @param array $data
     */
    public function plug(array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!empty($data["action"]) && $data["action"] == "update") {
            $condominiumUpdate = (new AppCondominium())->findById($data['id']);
            if ($condominiumUpdate->status == "confirmed") {
                $condominiumUpdate->status = "registered";
            } else {
                $condominiumUpdate->status = "confirmed";
            }

            if (!$condominiumUpdate->save()) {
                $json["message"] = $condominiumUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Status do condomínio atualizado com sucesso...")->flash();
            echo json_encode(["reload" => true]);
            return;
        }
        if (!empty($data["action"]) && $data["action"] == "updateUsers") {
            $usersUpdate = (new User())->findById($data['id']);
            if ( $usersUpdate->status == "confirmed") {
                $usersUpdate->status = "registered";
            } else {
                $usersUpdate->status = "confirmed";
            }

            if (!$usersUpdate->save()) {
                $json["message"] =  $usersUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Status do usuário atualizado com sucesso...")->flash();
            echo json_encode(["reload" => true]);
            return;
        }
    }

    public function search(array $data): void{
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!empty($data["search"])) {
            $search = str_search($data["search"]);
        }
        $condo = (new AppCondominium())->find("MATCH(condominium_name, fantasy_name, email) AGAINST(:search)", "search={$search}");

        var_dump($condo);
    }

    /**
     *
     */
    public function logoff(): void
    {
        (new AppLog())->register($this->user->id, "Deslogou");
        $this->message->success("Você saiu com sucesso {$this->user->first_name}.")->flash();
        Auth::logout();
        redirect("/login");
    }


}