<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Revisão de segurança da v1.0 (ROADMAP §3.7, ADR 0337): todo POST de página (ERP, site, Central de Ajuda, app web do
 * morador, Help Desk e Studio) confere o token CSRF. Varredura estática, vale para as rotas que vierem depois: a ação chama
 * csrf_verify (ou o csrfMatches da verificação de documento) direto ou por um método do próprio controller ($this->x /
 * self::x), em qualquer profundidade. A /api/v1 fica de fora: o middleware ApiAuthenticated confere o X-CSRF-Token em todo
 * método que muda dado (ApiGuardCoverageTest garante o middleware em todas as rotas).
 */
final class WebCsrfCoverageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const ROUTE_FILES = ['index.php', 'container/erp/connect/v1/routes.php', 'container/web/support/v1/routes.php',
        'container/web/default/v1/routes.php', 'container/app/connect/v1/routes.php', 'container/cms/helpdesk/v1/routes.php',
        'container/cms/studio/v1/routes.php'];

    /** POST que só busca e redireciona para um GET, sem gravar nada: CSRF não se aplica. */
    private const READ_ONLY = ['Source\\Controllers\\Web\\Connect\\Web:articlesSearch', 'Source\\Controllers\\Studio\\Studio:posts'];

    /** @return array<string, string> nome do método => corpo */
    private static function methods(string $file): array
    {
        preg_match_all('/(?:public|private|protected)(?: static)? function (\w+)\([^)]*\)[^{]*\{(.*?)\n    \}/s', (string)file_get_contents($file), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $name, $body]) {
            $out[$name] = $body;
        }
        return $out;
    }

    private static function reachesCsrf(array $methods, string $method, array $seen = []): bool
    {
        $body = $methods[$method] ?? '';
        if (str_contains($body, 'csrf_verify(') || str_contains($body, 'csrfMatches(')) {
            return true;
        }
        $seen[$method] = true;
        preg_match_all('/(?:\$this->|self::|static::)(\w+)\(/', $body, $calls);
        foreach (array_unique($calls[1]) as $call) {
            if (!isset($seen[$call]) && isset($methods[$call]) && self::reachesCsrf($methods, $call, $seen)) {
                return true;
            }
        }
        return false;
    }

    public function testEveryPagePostChecksCsrf(): void
    {
        $checked = 0;
        $missing = [];
        foreach (self::ROUTE_FILES as $file) {
            $namespace = null;
            foreach (file(self::ROOT . '/' . $file) ?: [] as $n => $line) {
                if (preg_match('/\$route->namespace\((null|"([^"]+)")\)/', $line, $m)) {
                    $namespace = isset($m[2]) ? str_replace('\\\\', '\\', $m[2]) : null;
                }
                if (!preg_match('/\$route->post\(.*?"(\w+):(\w+)"/', $line, $m) || $namespace === null || str_contains($namespace, '\\Api\\')) {
                    continue;
                }
                $action = $namespace . '\\' . $m[1] . ':' . $m[2];
                if (in_array($action, self::READ_ONLY, true)) {
                    continue;
                }
                $path = self::ROOT . '/' . str_replace(['Source\\', '\\'], ['source/', '/'], $namespace) . '/' . $m[1] . '.php';
                self::assertFileExists($path, "{$file}:" . ($n + 1));
                $checked++;
                if (!self::reachesCsrf(self::methods($path), $m[2])) {
                    $missing[] = "{$file}:" . ($n + 1) . ' ' . $action;
                }
            }
        }
        self::assertGreaterThan(100, $checked);
        self::assertSame([], $missing, 'POST de página sem conferência do CSRF.');
    }

    public function testRegistryLoopPostsCheckCsrf(): void
    {
        $routes = (string)file_get_contents(self::ROOT . '/container/erp/connect/v1/routes.php');
        self::assertSame(3, preg_match_all('/\$route->post\(\$base.{0,200}?\(new Registry\(\)\)->(save|destroy)\(/s', $routes));
        $methods = self::methods(self::ROOT . '/source/Controllers/Erp/Connect/Registry.php');
        self::assertTrue(self::reachesCsrf($methods, 'save'));
        self::assertTrue(self::reachesCsrf($methods, 'destroy'));
    }
}
