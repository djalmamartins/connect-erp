<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * O Source\Core\Router cria o controller com `new $class($router)`. Um construtor que pede outro tipo no primeiro
 * argumento quebra a rota com erro 500 sem registro (aconteceu com o Meu Dia em 22/11/2026). Varre os controllers
 * usados nas rotas ("Classe:metodo") e confere que o primeiro argumento aceita o router.
 */
final class RouteControllerConstructorTest extends TestCase
{
    public function testControllersUsedByRoutesAcceptTheRouterInTheConstructor(): void
    {
        $root = dirname(__DIR__, 3);
        $routeFiles = array_merge([$root . '/index.php'], glob($root . '/container/*/*/v1/routes.php') ?: []);
        $used = [];
        foreach ($routeFiles as $file) {
            preg_match_all('~"([A-Z][A-Za-z0-9\\\\]*):[a-zA-Z_]+"~', (string)file_get_contents($file), $m);
            foreach ($m[1] as $name) {
                $used[basename(str_replace('\\', '/', $name))] = true;
            }
        }
        self::assertArrayHasKey('MyDayScreens', $used, 'a varredura deixou de achar as rotas');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/source/Controllers', \FilesystemIterator::SKIP_DOTS));
        $bad = [];
        foreach ($files as $file) {
            $short = basename((string)$file, '.php');
            if (!str_ends_with((string)$file, '.php') || !isset($used[$short])) {
                continue;
            }
            $relative = substr((string)$file, strlen($root . '/source/'), -4);
            $class = 'Source\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }
            $constructor = (new ReflectionClass($class))->getConstructor();
            $first = $constructor?->getParameters()[0] ?? null;
            $type = $first?->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && !is_a(\MovesCode\Router\Router::class, $type->getName(), true)) {
                $bad[] = $class . ' (' . $type->getName() . ')';
            }
        }
        self::assertSame([], $bad, 'O primeiro argumento do construtor recebe o router: use mixed e confira o tipo.');
    }
}
