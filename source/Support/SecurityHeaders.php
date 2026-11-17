<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Cabeçalhos de segurança de toda resposta (ADR 0355), enviados no começo do index.php para todos os hosts (site, ERP,
 * Studio, app do morador, Talk e API):
 * - nosniff: o navegador não "adivinha" o tipo de um arquivo enviado (um .txt com HTML não vira página);
 * - frame-ancestors 'self' e X-Frame-Options SAMEORIGIN: nenhuma tela pode ser aberta escondida dentro de outro site
 *   (clickjacking). Só o próprio host pode emoldurar;
 * - Referrer-Policy: outro site recebe só o domínio de onde o visitante veio, nunca o caminho com ids;
 * - Permissions-Policy: câmera só para o próprio sistema (vistoria), sem microfone, localização ou pagamento;
 * - HSTS fora do laboratório: depois do primeiro acesso por HTTPS, o navegador não abre mais por HTTP. Sem
 *   includeSubDomains, para não prender subdomínios que a Connect ainda não usa.
 *
 * Não restringe scripts (CSP completa fica para depois do piloto, ROADMAP §3.7). Uma rota que precisa de mais (download
 * com sandbox) troca o Content-Security-Policy com header() normal.
 */
final class SecurityHeaders
{
    /** @return array<string, string> */
    public static function headers(bool $localHost): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',
        ];
        if (!$localHost) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $headers;
    }

    public static function send(bool $localHost): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        foreach (self::headers($localHost) as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
