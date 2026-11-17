<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\Storage\GraphTransport;
use Source\Support\Storage\OneDriveDocumentStore;

/** ADR 0373: OneDrive pelo Microsoft Graph, testado com um Graph falso (nenhuma chamada real). */
final class OneDriveDocumentStoreTest extends TestCase
{
    private string $cache;
    /** @var object&GraphTransport */
    private object $graph;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/onedrive-' . bin2hex(random_bytes(4));
        $this->graph = new class implements GraphTransport {
            /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
            public array $calls = [];
            /** @var array<string, string> arquivos no "drive", por endereço do item */
            public array $files = [];
            public int $tokenStatus = 200;
            public string $session = 'https://up.example.sharepoint.com/session/1';
            public string $sessionBody = '';

            public function send(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');
                if (str_contains($url, 'login.microsoftonline.com')) {
                    return ['status' => $this->tokenStatus, 'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600]), 'location' => ''];
                }
                if (str_starts_with($url, 'https://download.example.sharepoint.com/')) {
                    return ['status' => 200, 'body' => $this->files[substr($url, 40)] ?? '', 'location' => ''];
                }
                if ($url === $this->session) {
                    if ($method === 'DELETE') {
                        return ['status' => 204, 'body' => '', 'location' => ''];
                    }
                    $this->sessionBody .= (string)$body;
                    $total = (int)substr((string)strrchr($headers['Content-Range'], '/'), 1);
                    return ['status' => strlen($this->sessionBody) === $total ? 201 : 202, 'body' => '{}', 'location' => ''];
                }
                $item = preg_replace('~/(content|createUploadSession)$~', '', $url);
                return match (true) {
                    $method === 'PUT' => [$this->files[$item] = (string)$body, ['status' => 201, 'body' => '{}', 'location' => '']][1],
                    $method === 'POST' => ['status' => 200, 'body' => json_encode(['uploadUrl' => $this->session]), 'location' => ''],
                    $method === 'GET' && isset($this->files[$item]) => ['status' => 302, 'body' => '', 'location' => 'https://download.example.sharepoint.com/' . $item],
                    $method === 'DELETE' => [$found = isset($this->files[$item]), $this->files = array_diff_key($this->files, [$item => 1]), ['status' => $found ? 204 : 404, 'body' => '', 'location' => '']][2],
                    default => ['status' => 404, 'body' => '', 'location' => ''],
                };
            }
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->cache));
    }

    private function store(): OneDriveDocumentStore
    {
        return new OneDriveDocumentStore('tenant-id', 'client-id', 'segredo', 'drive!1', '/ConnectERP/documents/', $this->cache, $this->graph);
    }

    private function file(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'od');
        file_put_contents($file, $content);
        return $file;
    }

    public function testItemUrlEncodesEachPart(): void
    {
        self::assertSame('https://graph.microsoft.com/v1.0/drives/drive%211/root:/ConnectERP/documents/7/2026/a%20b.pdf:',
            $this->store()->itemUrl('7/2026/a b.pdf'));
    }

    public function testSmallFileRoundTripWithCacheAndTokenReuse(): void
    {
        $store = $this->store();
        $source = $this->file('%PDF-1.4 conteúdo');
        self::assertTrue($store->put('7/2026/a.pdf', $source, false));
        self::assertFileExists($source, 'cópia, não move');
        $path = $store->path('7/2026/a.pdf');
        self::assertSame($this->cache . '/7/2026/a.pdf', $path);
        self::assertSame('%PDF-1.4 conteúdo', file_get_contents((string)$path));
        $calls = count($this->graph->calls);
        touch((string)$path, time() - 3 * 86400);
        clearstatcache();
        self::assertSame($path, $store->path('7/2026/a.pdf'), 'segunda leitura vem do cache');
        clearstatcache();
        self::assertGreaterThan(time() - 60, filemtime((string)$path), 'usar renova a data para a faxina (ADR 0375)');
        self::assertCount($calls, $this->graph->calls);

        $tokens = array_filter($this->graph->calls, static fn(array $c): bool => str_contains($c['url'], 'oauth2'));
        self::assertCount(1, $tokens, 'um token só para tudo');
        $token = array_values($tokens)[0];
        self::assertStringContainsString('grant_type=client_credentials', (string)$token['body']);
        self::assertStringContainsString('scope=https%3A%2F%2Fgraph.microsoft.com%2F.default', (string)$token['body']);
        foreach ($this->graph->calls as $call) {
            if (str_starts_with($call['url'], 'https://download.')) {
                self::assertArrayNotHasKey('Authorization', $call['headers'], 'o token não vai para o endereço de download');
            } elseif (str_starts_with($call['url'], OneDriveDocumentStore::GRAPH)) {
                self::assertSame('Bearer tok', $call['headers']['Authorization']);
            }
        }
        $store->delete('7/2026/a.pdf');
        self::assertFileDoesNotExist($this->cache . '/7/2026/a.pdf');
        self::assertSame([], $this->graph->files);
        self::assertNull($store->path('7/2026/a.pdf'));
        unlink($source);
    }

    public function testLargeFileGoesInChunksWithoutTheToken(): void
    {
        $content = random_bytes(OneDriveDocumentStore::SIMPLE_LIMIT + 1000000);
        $source = $this->file($content);
        self::assertTrue($this->store()->put('7/2026/grande.pdf', $source, false));
        self::assertSame($content, $this->graph->sessionBody);
        $parts = array_values(array_filter($this->graph->calls, fn(array $c): bool => $c['url'] === $this->graph->session));
        self::assertCount(2, $parts);
        self::assertSame('bytes 0-' . (OneDriveDocumentStore::CHUNK - 1) . '/' . strlen($content), $parts[0]['headers']['Content-Range']);
        self::assertSame(0, OneDriveDocumentStore::CHUNK % 327680, 'parte múltipla de 320 KiB, como o Graph exige');
        foreach ($parts as $part) {
            self::assertArrayNotHasKey('Authorization', $part['headers']);
        }
        unlink($source);
    }

    public function testFailuresDoNotThrowAndNeverLeaveTheFolder(): void
    {
        $this->graph->tokenStatus = 401;
        $store = $this->store();
        $source = $this->file('x');
        self::assertFalse($store->put('7/2026/a.pdf', $source, false), 'autorização expirada: envio falha');
        self::assertNull($store->path('7/2026/b.pdf'), 'arquivo indisponível, sem exceção');
        $store->delete('7/2026/b.pdf');
        self::assertFalse($store->put('7/2026/a.pdf', $source, true), 'mover só arquivo de upload HTTP');
        $before = count($this->graph->calls);
        foreach (['../x', '/etc/passwd', '1/../../x', ''] as $bad) {
            self::assertFalse($store->put($bad, $source, false));
            self::assertNull($store->path($bad));
            $store->delete($bad);
        }
        self::assertCount($before, $this->graph->calls, 'caminho ruim nem chega ao Graph');
        unlink($source);
    }

    public function testOnlyFollowsHttpsRedirects(): void
    {
        self::assertTrue(OneDriveDocumentStore::trusted('https://x.sharepoint.com/download?token=1'));
        self::assertFalse(OneDriveDocumentStore::trusted('http://x.sharepoint.com/'));
        self::assertFalse(OneDriveDocumentStore::trusted('https://user:pass@x.sharepoint.com/'));
        self::assertFalse(OneDriveDocumentStore::trusted('file:///etc/passwd'));
        self::assertFalse(OneDriveDocumentStore::trusted(''));
    }
}
