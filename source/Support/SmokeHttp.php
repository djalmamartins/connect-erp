<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Conferência por HTTP depois de publicar (ADR 0356), usada por tools/hosting/smoke-http.php. Sem login e sem gravar
 * nada: só GET em páginas públicas, telas que precisam mandar para o login, o /api/v1/health, o redirecionamento de HTTP
 * para HTTPS, as pastas internas que o .htaccess barra (ADR 0338) e os cabeçalhos de segurança (ADR 0355).
 */
final class SmokeHttp
{
    /** Cabeçalhos que toda página precisa trazer em produção (nomes em minúsculas). */
    public const REQUIRED_HEADERS = ['x-content-type-options', 'x-frame-options', 'strict-transport-security', 'referrer-policy'];

    /**
     * @return list<array{name:string, url:string, codes:list<int>, kind:string}>
     *   kind: page (cabeçalhos), health (JSON ready), https (vai para https), blocked (403/404)
     */
    public static function checks(string $domain): array
    {
        $d = strtolower(trim($domain, " ./"));
        $www = "https://www.{$d}";
        $erp = "https://erp.{$d}";
        $studio = "https://studio.{$d}";
        $app = "https://app.{$d}";
        $out = [
            ['name' => 'API /api/v1/health', 'url' => "{$erp}/api/v1/health", 'codes' => [200], 'kind' => 'health'],
            ['name' => 'Site', 'url' => "{$www}/", 'codes' => [200], 'kind' => 'page'],
            ['name' => 'Central de Ajuda', 'url' => "{$www}/suporte", 'codes' => [200], 'kind' => 'page'],
            ['name' => 'ERP login', 'url' => "{$erp}/login", 'codes' => [200], 'kind' => 'page'],
            ['name' => 'ERP painel sem login vai para o login', 'url' => "{$erp}/dashboard", 'codes' => [302, 303], 'kind' => 'page'],
            ['name' => 'Verificação pública de certidão', 'url' => "{$erp}/verificar-certidao-debitos", 'codes' => [200], 'kind' => 'page'],
            ['name' => 'Studio', 'url' => "{$studio}/login", 'codes' => [200, 302], 'kind' => 'page'],
            ['name' => 'App do morador', 'url' => "{$app}/", 'codes' => [200, 302], 'kind' => 'page'],
            ['name' => 'HTTP vai para HTTPS', 'url' => "http://erp.{$d}/login", 'codes' => [301, 302, 307, 308], 'kind' => 'https'],
        ];
        foreach (['/.git/config', '/.env', '/composer.json', '/tools/hosting/backup.sh', '/docs/ROADMAP_V1.md', '/database/migrations/', '/storage/logs/', '/.local/'] as $path) {
            $out[] = ['name' => 'Bloqueado: ' . $path, 'url' => $erp . $path, 'codes' => [403, 404], 'kind' => 'blocked'];
        }
        return $out;
    }

    /**
     * @param array{name:string, url:string, codes:list<int>, kind:string} $check
     * @param array<string, string> $headers nomes em minúsculas
     * @return string|null o problema, ou null quando está certo
     */
    public static function evaluate(array $check, int $code, array $headers, string $body): ?string
    {
        if (!in_array($code, $check['codes'], true)) {
            return sprintf('respondeu %d (esperado %s)', $code, implode(' ou ', $check['codes']));
        }
        if ($check['kind'] === 'health') {
            $json = json_decode($body, true);
            $status = is_array($json) ? (string)($json['data']['status'] ?? '') : '';
            if ($status !== 'ready') {
                return 'status "' . ($status !== '' ? $status : 'sem JSON') . '" (esperado "ready"; veja as rotinas do cron em HOSPEDAGEM.md §8)';
            }
        }
        if ($check['kind'] === 'https' && !str_starts_with(strtolower($headers['location'] ?? ''), 'https://')) {
            return 'não manda para https:// (Location: ' . ($headers['location'] ?? 'vazio') . ')';
        }
        if ($check['kind'] === 'blocked' && $body !== '' && (str_contains($body, '[core]') || str_contains($body, 'APP_ENV') || str_contains($body, '"require"'))) {
            return 'mostrou o conteúdo do arquivo';
        }
        if ($check['kind'] === 'page' || $check['kind'] === 'health') {
            $missing = array_values(array_filter(self::REQUIRED_HEADERS, static fn (string $h): bool => !isset($headers[$h])));
            if ($missing !== []) {
                return 'sem cabeçalho de segurança: ' . implode(', ', $missing);
            }
        }
        return null;
    }

    /** @return array<string, string> cabeçalhos da última resposta, nomes em minúsculas */
    public static function parseHeaders(string $raw): array
    {
        $blocks = preg_split("~\r?\n\r?\n~", trim($raw)) ?: [];
        $last = (string)end($blocks);
        $out = [];
        foreach (preg_split("~\r?\n~", $last) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $out[strtolower(trim($name))] = trim($value);
            }
        }
        return $out;
    }
}
