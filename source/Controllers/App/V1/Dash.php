<?php

namespace Source\Controllers\App\V1;


use Source\Core\Controller;
use Source\Models\Auth;

class Dash extends Controller
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
        redirect("/app/dash/home");
    }

    public function home(?array $data): void
    {
        $head = $this->seo->render(
            CONF_SITE_NAME . " | Dashboard",
            CONF_SITE_DESC,
            url("/app"),
            theme("/assets/images/image.jpg", CONF_VIEW_APP),
            false
        );
        echo $this->view->render("components/dash/home", [
            "app" => "dash",
            "head" => $head,
            "user" => $this->user,

        ]);
    }

    /**
     * APP LOGOUT
     */
    public function logout(): void
    {
        $this->message->info("Você saiu com sucesso " . Auth::user()->first_name . ". Volte logo :)")->flash();

        Auth::logout();
        redirect("/entrar");
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

}