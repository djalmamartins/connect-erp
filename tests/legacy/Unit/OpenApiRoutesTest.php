<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;

/**
 * A documentação OpenAPI (docs/api/openapi.yaml + registries.openapi.yaml) e as rotas reais
 * de /api/v1 em index.php não podem divergir: toda rota documentada existe e toda rota
 * registrada está documentada, com o mesmo método.
 */
final class OpenApiRoutesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testOpenApiMatchesApiRoutes(): void
    {
        $routes = $this->routes();
        $documented = $this->documented();
        self::assertNotEmpty($routes);

        $undocumented = array_values(array_diff($routes, $documented));
        $missing = array_values(array_diff($documented, $routes));
        sort($undocumented);
        sort($missing);

        self::assertSame([], $undocumented, 'Rotas de /api/v1 sem documentação OpenAPI: ' . implode(', ', $undocumented));
        self::assertSame([], $missing, 'Operações OpenAPI sem rota em index.php: ' . implode(', ', $missing));
    }

    /** @return string[] "METHOD /path" registrados no grupo /api/v1 de index.php. */
    private function routes(): array
    {
        $index = (string)file_get_contents(self::ROOT . '/index.php');
        $start = strpos($index, '$route->group("/api/v1");');
        self::assertNotFalse($start, 'Grupo /api/v1 não encontrado em index.php');
        $end = strpos($index, '$route->group(', $start + 1);
        $api = substr($index, $start, $end === false ? null : $end - $start);

        preg_match_all('/\$route->(get|post|put|patch|delete)\(\s*"(\/[^"$]*)"/', $api, $matches, PREG_SET_ORDER);
        $routes = [];
        foreach ($matches as [, $method, $path]) {
            $routes[] = strtoupper($method) . ' ' . $path;
        }

        // Laço dos cadastros (ADR 0014): cada definição vira CRUD em /{api} e /{api}/{id}.
        self::assertStringContainsString('Definitions::all()', $api, 'O laço dos cadastros saiu do grupo /api/v1.');
        foreach (Definitions::all() as $definition) {
            $base = '/' . $definition['api'];
            foreach (['GET ' . $base, 'POST ' . $base, 'GET ' . $base . '/{id}', 'PUT ' . $base . '/{id}', 'PATCH ' . $base . '/{id}', 'DELETE ' . $base . '/{id}'] as $route) {
                $routes[] = $route;
            }
        }

        return array_values(array_unique($routes));
    }

    /** @return string[] "METHOD /path" das duas especificações OpenAPI. */
    private function documented(): array
    {
        $operations = [];
        foreach (['openapi.yaml', 'registries.openapi.yaml'] as $file) {
            $path = null;
            foreach (file(self::ROOT . '/docs/api/' . $file) ?: [] as $line) {
                if (preg_match('/^  (\/\S*):\s*$/', $line, $m)) {
                    $path = $m[1];
                    continue;
                }
                if (preg_match('/^\S/', $line)) {
                    $path = null; // saiu de paths: (components, etc.)
                    continue;
                }
                if ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
                    $operations[] = strtoupper($m[1]) . ' ' . $path;
                }
            }
        }

        return array_values(array_unique($operations));
    }
}
