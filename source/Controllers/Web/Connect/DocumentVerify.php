<?php

declare(strict_types=1);

namespace Source\Controllers\Web\Connect;

use Source\Core\Controller;
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
        if (!csrf_verify($data)) {
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

    private function headers(): void
    {
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex, nofollow');
        }
    }
}
