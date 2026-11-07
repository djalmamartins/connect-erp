<?php

namespace Source\Controllers\Erp\V1;

use Source\Core\View;
use Source\Models\Address;
use Source\Models\Corporation\AppCorporations;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Notification\Notification;
use Source\Models\Session\AppSession;
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
        if (!empty($data["condominium_id"])) {
            $condominium = (new AppCondominium())->findById($data["condominium_id"]);
            $subListUserscondominium = (new AppSession())->find("corporations_id = :corporations_id AND condominium_id = :condominium_id", "corporations_id={$this->corporations->id}&condominium_id={$condominium->id}")->count();
        } else {
            $condominium = null;
            $subListUserscondominium = 0;
        }

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
        $pager->pager($users->count(), 40, (!empty($data["page"]) ? $data["page"] : 1));

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
            "users" => $users->order("id DESC")->limit($pager->limit())->offset($pager->offset())->fetch(true),
            "paginator" => $pager->render(),

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

            "corporations" => (object)[
                "corporations" =>  $this->corporations,
                "list" => (new AppCorporations())->find()->fetch(true),
                "count" => (new AppCorporations())->find()->count()
            ],

            "condominium" => (object)[
                "condominium" => $condominium,
                "sub" => (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->fetch(true),
                "subListUsers" => (new AppSession())->find("corporations_id = :corporations_id", "corporations_id={$this->corporations->id}")->count(),
                "subListUserscondominium" => $subListUserscondominium,
                "subCount" => (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->count(),
                "list" => (new AppCondominium())->find()->fetch(true),
                "listCount" => (new AppCondominium())->find()->count(),
            ],

            "notifications" => (object)[
                "list" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->fetch(true),
                "count" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->count(),
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
            $userCreate->first_name = $first_name;
            $userCreate->last_name = $last_name;
            $userCreate->email = $data["email"];
            $userCreate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $userCreate->document_rg = preg_replace("/[^0-9]/", "", $data["document_rg"]);
            $userCreate->level = $data["level"];
            $userCreate->datebirth = date_fmt_back($data["datebirth"]);
            $userCreate->phone = preg_replace("/[^0-9]/", "", $data["phone"]);
            $userCreate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $userCreate->despatch = $data["despatch"];
            $userCreate->password = base64_encode($data["email"]);


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
    }

    public function profile(?array $data): void
    {
        if (!empty($data["condominium_id"])) {
            $condominium = (new AppCondominium())->findById($data["condominium_id"]);
            $subListUserscondominium = (new AppSession())->find("corporations_id = :corporations_id AND condominium_id = :condominium_id", "corporations_id={$this->corporations->id}&condominium_id={$condominium->id}")->count();
        } else {
            $condominium = null;
            $subListUserscondominium = 0;
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

            $userUpdate->first_name = $first_name;
            $userUpdate->last_name = $last_name;
            $userUpdate->email = $data["email"];
            $userUpdate->document = preg_replace("/[^0-9]/", "", $data["document"]);
            $userUpdate->document_rg = preg_replace("/[^0-9]/", "", $data["document_rg"]);
            $userUpdate->level = $data["level"];
            $userUpdate->datebirth = date_fmt_back($data["datebirth"]);
            $userUpdate->phone = preg_replace("/[^0-9]/", "", $data["phone"]);
            $userUpdate->phone_cell = preg_replace("/[^0-9]/", "", $data["phone_cell"]);
            $userUpdate->despatch = $data["despatch"];


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

        $userEdit = null;
        if (!empty($data["user_id"])) {
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            $userEdit = (new User())->findById($userId);
        }


        $head = $this->seo->render(
            CONF_SITE_NAME . " | Suas informações",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/users/profile", [
            "app" => "users/profile/{$userEdit->id}",
            "head" => $head,
            "user" => $this->user,
            "userEdit" => $userEdit,

            "corporations" => (object)[
                "corporations" =>  $this->corporations,
                "list" => (new AppCorporations())->find()->fetch(true),
                "count" => (new AppCorporations())->find()->count()
            ],

            "condominium" => (object)[
                "condominium" => $condominium,
                "sub" => (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->fetch(true),
                "subListUsers" => (new AppSession())->find("corporations_id = :corporations_id", "corporations_id={$this->corporations->id}")->count(),
                "subListUserscondominium" => $subListUserscondominium,
                "subCount" => (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->count(),
                "list" => (new AppCondominium())->find()->fetch(true),
                "listCount" => (new AppCondominium())->find()->count(),
            ],

            "notifications" => (object)[
                "list" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->fetch(true),
                "count" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->count(),
            ],

            "address" => null
        ]);
    }

    public function address(?array $data): void
    {
        if (!empty($data["condominium_id"])) {
            $condominium = (new AppCondominium())->findById($data["condominium_id"]);
            $subListUserscondominium = (new AppSession())->find("corporations_id = :corporations_id AND condominium_id = :condominium_id", "corporations_id={$this->corporations->id}&condominium_id={$condominium->id}")->count();
        } else {
            $condominium = null;
            $subListUserscondominium = 0;
        }

        $corporationsEdit = null;
        if (!empty($data["corporations_id"])) {
            $corporationsId = filter_var($data["corporations_id"], FILTER_VALIDATE_INT);
            $corporationsEdit = (new AppCorporations())->findById($corporationsId);
        }


        if (!empty($data["create"])) {
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

        if (!empty($data["update"])) {

            $address = (new Address())->find("user_id = :user AND id = :id",
                "user={$this->user->id}&id={$data["id"]}")->fetch();

            if (!empty ($data["association_id"])) {
                $address = (new Address());
                $address->users_id = ($data["user_id"] ?? null);
                $address->association_id = ($data["association_id"]);
                $address = (new Address())->find("association_id = :association AND id = :id",
                    "association={$address->association_id}&id={$data["id"]}")->fetch();
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

            if (!$address->save()) {
                $json["message"] = $address->message()->error("Ooops! ")->after(" {$this->user->first_name}.")->render();
                echo json_encode($json);
                return;
            }

            $json["message"] = $this->message->success("Pronto {$this->user->first_name}. Seus dados foram atualizados com sucesso!")->render();
            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Endereço do Usuário",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/corporations/address", [
            "app" => "users/address/{$corporationsEdit->id}",
            "head" => $head,
            "user" => $this->user,
            "userEdit" => $userEdit,
            "address" => (new Address())->find("users_id = :users_id", "users_id={$userEdit->id}")->fetch(),

            "corporations" => (object)[
                "corporations" =>  $this->corporations,
                "list" => (new AppCorporations())->find()->fetch(true),
                "count" => (new AppCorporations())->find()->count()
            ],

            "condominium" => (object)[
                "condominium" => $condominium,
                "sub" => (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->fetch(true),
                "subListUsers" => (new AppSession())->find("corporations_id = :corporations_id", "corporations_id={$this->corporations->id}")->count(),
                "subListUserscondominium" => $subListUserscondominium,
                "subCount" => (new AppCondominium())->find("sub_of = :sub_of", "sub_of={$this->corporations->id}")->count(),
                "list" => (new AppCondominium())->find()->fetch(true),
                "listCount" => (new AppCondominium())->find()->count(),
            ],

            "notifications" => (object)[
                "list" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->fetch(true),
                "count" => (new Notification())->find("users_id = :u", "u={$this->user->id}")->count(),
            ],

        ]);
    }
}
