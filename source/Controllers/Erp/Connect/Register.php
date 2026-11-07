<?php

namespace Source\Controllers\Erp\Connect;

use Source\Models\Address;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Corporation\AppUnits;
use Source\Models\User;
use Source\Support\Upload;

/**
 * ERP | Class Register
 *
 * @author Djalma Martins
 * @package Source/Controllers/Erp/Connect
 */
class Register extends Erp
{

    /**
     * @param array|null $data
     */
    public function home(?array $data): void
    {
        $head = $this->seo->render(
            CONF_SITE_NAME . " | Cadastro de condomínio",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/register/home", [
            "app" => "register/home",
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

        ]);
    }

    /**
     * @param array|null $data
     */
    public function condo(?array $data): void
    {
        $head = $this->seo->render(
            CONF_SITE_NAME . " | Cadastro de condomínio",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/register/condo", [
            "app" => "register/condo",
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

        ]);
    }

    /**
     * @param array|null $data
     */
    public function users(?array $data): void
    {
        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Cadastro de usuário",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        $urlapp = "register/users";
        if(!empty($userEdit)){
            $urlapp = "register/users/{$userEdit->id}";
        }

        echo $this->view->render("components/register/users", [
            "app" => $urlapp ,
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "users" => $userEdit,
            ],
        ]);
    }

    /**
     * @param array|null $data
     */
    public function usersPj(?array $data): void
    {
        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Cadastro de Usuário PJ",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        $urlapp = "register/users-pj";
        if(!empty($userEdit)){
            $urlapp = "register/users-pj/{$userEdit->id}";
        }

        echo $this->view->render("components/register/users-pj", [
            "app" => $urlapp,
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "users" => $userEdit,
            ],
        ]);
    }

    public function create(?array $data): void
    {
        //create Condo
        if (!empty($data["action"]) && $data["action"] == "create") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $condoCreate = new AppCondominium();

            $condoCreate->condo_name = $data["corporate_name"];
            $condoCreate->email = $data["email"];
            $condoCreate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $condoCreate->document_state = preg_replace("/[^0-9]/", "", $data["document_state"]);
            $condoCreate->document_municipal = preg_replace("/[^0-9]/", "", $data["document_municipal"]);
            $condoCreate->datebirth = date_fmt_back($data["datebirth"]);
            $condoCreate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $condoCreate->phone_residential = preg_replace("/[^0-9]/", "", $data["phone_residential"]);
            $condoCreate->phone_commercial = preg_replace("/[^0-9]/", "", $data["phone_commercial"]);
            $condoCreate->phone_messages = preg_replace("/[^0-9]/", "", $data["phone_messages"]);
            $condoCreate->phone_name = $data["phone_name"];
            $condoCreate->obs = $data["obs"];
            $condoCreate->send = "1";


            //upload photo
            if (!empty($_FILES["photo"])) {
                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $condoCreate->condo_name, 600);

                if (!$image) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }

                $condoCreate->photo = $image;
            }


            if (!$condoCreate->save()) {
                $json["message"] = $condoCreate->message()->render();
                echo json_encode($json);
                return;
            } else {
                $condoCreateAddress = (new Address());
                $condoCreateAddress->condo_id = $condoCreate->userLastId();
                $condoCreateAddress->code = str_replace(["-"], [""], $data["code"]);
                $condoCreateAddress->city = $data["city"];
                $condoCreateAddress->district = $data["district"];
                $condoCreateAddress->state = $data["state"];
                $condoCreateAddress->street = $data["street"];
                $condoCreateAddress->number = $data["number"];
                $condoCreateAddress->complement = ($data["complement"] ?? null);
                if (!$condoCreateAddress->save()) {
                    $json["message"] = $condoCreateAddress->message()->before("Ooops! ")->after(" {$this->user->first_name}.")->render();
                    echo json_encode($json);
                    return;
                }
            }

            $json["message"] = $this->message->success("Condomínio cadastrado com sucesso...")->flash();
            echo json_encode(["reload" => true]);
            return;
        }

        //update
        if (!empty($data["action"]) && $data["action"] == "update") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $condoUpdate = (new AppCondominium())->findById($data["condo_id"]);

            if (!$condoUpdate) {
                $this->message->error("Você tentou gerenciar um condomínio que não existe")->flash();
                echo json_encode(["redirect" => url("/erp/condo/home")]);
                return;
            }

            $condoUpdate->condo_name = $data["corporate_name"];
            $condoUpdate->email = $data["email"];
            $condoUpdate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $condoUpdate->document_state = preg_replace("/[^0-9]/", "", $data["document_state"]);
            $condoUpdate->document_municipal = preg_replace("/[^0-9]/", "", $data["document_municipal"]);
            $condoUpdate->datebirth = date_fmt_back($data["datebirth"]);
            $condoUpdate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $condoUpdate->phone_residential = preg_replace("/[^0-9]/", "", $data["phone_residential"]);
            $condoUpdate->phone_commercial = preg_replace("/[^0-9]/", "", $data["phone_commercial"]);
            $condoUpdate->phone_messages = preg_replace("/[^0-9]/", "", $data["phone_messages"]);
            $condoUpdate->phone_name = $data["phone_name"];
            $condoUpdate->obs = $data["obs"];
            $condoUpdate->send = $data["switch-send"];
            $condoUpdate->status = $data["switch-status"];
            $condoUpdate->despatch_sms = $data["switch-sms"];
            $condoUpdate->despatch_whatsapp = $data["switch-whatsapp"];;
            $condoUpdate->despatch_telegram = $data["switch-telegram"];
            $condoUpdate->despatch_letter = $data["switch-letter"];


            //upload photo
            if (!empty($_FILES["photo"])) {
                if ($condoUpdate->photo && file_exists(__DIR__ . "/../../../")) {
                    unlink(__DIR__ . "/../../../");
                    (new Thumb())->flush($condoUpdate->photo);
                }

                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $condoUpdate->condo_name, 600);

                if (!$image) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }

                $condoUpdate->photo = $image;
            }

            if (!$condoUpdate->save()) {
                $json["message"] = $condoUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Condomínio atualizado com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/condo/profile")]);
            return;
        }


        //delete
        if (!empty($data["action"]) && $data["action"] == "delete") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $userDelete = (new User())->findById($data["user_id"]);

            if (!$userDelete) {
                $this->message->error("Você tentnou deletar um usuário que não existe")->flash();
                echo json_encode(["redirect" => url("/erp/users/home")]);
                return;
            }

            if ($userDelete->photo && file_exists(__DIR__ . "/../../../")) {
                unlink(__DIR__ . "/../../../");
                (new Thumb())->flush($userDelete->photo);
            }

            if ($userDelete->doc_rg && file_exists(__DIR__ . "/../../../")) {
                unlink(__DIR__ . "/../../../");
            }

            if ($userDelete->doc_cpf && file_exists(__DIR__ . "/../../../")) {
                unlink(__DIR__ . "/../../../");
            }

            $userDelete->destroy();

            $this->message->success("O usuário foi excluído com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/users/home")]);

            return;
        }

        //address
        if (!empty($data["action"]) && $data["action"] == "updateAddress") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $addressUpdate = (new Address())->find("condo_id = :condo_id AND id = :id",
                "condo_id={$data["condo_id"]}&id={$data["id"]}")->fetch();

            if ($data["status"] == "main") {
                $main = (new Address())->find("users_id = :users_id AND status = :status",
                    "condo_id={$data["condo_id"]}&status={$data["status"]}")->fetch(true);

                if (!empty($main)) {
                    foreach ($main as $item) {
                        $item->status = "leading";
                        $item->save();
                    }

                    if (!$addressUpdate) {
                        $json["message"] = $this->message()->error("Ooops! ")->after(" {$this->user->first_name}, Não foi possível atualizar.")->render();
                        echo json_encode($json);
                        return;
                    }
                }

            }

            $addressUpdate->code = str_replace(["-"], [""], $data["code"]);
            $addressUpdate->city = $data["city"];
            $addressUpdate->district = $data["district"];
            $addressUpdate->state = $data["state"];
            $addressUpdate->street = $data["street"];
            $addressUpdate->number = $data["number"];
            $addressUpdate->complement = ($data["complement"] ?? null);
            $addressUpdate->description = $data["description"];
            $addressUpdate->status = $data["status"];


            if (!$addressUpdate->save()) {
                $json["message"] = $addressUpdate->message()->error("Ooops! ")->after(" {$this->user->first_name}.")->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Pronto {$this->user->first_name}. Seus dados foram atualizados com sucesso!")->flash();
            echo json_encode(["redirect" => url("/erp/condo/address")]);
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
    }
}