<?php
namespace Source\Controllers\App\Connect;

use Source\Models\Auth;
use Source\Models\User;

class Dash extends App
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
}