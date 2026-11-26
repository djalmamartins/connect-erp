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
 * A tela é a mesma do ERP (components/login/login do Studio, ADR 0657): foto do prédio, dicas de
 * segurança e o cartão. O formulário manda por AJAX (login.js) e recebe JSON; sem JavaScript,
 * o POST normal continua com redirecionamento.
 */
final class AppLogin extends Controller
{
    private const STUDIO_VIEWS = __DIR__ . '/../../../../container/cms/studio/v1/';

    public function __construct()
    {
        parent::__construct(self::STUDIO_VIEWS);
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
        $notice = $user !== null ? self::notResidentNotice($user) : null;
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $this->attempt($data ?? []);
            return;
        }
        echo $this->view->render('components/login/login', [
            'head' => $this->seo->render('Entrar · Connect Morador', 'Aplicativo do morador da Connect.', app_url('/login'), themeApp('/assets/images/icon-512.png'), false),
            'cookie' => (string)(filter_input(INPUT_COOKIE, 'authEmail') ?? ''),
            'adminBase' => rtrim(app_base(), '/'),
            'loginEnvironment' => 'app',
            'loginNotice' => $notice,
            'forgotUrl' => app_url('/recuperar-senha'),
        ]);
    }

    /**
     * GET /recuperar-senha — a recuperação do núcleo (/forget, ADR 0009/0655). Fora do host do app,
     * ?de=app faz a tela mostrar "CONNECT MORADOR" e voltar para o login do app.
     */
    public function forgot(): void
    {
        redirect(app_host() ? '/forget' : '/forget?de=app');
    }

    /** Aviso para quem já está logado (ex.: no ERP) com uma conta sem unidade de morador. */
    public static function notResidentNotice(object $user): string
    {
        $name = trim((string)($user->first_name ?? ''));
        return ($name !== '' ? "Olá, {$name}! " : 'Olá! ')
            . 'A conta com que você entrou no sistema não está cadastrada como morador de nenhuma unidade. '
            . 'Se você mora num condomínio atendido pela Connect, entre com a sua conta de morador ou peça o convite à administração.';
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
        $data = sanitize_keep_password($data);
        if (!csrf_verify($data)) {
            $this->answer('error', 'Sessão expirada. Atualize a página e tente de novo.');
            return;
        }
        if (request_limit('resident_app_login', 8, 300)) {
            $this->answer('warning', 'Muitas tentativas. Aguarde alguns minutos.');
            return;
        }
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        if ($email === '' || $password === '') {
            $this->answer('warning', 'Informe e-mail e senha.');
            return;
        }
        $auth = new Auth();
        $login = $auth->login($email, $password, !empty($data['save']), 1);
        if ($login === null && Auth::twoFactorPending()) {
            $this->go(url('/studio/login/2fa?next=app'));
            return;
        }
        if (!$login) {
            if (self::wantsJson()) {
                $this->json(['message' => $auth->message()->render()]);
                return;
            }
            $auth->message()->flash();
            redirect(app_url('/login'));
            return;
        }
        if ((new ResidentLinks())->forUser(Auth::user()) === []) {
            Auth::logout();
            $this->answer('warning', 'Sua conta não está ligada a nenhuma unidade ativa. Fale com a administração do condomínio.');
            return;
        }
        $this->go(app_url('/'));
    }

    /** O formulário da tela do ERP manda por AJAX (login.js, X-Requested-With). */
    private static function wantsJson(): bool
    {
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    private function answer(string $type, string $text): void
    {
        if (self::wantsJson()) {
            $this->json(['message' => $this->message->{$type}($text)->render()]);
            return;
        }
        $this->message->{$type}($text)->flash();
        redirect(app_url('/login'));
    }

    private function go(string $to): void
    {
        if (self::wantsJson()) {
            $this->json(['redirect' => $to]);
            return;
        }
        redirect($to);
    }

    /** @param array<string, string> $body */
    private function json(array $body): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($body);
    }
}
