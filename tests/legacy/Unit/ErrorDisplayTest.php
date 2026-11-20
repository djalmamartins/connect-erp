<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\ErrorDisplay;

/** ADR 0366: sem debug, erro do PHP não aparece na página nem a versão do PHP no cabeçalho. */
final class ErrorDisplayTest extends TestCase
{
    public function testSettingsOnlyWithoutDebug(): void
    {
        self::assertSame(['display_errors' => '0', 'display_startup_errors' => '0', 'log_errors' => '1'], ErrorDisplay::settings(false));
        self::assertSame([], ErrorDisplay::settings(true));
    }

    public function testRemovesThePhpVersionHeader(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Support/ErrorDisplay.php');
        self::assertStringContainsString("header_remove('X-Powered-By')", $source);
    }

    public function testCliIsLeftAlone(): void
    {
        $before = ini_get('display_errors');
        ErrorDisplay::apply(false, 'cli');
        self::assertSame($before, ini_get('display_errors'), 'o cron precisa ver o erro no log');
    }

    public function testFrontControllerAppliesItRightAfterTheAutoload(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        $call = strpos($index, '\\Source\\Support\\ErrorDisplay::apply(CONF_APP_DEBUG);');
        self::assertNotFalse($call);
        self::assertGreaterThan(strpos($index, 'vendor/autoload.php'), $call);
        self::assertLessThan(strpos($index, 'AppLogger::bootstrap()'), $call);
    }

    /** Servidor embutido com display_errors e expose_php ligados, como um php.ini de fábrica. */
    public function testRealServerHidesTheWarning(): void
    {
        $dir = sys_get_temp_dir() . '/errdisp-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/index.php', "<?php\nrequire " . var_export(dirname(__DIR__, 3) . '/source/Support/ErrorDisplay.php', true) . ";\n"
            . "Source\\Support\\ErrorDisplay::apply(isset(\$_GET['debug']));\necho 'antes|', \$naoExiste, '|depois';\n");
        $port = random_int(40001, 60000);
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'expose_php=On', '-d', 'error_reporting=-1', '-d', 'log_errors=0',
            '-S', '127.0.0.1:' . $port, '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            $get = static function (string $query) use ($port): array {
                for ($i = 0; $i < 50; $i++) {
                    $body = @file_get_contents('http://127.0.0.1:' . $port . '/' . $query);
                    if ($body !== false) {
                        return [$body, implode("\n", $http_response_header ?? [])];
                    }
                    usleep(100000);
                }
                return ['', ''];
            };
            [$body, $headers] = $get('');
            self::assertSame('antes||depois', $body);
            self::assertStringNotContainsStringIgnoringCase('X-Powered-By', $headers);
            // O servidor embutido não manda X-Powered-By mesmo com expose_php; aqui só o aviso é conferido com debug.
            [$debugBody] = $get('?debug=1');
            self::assertStringContainsString('naoExiste', $debugBody, 'com debug o aviso continua aparecendo');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
