<?php

namespace Source\Controllers\Erp\V1;

use Source\Models\Address;
use Source\Models\Corporation\AppCorporations;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Notification\Notification;
use Source\Support\Pager;
use Source\Support\Thumb;
use Source\Support\Upload;

/**
 * ERP | Class Corporations
 *
 * @author Djalma Martins
 * @package Source\App\Erp;
 */
class Corporations extends Erp
{
    /**
     * Corporations constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function corporations(?array $data): void
    {
        redirect("/erp/corporations/home");
    }

    public function home(?array $data): void
    {
        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominium = (new AppCondominium())->listCondominium($condominiumId);

        //Search redirect
        if (!empty($data["s"])) {
            $s = str_search($data["s"]);
            echo json_encode(["redirect" => url("/erp/corporations/home/{$s}/1")]);
            return;
        }
        $search = null;

        //pager corporation
        $corporation = (new AppCorporations())->find();

        if (!empty($data["search"]) && str_search($data["search"]) != "all") {
            $search = str_search($data["search"]);
            $corporation = (new AppCorporations())->find("MATCH(corporation_name, fantasy_name, email) AGAINST(:s)", "s={$search}");
            if (!$corporation->count()) {
                $this->message->info("Sua pesquisa não retornou resultados")->flash();
                redirect("/erp/corporations/home");
            }
        }

        $all = ($search ?? "all");
        $pager = new Pager(url("/erp/corporations/home/{$all}/"));
        $pager->pager($corporation->count(), 40, (!empty($data["page"]) ? $data["page"] : 1));

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Administradoras",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/corporations/home", [
            "app" => "corporations/home",
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

            "search" => $search,
            "paginator" => $pager->render(),

            "pager" => (object)[
                "corporations" => (object)[
                    "list" => $corporation->order("id DESC")->limit($pager->limit())->offset($pager->offset())->fetch(true),
                ],
            ],
        ]);
    }

    public function profile(?array $data): void
    {
        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominium = (new AppCondominium())->listCondominium($condominiumId);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Informações da Administradoras",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/corporations/profile", [
            "app" => "corporations/profile/{$corporations->id}",
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

            "profile" => (object)[
                "corporations" => (object)[
                    "edit" => $corporations,
                    "address" => $corporations->address("main"),
                ],
            ],
        ]);
    }

    public function create(?array $data): void
    {
        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominium = (new AppCondominium())->listCondominium($condominiumId);

        if(!$corporations){
            $link = "corporations/create";
            $title = "Cadastro";
        }else{
            $link = "corporations/create/{$corporations->id}";
            $title = "Editar";
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | " . $title,
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/corporations/create", [
            "app" => $link,
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

            "profile" => (object)[
                "corporations" => (object)[
                    "edit" => $corporations,
                ],
            ],
        ]);
    }

    public function register(?array $data): void
    {
        //create
        if (!empty($data["action"]) && $data["action"] == "create") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $corporationsCreate = new AppCorporations();
            $corporationsCreate->corporation_name = $data["corporation_name"];
            $corporationsCreate->fantasy_name = $data["fantasy_name"];
            $corporationsCreate->email = $data["email"];
            $corporationsCreate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $corporationsCreate->datebirth = date_fmt_back($data["datebirth"]);
            $corporationsCreate->phone = preg_replace("/[^0-9]/", "", $data["phone"]);
            $corporationsCreate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $corporationsCreate->despatch = $data["despatch"];
            $corporationsCreate->status = $data["status"];

            //Create photo
            if (!empty($_FILES["photo"])) {

                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $corporationsCreate->corporation_name, 600);

                if (!$image) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }

                $corporationsCreate->photo = $image;
            }

            if (!$corporationsCreate->save()) {
                $json["message"] = $corporationsCreate->message()->render();
                echo json_encode($json);
                return;
            }else {
                $corporationsCreateAddress = (new Address());
                $corporationsCreateAddress->corporations_id = $corporationsCreate->idLast();
                $corporationsCreateAddress->code = str_replace(["-"], [""], $data["code"]);
                $corporationsCreateAddress->city = $data["city"];
                $corporationsCreateAddress->district = $data["district"];
                $corporationsCreateAddress->state = $data["state"];
                $corporationsCreateAddress->street = $data["street"];
                $corporationsCreateAddress->number = $data["number"];
                $corporationsCreateAddress->complement = ($data["complement"] ?? null);
                if (!$corporationsCreateAddress->save()) {
                    $json["message"] = $corporationsCreateAddress->message()->before("Ooops! ")->after(
                        " {$this->user->first_name}."
                    )->render();
                    echo json_encode($json);
                    return;
                }
            }

            $this->message->success("Cadastrado com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/corporations/profile/{$corporationsCreate->idLast()}")]);
            return;

        }

        //update
        if (!empty($data["action"]) && $data["action"] == "update") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $corporationsUpdate = (new AppCorporations())->findById($data['corporations_id']);
            $corporationsUpdate->corporation_name = $data["corporation_name"];
            $corporationsUpdate->fantasy_name = $data["fantasy_name"];
            $corporationsUpdate->email = $data["email"];
            $corporationsUpdate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $corporationsUpdate->datebirth = date_fmt_back($data["datebirth"]);
            $corporationsUpdate->phone = preg_replace("/[^0-9]/", "", $data["phone"]);
            $corporationsUpdate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $corporationsUpdate->despatch = $data["despatch"];
            $corporationsUpdate->status = $data["status"];

            //Update photo
            if (!empty($_FILES["photo"])) {

                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $corporationsUpdate->corporation_name, 600);

                if (!$image) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }

                $corporationsUpdate->photo = $image;
            }

            if (!$corporationsUpdate->save()) {
                $json["message"] = $corporationsUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Administradora atualizada com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/corporations/profile/{$data['corporations_id']}")]);
            return;
        }

        //delete
        if (!empty($data["action"]) && $data["action"] == "delete") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $corporationsDelete = (new AppCorporations())->findById($data["corporations_id"]);

            if (!$corporationsDelete) {
                $this->message->error("Você tentnou deletar uma adinistradora que não existe")->flash();
                echo json_encode(["redirect" => url("/erp/corporations/home")]);
                return;
            }

            if ($corporationsDelete->photo && file_exists(__DIR__ . "/../../../")) {
                unlink(__DIR__ . "/../../../");
                (new Thumb())->flush($corporationsDelete->photo);
            }

            $corporationsDelete->destroy();

            $this->message->success("A Administradora foi excluído com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/corporations/home")]);

            return;
        }

        //address update
        if (!empty($data["action"]) && $data["action"] == "updateAddress")  {

            $address = (new Address())->find("id = :id AND corporations_id = :corporations_id",
                "id={$data["id"]}&corporations_id={$data["corporations_id"]}")->fetch();

            if ($data["status"] == "main"){
                $main = (new Address())->find("corporations_id = :corporations_id AND status = :status",
                    "corporations_id={$data["corporations_id"]}&status={$data["status"]}")->fetch(true);

                foreach ($main as $item){
                    $item->status = "leading";
                    $item->save();
                }
            }

            if (!$address) {
                $json["message"] = $this->message()->error("Ooops! ")->after(" {$this->user->first_name}, Não foi possível atualizar.")->render();
                echo json_encode($json);
                return;
            }

            $address->code = str_replace(["-"], [""], $data["code"]);
            $address->city = $data["city"];
            $address->district = $data["district"];
            $address->state = $data["state"];
            $address->street = $data["street"];
            $address->number = $data["number"];
            $address->complement = ($data["complement"] ?? null);
            $address->description = $data["description"];
            $address->status = $data["status"];


            if (!$address->save()) {
                $json["message"] = $address->message()->error("Ooops! ")->after(" {$this->user->first_name}.")->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Pronto {$this->user->first_name}. Seus dados foram atualizados com sucesso!")->flash();
            echo json_encode(["redirect" => url("/erp/corporations/address/{$data['corporations_id']}")]);
            return;

        }
    }

    public function address(?array $data): void
    {
        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominium = (new AppCondominium())->listCondominium($condominiumId);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Endereço da Administradora",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/corporations/address", [
            "app" => "corporations/address/{$corporations->id}",
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

            "profile" => (object)[
                "corporations" => (object)[
                    "edit" => $corporations,
                    "address" => (new Address())->find("corporations_id = :corporations_id", "corporations_id={$corporations->id}")->fetch(true),
                ],
            ],
        ]);
    }

    public function addressEdit(?array $data): void
    {
        //Address
        if (!empty($data["id"])){
            $addressId = filter_var($data["id"], FILTER_VALIDATE_INT);
            $address = (new Address())->findById($addressId);
        }

        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominium = (new AppCondominium())->listCondominium($condominiumId);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Editar Administradora",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/corporations/address_edit", [
            "app" => "corporations/address/{$corporations->id}",
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

            "profile" => (object)[
                "corporations" => (object)[
                    "edit" => $corporations,
                    "address" => $address,
                ],
            ],
        ]);
    }

    public function invoices(?array $data): void
    {
        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominium = (new AppCondominium())->listCondominium($condominiumId);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Faturas da Administradoras",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/corporations/invoices", [
            "app" => "corporations/invoices/{$corporations->id}",
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

            "profile" => (object)[
                "corporations" => (object)[
                    "edit" => $corporations,
                    "address" => $corporations->address("main"),
                ],
            ],
        ]);
    }

    public function historic(?array $data): void
    {
        //Corporations
        $corporationsId = ($data["corporations_id"] ?? null);
        $corporations = (new AppCorporations())->listCorporations($corporationsId);

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominium = (new AppCondominium())->listCondominium($condominiumId);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Histórico da Administradoras",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/corporations/historic", [
            "app" => "corporations/historic/{$corporations->id}",
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

            "profile" => (object)[
                "corporations" => (object)[
                    "edit" => $corporations,
                    "address" => $corporations->address("main"),
                ],
            ],
        ]);
    }

}