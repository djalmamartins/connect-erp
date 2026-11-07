<?php

namespace Source\Controllers\Erp\Connect;

use Source\Models\Address;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Corporation\AppCorporations;
use Source\Models\Corporation\AppOwner;
use Source\Models\Corporation\AppUnits;
use Source\Models\Notification\Notification;
use Source\Models\User;
use Source\Support\Pager;
use Source\Support\Thumb;
use Source\Support\Upload;

/**
 * ERP | Class Condominium
 *
 * @author Djalma Martins
 * @package Source/Controllers/Erp/Connect
 */
class Condominium extends Erp
{


    public function home(?array $data): void
    {

        var_dump($this->condo);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Condomínios",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/condominium/home", [
            "app" => "condominium/home",
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

        ]);
    }

    public function profile(?array $data): void
    {

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominiums = (new AppCondominium())->listCondominium($condominiumId);

        if (!$condominiums) {
            $this->message->error("Opss!! Este condomínio ainda não foi cadastrado.")->flash();
            redirect("/erp/condominium/home");
        }

        //Search redirect
        if (!empty($data["s"])) {
            $s = str_search($data["s"]);
            echo json_encode(["redirect" => url("/erp/condominium/home/{$s}/1")]);
            return;
        }
        $search = null;

        //pager condominium
        $condominium = (new AppCondominium())->find();

        if (!empty($data["search"]) && str_search($data["search"]) != "all") {
            $search = str_search($data["search"]);
            $condominium = (new AppCondominium())->find("MATCH(condominium_name, fantasy_name, email) AGAINST(:s)", "s={$search}");
            if (!$condominium->count()) {
                $this->message->info("Sua pesquisa não retornou resultados")->flash();
                redirect("/erp/condominium/home");
            }
        }

        $all = ($search ?? "all");
        $pager = new Pager(url("/erp/condominium/home/{$all}/"));
        $pager->pager($condominium->count(), 40, (!empty($data["page"]) ? $data["page"] : 1));

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Condomínios",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/condominium/profile", [
            "app" => "condominium/home",
            "head" => $head,
            "user" => $this->user,
            "search" => $search,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "condominium" => $condominiums,
                "address" => $condominiums->address("main"),
            ],
        ]);
    }

    public function address(?array $data): void
    {
        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominiums = (new AppCondominium())->listCondominium($condominiumId);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Endereços",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/condominium/address", [
            "app" => "condominium/address/{$condominiums->id}",
            "head" => $head,
            "user" => $this->user,
            "search" => NULL,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
                "address" => (new Address())->find("condo_id = :condo_id", "condo_id={$condominiums->id}")->fetch(true),
            ],

            "profile" => (object)[
                "condominium" => $condominiums,
                "address" => (new Address())->find("condominium_id = :condominium_id", "condominium_id={$condominiums->id}")->fetch(true),
            ],
        ]);
    }

    public function addressEdit(?array $data): void
    {
        //Address
        if (!empty($data["id"])) {
            $addressId = filter_var($data["id"], FILTER_VALIDATE_INT);
            $address = (new Address())->findById($addressId);
        }

        //condominium
        $condominiumId = ($data["condominium_id"] ?? null);
        $condominiums = (new AppCondominium())->listCondominium($condominiumId);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Editar Endereço",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/condominium/address_edit", [
            "app" => "condominium/address/{$condominiums->id}",
            "head" => $head,
            "user" => $this->user,
            "search" => NULL,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "condominium" => $condominiums,
                "address" => $address,
            ],

        ]);
    }

    public function units(?array $data): void
    {

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

        echo $this->view->render("components/condominium/units", [
            "app" => "condominium/units/{$condominiums->id}",
            "head" => $head,
            "user" => $this->user,
            "search" => $search,
            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],


            "paginator" => $pager->render(),

            "profile" => (object)[
                "condominium" => $condominiums,
            ],

            "pager" => (object)[
                "list" => $units->order("id")->limit($pager->limit())->offset($pager->offset())->fetch(true),
                "owner" => (new AppOwner())->find("sub_of = :sub_of AND owner = 'owner'", "sub_of={$condominiums->id}")->fetch(true),
            ],

            "ownerUser" => $ownerUser,
        ]);

    }

    public function accountable(?array $data): void
    {

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

        $all = ($search ?? "all");
        $pager = new Pager(url("/erp/condominium/units/{$condominiums->id}/{$all}/"));
        $pager->pager($units->count(), 60, (!empty($data["page"]) ? $data["page"] : 1));


        $head = $this->seo->render(
            CONF_SITE_NAME . " | Condomínios",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/condominium/accountable", [
            "app" => "condominium/accountable/{$condominiums->id}",
            "head" => $head,
            "user" => $this->user,
            "search" => $search,
            "paginator" => $pager->render(),

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "condominium" => $condominiums,
            ],

        ]);

    }


    public function register(?array $data): void
    {
        //create
        if (!empty($data["action"]) && $data["action"] == "create") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $condominiumCreate = new AppCondominium();
            $condominiumCreate->condominium_name = $data["condominium_name"];
            $condominiumCreate->fantasy_name = $data["fantasy_name"];
            $condominiumCreate->email = $data["email"];
            $condominiumCreate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $condominiumCreate->datebirth = date_fmt_back($data["datebirth"]);
            $condominiumCreate->phone = preg_replace("/[^0-9]/", "", $data["phone"]);
            $condominiumCreate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $condominiumCreate->despatch = $data["despatch"];
            $condominiumCreate->status = $data["status"];

            //Create photo
            if (!empty($_FILES["photo"])) {

                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $condominiumCreate->condominium_name, 600);

                if (!$image) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }

                $condominiumCreate->photo = $image;
            }

            if (!$condominiumCreate->save()) {
                $json["message"] = $condominiumCreate->message()->render();
                echo json_encode($json);
                return;
            } else {
                $condominiumCreateAddress = (new Address());
                $condominiumCreateAddress->condominium_id = $condominiumCreate->idLast();
                $condominiumCreateAddress->code = str_replace(["-"], [""], $data["code"]);
                $condominiumCreateAddress->city = $data["city"];
                $condominiumCreateAddress->district = $data["district"];
                $condominiumCreateAddress->state = $data["state"];
                $condominiumCreateAddress->street = $data["street"];
                $condominiumCreateAddress->number = $data["number"];
                $condominiumCreateAddress->complement = ($data["complement"] ?? null);
                if (!$condominiumCreateAddress->save()) {
                    $json["message"] = $condominiumCreateAddress->message()->before("Ooops! ")->after(
                        " {$this->user->first_name}."
                    )->render();
                    echo json_encode($json);
                    return;
                }
            }

            $this->message->success("Cadastrado com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/condominium/profile/{$condominiumCreate->idLast()}")]);
            return;

        }

        //update
        if (!empty($data["action"]) && $data["action"] == "update") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $condominiumUpdate = (new AppCondominium())->findById($data['condominium_id']);
            $condominiumUpdate->condominium_name = $data["condominium_name"];
            $condominiumUpdate->fantasy_name = $data["fantasy_name"];
            $condominiumUpdate->email = $data["email"];
            $condominiumUpdate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $condominiumUpdate->datebirth = date_fmt_back($data["datebirth"]);
            $condominiumUpdate->phone = preg_replace("/[^0-9]/", "", $data["phone"]);
            $condominiumUpdate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $condominiumUpdate->despatch = $data["despatch"];
            $condominiumUpdate->status = $data["status"];

            //Update photo
            if (!empty($_FILES["photo"])) {

                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $condominiumUpdate->condominium_name, 600);

                if (!$image) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }

                $condominiumUpdate->photo = $image;
            }

            if (!$condominiumUpdate->save()) {
                $json["message"] = $condominiumUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Administradora atualizada com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/condominium/profile/{$data['condominium_id']}")]);
            return;
        }

        //delete
        if (!empty($data["action"]) && $data["action"] == "delete") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $condominiumDelete = (new AppCondominium())->findById($data["condominium_id"]);

            if (!$condominiumDelete) {
                $this->message->error("Você tentnou deletar uma adinistradora que não existe")->flash();
                echo json_encode(["redirect" => url("/erp/condominium/home")]);
                return;
            }

            if ($condominiumDelete->photo && file_exists(__DIR__ . "/../../../")) {
                unlink(__DIR__ . "/../../../");
                (new Thumb())->flush($condominiumDelete->photo);
            }

            $condominiumDelete->destroy();

            $this->message->success("A Administradora foi excluído com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/condominium/home")]);

            return;
        }

        //address update
        if (!empty($data["action"]) && $data["action"] == "updateAddress") {

            $address = (new Address())->find("id = :id AND condominium_id = :condominium_id",
                "id={$data["id"]}&condominium_id={$data["condominium_id"]}")->fetch();

            if ($data["status"] == "main") {
                $main = (new Address())->find("condominium_id = :condominium_id AND status = :status",
                    "condominium_id={$data["condominium_id"]}&status={$data["status"]}")->fetch(true);

                foreach ($main as $item) {
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
            echo json_encode(["redirect" => url("/erp/condominium/address/{$data['condominium_id']}")]);
            return;
        }

        //create Units
        if (!empty($data["action"]) && $data["action"] == "createUnits") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $order = $data["order"];
            $start = $data["start"];
            $end = $data["end"];
            $apart_per_floor = $data["apart_per_floor"];
            $before = $data["before"];
            $after = $data["after"];


            if ($end == null) {
                $end = $start;
            }

            if ($order == "lower_floor") {
                $current_floor = ceil($start / $apart_per_floor);
                $apartment_number = $start;
                $number = array();
                while ($apartment_number <= $end) {
                    $number[] = $before . sprintf("%02s", $apartment_number) . $after;
                    $apartment_number++;
                    if ($apartment_number % $apart_per_floor == 1) {
                        $current_floor++;
                    }
                }

                foreach ($number as $item) {
                    $numberUnitsCreate = new AppUnits();
                    $numberUnitsCreate->sub_of = $data["sub_of"];
                    $numberUnitsCreate->units = $item;
                    if (!$numberUnitsCreate->save()) {
                        $json["message"] = $numberUnitsCreate->message()->render();
                        echo json_encode($json);
                        return;
                    }
                }

                $this->message->success("Unidades cadastrado com sucesso...")->flash();
                echo json_encode(["reload" => true]);
                return;


            } elseif ($order == "ground_floor") {
                $current_floor = ceil($start / $apart_per_floor);
                $apartment_number = $start;
                $number = array();
                while ($apartment_number <= $end) {
                    $number[] = $before . sprintf("%03s", $apartment_number) . $after;
                    $apartment_number++;
                    if ($apartment_number % $apart_per_floor == 1) {
                        $current_floor++;
                    }
                }

                foreach ($number as $item) {
                    $numberUnitsCreate = new AppUnits();
                    $numberUnitsCreate->sub_of = $data["sub_of"];
                    $numberUnitsCreate->units = $item;
                    if (!$numberUnitsCreate->save()) {
                        $json["message"] = $numberUnitsCreate->message()->render();
                        echo json_encode($json);
                        return;
                    }
                }
                $this->message->success("Unidades cadastrado com sucesso...")->flash();
                echo json_encode(["reload" => true]);
                return;
            } else {
                $current_floor = floor($start / 100);
                $apartment_number = $start;
                $number = array();
                while ($apartment_number <= $end) {
                    $number[] = $before . $apartment_number . $after;
                    $apartment_number++;
                    if ($apartment_number % 100 > $apart_per_floor) {
                        $current_floor++;
                        $apartment_number = $current_floor * 100 + 1;
                    }
                }
                foreach ($number as $item) {
                    $numberUnitsCreate = new AppUnits();
                    $numberUnitsCreate->sub_of = $data["sub_of"];
                    $numberUnitsCreate->units = $item;
                    if (!$numberUnitsCreate->save()) {
                        $json["message"] = $numberUnitsCreate->message()->render();
                        echo json_encode($json);
                        return;
                    }
                }

                $this->message->success("Unidades cadastrado com sucesso...")->flash();
                echo json_encode(["reload" => true]);
                return;
            }
        }

        //create Owner
        if (!empty($data["action"]) && $data["action"] == "createOwner") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $number = explode('-', $data["users_id"], 2);

            $ownerCreate = new AppOwner();

            if ($data["owner"] == "owner") {
                $owner = (new AppOwner())->find("units_id = :units_id AND owner = 'owner' AND status = 'confirmed'",
                    "units_id={$data["units_id"]}")->fetch(true);
                if (!empty($owner)) {
                    foreach ($owner as $item) {
                        $item->status = "registered";
                        $item->save();
                    }
                }
            }

            if ($data["owner"] == "tenant") {
                $owner = (new AppOwner())->find("units_id = :units_id AND owner = 'tenant' AND status = 'confirmed'",
                    "units_id={$data["units_id"]}")->fetch(true);
                if (!empty($owner)) {
                    foreach ($owner as $item) {
                        $item->status = "registered";
                        $item->save();
                    }
                }
            }

            $ownerCreate->sub_of = $data["sub_of"];
            $ownerCreate->units_id = $data["units_id"];
            $ownerCreate->users_id = $number[0];
            $ownerCreate->owner = $data["owner"];


            if (!$ownerCreate->save()) {
                $json["message"] = $ownerCreate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Morador cadastrado na unidade com sucesso...")->flash();
            echo json_encode(["reload" => true]);
            return;
        }
    }
}