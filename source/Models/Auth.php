<?php

namespace Source\Models;

use Source\Core\Model;
use Source\Core\Session;
use Source\Core\View;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Corporation\AppCorporations;
use Source\Models\Session\AppLog;
use Source\Support\Email;
use Source\Support\AppLogger;

/**
 * ERP | Class Auth
 *
 * @author Djalma Martins
 * @package Source\Models
 */
class Auth extends Model
{
    /**
     * Auth constructor.
     */
    public function __construct()
    {
        parent::__construct("users", ["id"], ["email", "document", "password"]);
    }

    /**
     * @return User|null
     */
    public static function user(): ?User
    {
        $session = new Session();
        if (!$session->has("authUser")) {
            return null;
        }
        return (new User())->findById($session->authUser);
    }

    public static function session(): ?AppCondominium
    {
        $session = new Session();
        if(!$session->has("authCondo")){
            return null;
        }
        return (new AppCondominium())->findById($session->authCondo);
    }

    /**
     * @return void
     */
    public static function logout(): void
    {
        $session = new Session();
        $session->unset("authUser");
        $session->unset("authCondo");
    }

    /**
     * @return void
     */
    public static function logoutCondo(): void
    {
        $session = new Session();
        $session->unset("authCondo");
    }

    /**
     * @param string $email
     * @param string $password
     * @param int $level
     * @return User|null
     */
    public function attempt(string $email, string $password, int $level = 1): ?User
    {
        if(!str_contains($email, '@')){
            $email = str_replace(array('.','-','/'), "", $email);
        }

        if (is_numeric ($email)){
            if (!is_document($email)) {
                $this->message->warning("O CPF informado não é válido");
                return null;
            }
        }elseif (!is_email($email)) {
            $this->message->warning("O e-mail  informado não é válido");
            return null;
        }

        if (!is_passwd($password)) {
            $this->message->warning("A senha informada não é válida");
            return null;
        }

        if (is_numeric ($email)){
            $user = (new User())->findByDocument($email);
            if (!$user) {
                $this->message->error("O CPF informado não está cadastrado");
                return null;
            }
        }else{
            $user = (new User())->findByEmail($email);
            if (!$user) {
                $this->message->error("O e-mail informado não está cadastrado");
                return null;
            }
        }

        if (!passwd_verify($password, $user->password)) {
            $this->message->error("A senha informada não confere");
            return null;
        }

        if ($user->level < $level) {
            $this->message->error("Desculpe, mas você não tem permissão para logar-se aqui");
            return null;
        }

        if (passwd_rehash($user->password)) {
            $user->password = $password;
            $user->save();
        }

        return $user;
    }

    /**
     * @param string $email
     * @param string $password
     * @param bool $save
     * @param int $level
     * @return bool|null
     */
    public function login(string $email, string $password, bool $save = false, int $level = 1): ?bool
    {
        $user = $this->attempt($email, $password, $level);
        if (!$user) {
            AppLogger::log('notice', 'Tentativa de autenticação não concluída', ['event_type' => 'authentication_failed', 'code' => 'AUTH_FAILED', 'identity_hash' => hash('sha256', mb_strtolower(trim($email))), 'status' => 'resolved'], 'authentication');
            return false;
        }

        if ($save) {
            setcookie("authEmail", $email, ['expires' => time() + 604800, 'path' => '/', 'secure' => $this->secureCookie(), 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            setcookie("authEmail", "", ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->secureCookie(), 'httponly' => true, 'samesite' => 'Lax']);
        }

        //LOGIN
        (new AppLog())->register($user->id, "Realizou login");
        (new Session())->regenerate()->set("authUser", $user->id);
        return true;
    }

    private function secureCookie(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * @param string $email
     * @return bool
     */
    public function forget(string $email): bool
    {
        if(!str_contains($email, '@')){
            $email = str_replace(array('.','-','/'), "", $email);
        }

        if (is_numeric ($email)){
            $user = (new User())->findByDocument($email);
            if (!$user) {
                $this->message->warning("O CPF ou CNPJ informado não está cadastrado.");
                return false;
            }
        }elseif(is_email($email)) {
            $user = (new User())->findByEmail($email);

            if (!$user) {
                $this->message->warning("O e-mail informado não está cadastrado.");
                return false;
            }
        }

        if(!empty($user)){
            $user->forget = md5(uniqid(rand(), true));
            $user->save();
        }

        $view = new View(moves_container_path('mail', CONF_VIEW_MAIL));
        $message = $view->render("forget", [
            "first_name" => $user->first_name,
            "forget_link" => url("/forget/{$user->forget}:{$user->email}")
        ]);
        (new Email())->bootstrap(
            "Recupere sua senha no " . CONF_SITE_NAME,
            $message,
            $user->email,
            "{$user->first_name} {$user->last_name}"
        )->send();
        (new AppLog())->register($user->id, "Solicitou recuperação de senha");
        return true;
    }

    /**
     * @param string $email
     * @param string $code
     * @param string $password
     * @param string $passwordRe
     * @return bool
     */
    public function reset(string $email, string $code, string $password, string $passwordRe): bool
    {
        $user = (new User())->findByEmail($email);

        if (!$user) {
            $this->message->warning("A conta para recuperação não foi encontrada.");
            return false;
        }

        if (empty($user->forget) || $user->forget != $code) {
            $this->message->error("O código de verificação não é válido.");
            return false;
        }

        if (!is_passwd($password)) {
            $min = CONF_PASSWD_MIN_LEN;
            $max = CONF_PASSWD_MAX_LEN;
            $this->message->info("Sua senha deve ter entre {$min} e {$max} caracteres.");
            return false;
        }

        if ($password != $passwordRe) {
            $this->message->warning("Você informou duas senhas diferentes.");
            return false;
        }

        $user->password = $password;
        $user->forget = null;
        if (!$user->save()) {
            $this->message->error("Não foi possível atualizar sua senha. Tente novamente.");
            return false;
        }
        return true;
    }

}
