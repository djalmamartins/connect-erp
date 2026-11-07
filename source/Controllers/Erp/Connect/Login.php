<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Support\Access;

/**
 * Login do ERP: a mesma tela e o mesmo fluxo do Studio (Source\Models\Auth + 2FA TOTP),
 * com destino /erp/dashboard e exigência de erp.access. Não há credencial fixa nem bypass:
 * a sessão só recebe authUser depois da senha e, quando ativo ou obrigatório, do 2FA,
 * concluído em /studio/login/2fa?next=erp (Studio\TwoFactor).
 */
final class Login extends Controller
{
    private const STUDIO_VIEWS = __DIR__ . '/../../../../container/cms/studio/v1/';

    public function __construct()
    {
        parent::__construct(self::STUDIO_VIEWS);
    }

    /** GET/POST /erp/login */
    public function login(?array $data): void
    {
        if (Access::can('erp.access', Auth::user())) {
            redirect('/erp/dashboard');
            return;
        }

        if (!empty($data['csrf'])) {
            header('Content-Type: application/json; charset=utf-8');
            $data = filter_var_array($data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            if (!csrf_verify($data)) {
                echo json_encode(['message' => $this->message->error('Sessão expirada. Atualize a página e tente novamente.')->render()]);
                return;
            }
            if (request_limit('erp_login', 8, 300)) {
                echo json_encode(['message' => $this->message->warning('Muitas tentativas. Aguarde alguns minutos.')->render()]);
                return;
            }
            if (empty($data['email']) || empty($data['password'])) {
                echo json_encode(['message' => $this->message->warning('Informe e-mail e senha.')->render()]);
                return;
            }

            $auth = new Auth();
            $login = $auth->login((string)$data['email'], (string)$data['password'], !empty($data['save']), 1);
            if ($login === null && Auth::twoFactorPending()) {
                // Senha válida; a sessão só é elevada após o segundo fator.
                echo json_encode(['redirect' => url('/studio/login/2fa?next=erp')]);
                return;
            }
            if (!$login) {
                echo json_encode(['message' => $auth->message()->render()]);
                return;
            }
            if (!Access::can('erp.access', Auth::user())) {
                Auth::logout();
                echo json_encode(['message' => $this->message->warning('Sua conta não possui acesso ao ERP.')->render()]);
                return;
            }
            echo json_encode(['redirect' => url('/erp/dashboard')]);
            return;
        }

        echo $this->view->render('components/login/login', [
            'head' => $this->seo->render('Entrar · Connect ERP', 'Acesso ao ERP da administradora Connect.', url('/erp/login'), theme('/assets/images/favicon.png'), false),
            'cookie' => filter_input(INPUT_COOKIE, 'authEmail'),
            'adminBase' => '/erp',
            'loginEnvironment' => 'erp',
            'forgotUrl' => url('/erp/recuperar-senha'),
        ]);
    }

    /** POST /erp/sair — encerrar sessão altera estado, então exige CSRF. */
    public function logout(?array $data): void
    {
        if (!csrf_verify($data ?? [])) {
            redirect('/erp/dashboard');
            return;
        }
        Auth::logout();
        redirect('/erp/login');
    }

    /**
     * GET /erp/recuperar-senha — a recuperação por e-mail depende de container/mail/default
     * (template "forget") e de uma rota de redefinição, que ainda não existem. Sem esses
     * pré-requisitos a tela explica a pendência em vez de simular o envio.
     */
    public function forgot(): void
    {
        echo $this->view->render('components/login/forgot-pending', [
            'head' => $this->seo->render('Recuperar senha · Connect ERP', 'Recuperação de senha do ERP Connect.', url('/erp/recuperar-senha'), theme('/assets/images/favicon.png'), false),
            'loginEnvironment' => 'erp',
            'adminBase' => '/erp',
        ]);
    }
}
