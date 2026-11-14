<?php

declare(strict_types=1);

namespace Source\Controllers\App\Resident;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Services\Resident\ResidentLinks;

/**
 * Login do aplicativo do morador (ADR 0302): o mesmo Auth do Studio e do ERP (senha com limite de
 * tentativas e 2FA TOTP quando ativo ou obrigatório, concluído em /studio/login/2fa?next=app). Só entra
 * quem tem vínculo de morador válido; a conta da equipe sem vínculo recebe a mensagem e sai.
 * A conta nasce no aceite do convite (ADR 0092); não há cadastro aberto.
 */
final class AppLogin extends Controller
{
    public function __construct()
    {
        parent::__construct(moves_container_path('residents', CONF_VIEW_APP) . '/');
    }

    /** GET/POST /login */
    public function login(?array $data): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('X-Frame-Options: DENY');
        }
        $user = Auth::user();
        if ($user !== null && (new ResidentLinks())->forUser($user) !== []) {
            redirect(app_url('/'));
            return;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $this->attempt($data ?? []);
            return;
        }
        echo $this->view->render('pages/login', [
            'head' => $this->seo->render('Entrar · Connect Morador', 'Aplicativo do morador da Connect.', app_url('/login'), themeApp('/assets/images/icon-512.png'), false),
            'email' => (string)(filter_input(INPUT_COOKIE, 'authEmail') ?? ''),
        ]);
    }

    /** POST /sair — encerrar sessão altera estado, então exige CSRF. */
    public function logout(?array $data): void
    {
        if (csrf_verify($data ?? [])) {
            Auth::logout(true);
        }
        redirect(app_url('/login'));
    }

    private function attempt(array $data): void
    {
        if (!csrf_verify($data)) {
            $this->message->error('Sessão expirada. Atualize a página e tente de novo.')->flash();
            redirect(app_url('/login'));
            return;
        }
        if (request_limit('resident_app_login', 8, 300)) {
            $this->message->warning('Muitas tentativas. Aguarde alguns minutos.')->flash();
            redirect(app_url('/login'));
            return;
        }
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        if ($email === '' || $password === '') {
            $this->message->warning('Informe e-mail e senha.')->flash();
            redirect(app_url('/login'));
            return;
        }
        $auth = new Auth();
        $login = $auth->login($email, $password, !empty($data['save']), 1);
        if ($login === null && Auth::twoFactorPending()) {
            redirect(url('/studio/login/2fa?next=app'));
            return;
        }
        if (!$login) {
            $auth->message()->flash();
            redirect(app_url('/login'));
            return;
        }
        if ((new ResidentLinks())->forUser(Auth::user()) === []) {
            Auth::logout();
            $this->message->warning('Sua conta não está ligada a nenhuma unidade ativa. Fale com a administração do condomínio.')->flash();
            redirect(app_url('/login'));
            return;
        }
        redirect(app_url('/'));
    }
}
