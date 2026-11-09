<?php

declare(strict_types=1);

namespace Source\Controllers\Web\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Services\Erp\ResidentInvitationAcceptance;
use Source\Services\Erp\ResidentInvitations;

/**
 * Aceite do convite do morador (AP-152, ADR 0092), página pública.
 *  GET  /convite#token   (link do e-mail, ADR 0112) o token fica depois do "#", não chega ao servidor nem ao log;
 *                         a página o tira do endereço e manda por POST /convite/abrir
 *  POST /convite/abrir    CSRF, confere o formato, guarda o token só na sessão e redireciona para /convite
 *  GET  /convite/{token}  formato antigo (o caminho pode ir para o log de acesso): mesmo efeito do POST
 *  GET  /convite          formulário (nome e e-mail mascarado), ou a mensagem genérica
 *  POST /convite          CSRF, limite por IP e por token, aceite atômico, cria a conta e manda para o login
 * Não faz login, não mexe no 2FA nem na sessão do Studio. Referrer-Policy: no-referrer e sem cache.
 */
final class ResidentInvite extends Controller
{
    private const SESSION_KEY = 'resident_invite_token';

    public function __construct()
    {
        parent::__construct(moves_container_path('web', CONF_VIEW_THEME) . '/');
    }

    /** @param array{token?:string} $data */
    public function open(array $data): void
    {
        $this->headers();
        $token = (string)($data['token'] ?? '');
        $session = new Session();
        if (ResidentInvitations::hashOf($token) !== null) {
            $session->set(self::SESSION_KEY, $token);
        } else {
            $session->unset(self::SESSION_KEY);
        }
        redirect('/convite');
    }

    /** @param array<string, mixed> $data POST /convite/abrir (ADR 0112) */
    public function claim(array $data): void
    {
        $this->headers();
        $session = new Session();
        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        if (csrf_verify($data) && ResidentInvitations::hashOf($token) !== null) {
            $session->set(self::SESSION_KEY, $token);
        } else {
            $session->unset(self::SESSION_KEY);
        }
        redirect('/convite');
    }

    public function form(): void
    {
        $this->headers();
        if (Auth::user()) {
            redirect('/redirect');
            return;
        }
        $session = new Session();
        $preview = (new ResidentInvitationAcceptance())->preview($session->{self::SESSION_KEY} ?? null);
        if ($preview === null) {
            $session->unset(self::SESSION_KEY);
        }
        echo $this->view->render('pages/login/invite', [
            'head' => $this->seo->render('Ative sua conta no ' . CONF_SITE_NAME, CONF_SITE_DESC, url('/convite'), theme('/assets/images/share.jpg'), false),
            'invite' => $preview,
            'generic' => ResidentInvitationAcceptance::GENERIC,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function accept(array $data): void
    {
        $this->headers();
        if (!csrf_verify($data)) {
            $this->message->error('Sessão expirada. Atualize a página e tente de novo.')->flash();
            redirect('/convite');
            return;
        }
        $session = new Session();
        $token = $session->{self::SESSION_KEY} ?? null;
        $result = (new ResidentInvitationAcceptance())->accept($token, (string)($data['document'] ?? ''), (string)($data['password'] ?? ''),
            (string)($data['password_re'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null);
        if ($result['ok']) {
            $session->unset(self::SESSION_KEY);
            $this->message->success($result['message'])->flash();
            redirect('/studio/login');
            return;
        }
        $this->message->warning($result['message'])->flash();
        redirect('/convite');
    }

    private function headers(): void
    {
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex, nofollow');
        }
    }
}
