<?php

namespace Source\Models;

use Source\Core\Connect;
use Source\Core\Model;
use Source\Core\Session;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Corporation\AppCorporations;
use Source\Models\Session\AppLog;
use Source\Support\Email;
use Source\Support\AppLogger;
use Source\Support\ClientIp;
use Source\Support\MailTemplate;
use Source\Services\PasswordResetService;
use Source\Services\TwoFactorService;
use Source\Services\LoginThrottle;
use Source\Services\SessionRevocation;
use Source\Services\TwoFactorNotice;

/**
 * ERP | Class Auth
 *
 * @author Djalma Martins
 * @package Source\Models
 */
class Auth extends Model
{
    /**
     * Hash bcrypt (custo 10, igual a CONF_PASSWD_OPTION) de um valor aleatório descartado.
     * Usuário inexistente também paga um password_verify, para que o tempo de resposta não
     * revele quais e-mails/CPFs estão cadastrados (REVIEW_FASE1_2FA.md, risco 3; issue #7).
     */
    public const DUMMY_PASSWORD_HASH = '$2y$10$8Q6tEFGQfJcJ5BoH9z1zZu/IECF5s4bIFOtl9JuUW29DodW5zHrlW';

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
    /**
     * Usuário só desta requisição (ADR 0303): o app do morador autentica pelo token Bearer, sem sessão nem
     * cookie. Quem chama é o ResidentApiAuthenticated, depois de validar o token. Nunca vai para a sessão.
     */
    private static ?User $actingUser = null;

    public static function actingAs(?User $user): void
    {
        self::$actingUser = $user;
    }

    /** Id do usuário da requisição autenticada por token, para a auditoria (null fora do app). */
    public static function actingUserId(): ?int
    {
        return self::$actingUser !== null ? (int)self::$actingUser->id : null;
    }

    public static function user(): ?User
    {
        if (self::$actingUser !== null) {
            return self::$actingUser;
        }
        $session = new Session();
        if (!$session->has("authUser")) {
            return null;
        }
        // Logout feito em outro host (ADR 0036): a sessão deste host também deixa de valer.
        // Sessão sem o instante do login (anterior a esta regra ou criada por outro fluxo, como a
        // troca de ambiente) é marcada agora e passa a ser revogável a partir daqui.
        if (!$session->has(self::AUTH_AT)) {
            $session->set(self::AUTH_AT, SessionRevocation::nowMs());
        }
        if ((new SessionRevocation())->isRevoked((int)$session->authUser, (int)$session->{self::AUTH_AT})) {
            self::logout();
            return null;
        }
        return (new User())->findById($session->authUser);
    }

    /** Instante do login (ms) guardado na sessão, comparado com o logout global (ADR 0036). */
    public const AUTH_AT = "authAt";

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
    /**
     * Encerra a sessão deste host. Com $everywhere (o botão "Sair"), encerra também as sessões
     * do usuário nos outros hosts (ADR 0036). Logouts forçados por falta de permissão não usam
     * $everywhere, para não derrubar o usuário de um ambiente em que ele tem acesso.
     */
    public static function logout(bool $everywhere = false): void
    {
        $session = new Session();
        if ($everywhere && $session->has("authUser")) {
            (new SessionRevocation())->revokeAll((int)$session->authUser);
        }
        $session->unset(self::AUTH_AT);
        $session->unset("authUser");
        $session->unset("authCondo");
        $session->unset(self::PENDING_2FA);
    }

    public const PENDING_2FA = "auth2faPending";
    private const PENDING_TTL = 300;
    private const PENDING_ATTEMPTS = 5;

    /**
     * Login aguardando o segundo fator. Enquanto existir, a sessão NÃO tem
     * authUser, então nenhuma rota autenticada fica acessível.
     *
     * @return object{user:int,mode:string,expires:int,attempts:int,save:bool,email:string}|null
     */
    public static function twoFactorPending(): ?object
    {
        $session = new Session();
        if (!$session->has(self::PENDING_2FA)) {
            return null;
        }
        $pending = (object)$session->{self::PENDING_2FA};
        if ((int)($pending->expires ?? 0) < time() || (int)($pending->attempts ?? 0) >= self::PENDING_ATTEMPTS) {
            $session->unset(self::PENDING_2FA);
            return null;
        }
        return $pending;
    }

    public static function cancelTwoFactor(): void
    {
        (new Session())->unset(self::PENDING_2FA);
    }

    /**
     * Conclui o login pendente com um código TOTP ou de recuperação.
     */
    public function completeTwoFactor(string $code, ?TwoFactorService $twoFactor = null, ?LoginThrottle $throttle = null): bool
    {
        $pending = self::twoFactorPending();
        if (!$pending || $pending->mode !== "verify") {
            $this->message->error("Sua verificação expirou. Entre novamente com e-mail e senha.");
            return false;
        }
        $twoFactor ??= new TwoFactorService();
        $userId = (int)$pending->user;
        $throttle ??= new LoginThrottle();
        $subjects = self::twoFactorSubjects($userId);
        if ($throttle->blocked(LoginThrottle::TWO_FACTOR, $subjects)) {
            $this->message->error(self::THROTTLED_MESSAGE);
            return false;
        }
        $code = trim($code);
        $valid = preg_match('/^\d{6}$/', preg_replace('/\s/', '', $code) ?? '')
            ? $twoFactor->verify($userId, $code)
            : $twoFactor->verifyRecoveryCode($userId, $code);
        if (!$valid) {
            $throttle->fail(LoginThrottle::TWO_FACTOR, $subjects);
            $session = new Session();
            $pending->attempts = (int)$pending->attempts + 1;
            $session->set(self::PENDING_2FA, (array)$pending);
            $this->message->error("Código de verificação inválido.");
            return false;
        }
        $user = (new User())->findById($userId);
        if (!$user) {
            self::cancelTwoFactor();
            $this->message->error("Sua verificação expirou. Entre novamente com e-mail e senha.");
            return false;
        }
        $throttle->clear(LoginThrottle::TWO_FACTOR, $subjects);
        $this->finishLogin($user, (string)$pending->email, (bool)$pending->save);
        return true;
    }

    /**
     * Ativa o 2FA durante um login pendente de configuração obrigatória e conclui o login.
     *
     * @return list<string>|null códigos de recuperação
     */
    public function completeTwoFactorSetup(string $code, ?TwoFactorService $twoFactor = null, ?LoginThrottle $throttle = null): ?array
    {
        $pending = self::twoFactorPending();
        if (!$pending || $pending->mode !== "setup") {
            $this->message->error("Sua verificação expirou. Entre novamente com e-mail e senha.");
            return null;
        }
        $twoFactor ??= new TwoFactorService();
        $throttle ??= new LoginThrottle();
        $subjects = self::twoFactorSubjects((int)$pending->user);
        if ($throttle->blocked(LoginThrottle::TWO_FACTOR, $subjects)) {
            $this->message->error(self::THROTTLED_MESSAGE);
            return null;
        }
        $codes = $twoFactor->confirm((int)$pending->user, $code);
        if ($codes === null) {
            $throttle->fail(LoginThrottle::TWO_FACTOR, $subjects);
            $pending->attempts = (int)$pending->attempts + 1;
            (new Session())->set(self::PENDING_2FA, (array)$pending);
            $this->message->error("Código de verificação inválido.");
            return null;
        }
        $user = (new User())->findById((int)$pending->user);
        if (!$user) {
            self::cancelTwoFactor();
            return null;
        }
        $throttle->clear(LoginThrottle::TWO_FACTOR, $subjects);
        $this->finishLogin($user, (string)$pending->email, (bool)$pending->save);
        (new TwoFactorNotice())->send($user, TwoFactorNotice::ENABLED_AT_LOGIN);
        return $codes;
    }

    /**
     * @return void
     */
    public static function logoutCondo(): void
    {
        $session = new Session();
        $session->unset("authCondo");
    }

    /** Mensagem única para bloqueio por conta/IP: não diz qual dos dois atingiu o limite. */
    public const THROTTLED_MESSAGE = "Muitas tentativas. Aguarde alguns minutos e tente novamente.";

    /** E-mail em minúsculas ou CPF/CNPJ só com dígitos, como attempt() procura a conta. */
    public static function throttleAccount(string $login): string
    {
        $login = mb_strtolower(trim($login));
        return str_contains($login, '@') ? $login : str_replace(['.', '-', '/'], '', $login);
    }

    private static function twoFactorSubjects(int $userId): array
    {
        return LoginThrottle::subjects('user:' . $userId, ClientIp::resolve());
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
                passwd_verify($password, self::DUMMY_PASSWORD_HASH);
                $this->message->error("Credenciais inválidas. Confira os dados e tente novamente.");
                return null;
            }
        }else{
            $user = (new User())->findByEmail($email);
            if (!$user) {
                passwd_verify($password, self::DUMMY_PASSWORD_HASH);
                $this->message->error("Credenciais inválidas. Confira os dados e tente novamente.");
                return null;
            }
        }

        if (!passwd_verify($password, $user->password)) {
            $this->message->error("Credenciais inválidas. Confira os dados e tente novamente.");
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
    public function login(string $email, string $password, bool $save = false, int $level = 1, ?TwoFactorService $twoFactor = null, ?LoginThrottle $throttle = null): ?bool
    {
        self::cancelTwoFactor();
        $throttle ??= new LoginThrottle();
        $subjects = LoginThrottle::subjects(self::throttleAccount($email), ClientIp::resolve());
        if ($throttle->blocked(LoginThrottle::LOGIN, $subjects)) {
            $this->message->error(self::THROTTLED_MESSAGE);
            return false;
        }
        $user = $this->attempt($email, $password, $level);
        if (!$user) {
            $throttle->fail(LoginThrottle::LOGIN, $subjects);
            AppLogger::log('notice', 'Tentativa de autenticação não concluída', ['event_type' => 'authentication_failed', 'code' => 'AUTH_FAILED', 'identity_hash' => hash('sha256', mb_strtolower(trim($email))), 'status' => 'resolved'], 'authentication');
            return false;
        }

        $throttle->clear(LoginThrottle::LOGIN, $subjects);
        $mode = $this->secondFactorMode((int)$user->id, $twoFactor ?? new TwoFactorService());
        if ($mode === "unavailable") {
            $this->message->error("O acesso está temporariamente indisponível. Tente novamente em alguns minutos.");
            return false;
        }
        if ($mode !== null) {
            // Senha correta, mas a sessão só é elevada depois do segundo fator.
            (new Session())->regenerate()->set(self::PENDING_2FA, [
                "user" => (int)$user->id, "mode" => $mode, "expires" => time() + self::PENDING_TTL,
                "attempts" => 0, "save" => $save, "email" => $email,
            ]);
            $this->message->info($mode === "verify" ? "Informe o código do seu aplicativo autenticador." : "Configure a autenticação em dois fatores para continuar.");
            return null;
        }

        $this->finishLogin($user, $email, $save);
        return true;
    }

    /** "verify" (2FA ativo), "setup" (obrigatório e não configurado), "unavailable" (obrigatório sem tabela) ou null. */
    private function secondFactorMode(int $userId, TwoFactorService $twoFactor): ?string
    {
        try {
            if ($twoFactor->isEnabled($userId)) {
                return "verify";
            }
        } catch (\PDOException $error) {
            // Antes de aplicar a migration 20261107_0001 a tabela não existe; qualquer outro erro bloqueia o login.
            if ($error->getCode() !== '42S02') {
                throw $error;
            }
            AppLogger::log('error', 'Tabela de 2FA ausente: aplique as migrations', ['event_type' => 'two_factor', 'code' => 'TWO_FACTOR_SCHEMA_MISSING', 'status' => 'open'], 'authentication');
            return self::modeWhenTwoFactorSchemaMissing();
        }
        // Conta só de morador fica fora da obrigatoriedade; liga o 2FA no perfil se quiser (ADR 0304).
        return \Source\Services\Resident\ResidentTwoFactor::enforcedFor($userId) ? "setup" : null;
    }

    /**
     * Tabela do 2FA ausente: com o 2FA obrigatório o login falha fechado ("unavailable");
     * antes da data de obrigatoriedade segue sem 2FA, como antes (REVIEW_FASE1_2FA.md, risco 2).
     */
    public static function modeWhenTwoFactorSchemaMissing(?int $now = null): ?string
    {
        return TwoFactorService::isEnforced($now) ? "unavailable" : null;
    }

    private function finishLogin(User $user, string $email, bool $save): void
    {
        self::cancelTwoFactor();
        if ($save) {
            setcookie("authEmail", $email, ['expires' => time() + 604800, 'path' => '/', 'secure' => $this->secureCookie(), 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            setcookie("authEmail", "", ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->secureCookie(), 'httponly' => true, 'samesite' => 'Lax']);
        }

        //LOGIN
        (new AppLog())->register($user->id, "Realizou login");
        (new Session())->regenerate()->set("authUser", $user->id)->set(self::AUTH_AT, SessionRevocation::nowMs() + 1);
    }

    private function secureCookie(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * Pedido de recuperação de senha por e-mail ou CPF/CNPJ.
     *
     * Retorna true para qualquer identificador bem formado, exista ou não a conta,
     * para não revelar cadastros. Só retorna false quando o formato é inválido.
     * O link leva apenas o token (o e-mail não vai na URL); o banco guarda o hash.
     */
    public function forget(string $email): bool
    {
        $login = trim($email);
        if (!str_contains($login, '@')) {
            $login = str_replace(['.', '-', '/'], '', $login);
        }

        if (is_numeric($login)) {
            $user = (new User())->findByDocument($login);
        } elseif (is_email($login)) {
            $user = (new User())->findByEmail($login);
        } else {
            $this->message->warning("Informe um e-mail ou CPF/CNPJ válido.");
            return false;
        }

        if (!$user) {
            AppLogger::log('notice', 'Recuperação de senha para conta inexistente', [
                'event_type' => 'password_reset', 'code' => 'PASSWORD_RESET_UNKNOWN_ACCOUNT', 'status' => 'resolved',
            ], 'authentication');
            return true;
        }

        $token = (new PasswordResetService())->issue((int)$user->id, $_SERVER['REMOTE_ADDR'] ?? null);
        $message = (new MailTemplate())->render("forget", [
            "first_name" => $user->first_name,
            "forget_link" => url("/forget/{$token}")
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

    /** O token de recuperação ainda pode ser usado (exibição do formulário). */
    public function resetTokenIsValid(string $token): bool
    {
        return (new PasswordResetService())->findValidUserId($token) !== null;
    }

    /**
     * Troca a senha com um token de recuperação. O consumo do token e a gravação
     * da senha ficam na mesma transação: se a senha não for salva, o token continua válido.
     */
    public function resetWithToken(string $token, string $password, string $passwordRe): bool
    {
        if (!is_passwd($password)) {
            $min = CONF_PASSWD_MIN_LEN;
            $max = CONF_PASSWD_MAX_LEN;
            $this->message->info("Sua senha deve ter entre {$min} e {$max} caracteres.");
            return false;
        }
        if ($password !== $passwordRe) {
            $this->message->warning("Você informou duas senhas diferentes.");
            return false;
        }

        $pdo = Connect::getInstance();
        $pdo->beginTransaction();
        try {
            $userId = (new PasswordResetService($pdo))->consume($token);
            $user = $userId !== null ? (new User())->findById($userId) : null;
            if (!$user) {
                $pdo->rollBack();
                $this->message->error("O link de recuperação é inválido ou expirou. Solicite um novo.");
                return false;
            }
            $user->password = $password;
            $user->forget = null;
            if (!$user->save()) {
                $pdo->rollBack();
                $this->message->error("Não foi possível atualizar sua senha. Tente novamente.");
                return false;
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            AppLogger::exception($exception, 'authentication', ['event_type' => 'password_reset_failed']);
            $this->message->error("Não foi possível atualizar sua senha. Tente novamente.");
            return false;
        }
        (new AppLog())->register((int)$user->id, "Redefiniu a senha por recuperação");
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
