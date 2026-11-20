<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\VirtualDisk;
use Source\Support\Storage\DocumentStore;
use Source\Support\Storage\LocalDocumentStore;

/** ADR 0370: o Disco Virtual guarda e lê pelo DocumentStore, para o OneDrive entrar depois sem mudar quem usa. */
final class DocumentStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/store-' . bin2hex(random_bytes(4)) . '/documents';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->root)));
    }

    public function testLocalStorePutsReadsAndDeletes(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($source, '%PDF-1.4');
        $store = new LocalDocumentStore($this->root . '/');
        self::assertTrue($store->put('7/2026/a.pdf', $source, false));
        self::assertSame($this->root . '/7/2026/a.pdf', $store->path('7/2026/a.pdf'));
        self::assertSame('%PDF-1.4', file_get_contents((string)$store->path('7/2026/a.pdf')));
        self::assertSame('0640', substr(sprintf('%o', fileperms($this->root . '/7/2026/a.pdf')), -4));
        self::assertSame("Require all denied\n", file_get_contents(dirname($this->root) . '/.htaccess'));
        self::assertFileExists($source, 'cópia, não move');
        $store->delete('7/2026/a.pdf');
        self::assertNull($store->path('7/2026/a.pdf'));
        $store->delete('7/2026/a.pdf');
        self::assertFalse($store->put('7/2026/b.pdf', $source, true), 'mover só arquivo de upload HTTP');
        unlink($source);
    }

    public function testLocalStoreNeverLeavesTheFolder(): void
    {
        $store = new LocalDocumentStore($this->root);
        foreach (['', '/etc/passwd', '../x', '1/../../x', '1/..', "1\\..\\x", "1/a\0.pdf"] as $bad) {
            self::assertFalse(LocalDocumentStore::safe($bad), json_encode($bad));
            self::assertNull($store->path($bad));
            self::assertFalse($store->put($bad, __FILE__, false));
        }
        self::assertTrue(LocalDocumentStore::safe('1/2026/..a.pdf'));
    }

    public function testVirtualDiskReadsThroughTheStore(): void
    {
        $name = '1/2026/' . bin2hex(random_bytes(16)) . '.pdf';
        $store = new class implements DocumentStore {
            /** @var list<string> */
            public array $asked = [];

            public function put(string $relative, string $source, bool $move): bool
            {
                return false;
            }

            public function path(string $relative): ?string
            {
                $this->asked[] = $relative;
                return '/cache/' . $relative;
            }

            public function delete(string $relative): void
            {
            }
        };
        $disk = new VirtualDisk(new \PDO('sqlite::memory:'), null, null, false, null, $store);
        self::assertSame('/cache/' . $name, $disk->absolute($name));
        self::assertNull($disk->absolute('../' . $name), 'o formato do caminho continua conferido antes do armazenamento');
        self::assertSame([$name], $store->asked);
    }

    public function testVirtualDiskNoLongerWritesFilesItself(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/VirtualDisk.php');
        foreach (['move_uploaded_file(', 'copy(', 'unlink(', 'mkdir('] as $call) {
            self::assertStringNotContainsString($call, $source, $call);
        }
    }
}
