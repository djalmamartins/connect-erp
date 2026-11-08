<?php

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\Erp\AppWallet;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\Session\AppSession;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Access as AccessControl;

/**
 * ERP | Class Erp
 *
 * @author Djalma Martins
 * @package Source\Controllers\Erp\Connect
 */
class Erp extends Controller
{
    /**
     * @var \Source\Models\User
     */
    protected $user;

    /**
     * Erp constructor.
     */
    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . "/");

        $this->user = Auth::user();
        if (!$this->user) {
            redirect(erp_url('/login'));
            return;
        }

        (new Access())->report();
        (new Online())->report();

        $sessionUser = (new AppSession())->find("users_id = :u", "u={$this->user->id}")->fetch();

        if (!Auth::session()) {
            $this->condo = null;
        }

        if (Auth::session()) {
            $this->condo = Auth::session();
        }

        if (!$sessionUser || !AccessControl::can("erp.access", $this->user)) {
            $this->message->warning("{$this->user->first_name}, você precisa de permissão, entre em contato com o administrador do sistema.")->flash();
            Auth::logout();
            redirect(erp_url('/login'));
        }
    }

    /**
     * Admin access redirect
     */
    public function root(): void
    {
        redirect("/erp/dash");
    }

    /**
     * Escolhe o condomínio da sessão (issue #7, risco 7): só aceita um id pedido ou salvo
     * que o usuário pode acessar, e nunca cai num condomínio padrão. $canAccess recebe o id
     * e responde se está no escopo (CondominiumScope::canAccess); erro conta como negado.
     *
     * @param callable(int):bool $canAccess
     */
    public static function resolveCondominium(mixed $requested, mixed $saved, callable $canAccess): ?int
    {
        foreach ([$requested, $saved] as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                continue;
            }
            try {
                return $canAccess($id) ? $id : null;
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    public function dash(?array $data): void
    {
        $data = filter_var_array($data ?? [], FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];
        $requested = $data["condominium_id"] ?? null;
        $scope = new CondominiumScope();
        $id = self::resolveCondominium($requested, $this->user->session_condo ?? null,
            fn (int $id): bool => $scope->canAccess($this->user, $id));

        if ($id === null) {
            if (!empty($requested)) {
                $this->message->warning("Condomínio indisponível para a sua conta.")->flash();
            }
            (new Session())->unset("authCondo");
            redirect("/erp/dash/home");
            return;
        }

        Auth::logoutCondo();
        (new Session())->set("authCondo", sprintf("%04d", $id));
        $this->condo = Auth::session();

        if (!empty($requested)) {
            $this->message->success("Condomínio alterado com sucesso...")->flash();
            $userOpt = (new User())->findById($this->user->id);
            $userOpt->session_condo = $id;
            if (!$userOpt->save()) {
                $json["message"] = $userOpt->message()->render();
                echo json_encode($json);
                return;
            }
            if ($this->condo) {
                (new AppWallet())->start($this->condo);
            }
        }

        redirect("/erp/condo/home");
    }
}
