<?php

declare(strict_types=1);

namespace Source\Controllers\Web\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Services\Erp\DocumentVerification;
use Source\Support\ClientIp;

/**
 * Verificação pública de documento emitido (ADR 0098), sem login.
 *  GET  /verificar  formulário do código
 *  POST /verificar  CSRF, limite por IP que falha fechado; responde só autêntico, tipo, data de emissão e
 *                   condomínio, ou a mensagem genérica
 * O resultado é mostrado na resposta do POST (não fica na sessão nem na URL).
 * Referrer-Policy: no-referrer, sem cache e noindex. O IP vem de ClientIp::resolve() (ADR 0023): atrás de
 * proxy confiável, cada visitante tem o seu limite, e não um limite único para todos.
 */
final class DocumentVerify extends Controller
{
    public function __construct()
    {
        parent::__construct(moves_container_path('web', CONF_VIEW_THEME) . '/');
    }

    public function form(): void
    {
        $this->headers();
        $this->page(null);
    }

    /** @param array<string, mixed> $data */
    public function verify(array $data): void
    {
        $this->headers();
        if (!self::csrfMatches((new Session())->csrf_token ?? null, $data['csrf'] ?? null)) {
            $this->page(['ok' => false, 'message' => 'Sessão expirada. Atualize a página e tente de novo.', 'document' => null]);
            return;
        }
        $code = is_string($data['code'] ?? null) ? mb_substr($data['code'], 0, 40) : '';
        $this->page((new DocumentVerification())->check($code, ClientIp::resolve()));
    }

    /** @param array{ok:bool, message:string, document:array|null}|null $result */
    private function page(?array $result): void
    {
        echo $this->view->render('pages/verify', [
            'head' => $this->seo->render('Verificar documento - ' . CONF_SITE_NAME, CONF_SITE_DESC, url('/verificar'), theme('/assets/images/share.jpg'), false),
            'result' => $result,
        ]);
    }

    /** Cabeçalhos da página pública (ADRs 0098 e 0160): sem cache, sem referrer, fora dos buscadores e sem moldura. */
    public const HEADERS = [
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'",
        'X-Content-Type-Options' => 'nosniff',
    ];

    /**
     * CSRF da página pública (ADR 0160), em tempo constante. O csrf_verify() do núcleo compara com "!=";
     * aqui vale o mesmo token de sessão, com hash_equals e só para texto.
     */
    public static function csrfMatches(mixed $sessionToken, mixed $sent): bool
    {
        return is_string($sessionToken) && $sessionToken !== '' && is_string($sent) && $sent !== '' && hash_equals($sessionToken, $sent);
    }

    private function headers(): void
    {
        if (!headers_sent()) {
            foreach (self::HEADERS as $name => $value) {
                header($name . ': ' . $value);
            }
        }
    }
}
