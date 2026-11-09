<?php

declare(strict_types=1);

namespace Source\Controllers\Studio;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\TwoFactorService;
use Source\Services\TwoFactorNotice;
use Source\Support\Access as AccessControl;
use Source\Support\SecretBox;

/**
 * Segundo fator do Studio/Help Desk: verificação no login, configuração
 * obrigatória e gestão pelo próprio usuário.
 */
final class TwoFactor extends Controller
{
    private const VIEW_PATH = __DIR__ . "/../../../container/cms/studio/v1/";

    public function __construct()
    {
        parent::__construct(self::VIEW_PATH);
    }

    /** GET/POST /studio/login/2fa */
    public function challenge(?array $data): void
    {
        $next = $this->next($data["next"] ?? ($_GET["next"] ?? null));
        $pending = Auth::twoFactorPending();
        if (!$pending) {
            if (!empty($data["csrf"])) {
                echo json_encode(["redirect" => url($next . "/login")]);
                return;
            }
            redirect($next . "/login");
        }

        $twoFactor = new TwoFactorService();
        if (!empty($data["csrf"])) {
            if (!csrf_verify($data)) {
                echo json_encode(["message" => $this->message->error("Sessão expirada. Atualize a página e tente novamente.")->render()]);
                return;
            }
            if (request_limit("studio_2fa", 6, 300)) {
                echo json_encode(["message" => $this->message->warning("Muitas tentativas. Aguarde alguns minutos.")->render()]);
                return;
            }
            $code = (string)($data["code"] ?? "");
            $auth = new Auth();
            if ($pending->mode === "setup") {
                $codes = $auth->completeTwoFactorSetup($code, $twoFactor);
                if ($codes === null) {
                    echo json_encode(["message" => $auth->message()->render()]);
                    return;
                }
                (new \Source\Core\Session())->set("auth2faRecoveryCodes", $codes);
                // O ERP mostra os códigos na própria moldura: quem só tem erp.access não abre o Studio.
                echo json_encode(["redirect" => ($next === "/erp" ? erp_url("/seguranca/codigos") : url("/studio/seguranca/2fa?novo=1"))]);
                return;
            }
            if (!$auth->completeTwoFactor($code, $twoFactor)) {
                echo json_encode(["message" => $auth->message()->render()]);
                return;
            }
            if (!AccessControl::can($next === "/erp" ? "erp.access" : "studio.access", Auth::user())) {
                Auth::logout();
                echo json_encode(["message" => $this->message->warning("Sua conta não possui acesso a este ambiente.")->render()]);
                return;
            }
            echo json_encode(["redirect" => $next === "/erp" ? erp_url("/dashboard") : url(match ($next) { "/helpdesk" => "/helpdesk/tickets", default => "/studio/dash" })]);
            return;
        }

        $setup = null;
        if ($pending->mode === "setup") {
            if (!SecretBox::isConfigured()) {
                Auth::cancelTwoFactor();
                $this->message->error("A autenticação em dois fatores é obrigatória, mas não está configurada no servidor. Avise o administrador.")->flash();
                redirect($next . "/login");
            }
            $user = (new User())->findById((int)$pending->user);
            $secret = $twoFactor->beginSetup((int)$pending->user);
            $setup = ["secret" => $secret, "uri" => $twoFactor->provisioningUri((int)$pending->user, $secret, (string)($user->email ?? "usuario"))];
            $setup["qr"] = \Source\Support\QrCode::svg($setup["uri"], 196, "QR code para o aplicativo autenticador");
        }

        echo $this->view->render("components/login/two-factor", [
            "head" => $this->seo->render("Verificação em duas etapas - " . CONF_SITE_NAME, CONF_SITE_DESC, url("/studio/login/2fa"), themeStudio("/assets/images/favicon.png", "default")),
            "mode" => $pending->mode,
            "setup" => $setup,
            "next" => $next,
        ]);
    }

    /** GET/POST /studio/seguranca/2fa — o usuário autenticado gerencia o próprio 2FA. */
    public function manage(?array $data): void
    {
        $user = Auth::user();
        if (!$user || !AccessControl::can("studio.access", $user)) {
            Auth::logout();
            redirect("/studio/login");
        }
        $twoFactor = new TwoFactorService();
        $session = new \Source\Core\Session();

        if (!empty($data["csrf"])) {
            if (!csrf_verify($data)) {
                echo json_encode(["message" => $this->message->error("Sessão expirada. Atualize a página e tente novamente.")->render()]);
                return;
            }
            if (request_limit("studio_2fa_manage", 10, 300)) {
                echo json_encode(["message" => $this->message->warning("Muitas tentativas. Aguarde alguns minutos.")->render()]);
                return;
            }
            $action = (string)($data["action"] ?? "");
            $code = (string)($data["code"] ?? "");
            $enabled = $twoFactor->isEnabled((int)$user->id);

            if ($action === "confirm" && !$enabled) {
                $codes = $twoFactor->confirm((int)$user->id, $code);
                if ($codes === null) {
                    echo json_encode(["message" => $this->message->error("Código de verificação inválido.")->render()]);
                    return;
                }
                $session->set("auth2faRecoveryCodes", $codes);
                (new TwoFactorNotice())->send($user, TwoFactorNotice::ENABLED);
                echo json_encode(["redirect" => url("/studio/seguranca/2fa?novo=1")]);
                return;
            }
            // Ações sobre um 2FA ativo exigem um código atual (reautenticação).
            if ($enabled && in_array($action, ["disable", "recovery"], true)) {
                if (!$twoFactor->verify((int)$user->id, $code)) {
                    echo json_encode(["message" => $this->message->error("Código de verificação inválido.")->render()]);
                    return;
                }
                if ($action === "disable") {
                    if (TwoFactorService::isEnforced()) {
                        echo json_encode(["message" => $this->message->warning("A autenticação em dois fatores é obrigatória e não pode ser desativada.")->render()]);
                        return;
                    }
                    $twoFactor->disable((int)$user->id, (int)$user->id);
                    (new TwoFactorNotice())->send($user, TwoFactorNotice::DISABLED);
                    $this->message->success("Autenticação em dois fatores desativada.")->flash();
                    echo json_encode(["redirect" => url("/studio/seguranca/2fa")]);
                    return;
                }
                $session->set("auth2faRecoveryCodes", $twoFactor->regenerateRecoveryCodes((int)$user->id));
                echo json_encode(["redirect" => url("/studio/seguranca/2fa?novo=1")]);
                return;
            }
            echo json_encode(["message" => $this->message->warning("Ação não reconhecida.")->render()]);
            return;
        }

        $enabled = $twoFactor->isEnabled((int)$user->id);
        $setup = null;
        if (!$enabled && SecretBox::isConfigured()) {
            $secret = $twoFactor->beginSetup((int)$user->id);
            $setup = ["secret" => $secret, "uri" => $twoFactor->provisioningUri((int)$user->id, $secret, (string)$user->email)];
            $setup["qr"] = \Source\Support\QrCode::svg($setup["uri"], 196, "QR code para o aplicativo autenticador");
        }
        $codes = null;
        if ($session->has("auth2faRecoveryCodes")) {
            $codes = (array)$session->auth2faRecoveryCodes;
            $session->unset("auth2faRecoveryCodes");
        }

        echo $this->view->render("components/login/two-factor-manage", [
            "head" => $this->seo->render("Segurança da conta - " . CONF_SITE_NAME, CONF_SITE_DESC, url("/studio/seguranca/2fa"), themeStudio("/assets/images/favicon.png", "default")),
            "enabled" => $enabled,
            "configured" => SecretBox::isConfigured(),
            "enforced" => TwoFactorService::isEnforced(),
            "setup" => $setup,
            "recoveryCodes" => $codes,
            "remaining" => $enabled ? $twoFactor->remainingRecoveryCodes((int)$user->id) : 0,
        ]);
    }

    private function next(mixed $value): string
    {
        return match ($value) {
            "helpdesk", "/helpdesk" => "/helpdesk",
            "erp", "/erp" => "/erp",
            default => "/studio",
        };
    }
}
