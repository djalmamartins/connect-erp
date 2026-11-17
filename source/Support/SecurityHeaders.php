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
 * - X-Robots-Tag noindex (ADR 0365): ERP, app do morador, Studio, Talk, a API e o app em /app não aparecem no Google.
 *   Só o site institucional e a Central de Ajuda são indexados (o /robots.txt diz o mesmo).
 *
 * Não restringe scripts (CSP completa fica para depois do piloto, ROADMAP §3.7). Uma rota que precisa de mais (download
 * com sandbox) troca o Content-Security-Policy com header() normal.
 */
final class SecurityHeaders
{
    /** Primeiro nome dos hosts que não são o site público. */
    private const PRIVATE_HOSTS = ['erp', 'app', 'studio', 'talk'];

    public static function indexable(string $host, string $path): bool
    {
        $label = explode('.', strtolower($host))[0];
        if (in_array($label, self::PRIVATE_HOSTS, true)) {
            return false;
        }
        $path = strtolower($path);
        foreach (['/api/', '/app', '/ops/'] as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, string> */
    public static function headers(bool $localHost, string $host = '', string $path = '/'): array
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
        if (!self::indexable($host, $path)) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }
        return $headers;
    }

    public static function send(bool $localHost): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        foreach (self::headers($localHost, (string)($_SERVER['HTTP_HOST'] ?? ''), $path) as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
