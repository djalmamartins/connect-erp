<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Revisão de cobertura de 10/11/2026 (ADR 0150): a matriz do ADR 0094 cobre só os controllers listados nela.
 * Esta varredura estática vale para todos os controllers de /api/v1, inclusive os que vierem depois:
 *  - toda rota de /api/v1 passa pelo middleware ApiAuthenticated, menos o /health, a confirmação do 2FA, a sessão do app do morador (ADR 0303) e o webhook do banco (ADR 0156),
 *    que acontece antes da sessão completa;
 *  - toda ação pública de instância chama Auth::user() ou Access::can, direto ou por um método do próprio
 *    controller (authorize, write, guard...).
 */
final class ApiGuardCoverageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    // Sessão do app do morador (ADR 0303): entrar e sair usam o token Bearer próprio, sem sessão; o DELETE só revoga o token
    // que veio no cabeçalho.
    private const PUBLIC_ROUTES = ['GET /health', 'POST /auth/2fa/verify', 'POST /resident/app-sessions', 'POST /resident/app-sessions/verify', 'DELETE /resident/app-sessions/current', 'POST /webhooks/bank/{adapter}/{secret}'];

    public function testEveryApiRouteUsesTheAuthenticatedMiddleware(): void
    {
        $index = (string)file_get_contents(self::ROOT . '/index.php');
        $start = strpos($index, '$route->group("/api/v1");');
        self::assertNotFalse($start);
        $end = strpos($index, '$route->group(', $start + 1);
        $api = substr($index, $start, $end === false ? null : $end - $start);
        preg_match_all('/\$route->(get|post|put|patch|delete)\(\s*"([^"]+)",\s*(.*?)\);\s*$/ms', $api, $matches, PREG_SET_ORDER);
        self::assertGreaterThan(150, count($matches));
        $open = [];
        foreach ($matches as [, $method, $path, $rest]) {
            if (!str_contains($rest, 'ApiAuthenticated::class')) {
                $open[] = strtoupper($method) . ' ' . $path;
            }
        }
        self::assertSame(self::PUBLIC_ROUTES, $open, 'Rota de /api/v1 sem ApiAuthenticated.');
        // Laço dos cadastros (ADR 0014): as seis rotas de cada definição usam a mesma variável com o middleware.
        self::assertStringContainsString('$registryAuth = \\Source\\Support\\Middleware\\ApiAuthenticated::class;', $api, 'O laço dos cadastros perdeu o middleware.');
        self::assertSame(6, preg_match_all('/\$route->(get|post|put|patch|delete)\(\$registryPath[^;]*, null, \$registryAuth\);/', $api));
    }

    public function testEveryPublicActionReachesAuthorization(): void
    {
        $files = glob(self::ROOT . '/source/Controllers/Api/V1/*.php') ?: [];
        self::assertGreaterThan(40, count($files));
        $unguarded = [];
        foreach ($files as $file) {
            $controller = basename($file, '.php');
            preg_match_all('/(public|private|protected)( static)? function (\w+)\([^)]*\)[^{]*\{(.*?)\n    \}/s', (string)file_get_contents($file), $m, PREG_SET_ORDER);
            $bodies = [];
            $actions = [];
            foreach ($m as [, $visibility, $static, $name, $body]) {
                $bodies[$name] = $body;
                if ($visibility === 'public' && $static === '' && !str_starts_with($name, '__')) {
                    $actions[] = $name;
                }
            }
            foreach ($actions as $action) {
                if (!self::guarded($action, $bodies, []) && !in_array($controller . '::' . $action, ['TwoFactor::verify', 'BankWebhook::receive', 'ResidentAppSessions::store', 'ResidentAppSessions::verify', 'ResidentAppSessions::destroy'], true)) {
                    $unguarded[] = $controller . '::' . $action;
                }
            }
        }
        self::assertSame([], $unguarded, 'Ação pública sem Auth::user() nem Access::can.');
    }

    public function testTwoFactorVerifyStaysLockedDown(): void
    {
        $source = (string)file_get_contents(self::ROOT . '/source/Controllers/Api/V1/TwoFactor.php');
        preg_match('/public function verify\(\)[^{]*\{(.*?)\n    \}/s', $source, $m);
        $body = $m[1] ?? '';
        self::assertStringContainsString('ApiAuthenticated::validCsrf()', $body, 'Confirmação do 2FA exige CSRF.');
        self::assertStringContainsString('Auth::twoFactorPending()', $body, 'Só com desafio pendente.');
        self::assertStringContainsString("request_limit('api_2fa'", $body, 'Com limite de tentativas.');
    }

    public function testBankWebhookStaysLockedDown(): void
    {
        $source = (string)file_get_contents(self::ROOT . '/source/Controllers/Api/V1/BankWebhook.php');
        self::assertStringContainsString('(new BankWebhooks())->receive(', $source, 'Webhook passa pelo serviço que confere o segredo e consulta o banco.');
        self::assertStringNotContainsString('Auth::', $source, 'Webhook não usa sessão.');
        $service = (string)file_get_contents(self::ROOT . '/source/Services/Erp/Banking/BankWebhooks.php');
        self::assertStringContainsString('accountByWebhookSecret(', $service);
        self::assertStringContainsString('refreshFromBank(', $service, 'Estado só muda pela consulta ao banco.');
    }

    /** @param array<string, string> $bodies @param array<string, true> $seen */
    private static function guarded(string $name, array $bodies, array $seen): bool
    {
        if (isset($seen[$name]) || !isset($bodies[$name])) {
            return false;
        }
        $seen[$name] = true;
        if (preg_match('/Auth::user\(\)|Access::can\(/', $bodies[$name])) {
            return true;
        }
        preg_match_all('/(?:\$this->|self::|static::)(\w+)\(/', $bodies[$name], $calls);
        foreach (array_unique($calls[1]) as $call) {
            if (self::guarded($call, $bodies, $seen)) {
                return true;
            }
        }
        return false;
    }
}
