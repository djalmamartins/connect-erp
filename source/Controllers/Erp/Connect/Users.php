<?php

namespace Source\Controllers\Erp\Connect;

use Source\Core\View;
use Source\Models\Address;
use Source\Models\Auth;
use Source\Models\Corporation\AppCondominium;
use Source\Models\User;
use Source\Support\Email;
use Source\Support\Pager;
use Source\Support\Upload;

/**
 * ERP | Class Users
 *
 * @author Djalma Martins
 * @package Source\App\Erp
 */
class Users extends Erp
{
    /**
     * Users constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param array|null $data
     */
    public function home(?array $data): void
    {

        //search redirect
        if (!empty($data["s"])) {
            $s = str_search($data["s"]);
            echo json_encode(["redirect" => url("/erp/users/home/{$s}/1")]);
            return;
        }

        $search = null;
        $users = (new User())->find();

        if (!empty($data["search"]) && str_search($data["search"]) != "all") {
            $search = str_search($data["search"]);
            $users = (new User())->find("MATCH(first_name, last_name, email, document) AGAINST(:s)", "s={$search}");
            if (!$users->count()) {
                $this->message->info("Sua pesquisa não retornou resultados")->flash();
                redirect("/erp/users/home");
            }
        }

        $all = ($search ?? "all");
        $pager = new Pager(url("/erp/users/home/{$all}/"));
        $pager->pager($users->count(), 20, (!empty($data["page"]) ? $data["page"] : 1));

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Usuários",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/users/home", [
            "app" => "users/home",
            "head" => $head,
            "search" => $search,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "users" => $users->order("id DESC")->limit($pager->limit())->offset($pager->offset())->fetch(true),

            "paginator" => $pager->render(),



        ]);
    }

    public function profile(?array $data): void
    {

        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }

        if(!$userEdit){
            $this->message->info("Você tentnou acessar um usuário que não existe")->flash();
            redirect("/erp/users/home");
        }

        $edit = ($data["edit"] ?? null);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Informações do Usuário",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );


        $urlapp = "users/profile";
        if(!empty($userEdit)){
            $urlapp = "users/profile/{$userEdit->id}";
        }

        echo $this->view->render("components/users/profile", [
            "app" => $urlapp ,
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "users" => $userEdit,
                "edit" => $edit,
            ],
        ]);
    }

    public function profilePj(?array $data): void
    {

        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }

        $edit = ($data["edit"] ?? null);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Informações do Usuário PJ",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        $urlapp = "users/profile/profile_pj";
        if(!empty($userEdit)){
            $urlapp = "users/profile/profile_pj/{$userEdit->id}";
        }

        echo $this->view->render("components/users/profile_pj", [
            "app" => $urlapp,
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "users" => $userEdit,
                "edit" => $edit,
            ],
        ]);
    }

    public function address(?array $data): void
    {
        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }

        $edit = ($data["edit"] ?? null);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Endereço do Usuário",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/users/address", [
            "app" => "users/address/{$userEdit->id}",
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "users" => $userEdit,
                "address" => (new Address())->find("users_id = :users_id", "users_id={$userEdit->id}")->fetch(),
                "edit" => $edit,
            ],

        ]);
    }

    public function addressEdit(?array $data): void
    {
        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Endereço do Usuário",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/users/address_edit", [
            "app" => "users/address",
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "users" => $userEdit,
                "address" => (new Address())->find("users_id = :users_id", "users_id={$userEdit->id}")->fetch(),
            ],

        ]);
    }

    public function invoices(?array $data): void
    {

        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Usuários",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/users/invoices", [
            "app" => "users/invoices/{$userEdit->id}",
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "users" => $userEdit,
                "address" => (new Address())->find("users_id = :users_id", "users_id={$userEdit->id}")->fetch(true),
            ],

        ]);
    }

    public function historic(?array $data): void
    {
        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Usuários",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/users/invoices", [
            "app" => "users/invoices/{$userEdit->id}",
            "head" => $head,
            "user" => $this->user,

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],

            "profile" => (object)[
                "users" => $userEdit,
                "address" => (new Address())->find("users_id = :users_id", "users_id={$userEdit->id}")->fetch(true),
            ],

        ]);
    }

    public function register(?array $data): void
    {
        //create
        if (!empty($data["action"]) && $data["action"] == "create") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $name = explode(' ', $data["name"], 2);
            $first_name = array_shift($name);
            $last_name = array_pop($name);

            $userCreate = new User();
            if($data["type"] == "pf" ){
                $userCreate->document_rg = preg_replace("/[^0-9]/", "", $data["document_rg"]);
            }else{
                $userCreate->corporate_name = $data["corporate_name"];
                $userCreate->fantasy_name = $data["fantasy_name"];
                $userCreate->document_state = preg_replace("/[^0-9]/", "", $data["document_state"]);
                $userCreate->document_municipal = preg_replace("/[^0-9]/", "", $data["document_municipal"]);
            }

            $userCreate->first_name = $first_name;
            $userCreate->last_name = $last_name;
            $userCreate->email = $data["email"];
            $userCreate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $userCreate->type = $data["type"];
            $userCreate->level = $data["level"];
            $userCreate->datebirth = date_fmt_back($data["datebirth"]);
            $userCreate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $userCreate->phone_residential = preg_replace("/[^0-9]/", "", $data["phone_residential"]);
            $userCreate->phone_commercial = preg_replace("/[^0-9]/", "", $data["phone_commercial"]);
            $userCreate->phone_messages = preg_replace("/[^0-9]/", "", $data["phone_messages"]);
            $userCreate->phone_name = $data["phone_name"];
            $userCreate->obs = $data["obs"];
            $userCreate->send = "0";
            $userCreate->password = substr(base64_encode($data["email"]),0,39);


            //upload photo
            if (!empty($_FILES["photo"])) {
                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $userCreate->first_name, 600);

                if (!$image) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }

                $userCreate->photo = $image;
            }

            //upload CPF
            if (!empty($_FILES["doc_cpf"])) {
                $files = $_FILES["doc_cpf"];
                $upload = new Upload();
                $file = $upload->file($files, base64_encode($userCreate->document));

                if (!$file) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $userCreate->doc_cpf = $file;
            }

            //upload RG
            if (!empty($_FILES["doc_rg"])) {
                $files = $_FILES["doc_rg"];
                $upload = new Upload();
                $file = $upload->file($files, base64_encode($userCreate->document_rg));

                if (!$file) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $userCreate->doc_rg = $file;
            }

            $userCreate->forget = md5(uniqid(rand(), true));

            if (!$userCreate->save()) {
                $json["message"] = $userCreate->message()->render();
                echo json_encode($json);
                return;
            }else{

                $userCreateAddress = (new Address());
                $userCreateAddress->users_id = $userCreate->userLastId();
                $userCreateAddress->code = str_replace(["-"], [""], $data["code"]);
                $userCreateAddress->city = $data["city"];
                $userCreateAddress->district = $data["district"];
                $userCreateAddress->state = $data["state"];
                $userCreateAddress->street = $data["street"];
                $userCreateAddress->number = $data["number"];
                $userCreateAddress->complement = ($data["complement"] ?? null);
                if (!$userCreateAddress->save()) {
                    $json["message"] = $userCreateAddress->message()->before("Ooops! ")->after(" {$this->user->first_name}.")->render();
                    echo json_encode($json);
                    return;
                }

                $view = new View(moves_container_path('mail', CONF_VIEW_MAIL));
                $message = $view->render("confirm", [
                    "first_name" => $userCreate->first_name,
                    "confirm_link" => url("/confirm/{$userCreate->forget}:{$userCreate->email}")
                ]);

                (new Email())->bootstrap(
                    "Confirme Seu Cadastro - " . CONF_SITE_NAME,
                    $message,
                    $userCreate->email,
                    "{$userCreate->first_name} {$userCreate->last_name}"
                )->send();
            }

            $json["message"] = $this->message->success("Usuário cadastrado com sucesso...")->flash();
            echo json_encode(["reload" => true]);
            return;
        }

        //update
        if (!empty($data["action"]) && $data["action"] == "update") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $userUpdate = (new User())->findById($data["user_id"]);

            if (!$userUpdate) {
                $this->message->error("Você tentou gerenciar um usuário que não existe")->flash();
                echo json_encode(["redirect" => url("/erp/users/home")]);
                return;
            }

            $name = explode(' ', $data["name"], 2);
            $first_name = array_shift($name);
            $last_name = array_pop($name);


            if($data["type"] == "pf" ){
                $userUpdate->document_rg = preg_replace("/[^0-9]/", "", $data["document_rg"]);
            }else{
                $userUpdate->corporate_name = $data["corporate_name"];
                $userUpdate->fantasy_name = $data["fantasy_name"];
                $userUpdate->document_state = preg_replace("/[^0-9]/", "", $data["document_state"]);
                $userUpdate->document_municipal = preg_replace("/[^0-9]/", "", $data["document_municipal"]);
            }

            $userUpdate->first_name = $first_name;
            $userUpdate->last_name = $last_name;
            $userUpdate->email = $data["email"];
            $userUpdate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $userUpdate->level = $data["level"];
            $userUpdate->datebirth = date_fmt_back($data["datebirth"]);
            $userUpdate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $userUpdate->phone_residential = preg_replace("/[^0-9]/", "", $data["phone_residential"]);
            $userUpdate->phone_commercial = preg_replace("/[^0-9]/", "", $data["phone_commercial"]);
            $userUpdate->phone_messages = preg_replace("/[^0-9]/", "", $data["phone_messages"]);
            $userUpdate->phone_name = $data["phone_name"];
            $userUpdate->obs = $data["obs"];
            $userUpdate->send = $data["switch-send"];
            $userUpdate->status = $data["switch-status"];
            $userUpdate->despatch_sms = $data["switch-sms"];
            $userUpdate->despatch_whatsapp = $data["switch-whatsapp"];;
            $userUpdate->despatch_telegram = $data["switch-telegram"];
            $userUpdate->despatch_letter = $data["switch-letter"];


            //upload photo
            if (!empty($_FILES["photo"])) {
                if ($userUpdate->photo && file_exists(__DIR__ . "/../../../")) {
                    unlink(__DIR__ . "/../../../");
                    (new Thumb())->flush($userUpdate->photo);
                }

                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $userUpdate->first_name, 600);

                if (!$image) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }

                $userUpdate->photo = $image;
            }

            //upload CPF
            if (!empty($_FILES["doc_cpf"])) {
                if ($userUpdate->doc_cpf && file_exists(__DIR__ . "/../../../")) {
                    unlink(__DIR__ . "/../../../");
                }

                $files = $_FILES["doc_cpf"];
                $upload = new Upload();
                $file = $upload->file($files, base64_encode($userUpdate->document));

                if (!$file) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $userUpdate->doc_cpf = $file;
            }

            //upload RG
            if (!empty($_FILES["doc_rg"])) {
                if ($userUpdate->doc_rg && file_exists(__DIR__ . "/../../../")) {
                    unlink(__DIR__ . "/../../../");
                }

                $files = $_FILES["doc_rg"];
                $upload = new Upload();
                if(!empty($userUpdate->document_rg)){
                    $file = $upload->file($files, base64_encode($userUpdate->document_rg));
                }else{
                    $file = $upload->file($files, base64_encode($userUpdate->last_name));
                }

                if (!$file) {
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $userUpdate->doc_rg = $file;
            }


            if (!$userUpdate->save()) {
                $json["message"] = $userUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Usuário atualizado com sucesso...")->flash();
            echo json_encode(["redirect" => url("/erp/users/profile/{$userUpdate->id}")]);
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

        if (!empty($data["action"]) && $data["action"] == "createAddress") {
            $address = (new Address());
            $address->users_id = ($data["user_id"] ?? null);
            $address->code = str_replace(["-"], [""], $data["code"]);
            $address->city = $data["city"];
            $address->district = $data["district"];
            $address->state = $data["state"];
            $address->street = $data["street"];
            $address->number = $data["number"];
            $address->complement = ($data["complement"] ?? null);
            $address->save();

            if (!$address->save()) {
                $json["message"] = $address->message()->before("Ooops! ")->after(" {$this->user->first_name}.")->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Pronto {$this->user->first_name}. Seu endereço foi cadastrado com sucesso!")->flash();
            echo json_encode(["reload" => true]);
            return;
        }

        if (!empty($data["action"]) && $data["action"] == "updateAddress") {
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $addressUpdate = (new Address())->find("users_id = :user AND id = :id",
                "user={$data["users_id"]}&id={$data["id"]}")->fetch();

            if ($data["status"] == "main") {
                $main = (new Address())->find("users_id = :users_id AND status = :status",
                    "users_id={$data["users_id"]}&status={$data["status"]}")->fetch(true);

                if(!empty($main)){
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
            echo json_encode(["redirect" => url("/erp/users/address/{$data['users_id']}")]);
            return;
        }

    }

    /**
     * SITE PASSWORD FORGET
     * @param null|array $data
     */
    public function forget(?array $data)
    {

        //Register
        if (!empty($data["action"]) && $data["action"] == "register") {

            $userRegister = (new User())->findById($data["user_id"]);
            $userRegister->forget = md5(uniqid(rand(), true));

            if (!$userRegister->save()) {
                $json["message"] = $userRegister->message()->render();
                echo json_encode($json);
                return;
            }else{
                $view = new View(moves_container_path('mail', CONF_VIEW_MAIL));
                $message = $view->render("confirm", [
                    "first_name" => $userRegister->first_name,
                    "confirm_link" => url("/confirm/{$userRegister->forget}:{$data["email"]}")
                ]);

                (new Email())->bootstrap(
                    "Confirme Seu Cadastro - " . CONF_SITE_NAME,
                    $message,
                    $data["email"],
                    "{$data["first_name"]} {$data["last_name"]}"
                )->send();
            }

            $json["message"] = $this->message->success("Novo acesso enviado ao usuário :)")->flash();
            echo json_encode(["reload" => true]);
            return;
        }

        //Recuperar Senha
        if (!empty($data["action"]) && $data["action"] == "forget") {

            $auth = new Auth();
            if ($auth->forget($data["email"])) {
                $json["message"] = $this->message->success("Acesse seu e-mail para recuperar a senha")->render();
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            $json["message"] = $this->message->success("Recuperação de senha enviado ao usuário...")->flash();
            echo json_encode(["reload" => true]);
            return;
        }
    }


}
