<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Conferência geral de 10/11/2026: o Source\Core\Router instancia todo controller com
 * `new $class($this->router)`. Controller::__construct(?string $pathToViews) não aceita o
 * roteador, então um controller sem construtor próprio quebra com TypeError — e o MovesCode
 * Router transforma isso em "erro 500" sem log (foi o caso de /seguranca no ERP).
 *
 * Varredura estática: todo controller concreto que estende Source\Core\Controller declara o
 * próprio construtor, e esse construtor não exige argumento string/obrigatório incompatível.
 */
final class ControllerConstructorTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testEveryRoutedControllerDeclaresItsOwnConstructor(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/source/Controllers', \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        $missing = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;
            $code = (string)file_get_contents($file->getPathname());
            if (!preg_match('/^namespace\s+([^;]+);/m', $code, $ns) || !preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)\s+extends\s+Controller\b/m', $code, $class)) continue;
            if (str_contains($code, 'abstract class ' . $class[1])) continue;
            $checked++;
            if (!preg_match('/function\s+__construct\s*\(\s*([^)]*)\)/', $code, $ctor)) {
                $missing[] = $ns[1] . '\\' . $class[1] . ' (sem __construct)';
                continue;
            }
            // Parâmetro obrigatório tipado como string quebraria com o roteador como argumento.
            if (preg_match('/^\s*\??string\s+\$\w+\s*(?:,|$)/', $ctor[1])) {
                $missing[] = $ns[1] . '\\' . $class[1] . ' (primeiro parâmetro string)';
            }
        }
        self::assertGreaterThan(20, $checked);
        self::assertSame([], $missing, 'Controllers que quebram quando o Router os instancia: ' . implode(', ', $missing));
    }

    public function testSecurityControllerAcceptsTheRouterArgument(): void
    {
        $reflection = new ReflectionClass(\Source\Controllers\Erp\Connect\Security::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(\Source\Controllers\Erp\Connect\Security::class, $constructor->getDeclaringClass()->getName());
        self::assertSame(0, $constructor->getNumberOfRequiredParameters());
    }
}
