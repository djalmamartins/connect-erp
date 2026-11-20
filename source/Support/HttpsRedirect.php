<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * HTTP vai para HTTPS em produção (ADR 0364).
 *
 * O HSTS (ADR 0355) só vale depois da primeira visita por HTTPS, e nada mandava quem digita http://... para o HTTPS:
 * o login e o cookie de sessão podiam passar em texto aberto. Aqui, antes da sessão, quando APP_ENV=production:
 * GET/HEAD recebem 301 e os demais métodos 308 (mantém o método e o corpo) para https:// no mesmo host e caminho.
 *
 * Só redireciona para o domínio do APP_URL (o próprio host ou um subdomínio dele), nunca para um Host qualquer enviado
 * pelo visitante. CONNECT_FORCE_HTTPS=false desliga, para o caso de um proxy na frente não informar o HTTPS e causar
 * redirecionamento em laço.
 */
final class HttpsRedirect
{
    /** Primeiros nomes que o sistema usa nos subdomínios; tirados do APP_URL para achar o domínio principal. */
    private const SUBDOMAINS = ['www', 'erp', 'app', 'studio', 'talk'];

    /** @param array<string, mixed> $server */
    public static function isHttps(array $server): bool
    {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        $proto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return ($https !== '' && $https !== 'off') || $proto === 'https'
            || strtolower((string)($server['REQUEST_SCHEME'] ?? '')) === 'https' || (string)($server['SERVER_PORT'] ?? '') === '443';
    }

    /** "https://erp.connectcondominios.com.br" → "connectcondominios.com.br". */
    public static function domain(string $appUrl): string
    {
        $host = strtolower((string)parse_url($appUrl, PHP_URL_HOST));
        $labels = explode('.', $host);
        if (count($labels) > 2 && in_array($labels[0], self::SUBDOMAINS, true)) {
            array_shift($labels);
        }
        return implode('.', $labels);
    }

    /**
     * Para onde e com que código redirecionar; null quando não redireciona.
     *
     * @param array<string, mixed> $server
     * @param array<string, string|false> $env APP_ENV, APP_URL e CONNECT_FORCE_HTTPS
     * @return array{url: string, code: int}|null
     */
    public static function target(array $server, array $env): ?array
    {
        if (strtolower(trim((string)($env['APP_ENV'] ?? ''))) !== 'production' || self::isHttps($server)) {
            return null;
        }
        $force = trim((string)($env['CONNECT_FORCE_HTTPS'] ?? ''));
        if ($force !== '' && filter_var($force, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false) {
            return null;
        }
        $domain = self::domain((string)($env['APP_URL'] ?? ''));
        $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
        $name = (string)preg_replace('~:\d+$~', '', $host);
        if ($domain === '' || !preg_match('~^[a-z0-9.-]+(:\d+)?$~', $host) || ($name !== $domain && !str_ends_with($name, '.' . $domain))) {
            return null;
        }
        $uri = (string)($server['REQUEST_URI'] ?? '/');
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//') || preg_match('~[\r\n]~', $uri)) {
            $uri = '/';
        }
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        return ['url' => 'https://' . $name . $uri, 'code' => in_array($method, ['GET', 'HEAD'], true) ? 301 : 308];
    }

    /** Chamado no index.php antes da sessão. */
    public static function handle(): void
    {
        $env = [];
        foreach (['APP_ENV', 'APP_URL', 'CONNECT_FORCE_HTTPS'] as $key) {
            $env[$key] = getenv($key);
        }
        $target = self::target($_SERVER, $env);
        if ($target === null) {
            return;
        }
        header('Location: ' . $target['url'], true, $target['code']);
        exit;
    }
}
