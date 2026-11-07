<?php

namespace Source\Controllers\Web\V1;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\Session\AppLog;
use Source\Models\Session\AppSession;
use Source\Models\User;


/**
 * ERP | Class Web Controller
 *
 * @author Djalma Martins
 * @package Source\Base\Web
 */
class Web extends Controller
{
    /**
     * Web constructor.
     */
    public function __construct()
    {
        parent::__construct(moves_container_path('web', CONF_VIEW_THEMES) . "/");
    }

    /**
     * SITE HOME
     */
    public function home(): void
    {
        $head = $this->seo->render(
            CONF_SITE_NAME . " - " . CONF_SITE_TITLE,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg")
        );
        echo $this->view->render("pages/home", [
            "head" => $head,
        ]);
    }


    public function about(): void
    {
        $head = $this->seo->render(
            CONF_SITE_NAME . " - " . CONF_SITE_TITLE,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/home", [
            "head" => $head,

        ]);
    }

    /**
     * SITE LOGIN
     * @param null|array $data
     */
    public function login(?array $data): void
    {
        if(Auth::user()){
            if(Auth::user()->privacy == "reject"){
                redirect("/termos");
            }else{
                redirect("/redirect");
            }
        }

        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (request_limit("weblogin", 8, 60 * 5)) {
                $json['message'] = $this->message->error("Você já efetuou 3 tentativas, esse é o limite. Por favor, aguarde 5 minutos para tentar novamente!")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data['email']) || empty($data['password'])) {
                $json['message'] = $this->message->warning("Informe seu email e senha para entrar")->render();
                echo json_encode($json);
                return;
            }

            $save = (!empty($data['save']) ? true : false);
            $auth = new Auth();
            $login = $auth->login($data['email'], $data['password'], $save);

            if ($login) {
                    $this->message->success("Seja bem-vindo(a) " . Auth::user()->first_name . " vamos conhecer os termos de uso para utilizar o sistema.")->flash();
                    $json['redirect'] = url("/termos");
            } else {
                $json['message'] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Entrar - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/login"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/login", [
            "head" => $head,
            "cookie" => filter_input(INPUT_COOKIE, "authEmail")
        ]);
    }

    /**
     * SITE PASSWORD FORGET
     * @param null|array $data
     */
    public function forget(?array $data)
    {
        if (Auth::user()) {
            redirect("/redirect");
        }

        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data["email"])) {
                $json['message'] = $this->message->info("Informe seu e-mail para continuar")->render();
                echo json_encode($json);
                return;
            }

            if (request_repeat("webforget", $data["email"])) {
                $json['message'] = $this->message->error("Ooops! Você já tentou este e-mail antes")->render();
                echo json_encode($json);
                return;
            }

            $auth = new Auth();
            if ($auth->forget($data["email"])) {
                $json["message"] = $this->message->success("Acesse seu e-mail para recuperar a senha")->render();
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Recuperar Senha - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/forget"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/forget", [
            "head" => $head
        ]);
    }

    /**
     * SITE FORGET RESET
     * @param array $data
     */
    public function reset(array $data): void
    {
        if (Auth::user()) {
            redirect("/redirect");
        }
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data["password"]) || empty($data["password_re"])) {
                $json["message"] = $this->message->info("Informe e repita a senha para continuar")->render();
                echo json_encode($json);
                return;
            }

            list($code, $email) = explode(":", $data["code"]);
            $auth = new Auth();

            if ($auth->reset($email, $code, $data["password"], $data["password_re"])) {
                $this->message->success("Senha alterada com sucesso.")->flash();
                $json["redirect"] = url("/login");
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Crie sua nova senha no " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/forget"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/reset", [
            "head" => $head,
            "code" => $data["code"]
        ]);
    }

    /**
     * SITE CONFIRM
     * @param array $data
     */
    public function confirmation(array $data): void
    {
        if (Auth::user()) {
            redirect("/redirect");
        }

        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data["password"]) || empty($data["password_re"])) {
                $json["message"] = $this->message->info("Informe e repita a senha para continuar")->render();
                echo json_encode($json);
                return;
            }

            list($code, $email) = explode(":", $data["code"]);
            $auth = new Auth();

            if ($auth->reset($email, $code, $data["password"], $data["password_re"])) {
                $user = (new User())->findByEmail($email);
                if ($user && $user->status != "confirmed") {
                    $user->status = "confirmed";
                    $user->save();
                }
                $this->message->success("Senha cadastrada com sucesso :)")->flash();
                $json["redirect"] = url("/login");
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Crie sua nova senha no " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/forget"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/confirmation", [
            "head" => $head,
            "code" => $data["code"]
        ]);
    }

    /**
     * SITE TERMS
     */
    public function terms(array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar nosso serviços.")->flash();
            redirect("/login");
        }

        if ($this->user->privacy == "accept") {
                $this->message->success("Seja bem-vindo(a) de volta " . Auth::user()->first_name . "!")->flash();
                redirect("/redirect");
        }

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data['confirm'])) {
                $json['message'] = $this->message->error("Você precisa aceitar os termos e política de privacidade.")->render();
                echo json_encode($json);
                return;
            }

            if ($this->user && $this->user->privacy != "accept") {
                $this->user->privacy = "accept";
                $this->user->save();
            }

            $this->message->success("Seja bem-vindo(a) " . Auth::user()->first_name . "!")->flash();
            $json['redirect'] = url("/redirect");

        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " - Termos de uso",
            CONF_SITE_DESC,
            url("/termos"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("pages/login/terms", [
            "head" => $head
        ]);
    }

    public function privacy(array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar nosso serviços.")->flash();
            redirect("/login");
        }

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data['confirm'])) {
                $json['message'] = $this->message->error("Você precisa aceitar os termos e política de privacidade.")->render();
                echo json_encode($json);
                return;
            }

            if ($this->user && $this->user->privacy != "accept") {
                $this->user->privacy = "accept";

                if(!empty($this->user->save())){
                    $this->message->success("Seja bem-vindo(a) " . Auth::user()->first_name . "!")->flash();
                    redirect("/redirect");
                }
            }
        }
    }

    public function redirect(array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar nosso serviços.")->flash();
            redirect("/login");
        }

        if($this->user->level > 1){
            redirect("/app");
        }else{
            redirect("/");
        }

    }

    /**
     * SITE NAV ERROR
     * @param array $data
     */
    public function error(array $data): void
    {
        $error = new \stdClass();

        $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $requestedError = (string)($data['errcode'] ?? '404');
        $httpStatus = in_array((int)$requestedError, [403, 404, 500], true) ? (int)$requestedError : (in_array($requestedError, ['problemas', 'manutencao'], true) ? 503 : 404);
        http_response_code($httpStatus);

        switch ($data['errcode']) {
            case "problemas":
                $error->code = "OPS";
                $error->title = "Estamos enfrentando problemas!";
                $error->message = "Parece que nosso serviço não está diponível no momento. <br> Já estamos vendo isso mas caso precise, envie um e-mail :)";
                $error->linkTitle = "ENVIAR E-MAIL";
                $error->link = "mailto:" . CONF_MAIL_SUPPORT;
                break;

            case "manutencao":
                $error->code = "OPS";
                $error->title = "Desculpe. Estamos em manutenção!";
                $error->message = "Voltamos logo! Por hora estamos trabalhando para melhorar nosso conteúdo para você controlar melhor as suas contas :P";
                $error->linkTitle = null;
                $error->link = null;
                break;

            default:
                $error->code = $data['errcode'];
                $error->title = "Ooops. Conteúdo indispinível :/";
                $error->message = "Sentimos muito, mas o conteúdo que você tentou acessar não existe, está indisponível no momento ou foi removido :/";
                $error->linkTitle = "Continue navegando!";
                $error->link = url_back();
                break;
        }

        $head = $this->seo->render(
            "{$error->code} | {$error->title}",
            $error->message,
            url("/ops/{$error->code}"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("pages/error", [
            "head" => $head,
            "error" => $error
        ]);
    }
}
