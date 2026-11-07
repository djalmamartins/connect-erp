<?php

namespace Source\Controllers\Erp\V1;

use Source\Models\Auth;
use Source\Models\Corporation\AppCorporations;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Faq\Channel;
use Source\Models\Faq\Question;
use Source\Models\Notification\Notification;
use Source\Models\Post\Category;
use Source\Models\Post\Post;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\Session\AppLog;
use Source\Models\Session\AppSession;
use Source\Models\User;

/**
 * ERP | Class Dash
 *
 * @author Djalma Martins
 * @package SSource\Controllers\Erp\V1
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

    public function dash(?array $data): void
    {
        if (!empty($data["corporations_id"])) {
            $sessionUpdate = (new AppSession())->find("users_id = :user", "user={$this->user->id}")->fetch();
            $sessionUpdate->corporations_id = $data["corporations_id"];
            $sessionUpdate->users_id = $this->user->id;

            if (!$sessionUpdate->save()) {
                $json["message"] = $sessionUpdate->message()->render();
                echo json_encode($json);
            }
            $this->message->success("Administradora alterada com sucesso...")->flash();
        }
        var_dump($data["corporations_id"]);
        redirect("/erp/dash/home");
    }

    public function home(?array $data): void
    {
        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominium = (new AppCondominium())->listCondominium($condominiumId);


        $head = $this->seo->render(
            CONF_SITE_NAME . " | Dashboard",
            CONF_SITE_DESC,
            url("/erp"),
            theme("/assets/images/image.jpg", CONF_VIEW_ERP),
            false
        );
        echo $this->view->render("components/dash/home", [
            "app" => "dash",
            "head" => $head,
            "user" => $this->user,

            "header" => (object)[
                "user" => $this->user,
                "corporations" => (object)[
                    "selected" => $this->corporations,
                    "list" => (new AppCorporations())->find()->fetch(true),
                ],
                "condominium" => (object)[
                    "selected" => $condominium,
                    "list" => (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->fetch(true),
                ],
                "notifications" => (object)[
                    "list" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->fetch(true),
                    "count" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->count(),
                ],
            ],

            "dash" => (object)[
                "master" => $this->corporations->id,
                "corporations" => (object)[
                    "count" => (new AppCorporations())->find()->count()
                ],

                "condominium" => (object)[
                    "count" => (new AppCondominium())->find()->count(),
                    "subCount" => (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->count(),
                ],

                "user" => (object)[
                    "master" => (new User())->find("level >= 5")->count(),
                    "adm" => (new User())->find("level = 2")->count(),
                    "users" => (new User())->find("level < 5")->count(),
                    "usersAdm" => (new AppSession())->find("corporations_id = :c", "c={$this->corporations->id}")->count(),
                    "total" => (new User())->find()->count()
                ],

            ],

            "faqs" => (new Channel())->find()->count(),
            "question" => (new Question())->find()->count(),

            "view" => (new Access())->find()->order("created_at DESC")->fetch(),
            "online" => (new Online())->findByActive(),
            "onlineCount" => (new Online())->findByActive(true),
            "ticket" => null,

        ]);

    }


    /**
     * @return void
     */
    public function accCookie(): void
    {
        $cookie = $this->user->id;
        setcookie("authCookie", $cookie, time() + 604800, "/");
        $this->message->warning("{$this->user->first_name}, cookie")->flash();
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

    /**
     * @return void
     */
    public function permissions(): void
    {
        if(!empty($this->user)){
            $this->message->warning("{$this->user->first_name}, você precisa de permissão, entre em contato com o administrador do sistema.")->flash();
            Auth::logout();
            redirect("/login");
        }else{
            $this->message->warning("Informe seu login e senha para entrar.")->flash();
            Auth::logout();
            redirect("/login");
        }
    }

    public function link():void
    {

    }
}