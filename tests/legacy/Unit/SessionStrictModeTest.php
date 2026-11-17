<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0367: a sessão só aceita id criado pelo servidor (session.use_strict_mode). Um id escolhido por terceiro (fixação
 * de sessão) é trocado por um novo. Confere com o servidor embutido do PHP e a classe Session de verdade.
 */
final class SessionStrictModeTest extends TestCase
{
    public function testForeignSessionIdIsReplaced(): void
    {
        $dir = sys_get_temp_dir() . '/sess-' . bin2hex(random_bytes(4));
        mkdir($dir . '/s', 0777, true);
        file_put_contents($dir . '/index.php', "<?php\nrequire " . var_export(dirname(__DIR__, 3) . '/source/Core/Session.php', true) . ";\n"
            . "new Source\\Core\\Session();\n\$_SESSION['n'] = (\$_SESSION['n'] ?? 0) + 1;\necho session_id(), '|', \$_SESSION['n'];\n");
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $dir . '/s', '-d', 'session.use_strict_mode=0', '-S', '127.0.0.1:' . $port, '-t', $dir],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            $get = static function (string $cookie) use ($port): string {
                for ($i = 0; $i < 50; $i++) {
                    $ctx = stream_context_create(['http' => ['header' => $cookie === '' ? '' : "Cookie: PHPSESSID={$cookie}\r\n", 'timeout' => 5]]);
                    $out = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx);
                    if ($out !== false) {
                        return $out;
                    }
                    usleep(100000);
                }
                return '';
            };
            $chosen = 'atacante' . bin2hex(random_bytes(6));
            [$id, $count] = explode('|', $get($chosen));
            self::assertNotSame($chosen, $id, 'id inventado foi aceito');
            self::assertSame('1', $count);

            [$again, $second] = explode('|', $get($id));
            self::assertSame($id, $again, 'id criado pelo servidor continua valendo');
            self::assertSame('2', $second);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
