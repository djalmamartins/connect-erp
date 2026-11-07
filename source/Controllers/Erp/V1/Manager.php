<?php

namespace Source\Controllers\Erp\V1;

use Source\Models\Banking\AppBankInter;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Corporation\AppCorporations;
use Source\Models\Corporation\AppUnits;
use Source\Models\Notification\Notification;
use Source\Models\User;
use Source\Support\Pager;

/**
 * ERP | Class Manager
 *
 * @author Djalma Martins
 * @package Source\App\Erp
 */
class Manager extends Erp
{

    public function __construct()
    {
        parent::__construct();
    }

    public function home(?array $data): void
    {
        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominiums = (new AppCondominium())->listCondominium($condominiumId);

        //Search redirect
        if (!empty($data["s"])) {
            $s = str_search($data["s"]);
            echo json_encode(["redirect" => url("/erp/condominium/units/{$condominiums->id}/{$s}/1")]);
            return;
        }
        $search = null;

        //pager condominium
        $units = (new AppUnits())->find("sub_of = :sub_of", "sub_of={$condominiums->id}");

        if (!empty($data["search"]) && str_search($data["search"]) != "all") {
            $search = str_search($data["search"]);
            $units = (new AppUnits())->find("MATCH(units) AGAINST(:s)", "s={$search}");
            if (!$units->count()) {
                $this->message->info("Sua pesquisa não retornou resultados")->flash();
                redirect("/erp/condominium/units/{$condominiums->id}");
            }
        }

        $inter = (new AppBankInter())->authentication();

        var_dump($inter);

        $all = ($search ?? "all");
        $pager = new Pager(url("/erp/condominium/units/{$condominiums->id}/{$all}/"));
        $pager->pager($units->count(), 60, (!empty($data["page"]) ? $data["page"] : 1));


        $ownerUser = (new User())->find()->fetch(true);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Condomínios",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/manager/home", [
            "app" => "manager/home/{$condominiums->id}",
            "head" => $head,
            "user" => $this->user,

            "header" => (object)[
                "user" => $this->user,
                "corporations" => (object)[
                    "selected" => $this->corporations,
                    "list" => (new AppCorporations())->find()->fetch(true),
                ],
                "condominium" => (object)[
                    "selected" => $condominiums,
                    "list" => $list = (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->fetch(true),
                ],
                "notifications" => (object)[
                    "list" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->fetch(true),
                    "count" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->count(),
                ],
            ],

            "search" => $search,
            "paginator" => $pager->render(),

            "profile" => (object)[
                "condominium" => (object)[
                    "edit" => $condominiums,
                ],
            ],

            "chart" => null,
            "income" => null,
            "expense" => null,

        ]);
    }
}