<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\OversizedPost;

/** ADR 0363: envio acima do post_max_size avisa o limite em vez de "sessão expirada". */
final class OversizedPostTest extends TestCase
{
    private const SERVER = ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '9000000', 'REQUEST_URI' => '/condominios/1/disco'];

    public function testDetectsOnlyADiscardedBodyAboveTheLimit(): void
    {
        self::assertSame(8388608, OversizedPost::limit(self::SERVER, [], [], '8M'));
        self::assertSame(8388608, OversizedPost::limit(['REQUEST_METHOD' => 'put'] + self::SERVER, [], [], '8M'));

        self::assertNull(OversizedPost::limit(['CONTENT_LENGTH' => '8388608'] + self::SERVER, [], [], '8M'), 'no limite exato o PHP recebe');
        self::assertNull(OversizedPost::limit(self::SERVER, ['csrf' => 'x'], [], '8M'), 'corpo recebido');
        self::assertNull(OversizedPost::limit(self::SERVER, [], ['file' => []], '8M'));
        self::assertNull(OversizedPost::limit(['REQUEST_METHOD' => 'GET'] + self::SERVER, [], [], '8M'));
        self::assertNull(OversizedPost::limit(self::SERVER, [], [], '0'), 'post_max_size 0 = sem limite');
        self::assertNull(OversizedPost::limit(self::SERVER, [], [], false));
        self::assertNull(OversizedPost::limit(['REQUEST_METHOD' => 'POST'], [], [], '8M'), 'sem Content-Length');
    }

    public function testMessageNamesTheLimitInMegabytes(): void
    {
        self::assertStringContainsString('limite de 8 MB', OversizedPost::message(8388608));
        self::assertStringContainsString('limite de 1 MB', OversizedPost::message(1000));
    }

    public function testJsonForApiAndScriptsHtmlRedirectForForms(): void
    {
        self::assertTrue(OversizedPost::wantsJson(['REQUEST_URI' => '/api/v1/documents?x=1']));
        self::assertTrue(OversizedPost::wantsJson(['REQUEST_URI' => '/disco', 'HTTP_ACCEPT' => 'application/json, text/plain']));
        self::assertTrue(OversizedPost::wantsJson(['REQUEST_URI' => '/disco', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        self::assertFalse(OversizedPost::wantsJson(['REQUEST_URI' => '/disco', 'HTTP_ACCEPT' => 'text/html']));
    }

    public function testGoesBackOnlyToTheSameHost(): void
    {
        $server = ['HTTP_HOST' => 'erp.connect.lab', 'REQUEST_URI' => '/disco/enviar?a=1'];
        self::assertSame('https://erp.connect.lab/disco?pasta=2', OversizedPost::backTo(['HTTP_REFERER' => 'https://erp.connect.lab/disco?pasta=2'] + $server));
        self::assertSame('http://erp.connect.lab:8080/x', OversizedPost::backTo(['HTTP_HOST' => 'erp.connect.lab:8080', 'HTTP_REFERER' => 'http://erp.connect.lab:8080/x'] + $server));
        self::assertSame('/disco/enviar', OversizedPost::backTo(['HTTP_REFERER' => 'https://evil.example/erp.connect.lab'] + $server));
        self::assertSame('/disco/enviar', OversizedPost::backTo(['HTTP_REFERER' => 'javascript://erp.connect.lab/'] + $server));
        self::assertSame('/disco/enviar', OversizedPost::backTo($server));
        self::assertSame('/x', OversizedPost::backTo(['HTTP_HOST' => 'erp.connect.lab', 'REQUEST_URI' => '//evil.example/x']), 'nunca //host');
    }

    public function testFrontControllerCallsItBeforeTheRoutes(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        $call = strpos($index, '\\Source\\Support\\OversizedPost::handle();');
        self::assertNotFalse($call);
        self::assertLessThan(strpos($index, '$route = new Router('), $call);
        self::assertGreaterThan(strpos($index, '$session = new Session();'), $call, 'o aviso precisa da sessão para o flash');
    }

    /** O PHP de verdade descarta o corpo: confere ponta a ponta com o servidor embutido e post_max_size pequeno. */
    public function testRealPhpDiscardsTheBodyAndTheGuardSeesIt(): void
    {
        $dir = sys_get_temp_dir() . '/oversized-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $class = dirname(__DIR__, 3) . '/source/Support/OversizedPost.php';
        file_put_contents($dir . '/index.php', "<?php\nrequire " . var_export(dirname(__DIR__, 3) . '/source/Support/DeployReadiness.php', true) . ";\n"
            . "require " . var_export($class, true) . ";\n"
            . "\$limit = Source\\Support\\OversizedPost::limit(\$_SERVER, \$_POST, \$_FILES, ini_get('post_max_size'));\n"
            . "echo \$limit === null ? 'recebido:' . count(\$_POST) : 'descartado:' . \$limit;\n");
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, '-d', 'post_max_size=1K', '-S', '127.0.0.1:' . $port, '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            $post = static function (string $body) use ($port): string {
                for ($i = 0; $i < 50; $i++) {
                    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => $body, 'ignore_errors' => true, 'timeout' => 5]]);
                    $out = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx);
                    if ($out !== false) {
                        return $out;
                    }
                    usleep(100000);
                }
                return '';
            };
            self::assertSame('recebido:1', $post('csrf=abc'));
            self::assertSame('descartado:1024', $post('csrf=abc&x=' . str_repeat('a', 4096)));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
