<?php

declare(strict_types=1);

namespace Source\Controllers\Web\Connect;

use Source\Core\Connect;
use Source\Core\View;
use Source\Services\Erp\AccountabilityBookDocument;
use Source\Services\Erp\AccountabilityLinks;
use Source\Support\AppLogger;

/**
 * Link público da prestação de contas (ADR 0301), sem login, em qualquer host:
 *  GET /prestacao/{token}                        o livro do mês ou do ano, com a identidade da Connect
 *  GET /prestacao/{token}/documento/{attachment} um documento do livro (comprovante, conta, nota fiscal...)
 *  GET /prestacao/{token}/pdf                    o livro em PDF (ADR 0306), com o endereço de cada documento
 *
 * Token inválido, revogado ou desconhecido dá a mesma página 404 (não diz se o link existiu). Sem cache, sem
 * referrer (o token não vaza para os sites dos documentos), fora dos buscadores e sem moldura. CSP sem script
 * externo: só o pequeno script do botão de imprimir, com nonce. Limite de acessos por sessão.
 */
final class AccountabilityLinkPublic
{
    public const VIEW_LIMIT = 120;
    public const FILE_LIMIT = 300;
    public const PDF_LIMIT = 20;
    public const WINDOW = 600;
    /** Tipos que o navegador abre na própria aba (os demais baixam). */
    public const INLINE = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** Cabeçalhos da página (regra pura, para teste). */
    public static function headers(string $nonce): array
    {
        return [
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ];
    }

    /** @param array{token?:string} $data */
    public function show(array $data): void
    {
        $nonce = bin2hex(random_bytes(12));
        $this->send(self::headers($nonce));
        $token = (string)($data['token'] ?? '');
        if (request_limit('accountability_link_view', self::VIEW_LIMIT, self::WINDOW)) {
            $this->page(429, null, $token, $nonce);
            return;
        }
        $view = null;
        if (AccountabilityLinks::validToken($token) && !Connect::databaseDisabled()) {
            try {
                $view = (new AccountabilityLinks())->publicView($token);
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'erp', ['event_type' => 'accountability_link_view_failed']);
                $this->page(503, null, $token, $nonce);
                return;
            }
        }
        $this->page($view === null ? 404 : 200, $view, $token, $nonce);
    }

    /** @param array{token?:string, attachment?:string} $data */
    public function file(array $data): void
    {
        $token = (string)($data['token'] ?? '');
        $attachment = filter_var($data['attachment'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->send(['Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
        if (request_limit('accountability_link_file', self::FILE_LIMIT, self::WINDOW)) {
            http_response_code(429);
            echo 'Muitos downloads em pouco tempo. Aguarde alguns minutos.';
            return;
        }
        $file = null;
        if ($attachment !== false && AccountabilityLinks::validToken($token) && !Connect::databaseDisabled()) {
            try {
                $file = (new AccountabilityLinks())->publicFile($token, (int)$attachment);
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'erp', ['event_type' => 'accountability_link_file_failed']);
            }
        }
        if ($file === null) {
            http_response_code(404);
            echo 'Documento não encontrado.';
            return;
        }
        $inline = in_array($file['mime'], self::INLINE, true);
        $ascii = (string)preg_replace('/[^A-Za-z0-9._ -]+/', '_', $file['name']);
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . $file['size']);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; object-src 'self'; plugin-types application/pdf; sandbox");
        readfile($file['path']);
    }

    /** @param array{token?:string} $data */
    public function pdf(array $data): void
    {
        $token = (string)($data['token'] ?? '');
        $this->send(['Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
        if (request_limit('accountability_link_pdf', self::PDF_LIMIT, self::WINDOW)) {
            http_response_code(429);
            echo 'Muitos downloads em pouco tempo. Aguarde alguns minutos.';
            return;
        }
        $view = null;
        $pdf = null;
        if (AccountabilityLinks::validToken($token) && !Connect::databaseDisabled()) {
            try {
                $view = (new AccountabilityLinks())->publicBook($token);
                if ($view !== null) {
                    $pdf = self::cached($token, $view);
                }
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'erp', ['event_type' => 'accountability_link_pdf_failed']);
                http_response_code(503);
                echo 'Não foi possível gerar o PDF agora. Tente de novo em alguns minutos.';
                return;
            }
        }
        if ($view === null || $pdf === null) {
            http_response_code(404);
            echo 'Link não encontrado.';
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: attachment; filename="' . AccountabilityBookDocument::filename($view) . '"');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        echo $pdf;
    }

    /**
     * PDF guardado na pasta temporária pelo conteúdo do livro e pelo token (o livro anual leva alguns segundos para
     * sair). Outra versão ou outro token = outro arquivo; o nome não revela o token.
     */
    private static function cached(string $token, array $view): string
    {
        @set_time_limit(180);
        $dir = sys_get_temp_dir() . '/connect-prestacao-pdf';
        $file = $dir . '/' . hash('sha256', $token . '|' . $view['sha256']) . '.pdf';
        if (is_file($file) && filemtime($file) > time() - 86400) {
            return (string)file_get_contents($file);
        }
        $pdf = (new AccountabilityBookDocument())->pdf($view, AccountabilityLinks::url($token));
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            @file_put_contents($file, $pdf, LOCK_EX);
        }
        return $pdf;
    }

    private function page(int $status, ?array $view, string $token, string $nonce): void
    {
        http_response_code($status);
        $logoPath = dirname(__DIR__, 4) . '/container/web/default/v1/assets/images/logo-connect-condominios.svg';
        echo (new View(rtrim(moves_container_path('erp', CONF_VIEW_ERP), '/') . '/'))->render('pages/public/accountability-link', [
            'status' => $status,
            'view' => $view,
            'base' => AccountabilityLinks::validToken($token) ? AccountabilityLinks::url($token) : '',
            'nonce' => $nonce,
            'logo' => is_file($logoPath) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($logoPath)) : '',
            'siteName' => defined('CONF_SITE_NAME') && CONF_SITE_NAME !== '' ? CONF_SITE_NAME : 'Connect Condomínios',
        ]);
    }

    /** @param array<string, string> $headers */
    private function send(array $headers): void
    {
        if (headers_sent()) {
            return;
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
